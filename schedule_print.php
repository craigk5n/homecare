<?php
/**
 * Printable daily medication sheet — PDF for all active patients.
 *
 * Generates a one-page-per-patient PDF showing today's scheduled doses
 * with checkboxes for caregivers to tick off. Pin it to the fridge.
 *
 * Usage: GET /schedule_print.php             (all patients)
 *        GET /schedule_print.php?patient_id=1 (single patient)
 */

declare(strict_types=1);

require_once 'includes/init.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$singlePatientId = getIntValue('patient_id');
$patients = getPatients();

if ($singlePatientId) {
    $patients = array_filter($patients, static fn(array $p): bool => (int) $p['id'] === $singlePatientId);
}

if (empty($patients)) {
    header('Location: dashboard.php');
    exit;
}

$date = date('Y-m-d');
$dateDisplay = date('l, F j, Y'); // e.g., "Thursday, April 17, 2026"

// ── Build schedule data per patient ─────────────────────────────────
$patientSchedules = [];

foreach ($patients as $p) {
    $patientId = (int) $p['id'];
    $patientName = (string) $p['name'];

    $sql = "SELECT ms.id, m.name, m.dosage, ms.frequency, ms.unit_per_dose,
                   ms.start_date, ms.end_date,
                   (SELECT GROUP_CONCAT(mi.taken_time ORDER BY mi.taken_time)
                      FROM hc_medicine_intake mi
                     WHERE mi.schedule_id = ms.id AND DATE(mi.taken_time) = ?) AS taken_today
              FROM hc_medicine_schedules ms
              JOIN hc_medicines m ON ms.medicine_id = m.id
             WHERE ms.patient_id = ?
               AND ms.is_prn = 'N'
               AND ms.frequency IS NOT NULL
               AND ms.start_date <= ?
               AND (ms.end_date IS NULL OR ms.end_date >= ?)
             ORDER BY m.name ASC";

    $rows = dbi_get_cached_rows($sql, [$date, $patientId, $date, $date]);

    // Compute today's expected dose times using the same logic as
    // schedule_daily.php: advance from the last recorded intake (or
    // start_date) by frequency. Show ALL doses for the day — past
    // ones marked as taken (checked), future ones as pending.
    $doses = [];
    $todayStart = new DateTime("$date 00:00:00");
    $todayEnd = new DateTime("$date 23:59:59");

    foreach ($rows as $row) {
        $scheduleId = (int) $row[0];
        $medName = (string) $row[1];
        $dosage = (string) $row[2];
        $frequency = (string) $row[3];
        $unitPerDose = (float) $row[4];
        $startDate = (string) $row[5];
        $endDate = $row[6] !== null ? new DateTime((string) $row[6]) : null;
        $takenTimesToday = !empty($row[7]) ? explode(',', (string) $row[7]) : [];

        try {
            $freqSeconds = frequencyToSeconds($frequency);
        } catch (\InvalidArgumentException) {
            continue;
        }

        // Find most recent intake (any day) for anchoring.
        $lastIntake = dbi_get_cached_rows(
            'SELECT taken_time FROM hc_medicine_intake WHERE schedule_id = ? ORDER BY taken_time DESC LIMIT 1',
            [$scheduleId],
        );

        $startDateTime = new DateTime($startDate);

        if (!empty($lastIntake)) {
            $nextDose = new DateTime((string) $lastIntake[0][0]);
            $nextDose->modify("+{$freqSeconds} seconds");
            while ($nextDose < $todayStart) {
                $nextDose->modify("+{$freqSeconds} seconds");
            }
        } else {
            $nextDose = clone $todayStart;
            if ($frequency === '1d') {
                $nextDose->setTime((int) $startDateTime->format('H'), (int) $startDateTime->format('i'));
                if ($nextDose < $todayStart) {
                    $nextDose->modify('+1 day');
                }
            } else {
                $secondsSinceMidnight = ((int) $startDateTime->format('H') * 3600)
                    + ((int) $startDateTime->format('i') * 60);
                $interval = $secondsSinceMidnight % $freqSeconds;
                if ($interval > 0) {
                    $nextDose->modify('+' . ($freqSeconds - $interval) . ' seconds');
                }
            }
        }

        // Walk through today generating each expected dose.
        $current = clone $nextDose;
        while ($current <= $todayEnd) {
            if ($endDate !== null && $current > $endDate) {
                break;
            }

            // Check if this dose was taken (intake within 5 minutes of
            // the expected time, matching schedule_daily.php).
            $expectedTs = $current->getTimestamp();
            $taken = false;
            foreach ($takenTimesToday as $takenTime) {
                if (abs($expectedTs - strtotime(trim($takenTime))) < 300) {
                    $taken = true;
                    break;
                }
            }

            $h = (int) $current->format('H');
            $m = (int) $current->format('i');

            $doses[] = [
                'time_sort' => sprintf('%02d%02d', $h, $m),
                'time' => $current->format('g:i A'),
                'name' => $medName,
                'dosage' => $dosage,
                'unit_per_dose' => $unitPerDose,
                'frequency' => $frequency,
                'taken' => $taken,
            ];

            $current->modify("+{$freqSeconds} seconds");
        }
    }

    // Sort by time of day.
    usort($doses, static fn(array $a, array $b): int => strcmp($a['time_sort'], $b['time_sort']));

    $patientSchedules[] = [
        'name' => $patientName,
        'doses' => $doses,
    ];
}

