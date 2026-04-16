<?php
require_once 'includes/init.php';

use HomeCare\Auth\SignedUrl;
use HomeCare\Config\EmailConfig;
use HomeCare\Config\NtfyConfig;
use HomeCare\Config\WebhookConfig;
use HomeCare\Database\DbiAdapter;
use HomeCare\Notification\ChannelRegistry;
use HomeCare\Notification\CurlHttpClient;
use HomeCare\Notification\EmailChannel;
use HomeCare\Notification\NotificationMessage;
use HomeCare\Notification\NtfyChannel;
use HomeCare\Notification\WebhookChannel;
use HomeCare\Repository\InventoryRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\InventoryService;
use HomeCare\Service\SupplyAlertLog;
use HomeCare\Service\SupplyAlertService;

// HC-100: channels live behind a registry now. Ntfy is the only
// default member; HC-101/102 land email and webhook adapters here.
// `NtfyConfig` is still the source of truth for ntfy's URL/topic/
// enabled flag (HC-041).
$db = new DbiAdapter();
$ntfy = new NtfyConfig($db);
$channels = new ChannelRegistry();
$channels->register(new NtfyChannel($ntfy));
// HC-101: email channel participates when admin has configured SMTP.
// The channel's `isReady()` short-circuits when the DSN/from-address
// isn't set, so registering unconditionally is safe.
$channels->register(new EmailChannel(new EmailConfig($db)));
// HC-102: webhook channel. Uses its own HttpClient so the per-webhook
// timeout can honour `webhook_timeout_seconds` instead of ntfy's.
$webhookConfig = new WebhookConfig($db);
$channels->register(new WebhookChannel(
    config: $webhookConfig,
    secret: SignedUrl::getSecret(),
    http: new CurlHttpClient($webhookConfig->getTimeoutSeconds()),
));

$dryRun = in_array('--dry-run', $argv, true);

// Minutes-before-due override: optional positional arg.
$minutesBeforeDue = 5;
foreach ($argv as $arg) {
    if (is_numeric($arg)) {
        $minutesBeforeDue = (int) $arg;
        break;
    }
}

$patients = getPatients();

foreach ($patients as $patient) {
    $patient_id = $patient['id'];
    $patient_name = $patient['name'];

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

        if (!$lastTaken) {
            continue;
        }

        $secondsUntilDue = calculateSecondsUntilDue($lastTaken, $frequency);

        if ($secondsUntilDue <= $minutesBeforeDue * 60) {
            $body = "Medication due soon for {$patient_name}: " . $schedule[1];
            if ($dryRun) {
                echo "Dry run: Would send notification for patient {$patient_id}: {$body}\n";
                continue;
            }
            dispatchReminder($channels, $patient_id, $patient_name, $body);
        }
    }
}

// ── HC-040: Low-supply alerts ─────────────────────────────────────────

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

$supplyService = new SupplyAlertService(
    $db,
    new InventoryService(new InventoryRepository($db), new ScheduleRepository($db)),
    new SupplyAlertLog($db),
);

foreach ($supplyService->findPendingAlerts($supplyThreshold) as $alert) {
    $message = $alert->message();
    if ($dryRun) {
        echo "Dry run: Would send low-supply alert for medicine {$alert->medicineId}: {$message}\n";
        continue;
    }
    $delivered = $channels->dispatch(new NotificationMessage(
        title: 'Low Medication Supply',
        body: $message,
        priority: NotificationMessage::PRIORITY_HIGH,
        tags: ['warning', 'pill'],
    ));
    if ($delivered === 0) {
        echo "Skipped (no channel ready): {$message}\n";
    } else {
        echo "Low-supply alert sent: {$message}\n";
        $supplyService->recordSent($alert->medicineId);
    }
}

/**
 * Fan a per-dose reminder out to every ready channel in the registry.
 * Mirrors the stdout shape the legacy `sendNotification()` used so
 * cron log consumers keep parsing what they expect.
 */
function dispatchReminder(
    ChannelRegistry $channels,
    int $patientId,
    string $patientName,
    string $body,
): void {
    $delivered = $channels->dispatch(new NotificationMessage(
        title: 'Medication Reminder',
        body: $body,
        tags: ['pill'],
    ));
    if ($delivered === 0) {
        echo "Skipped (no channel ready): {$body}\n";
        return;
    }
    echo "Notification sent for patient {$patientName} ({$patientId}): {$body}\n";
}
