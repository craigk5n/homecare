<?php
/**
 * HC-061: schedule_daily.php using the shared page shell.
 *
 * Sticky header with patient name + Print. Today / Tomorrow each
 * render as a section with the Bootstrap section-color heading, then
 * a cards-on-mobile / table-on-desktop list of scheduled doses.
 *
 * Status stripe + status colour per dose:
 *   - taken     → status-done (struck-through name)
 *   - scheduled → status-ok (or status-due-soon if within an hour)
 */

require_once 'includes/init.php';

$patient_id = (int) (getIntValue('patient_id') ?? 0);
$date = date('Y-m-d');
$tomorrowDate = date('Y-m-d', strtotime('+1 day'));

$patient = getPatient($patient_id);
$patientName = $patient['name'];

// HC-120: exclude PRN schedules — they have no cadence to render on a
// daily timeline. PRN intakes are still visible in the history views.
$sql = "SELECT ms.id, m.name, ms.frequency, ms.start_date, ms.end_date,
        (SELECT GROUP_CONCAT(mi.taken_time ORDER BY mi.taken_time) FROM hc_medicine_intake mi
         WHERE mi.schedule_id = ms.id AND DATE(mi.taken_time) = ?) AS taken_times_today
        FROM hc_medicine_schedules ms
        JOIN hc_medicines m ON ms.medicine_id = m.id
        WHERE ms.patient_id = ?
          AND ms.is_prn = 'N'
          AND ms.frequency IS NOT NULL
          AND (ms.start_date <= ? AND (ms.end_date IS NULL OR ms.end_date >= ?))
        ORDER BY ms.start_date ASC";
$schedules = dbi_get_cached_rows($sql, [$date, $patient_id, $date, $date]);

$scheduleToday = [];     // unixTime => list<['time','name','status']>
$scheduleTomorrow = [];

foreach ($schedules as $row) {
    $scheduleId = (int) $row[0];
    $medicationName = (string) $row[1];
    $frequency = (string) $row[2];
    $startDate = (string) $row[3];
    $endDate = $row[4] ? new DateTime((string) $row[4]) : null;

    try {
        $frequencySeconds = frequencyToSeconds($frequency);
    } catch (\InvalidArgumentException) {
        continue;
    }

    // Today's recorded intakes
    $takenTimesToday = !empty($row[5]) ? explode(',', (string) $row[5]) : [];
    foreach ($takenTimesToday as $takenTime) {
        $unixTime = strtotime($takenTime);
        if (date('Y-m-d', $unixTime) == $date) {
            $scheduleToday[$unixTime][] = [
                'time' => date('g:i A', $unixTime),
                'name' => $medicationName,
                'status' => 'taken',
            ];
        }
    }

    // Most recent intake (any day)
    $lastIntake = dbi_get_cached_rows(
        'SELECT taken_time FROM hc_medicine_intake WHERE schedule_id = ? ORDER BY taken_time DESC LIMIT 1',
        [$scheduleId]
    );

    $todayMidnight = new DateTime("$date 00:00:00");
    $tomorrowEnd = new DateTime("$tomorrowDate 23:59:59");
    $startDateTime = new DateTime($startDate);

    if (!empty($lastIntake)) {
        $lastTaken = new DateTime((string) $lastIntake[0][0]);
        $nextDose = clone $lastTaken;
        $nextDose->modify("+$frequencySeconds seconds");
        while ($nextDose < $todayMidnight) {
            $nextDose->modify("+$frequencySeconds seconds");
        }
    } else {
        $nextDose = clone $startDateTime;
        $nextDose->setDate(
            (int) $todayMidnight->format('Y'),
            (int) $todayMidnight->format('m'),
            (int) $todayMidnight->format('d')
        );
        if ($frequency == '1d') {
            $nextDose->setTime((int) $startDateTime->format('H'), (int) $startDateTime->format('i'));
            if ($nextDose < $todayMidnight) {
                $nextDose->modify('+1 day');
            }
        } else {
            $secondsSinceMidnight = ((int) $startDateTime->format('H') * 3600)
                + ((int) $startDateTime->format('i') * 60);
            $interval = $secondsSinceMidnight % $frequencySeconds;
            if ($interval > 0) {
                $nextDose->modify('+' . ($frequencySeconds - $interval) . ' seconds');
            }
        }
    }

    $currentDose = clone $nextDose;
    while ($currentDose <= $tomorrowEnd) {
        if ($endDate && $currentDose > $endDate) {
            break;
        }
        $unixTime = $currentDose->getTimestamp();
        $displayDate = $currentDose->format('Y-m-d');
        $displayTime = $currentDose->format('g:i A');

        $isTaken = false;
        if ($displayDate == $date) {
            foreach ($takenTimesToday as $takenTime) {
                if (abs($unixTime - strtotime($takenTime)) < 300) {
                    $isTaken = true;
                    break;
                }
            }
        }

        if ($displayDate == $date && !$isTaken) {
            $scheduleToday[$unixTime][] = [
                'time' => $displayTime,
                'name' => $medicationName,
                'status' => 'scheduled',
            ];
        } elseif ($displayDate == $tomorrowDate) {
            $scheduleTomorrow[$unixTime][] = [
                'time' => $displayTime,
                'name' => $medicationName,
                'status' => 'scheduled',
            ];
        }
        $currentDose->modify("+$frequencySeconds seconds");
    }
}