// ── Generate HTML ───────────────────────────────────────────────────
$html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
$html .= '<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1a1a1a; margin: 0; padding: 0; }
.patient-page { page-break-after: always; padding: 20px 30px; }
.patient-page:last-child { page-break-after: avoid; }
h1 { font-size: 16pt; margin: 0 0 2px; }
h2 { font-size: 11pt; color: #555; font-weight: normal; margin: 0 0 15px; }
table { width: 100%; border-collapse: collapse; }
th { background: #2c7a7b; color: #fff; font-size: 9pt; text-align: left; padding: 6px 8px; }
td { padding: 8px 8px; border-bottom: 1px solid #e2e8f0; font-size: 10pt; vertical-align: middle; }
tr:nth-child(even) td { background: #f7fafc; }
.checkbox { width: 14px; height: 14px; border: 1.5px solid #999; display: inline-block; vertical-align: middle; text-align: center; line-height: 14px; font-size: 11px; }
.checkbox.done { background: #28a745; border-color: #28a745; color: #fff; }
.taken-row td { color: #999; }
.taken-row .time { color: #999; }
.time { color: #2c7a7b; font-weight: bold; white-space: nowrap; }
.dosage { color: #666; font-size: 9pt; }
.footer { margin-top: 15px; font-size: 8pt; color: #999; text-align: center; }
.notes-area { margin-top: 20px; border-top: 1px solid #ccc; padding-top: 8px; }
.notes-area p { font-size: 9pt; color: #666; margin: 0 0 4px; }
.notes-line { border-bottom: 1px solid #ddd; height: 22px; }
</style></head><body>';

foreach ($patientSchedules as $ps) {
    $html .= '<div class="patient-page">';
    $html .= '<h1>' . htmlspecialchars($ps['name']) . ' — Daily Medication Sheet</h1>';
    $html .= '<h2>' . htmlspecialchars($dateDisplay) . '</h2>';

    if (empty($ps['doses'])) {
        $html .= '<p style="color:#666;">No scheduled medications for today.</p>';
    } else {
        $html .= '<table>';
        $html .= '<tr><th style="width:5%;"></th><th style="width:15%;">Time</th>'
                . '<th style="width:35%;">Medication</th><th style="width:20%;">Dosage</th>'
                . '<th style="width:10%;">Dose</th><th style="width:15%;">Frequency</th></tr>';

        foreach ($ps['doses'] as $dose) {
            $rowClass = $dose['taken'] ? ' class="taken-row"' : '';
            $checkboxClass = $dose['taken'] ? 'checkbox done' : 'checkbox';
            $checkmark = $dose['taken'] ? '&#10003;' : '';

            $html .= '<tr' . $rowClass . '>';
            $html .= '<td><span class="' . $checkboxClass . '">' . $checkmark . '</span></td>';
            $html .= '<td class="time">' . htmlspecialchars($dose['time']) . '</td>';
            $html .= '<td>' . htmlspecialchars($dose['name']) . '</td>';
            $html .= '<td class="dosage">' . htmlspecialchars($dose['dosage']) . '</td>';
            $html .= '<td>' . rtrim(rtrim(number_format($dose['unit_per_dose'], 2), '0'), '.') . '</td>';
            $html .= '<td class="dosage">' . htmlspecialchars($dose['frequency']) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
    }

    // Notes area at the bottom.
    $html .= '<div class="notes-area">';
    $html .= '<p><strong>Notes:</strong></p>';
    for ($i = 0; $i < 4; $i++) {
        $html .= '<div class="notes-line"></div>';
    }
    $html .= '</div>';

    $html .= '<div class="footer">Generated by HomeCare on ' . htmlspecialchars(date('Y-m-d H:i')) . '</div>';
    $html .= '</div>';
}

$html .= '</body></html>';

// ── Render PDF ──────────────────────────────────────────────────────
$options = new Options();
$options->setIsRemoteEnabled(false);
$options->setIsHtml5ParserEnabled(true);
$options->setChroot([dirname(__DIR__)]);
$options->setDefaultFont('DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->setPaper('Letter', 'portrait');
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->render();

audit_log('export.daily_sheet', 'patient', $singlePatientId ?: null, [
    'patient_count' => count($patientSchedules),
    'date' => $date,
]);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="medication-sheet-' . $date . '.pdf"');

echo $dompdf->output();
exit;
