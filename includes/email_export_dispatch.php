<?php
/**
 * HC-108 helpers: "Email this export to me" dispatch shared by the
 * three export endpoints (export_intake_csv.php,
 * export_intake_fhir.php, medication_summary.php).
 *
 * Each endpoint includes this file and calls
 * {@see hc108_email_and_exit()} when the request is a POST with
 * `delivery=email`. Anything else falls through to the existing
 * stream-to-browser behaviour.
 */

declare(strict_types=1);

use HomeCare\Config\EmailConfig;
use HomeCare\Database\DbiAdapter;
use HomeCare\Export\CsvIntakeExporter;
use HomeCare\Export\FhirIntakeExporter;
use HomeCare\Export\IntakeExportQuery;
use HomeCare\Report\MedicationSummaryReport;
use HomeCare\Repository\InventoryRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Repository\UserRepository;
use HomeCare\Service\EmailExportService;
use HomeCare\Service\InventoryService;

if (!function_exists('hc108_email_and_exit')) {
    /**
     * Render the selected export and email it to the logged-in user.
     *
     * Exits after rendering a feedback page — caller does not return.
     *
     * @param 'csv'|'fhir'|'medication_summary' $type
     * @param array{id:int,name:string}         $patient
     */
    function hc108_email_and_exit(
        string $type,
        int $patientId,
        ?string $startDate,
        ?string $endDate,
        array $patient,
    ): never {
        $db = new DbiAdapter();
        $login = (string) ($GLOBALS['login'] ?? '');
        $user = (new UserRepository($db))->findByLogin($login);

        if ($user === null
            || ($user['email_notifications'] ?? 'N') !== 'Y'
            || $user['email'] === null
            || $user['email'] === ''
            || filter_var($user['email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            hc108_render_error_page(
                "Your account doesn't have a verified email. "
                . 'Set one on the Contact section of your Settings page, '
                . "then enable 'Email me reminders', and try again."
            );
            exit;
        }

        $service = new EmailExportService(
            db:            $db,
            emailConfig:   new EmailConfig($db),
            exportQuery:   new IntakeExportQuery($db),
            csvExporter:   new CsvIntakeExporter(),
            fhirExporter:  new FhirIntakeExporter(),
            summaryReport: new MedicationSummaryReport(
                $db,
                new InventoryService(
                    new InventoryRepository($db),
                    new ScheduleRepository($db),
                    new \HomeCare\Repository\PatientRepository($db),
                    new \HomeCare\Repository\StepRepository($db),
                ),
            ),
            audit: static function (string $a, string $e, ?int $id, array $d): void {
                audit_log($a, $e, $id, $d);
            },
        );

        $result = match ($type) {
            'csv' => $service->sendCsvExport(
                $login,
                $user['email'],
                $patientId,
                (string) $startDate,
                (string) $endDate,
            ),
            'fhir' => $service->sendFhirExport(
                $login,
                $user['email'],
                $patientId,
                (string) $startDate,
                (string) $endDate,
            ),
            'medication_summary' => $service->sendMedicationSummary(
                $login,
                $user['email'],
                $patientId,
            ),
        };

        if ($result['ok']) {
            hc108_render_success_page($user['email'], $patient['name']);
        } else {
            hc108_render_error_page(match ($result['reason']) {
                'rate_limited'       => 'Too many export emails in the last hour. Try again shortly.',
                'email_disabled'     => 'The admin has not configured SMTP on this HomeCare server.',
                'invalid_recipient'  => "We don't have a valid email for your account.",
                'transport_error'    => 'The mail server rejected the message. Check SMTP config.',
                'patient_not_found'  => 'Patient not found.',
                default              => 'Unexpected error — nothing was sent.',
            });
        }
        exit;
    }

    function hc108_render_success_page(string $to, string $patientName): void
    {
        print_header();
        echo '<div class="container mt-3"><div class="alert alert-success">'
           . 'Export emailed to <strong>' . htmlspecialchars($to) . '</strong>'
           . ' for ' . htmlspecialchars($patientName) . '. Check your inbox.'
           . '</div>'
           . '<p><a href="javascript:history.back()" class="btn btn-secondary">Back</a></p>'
           . '</div>';
        echo print_trailer();
    }

    function hc108_render_error_page(string $message): void
    {
        print_header();
        echo '<div class="container mt-3"><div class="alert alert-danger">'
           . htmlspecialchars($message)
           . '</div>'
           . '<p><a href="javascript:history.back()" class="btn btn-secondary">Back</a></p>'
           . '</div>';
        echo print_trailer();
    }
}
