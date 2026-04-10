<?php
require_once 'includes/init.php';

$patient_id = getGetValue('patient_id');
$date = date('Y-m-d');
$tomorrowDate = date('Y-m-d', strtotime('+1 day'));

$patient = getPatient($patient_id);
$patientName = $patient['name'];

print_header();
echo "<h2>Medication Schedule: " . htmlentities($patientName) . "</h2>\n";
echo "<div class='container'>\n";

$sql = "SELECT ms.id, m.name, ms.frequency, ms.start_date, ms.end_date,
        (SELECT GROUP_CONCAT(mi.taken_time ORDER BY mi.taken_time) FROM hc_medicine_intake mi WHERE mi.schedule_id = ms.id AND DATE(mi.taken_time) = ?) AS taken_times_today
        FROM hc_medicine_schedules ms
        JOIN hc_medicines m ON ms.medicine_id = m.id
        WHERE ms.patient_id = ? AND (ms.start_date <= ? AND (ms.end_date IS NULL OR ms.end_date >= ?))
        ORDER BY ms.start_date ASC";
$params = [$date, $patient_id, $date, $date];

$schedules = dbi_get_cached_rows($sql, $params);

$scheduleToday = [];
$scheduleTomorrow = [];

foreach ($schedules as $row) {
    $scheduleId = $row[0];
    $medicationName = $row[1];
    $frequency = $row[2];
    $startDate = $row[3];
    $endDate = $row[4] ? new DateTime($row[4]) : null;
    $frequencySeconds = frequencyToSeconds($frequency);

    // Process today's recorded intakes
    $takenTimesToday = !empty($row[5]) ? explode(',', $row[5]) : [];
    foreach ($takenTimesToday as $takenTime) {
        $unixTime = strtotime($takenTime);
        if (date('Y-m-d', $unixTime) == $date) {
            $displayTime = date('g:i A', $unixTime);
            $scheduleToday[$unixTime][] = ['time' => $displayTime, 'name' => $medicationName, 'status' => 'taken'];
        }
    }

    // Get the most recent intake
    $sql = "SELECT taken_time FROM hc_medicine_intake WHERE schedule_id = ? ORDER BY taken_time DESC LIMIT 1";
    $lastIntake = dbi_get_cached_rows($sql, [$scheduleId]);

    $todayMidnight = new DateTime("$date 00:00:00");
    $tomorrowEnd = new DateTime("$tomorrowDate 23:59:59");
    $startDateTime = new DateTime($startDate);

    if (!empty($lastIntake)) {
        // Medication has been taken before
        $lastTaken = new DateTime($lastIntake[0][0]);
        // Find the next dose after the last intake
        $nextDose = clone $lastTaken;
        $nextDose->modify("+$frequencySeconds seconds");

        // Adjust to the first dose today
        while ($nextDose < $todayMidnight) {
            $nextDose->modify("+$frequencySeconds seconds");
        }
    } else {
        // Medication never taken, align with start_date time
        $nextDose = clone $startDateTime;
        // Set to today, preserving the time of day from start_date
        $nextDose->setDate($todayMidnight->format('Y'), $todayMidnight->format('m'), $todayMidnight->format('d'));
        // For daily medications, use the start time directly
        if ($frequency == '1d') {
            $startHour = (int)$startDateTime->format('H');
            $startMinute = (int)$startDateTime->format('i');
            $nextDose->setTime($startHour, $startMinute);
            // Ensure the dose is today or later
            if ($nextDose < $todayMidnight) {
                $nextDose->modify('+1 day');
            }
        } else {
            // For other frequencies, align with midnight
            $startHour = (int)$startDateTime->format('H');
            $startMinute = (int)$startDateTime->format('i');
            $secondsSinceMidnight = ($startHour * 3600) + ($startMinute * 60);
            $interval = $secondsSinceMidnight % $frequencySeconds;
            if ($interval > 0) {
                $nextDose->modify("+" . ($frequencySeconds - $interval) . " seconds");
            }
        }
    }

    // Calculate scheduled doses for today and tomorrow
    $currentDose = clone $nextDose;
    while ($currentDose <= $tomorrowEnd) {
        // Skip if the dose is after the end_date
        if ($endDate && $currentDose > $endDate) {
            break;
        }
        $unixTime = $currentDose->getTimestamp();
        $displayDate = $currentDose->format('Y-m-d');
        $displayTime = $currentDose->format('g:i A');

        // Only add scheduled doses if not already taken
        $isTaken = false;
        if ($displayDate == $date) {
            foreach ($takenTimesToday as $takenTime) {
                $takenUnix = strtotime($takenTime);
                // Allow a 5-minute window to consider a dose taken
                if (abs($unixTime - $takenUnix) < 300) {
                    $isTaken = true;
                    break;
                }
            }
        }

        if ($displayDate == $date && !$isTaken) {
            $scheduleToday[$unixTime][] = ['time' => $displayTime, 'name' => $medicationName, 'status' => 'scheduled'];
        } elseif ($displayDate == $tomorrowDate) {
            $scheduleTomorrow[$unixTime][] = ['time' => $displayTime, 'name' => $medicationName, 'status' => 'scheduled'];
        }

        $currentDose->modify("+$frequencySeconds seconds");
    }
}

// Displaying the medication schedule
echo "<strong>Today:</strong><br>";
ksort($scheduleToday);
foreach ($scheduleToday as $entries) {
    foreach ($entries as $entry) {
        $style = $entry['status'] === 'taken' ? 'text-decoration: line-through;' : '';
        echo "<div style='$style'>{$entry['time']} {$entry['name']}</div>\n";
    }
}

echo "<br><strong>Tomorrow:</strong><br>";
ksort($scheduleTomorrow);
foreach ($scheduleTomorrow as $entries) {
    foreach ($entries as $entry) {
        echo "<div>{$entry['time']} {$entry['name']}</div>\n";
    }
}

echo "</div>\n";
echo print_trailer();
?>