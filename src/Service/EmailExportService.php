<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Config\EmailConfig;
use HomeCare\Database\DatabaseInterface;
use HomeCare\Export\CsvIntakeExporter;
use HomeCare\Export\FhirIntakeExporter;
use HomeCare\Export\IntakeExportQuery;
use HomeCare\Report\MedicationSummaryReport;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * HC-108: "Email this export to me" flow.
 *
 * Each send method:
 *   1. Rate-limits the user (max 3/h via hc_audit_log).
 *   2. Validates the caller-supplied email.
 *   3. Renders the export bytes in memory.
 *   4. Attaches them to a short Symfony Email and hands it to the
 *      configured mailer (or returns a shaped "email-disabled"
 *      failure when SMTP isn't set up yet).
 *   5. Writes one `export.emailed` audit row with the shape
 *      required by HC-108.
 *
 * Uses Symfony Mailer directly (not EmailChannel) because
 * NotificationMessage has no attachment surface. Reminder-class
 * messages shouldn't carry attachments anyway.
 */
final class EmailExportService
{
    public const MAX_PER_HOUR = 3;

    private const TYPE_CSV = 'csv';
    private const TYPE_FHIR = 'fhir';
    private const TYPE_MEDICATION_SUMMARY = 'medication_summary';

    /** @var callable():string */
    private readonly mixed $clock;

    /** @var callable(string,string,?int,array<string,mixed>):void */
    private readonly mixed $audit;

    private ?MailerInterface $mailer;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly EmailConfig $emailConfig,
        private readonly IntakeExportQuery $exportQuery,
        private readonly CsvIntakeExporter $csvExporter,
        private readonly FhirIntakeExporter $fhirExporter,
        private readonly MedicationSummaryReport $summaryReport,
        ?MailerInterface $mailer = null,
        ?callable $clock = null,
        ?callable $audit = null,
    ) {
        $this->mailer = $mailer;
        $this->clock = $clock ?? static fn (): string => date('Y-m-d H:i:s');
        $this->audit = $audit
            ?? static fn (string $action, string $entityType, ?int $entityId, array $details): null => null;
    }

    /**
     * @return array{ok:bool,reason:?string,size_bytes:int}
     */
    public function sendCsvExport(
        string $login,
        string $recipientEmail,
        int $patientId,
        string $startDate,
        string $endDate,
    ): array {
        $guard = $this->preflight($login, $recipientEmail);
        if ($guard !== null) {
            return $guard;
        }

        $rows = $this->exportQuery->fetch($patientId, $startDate, $endDate);
        $bytes = $this->csvExporter->toCsv($rows);
        $patientName = $rows[0]['patient_name'] ?? "patient-{$patientId}";
        $filename = self::filename($patientName, 'csv');
        $size = strlen($bytes);

        return $this->dispatch(
            login:         $login,
            recipient:     $recipientEmail,
            type:          self::TYPE_CSV,
            filename:      $filename,
            contentType:   'text/csv; charset=utf-8',
            attachment:    $bytes,
            subject:       "[HomeCare] Intake export — {$patientName}",
            body:          $this->csvBody($patientName, $startDate, $endDate, count($rows)),
            auditMeta:     [
                'type'       => self::TYPE_CSV,
                'patient_id' => $patientId,
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'size_bytes' => $size,
            ],
        );
    }

    /**
     * @return array{ok:bool,reason:?string,size_bytes:int}
     */
    public function sendFhirExport(
        string $login,
        string $recipientEmail,
        int $patientId,
        string $startDate,
        string $endDate,
    ): array {
        $guard = $this->preflight($login, $recipientEmail);
        if ($guard !== null) {
            return $guard;
        }

        $rows = $this->exportQuery->fetch($patientId, $startDate, $endDate);
        $bytes = $this->fhirExporter->toJson($rows);
        $patientName = $rows[0]['patient_name'] ?? "patient-{$patientId}";
        $filename = self::filename($patientName, 'json');
        $size = strlen($bytes);

        return $this->dispatch(
            login:         $login,
            recipient:     $recipientEmail,
            type:          self::TYPE_FHIR,
            filename:      $filename,
            contentType:   'application/fhir+json; charset=utf-8',
            attachment:    $bytes,
            subject:       "[HomeCare] Intake export (FHIR) — {$patientName}",
            body:          $this->fhirBody($patientName, $startDate, $endDate, count($rows)),
            auditMeta:     [
                'type'       => self::TYPE_FHIR,
                'patient_id' => $patientId,
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'size_bytes' => $size,
            ],
        );
    }

    /**
     * Renders the medication-summary inline as a plain-text body
     * rather than attaching a PDF. Operators reading on a phone
     * see the table immediately without hopping to a viewer.
     *
     * @return array{ok:bool,reason:?string,size_bytes:int}
     */
    public function sendMedicationSummary(
        string $login,
        string $recipientEmail,
        int $patientId,
    ): array {
        $guard = $this->preflight($login, $recipientEmail);
        if ($guard !== null) {
            return $guard;
        }

        $summary = $this->summaryReport->build($patientId, date('Y-m-d'));
        if ($summary === null) {
            return ['ok' => false, 'reason' => 'patient_not_found', 'size_bytes' => 0];
        }

        $body = $this->renderSummaryBody($summary);
        $patientName = $summary['patient']['name'];
        $size = strlen($body);

        return $this->dispatch(
            login:         $login,
            recipient:     $recipientEmail,
            type:          self::TYPE_MEDICATION_SUMMARY,
            filename:      null,
            contentType:   null,
            attachment:    null,
            subject:       "[HomeCare] Medication summary — {$patientName}",
            body:          $body,
            auditMeta:     [
                'type'       => self::TYPE_MEDICATION_SUMMARY,
                'patient_id' => $patientId,
                'start_date' => null,
                'end_date'   => null,
                'size_bytes' => $size,
            ],
        );
    }

    /**
     * Pre-flight checks common to all three send paths.
     *
     * @return array{ok:bool,reason:?string,size_bytes:int}|null
     *   Null when the caller should proceed; shaped failure otherwise.
     */
    private function preflight(string $login, string $recipientEmail): ?array
    {
        if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'reason' => 'invalid_recipient', 'size_bytes' => 0];
        }
        if (!$this->emailConfig->isReady()) {
            return ['ok' => false, 'reason' => 'email_disabled', 'size_bytes' => 0];
        }
        if ($this->recentExportCount($login) >= self::MAX_PER_HOUR) {
            return ['ok' => false, 'reason' => 'rate_limited', 'size_bytes' => 0];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $auditMeta
     *
     * @return array{ok:bool,reason:?string,size_bytes:int}
     */
    private function dispatch(
        string $login,
        string $recipient,
        string $type,
        ?string $filename,
        ?string $contentType,
        ?string $attachment,
        string $subject,
        string $body,
        array $auditMeta,
    ): array {
        try {
            $email = (new Email())
                ->from(new Address(
                    $this->emailConfig->getFromAddress(),
                    $this->emailConfig->getFromName()
                ))
                ->to($recipient)
                ->subject($subject)
                ->text($body);

            if ($attachment !== null && $filename !== null && $contentType !== null) {
                $email->attach($attachment, $filename, $contentType);
            }

            $this->resolveMailer()->send($email);
        } catch (TransportExceptionInterface $e) {
            return ['ok' => false, 'reason' => 'transport_error', 'size_bytes' => 0];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'unexpected_error', 'size_bytes' => 0];
        }

        ($this->audit)('export.emailed', 'user', null, $auditMeta);
        $size = is_int($auditMeta['size_bytes'] ?? null)
            ? (int) $auditMeta['size_bytes']
            : 0;

        return ['ok' => true, 'reason' => null, 'size_bytes' => $size];
    }

    /**
     * Count `export.emailed` audit rows for this user in the last hour.
     */
    private function recentExportCount(string $login): int
    {
        $oneHourAgo = date('Y-m-d H:i:s', (int) strtotime($this->now()) - 3600);
        $rows = $this->db->query(
            "SELECT COUNT(*) AS n FROM hc_audit_log
             WHERE user_login = ? AND action = 'export.emailed'
               AND created_at >= ?",
            [$login, $oneHourAgo]
        );

        return $rows === [] ? 0 : (int) ($rows[0]['n'] ?? 0);
    }

    private function now(): string
    {
        /** @var callable():string $fn */
        $fn = $this->clock;

        return ($fn)();
    }

    private function resolveMailer(): MailerInterface
    {
        if ($this->mailer === null) {
            $transport = Transport::fromDsn($this->emailConfig->getDsn());
            $this->mailer = new Mailer($transport);
        }

        return $this->mailer;
    }

    private static function filename(string $patientName, string $ext): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', trim($patientName)) ?? 'patient');
        $slug = trim($slug, '-') ?: 'patient';

        return "intake-{$slug}-" . date('Y-m-d') . '.' . $ext;
    }

    private function csvBody(string $patientName, string $start, string $end, int $rowCount): string
    {
        return "Attached is the intake-history CSV for {$patientName}, "
            . "covering {$start} to {$end}.\n\n"
            . "Total intake records: {$rowCount}.\n\n"
            . 'Open the attachment in any spreadsheet app or import it into a clinic EMR.';
    }

    private function fhirBody(string $patientName, string $start, string $end, int $rowCount): string
    {
        return "Attached is the FHIR R4 bundle for {$patientName}, "
            . "covering {$start} to {$end}.\n\n"
            . "Total MedicationAdministration resources: {$rowCount}.\n\n"
            . 'Import into any FHIR-capable EMR or PHR.';
    }

    /**
     * @param array{patient:array{id:int,name:string},today:string,active:list<mixed>,discontinued:list<mixed>} $summary
     */
    private function renderSummaryBody(array $summary): string
    {
        $out = ["Medication summary — {$summary['patient']['name']}",
                "Generated on: {$summary['today']}",
                ''];

        $out[] = 'Active medications:';
        if ($summary['active'] === []) {
            $out[] = '  (none)';
        } else {
            foreach ($summary['active'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = self::strOrEmpty($row['name'] ?? null, '?');
                $dosage = self::strOrEmpty($row['dosage'] ?? null);
                $frequency = self::strOrEmpty($row['frequency'] ?? null);
                $remainingDays = $row['remaining_days'] ?? null;
                $line = "  • {$name}";
                if ($dosage !== '') {
                    $line .= " — {$dosage}";
                }
                if ($frequency !== '') {
                    $line .= " every {$frequency}";
                }
                if (is_numeric($remainingDays)) {
                    $line .= " (~{$remainingDays} days remaining)";
                }
                $out[] = $line;
            }
        }

        $out[] = '';
        $out[] = 'Recently discontinued:';
        if ($summary['discontinued'] === []) {
            $out[] = '  (none)';
        } else {
            foreach ($summary['discontinued'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = self::strOrEmpty($row['name'] ?? null, '?');
                $endDate = self::strOrEmpty($row['end_date'] ?? null, '?');
                $out[] = "  • {$name} (ended {$endDate})";
            }
        }

        return implode("\n", $out) . "\n";
    }

    private static function strOrEmpty(mixed $value, string $fallback = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $fallback;
    }
}
