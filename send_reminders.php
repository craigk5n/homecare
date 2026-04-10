<?php
require_once 'includes/init.php';

// TODO: store this in hc_config or another table for notifications
$NOTIFY_URL = 'http://192.168.0.100:8802/';
//$NOTIFY_URL = 'https://ntfy.sh/';
$NOTIFY_CHANNEL = 'craig';
//$NOTIFY_CHANNEL = 'FyqRAPozGb3KrTVm';


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

function sendNotification($patient_id, $patient_name, $message) {
    global $NOTIFY_URL, $NOTIFY_CHANNEL;
    // TODO: Look into X-Delay, click/actions
    $postData = json_encode([
        'topic' => $NOTIFY_CHANNEL,
        'title' => 'Medication Reminder',
        'message' => $message,
        'tags' => ['pill']
    ]);

    $ch = curl_init($NOTIFY_URL);
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
