<?php
require_once 'includes/init.php';

use HomeCare\Auth\SignedUrl;
use HomeCare\Config\EmailConfig;
use HomeCare\Config\NtfyConfig;
use HomeCare\Config\WebhookConfig;
use HomeCare\Database\DbiAdapter;
use HomeCare\Notification\ChannelRegistry;
use HomeCare\Notification\ChannelResolver;
use HomeCare\Notification\CurlHttpClient;
use HomeCare\Notification\EmailChannel;
use HomeCare\Notification\NotificationMessage;
use HomeCare\Notification\NtfyChannel;
use HomeCare\Notification\WebhookChannel;
use HomeCare\Repository\InventoryRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\InventoryService;
use HomeCare\Service\LateDoseAlertLog;
use HomeCare\Service\LateDoseAlertService;
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
// HC-103: resolver wires per-user preference → registry default when
// we have user context.
$resolver = new ChannelResolver($channels);

// HC-104: addressing plumbing. Pre-load the opted-in email addresses
// once per run so the per-reminder dispatch doesn't re-query.
$users = new \HomeCare\Repository\UserRepository($db);
$emailSubscribers = $users->getEmailSubscribers();

// HC-124: pause repository for skipping paused schedules.
$pauseRepo = new \HomeCare\Repository\PauseRepository($db);
$todayDate = date('Y-m-d');

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

    // HC-120: PRN rows have no cadence, so `ms.is_prn = 'N'` excludes
    // them from the reminder fan-out entirely -- the cron job has
    // nothing to remind about until a caregiver takes one voluntarily.
    $sql = "SELECT ms.id, m.name, ms.frequency, ms.start_date,
            ms.cycle_on_days, ms.cycle_off_days,
            (SELECT MAX(mi.taken_time) FROM hc_medicine_intake mi WHERE mi.schedule_id = ms.id) AS last_taken
            FROM hc_medicine_schedules ms
            JOIN hc_medicines m ON ms.medicine_id = m.id
            WHERE ms.patient_id = ?
            AND ms.is_prn = 'N'
            AND ms.frequency IS NOT NULL
            AND (ms.end_date IS NULL OR ms.end_date >= CURDATE())";
    $schedules = dbi_get_cached_rows($sql, [$patient_id]);

    foreach ($schedules as $schedule) {
        $scheduleId = (int) $schedule[0];
        $frequency = $schedule[2];
        $schedStartDate = (string) $schedule[3];
        $cycleOn = $schedule[4] !== null ? (int) $schedule[4] : null;
        $cycleOff = $schedule[5] !== null ? (int) $schedule[5] : null;
        $lastTaken = $schedule[6];

        if (!$lastTaken || $frequency === null || $frequency === '') {
            continue;
        }

        // HC-124: skip paused schedules — no reminders while on hold.
        if ($pauseRepo->isPausedOn($scheduleId, $todayDate)) {
            continue;
        }

        // HC-121: skip off-days in cycle dosing schedules.
        if (!HomeCare\Domain\ScheduleCalculator::isOnDay($schedStartDate, $cycleOn, $cycleOff, $todayDate)) {
            continue;
        }

        $secondsUntilDue = calculateSecondsUntilDue($lastTaken, $frequency);

        if ($secondsUntilDue <= $minutesBeforeDue * 60) {
            $body = "Medication due soon for {$patient_name}: " . $schedule[1];
            if ($dryRun) {
                echo "Dry run: Would send notification for patient {$patient_id}: {$body}\n";
                continue;
            }
            dispatchReminder($channels, $emailSubscribers, $patient_id, $patient_name, $body);
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
    new InventoryService(new InventoryRepository($db), new ScheduleRepository($db), new \HomeCare\Repository\PatientRepository($db)),
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

// ── HC-105: Late-dose alerts ─────────────────────────────────────────
// Threshold in minutes comes from hc_config.late_dose_alert_minutes;
// 0 or unset ⇒ feature off. Throttled per-schedule on the exact due
// instant so a permanently-overdue schedule fires exactly once until
// the caregiver records the dose.

$lateThreshold = 0;
$cfg = dbi_get_cached_rows(
    "SELECT value FROM hc_config WHERE setting = 'late_dose_alert_minutes'"
);
if (!empty($cfg) && !empty($cfg[0][0])) {
    $parsed = (int) $cfg[0][0];
    if ($parsed > 0) {
        $lateThreshold = $parsed;
    }
}

if ($lateThreshold > 0) {
    $lateService = new LateDoseAlertService($db, new LateDoseAlertLog($db));
    foreach ($lateService->findPendingAlerts($lateThreshold) as $lateAlert) {
        $body = $lateAlert->message();
        $title = 'Late dose: ' . $lateAlert->medicineName;
        if ($dryRun) {
            echo "Dry run: Would send late-dose alert for schedule "
               . "{$lateAlert->scheduleId}: {$body}\n";
            continue;
        }

        // Topic fan-out (ntfy, webhook).
        $topicMsg = new NotificationMessage(
            title:    $title,
            body:     $body,
            priority: NotificationMessage::PRIORITY_HIGH,
            tags:     ['late', 'pill'],
        );
        $accepted = $channels->dispatch($topicMsg, ['ntfy', 'webhook']);

        // Per-user email. The [URGENT] subject prefix is added
        // automatically by EmailChannel for PRIORITY_HIGH.
        foreach ($emailSubscribers as $address) {
            $perUser = new NotificationMessage(
                title:     $title,
                body:      $body,
                priority:  NotificationMessage::PRIORITY_HIGH,
                tags:      ['late', 'pill'],
                recipient: $address,
            );
            if ($channels->dispatch($perUser, ['email']) > 0) {
                $accepted++;
            }
        }

        if ($accepted === 0) {
            echo "Skipped (no channel ready): {$body}\n";
            continue;
        }
        echo "Late-dose alert sent for schedule {$lateAlert->scheduleId}: {$body}\n";
        $lateService->recordSent($lateAlert->scheduleId, $lateAlert->dueAt);
    }
}

/**
 * Fan a per-dose reminder out across the channel registry.
 *
 * Two addressing models coexist:
 *   - Topic channels (ntfy, webhook) get a recipient-less message.
 *     One dispatch reaches every subscriber on the topic.
 *   - Email is per-user: we iterate the opted-in subscriber list
 *     and dispatch one message per address.
 *
 * @param list<string> $emailSubscribers raw email addresses
 */
function dispatchReminder(
    ChannelRegistry $channels,
    array $emailSubscribers,
    int $patientId,
    string $patientName,
    string $body,
): void {
    // Topic-based delivery. Email is excluded from the default fan-out
    // so it doesn't fire with no recipient.
    $topicMsg = new NotificationMessage(
        title: 'Medication Reminder',
        body:  $body,
        tags:  ['pill'],
    );
    $topicAccepted = $channels->dispatch($topicMsg, ['ntfy', 'webhook']);

    // Per-user email. EmailChannel rejects messages without a
    // recipient, so we build a fresh one per address.
    $emailAccepted = 0;
    foreach ($emailSubscribers as $address) {
        $perUser = new NotificationMessage(
            title:     'Medication Reminder',
            body:      $body,
            tags:      ['pill'],
            recipient: $address,
        );
        if ($channels->dispatch($perUser, ['email']) > 0) {
            $emailAccepted++;
        }
    }

    $total = $topicAccepted + $emailAccepted;
    if ($total === 0) {
        echo "Skipped (no channel ready): {$body}\n";
        return;
    }
    echo "Notification sent for patient {$patientName} ({$patientId}): {$body}"
       . " [topic:{$topicAccepted} email:{$emailAccepted}]\n";
}
