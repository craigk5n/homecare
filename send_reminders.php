<?php
require_once 'includes/init.php';

use HomeCare\Config\NtfyConfig;
use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\InventoryRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\InventoryService;
use HomeCare\Service\SupplyAlertLog;
use HomeCare\Service\SupplyAlertService;

// HC-041: ntfy settings live in hc_config now. Manage via settings.php
// (admin section) or direct SQL. Defaults: ntfy_url='https://ntfy.sh/',
// ntfy_topic='', ntfy_enabled='N'. The cron script short-circuits
// push calls when the config isn't `isReady()`.
$ntfy = new NtfyConfig(new DbiAdapter());


$dryRun = in_array('--dry-run', $argv);


// Handle command line arguments for the minutes before due
$minutesBeforeDue = 5;  // default minutes before due
foreach ($argv as $arg) {
    if (is_numeric($arg)) {
        $minutesBeforeDue = intval($arg);
        break;
    }
}

// Fetch all active patients
$patients = getPatients();  // Using the existing getPatients function that fetches active patients

foreach ($patients as $patient) {
    $patient_id = $patient['id'];
    $patient_name = $patient['name'];

    // Fetch medication schedules close to being due for this patient
    $sql = "SELECT ms.id, m.name, ms.frequency,
            (SELECT MAX(mi.taken_time) FROM hc_medicine_intake mi WHERE mi.schedule_id = ms.id) AS last_taken
            FROM hc_medicine_schedules ms
            JOIN hc_medicines m ON ms.medicine_id = m.id
            WHERE ms.patient_id = ?
            AND (ms.end_date IS NULL OR ms.end_date >= CURDATE())";
    $schedules = dbi_get_cached_rows($sql, [$patient_id]);

    foreach ($schedules as $schedule) {
        $lastTaken = $schedule[3];
        $frequency = $schedule[2];

        if (!$lastTaken) continue;

        if (!empty($end_date) && $end_date < date('Y-m-d'))
            continue; // Finished this medication
        
        $secondsUntilDue = calculateSecondsUntilDue($lastTaken, $frequency);

        // Check if the notification should be sent
        if ($secondsUntilDue <= $minutesBeforeDue * 60) {
            $message = "Medication due soon for $patient_name: " . $schedule[1];  // Medicine name is the second item
            if ($dryRun) {
                echo "Dry run: Would send notification for patient $patient_id: $message\n";
            } else {
                sendNotification($patient_id, $patient_name, $message);
            }
        }
    }
}

// ── HC-040: Low-supply alerts ─────────────────────────────────────────
// Runs after the per-dose reminders. Threshold comes from hc_config
// (`supply_alert_days`, default 7). Each alert fires at most once per
// 24h per medicine; state is tracked in hc_supply_alert_log.

$supplyThreshold = SupplyAlertService::DEFAULT_THRESHOLD_DAYS;
$cfg = dbi_get_cached_rows(
    "SELECT value FROM hc_config WHERE setting = 'supply_alert_days'"
);
if (!empty($cfg) && !empty($cfg[0][0])) {
    $parsed = (int) $cfg[0][0];
    if ($parsed > 0) {
        $supplyThreshold = $parsed;
    }
}

$supplyDb = new DbiAdapter();
$supplyService = new SupplyAlertService(
    $supplyDb,
    new InventoryService(new InventoryRepository($supplyDb), new ScheduleRepository($supplyDb)),
    new SupplyAlertLog($supplyDb),
);

foreach ($supplyService->findPendingAlerts($supplyThreshold) as $alert) {
    $message = $alert->message();
    if ($dryRun) {
        echo "Dry run: Would send low-supply alert for medicine {$alert->medicineId}: {$message}\n";
    } elseif (!$ntfy->isReady()) {
        echo "Skipped (ntfy disabled in hc_config): {$message}\n";
    } else {
        sendSupplyAlert($ntfy, $message);
        $supplyService->recordSent($alert->medicineId);
    }
}

function sendSupplyAlert(NtfyConfig $ntfy, string $message): void
{
    $postData = json_encode([
        'topic' => $ntfy->getTopic(),
        'title' => 'Low Medication Supply',
        'message' => $message,
        'tags' => ['warning', 'pill'],
        'priority' => 4, // ntfy: "high"
    ]);

    $ch = curl_init($ntfy->getUrl());
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_exec($ch);
    curl_close($ch);

    echo "Low-supply alert sent: $message\n";
}

function sendNotification($patient_id, $patient_name, $message) {
    global $ntfy;
    if (!$ntfy->isReady()) {
        echo "Skipped (ntfy disabled in hc_config): $message\n";
        return;
    }
    // TODO: Look into X-Delay, click/actions
    $postData = json_encode([
        'topic' => $ntfy->getTopic(),
        'title' => 'Medication Reminder',
        'message' => $message,
        'tags' => ['pill']
    ]);

    $ch = curl_init($ntfy->getUrl());
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: text/plain',
        'Content-Length: ' . strlen($postData)
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    $response = curl_exec($ch);
    print_r($response);
    curl_close($ch);

    echo "Notification sent for patient $patient_name ($patient_id): $message\n";
}

?>
