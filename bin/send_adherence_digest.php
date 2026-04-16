<?php
/**
 * HC-107: Weekly adherence-digest CLI.
 *
 * Intended entry for a Monday-morning cron:
 *   0 8 * * 1  php /path/to/homecare/bin/send_adherence_digest.php
 *
 * Computes per-patient / per-medication adherence over the last 7
 * and 30 days (windows ending yesterday so a partial current day
 * doesn't skew the numbers), renders one body via
 * {@see AdherenceDigestBuilder}, and emails every opted-in user.
 *
 * Flags:
 *   --dry-run    Print per-recipient preview, do not hand off to
 *                the EmailChannel.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/init.php';

use HomeCare\Config\EmailConfig;
use HomeCare\Database\DbiAdapter;
use HomeCare\Notification\EmailChannel;
use HomeCare\Notification\NotificationMessage;
use HomeCare\Repository\IntakeRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Repository\UserRepository;
use HomeCare\Service\AdherenceDigestBuilder;
use HomeCare\Service\AdherenceDigestPatient;
use HomeCare\Service\AdherenceDigestRow;
use HomeCare\Service\AdherenceService;

$dryRun = in_array('--dry-run', $argv, true);

$db = new DbiAdapter();
$users = new UserRepository($db);
$schedules = new ScheduleRepository($db);
$intakes = new IntakeRepository($db);
$adherence = new AdherenceService($schedules, $intakes);
$builder = new AdherenceDigestBuilder();
$emailChannel = new EmailChannel(new EmailConfig($db));

$subscribers = $users->getDigestSubscribers();
if ($subscribers === []) {
    fwrite(STDERR, "No opted-in digest subscribers.\n");
    exit(0);
}

// Window ends "yesterday" so the digest covers complete days only.
$today = new DateTimeImmutable('today');
$endDay = $today->modify('-1 day')->format('Y-m-d');
$sevenStart = $today->modify('-7 days')->format('Y-m-d');
$thirtyStart = $today->modify('-30 days')->format('Y-m-d');
$runLabel = $today->format('Y-m-d');

/**
 * Walk every active patient and build the digest section for
 * each. "Active" follows the existing convention: `is_active = 1`
 * on the patient row. Inactive patients don't render at all.
 *
 * @return list<AdherenceDigestPatient>
 */
$buildSections = static function () use ($adherence, $schedules, $sevenStart, $thirtyStart, $endDay): array {
    $sections = [];
    foreach (getPatients() as $p) {
        $patientId = (int) $p['id'];
        $patientName = (string) $p['name'];

        $scheduleRows = $schedules->getActiveSchedules($patientId, $endDay);
        $rows = [];
        foreach ($scheduleRows as $s) {
            $medicineName = (string) dbi_get_cached_rows(
                'SELECT name FROM hc_medicines WHERE id = ?',
                [$s['medicine_id']]
            )[0][0] ?? '';
            if ($medicineName === '') {
                continue;
            }

            $sevenDay = $adherence->calculateAdherence((int) $s['id'], $sevenStart, $endDay);
            $thirtyDay = $adherence->calculateAdherence((int) $s['id'], $thirtyStart, $endDay);

            $rows[] = new AdherenceDigestRow(
                medicineName: $medicineName,
                sevenDayPct:  (float) $sevenDay['percentage'],
                thirtyDayPct: (float) $thirtyDay['percentage'],
            );
        }

        $sections[] = new AdherenceDigestPatient($patientName, $rows);
    }

    return $sections;
};

$sections = $buildSections();
$body = $builder->build($runLabel, $sections);

$subject = "[HomeCare] Weekly adherence digest — {$runLabel}";
$sentCount = 0;
foreach ($subscribers as $sub) {
    if ($dryRun) {
        echo "Dry run: Would send digest to {$sub['login']} <{$sub['email']}>:\n";
        echo $body . "\n";
        continue;
    }
    $ok = $emailChannel->send(new NotificationMessage(
        title: $subject,
        body: $body,
        priority: NotificationMessage::PRIORITY_DEFAULT,
        tags: ['digest'],
        recipient: $sub['email'],
    ));
    if ($ok) {
        $sentCount++;
        echo "Digest sent to {$sub['login']} <{$sub['email']}>.\n";
    } else {
        fwrite(STDERR, "Digest FAILED to {$sub['login']} <{$sub['email']}>.\n");
    }
}

if (!$dryRun) {
    echo "Digest run complete: {$sentCount}/" . count($subscribers) . " delivered.\n";
}