ksort($scheduleToday);
ksort($scheduleTomorrow);

print_header();

// ── Sticky header ──
echo '<div class="page-sticky-header noprint">';
echo '  <div class="container-fluid d-flex justify-content-between align-items-center">';
echo '    <h5 class="page-title mb-0">Daily schedule: ' . htmlentities($patientName) . '</h5>';
echo '    <div class="page-actions">';
echo '      <a href="list_schedule.php?patient_id=' . $patient_id
    . '" class="btn btn-sm btn-outline-secondary">Full schedule</a>';
echo '      <a href="schedule_print.php?patient_id=' . $patient_id
    . '" class="btn btn-sm btn-outline-secondary" title="PDF medication sheet">PDF Sheet</a>';
echo '      <button class="btn btn-sm btn-outline-secondary" data-print>Print</button>';
echo '    </div>';
echo '  </div>';
echo '</div>';

/**
 * Render one section (Today or Tomorrow).
 *
 * @param array<int,list<array{time:string,name:string,status:string}>> $entries
 */
function render_daily_section(string $title, string $sectionClass, array $entries, int $now): void
{
    echo '<h6 class="schedule-section-header ' . $sectionClass . '">' . htmlspecialchars($title) . '</h6>';

    if ($entries === []) {
        echo '<p class="text-muted ms-2">Nothing scheduled.</p>';
        return;
    }

    // Desktop table
    echo '<div class="d-none d-md-block">';
    echo '  <div class="table-responsive">';
    echo '    <table class="table table-hover page-table">';
    echo '      <thead class="thead-light"><tr><th style="width:9rem;">Time</th><th>Medication</th></tr></thead>';
    echo '      <tbody>';
    foreach ($entries as $unixTime => $items) {
        foreach ($items as $item) {
            $statusClass = daily_status_class($item['status'], $unixTime, $now);
            echo '<tr class="' . $statusClass . '">';
            echo '<td>' . htmlspecialchars($item['time']) . '</td>';
            $name = htmlspecialchars($item['name']);
            if ($item['status'] === 'taken') {
                $name = '<s class="text-muted">' . $name . '</s> <span class="badge badge-secondary">taken</span>';
            }
            echo '<td>' . $name . '</td>';
            echo '</tr>';
        }
    }
    echo '      </tbody>';
    echo '    </table>';
    echo '  </div>';
    echo '</div>';

    // Mobile cards
    echo '<div class="d-md-none">';
    foreach ($entries as $unixTime => $items) {
        foreach ($items as $item) {
            $statusClass = daily_status_class($item['status'], $unixTime, $now);
            echo '<div class="page-card ' . $statusClass . '">';
            echo '  <div class="card-title-row">';
            $name = htmlspecialchars($item['name']);
            if ($item['status'] === 'taken') {
                $name = '<s class="text-muted">' . $name . '</s>';
            }
            echo '    <span class="card-primary">' . $name . '</span>';
            echo '    <span class="card-meta">' . htmlspecialchars($item['time']) . '</span>';
            echo '  </div>';
            if ($item['status'] === 'taken') {
                echo '  <div class="card-meta"><span class="badge badge-secondary">taken</span></div>';
            }
            echo '</div>';
        }
    }
    echo '</div>';
}

function daily_status_class(string $status, int $unixTime, int $now): string
{
    if ($status === 'taken') {
        return 'status-done';
    }
    // Scheduled within the next hour gets the "due-soon" stripe.
    if ($unixTime - $now > 0 && $unixTime - $now < 3600) {
        return 'status-due-soon';
    }
    if ($unixTime < $now) {
        return 'status-overdue';
    }
    return 'status-ok';
}

$now = time();
echo '<div class="container-fluid mt-3">';
render_daily_section('Today', 'section-due-soon', $scheduleToday, $now);
render_daily_section('Tomorrow', 'section-ok', $scheduleTomorrow, $now);
echo '</div>';

?>
<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
document.addEventListener('click', function(e) {
  if (e.target.closest('[data-print]')) window.print();
});
</script>
<?php
echo print_trailer();
