<?php
/*
 * PHP functions that are specific to the HomeCare database or its use.
 * General purpose functions that were borrowed/copied from other projects
 * are in functions.php, formvars.php, etc. The functions in this file would
 * not be useful to other projects.
 */

// $lastTaken is the DateTime object of the last time the medication was taken
// $frequency is how often to take it (1d, 8h, 12h, etc.)
function calculateSecondsUntilDue($lastTaken, $frequency, $showNegative = false)
{
    // Parse the frequency to extract the number and unit
    $amount = intval($frequency);  // Extracts the integer value from the start of the frequency
    $unit = substr($frequency, -1);  // Gets the last character to determine the unit (d, h, or m)

    // Calculate the next due time from the last taken time
    $lastTakenTimestamp = strtotime($lastTaken);

    // Calculate the additional seconds based on the frequency
    switch ($unit) {
        case 'd':  // Days
            $secondsToAdd = $amount * 86400;  // 86400 seconds in a day
            break;
        case 'h':  // Hours
            $secondsToAdd = $amount * 3600;  // 3600 seconds in an hour
            break;
        case 'm':  // Minutes
            $secondsToAdd = $amount * 60;  // 60 seconds in a minute
            break;
        default:
            throw new Exception("Invalid frequency unit $unit");
    }

    // Compute the next due timestamp
    $nextDueTimestamp = $lastTakenTimestamp + $secondsToAdd;

    // Get the current timestamp
    $currentTimestamp = time();

    // Calculate seconds until due
    if ($nextDueTimestamp > $currentTimestamp || $showNegative) {
        // Future due
        return $nextDueTimestamp - $currentTimestamp;
    } else {
        // Past due or due now
        return 0;
    }
}

function getDueDateTimeInSeconds($patient_id, $schedule_id, $medicine_id, $show_negative = false)
{
    // Fetch patient's medication schedule
    $sql = "SELECT ms.id, m.name, ms.frequency, ms.start_date, ms.end_date, 
            (SELECT MAX(taken_time) FROM hc_medicine_intake WHERE schedule_id = ms.id) AS last_taken
            FROM hc_medicine_schedules ms
            JOIN hc_medicines m ON ms.medicine_id = m.id
            WHERE ms.patient_id = ?
            AND ms.id = ?
            AND ms.medicine_id = ?";

    $rows = dbi_get_cached_rows($sql, [$patient_id, $schedule_id, $medicine_id]);
    if (!empty($rows) && !empty($rows[0])) {
        $freq = $rows[0][2];
        $lastTaken = $rows[0][5];
        if (empty($lastTaken)) {
            return null;
        }
        return calculateSecondsUntilDue($lastTaken, $freq, $show_negative);
    } else {
        return null;
    }
}

function getPatient($patientId)
{
    $sql = 'SELECT id, name, created_at, updated_at, is_active FROM hc_patients WHERE id = ?';
    $rows = dbi_get_cached_rows($sql, [$patientId]);
    if ($rows) {
        $patient = [
            'id' => $rows[0][0],
            'name' => $rows[0][1],
            'created_at' => $rows[0][2],
            'updated_at' => $rows[0][3],
            'is_active' => $rows[0][4],
        ];
        return $patient;
    } else {
        die_miserable_death("No such patient id " . htmlspecialchars($patientId));
    }
}

function getPatients($includeDisabled = false)
{
    $sql = $includeDisabled
        ? 'SELECT name, id FROM hc_patients ORDER BY name ASC'
        : 'SELECT name, id FROM hc_patients WHERE is_active = 1 ORDER BY name ASC';
    $rows = dbi_get_cached_rows($sql);
    $ret = [];
    foreach ($rows as $row) {
        $patient = [
            'name' => $row[0],
            'id' => $row[1]
        ];
        $ret[] = $patient;
    }
    return $ret;
}

// Calculate the next due date and time and return in iso8601 format.
function calculateNextDueDate($lastTaken, $frequency) {
    $date = new DateTime($lastTaken);
    $intervalSpec = getIntervalSpecFromFrequency($frequency);
    if ($intervalSpec) {
        $interval = new DateInterval($intervalSpec);
        $date->add($interval);
        return $date->format('Y-m-d H:i');
    }
    return "Frequency error";
}

function getIntervalSpecFromFrequency($frequency) {
    $unit = substr($frequency, -1);
    $number = intval($frequency);
    
    if ($unit === 'h') {
        return 'PT' . $number . 'H';
    } elseif ($unit === 'd') {
        return 'P' . $number . 'D';
    }
    return null;
}

function frequencyToSeconds($frequency) {
    // Extract the number from the frequency string
    $amount = intval($frequency);

    // Extract the last character to determine the unit (d, h, or m)
    $unit = substr($frequency, -1);

    // Calculate the number of seconds based on the unit
    switch ($unit) {
        case 'd': // Days
            return $amount * 86400; // 86400 seconds in a day
        case 'h': // Hours
            return $amount * 3600;  // 3600 seconds in an hour
        case 'm': // Minutes
            return $amount * 60;    // 60 seconds in a minute
        default:
            throw new Exception("Invalid frequency unit provided: " . $unit);
    }
}

// Return an array of what remains of a medication
// [ 'remainingDays' => 100, 'remainingDoses' => 50, 'lastInventory' => 70, 'quantityTakenSince' => 30, 'unitPerDose' => 0, 'medicineName' => '' ]
function dosesRemaining($medicine_id, $schedule_id, $assumePastIntake = false, $start_date = null, $frequency = null) {
    $ret = ['remainingDays' => 0, 'remainingDoses' => 0, 'lastInventory' => null, 'quantityTakenSince' => 0, 'unitPerDose' => 0, 'medicineName' => ''];

    // Get the medicine details including unit per dose
    $medicineDetailsSql = "SELECT name, unit_per_dose FROM hc_medicines WHERE id = ?";
    $medicineDetailsRows = dbi_get_cached_rows($medicineDetailsSql, [$medicine_id]);
    if ($medicineDetailsRows && !empty($medicineDetailsRows[0])) {
        $ret['medicineName'] = $medicineDetailsRows[0][0];
        $ret['unitPerDose'] = $medicineDetailsRows[0][1];
    }

    // Check for schedule-level unit_per_dose override
    $scheduleUpdSql = "SELECT unit_per_dose FROM hc_medicine_schedules WHERE id = ?";
    $scheduleUpdRows = dbi_get_cached_rows($scheduleUpdSql, [$schedule_id]);
    if (!empty($scheduleUpdRows) && !empty($scheduleUpdRows[0][0])) {
        $ret['unitPerDose'] = $scheduleUpdRows[0][0];
    }

    // Fetch the most recent inventory for this medicine
    $inventorySql = "SELECT current_stock, recorded_at FROM hc_medicine_inventory 
                     WHERE medicine_id = ? ORDER BY recorded_at DESC LIMIT 1";
    $inventoryRows = dbi_get_cached_rows($inventorySql, [$medicine_id]);

    if ($inventoryRows && !empty($inventoryRows[0])) {
        $lastInventoryQuantity = $inventoryRows[0][0];
        $lastInventoryDate = $inventoryRows[0][1];
        $ret['lastInventory'] = $lastInventoryQuantity;

        // Fetch the total quantity of medicine consumed since the last inventory, accounting for unit per dose
        // Use schedule-level unit_per_dose when set, otherwise fall back to medicine-level
        $intakeSql = "SELECT SUM(COALESCE(s.unit_per_dose, m.unit_per_dose)) AS total_consumed
                      FROM hc_medicine_intake i
                      JOIN hc_medicine_schedules s ON s.id = i.schedule_id
                      JOIN hc_medicines m ON m.id = s.medicine_id
                      WHERE s.medicine_id = ? AND i.taken_time > ?";
        $intakeParams = [$medicine_id, $lastInventoryDate];
        $intakeRows = dbi_get_cached_rows($intakeSql, $intakeParams);

        $totalConsumed = 0;
        if (!empty($intakeRows) && !empty($intakeRows[0])) {
            $totalConsumed = $intakeRows[0][0] ?? 0;
            $ret['quantityTakenSince'] = $totalConsumed;
        }

        // Calculate assumed doses if enabled
        $assumedConsumed = 0;
        if ($assumePastIntake && $start_date && $frequency) {
            $startDate = new DateTime($start_date);
            $yesterday = (new DateTime())->modify('-1 day');
            // Use end_date or yesterday, whichever is earlier
            $endDate = $yesterday;
            $sql = "SELECT end_date FROM hc_medicine_schedules WHERE id = ?";
            $endDateRows = dbi_get_cached_rows($sql, [$schedule_id]);
            if (!empty($endDateRows) && !empty($endDateRows[0][0])) {
                $scheduleEndDate = new DateTime($endDateRows[0][0]);
                if ($scheduleEndDate < $yesterday) {
                    $endDate = $scheduleEndDate;
                }
            }

            if ($startDate <= $endDate) {
                $days = $startDate->diff($endDate)->days + 1; // Include start and end days
                $secondsPerDose = frequencyToSeconds($frequency);
                $dosesPerDay = 86400 / $secondsPerDose; // Doses per day
                $assumedDoses = $dosesPerDay * $days;
                $assumedConsumed = $assumedDoses * $ret['unitPerDose'];
            }
        }

        // Calculate the remaining amount and doses considering the unit per dose
        $remainingAmount = $lastInventoryQuantity - ($totalConsumed + $assumedConsumed);

        // Calculate remaining doses based on unit per dose
        $remainingDoses = $remainingAmount / $ret['unitPerDose'];

        // Get frequency information from the schedule if not provided
        if (!$frequency) {
            $frequencySql = "SELECT frequency FROM hc_medicine_schedules WHERE id = ?";
            $frequencyRow = dbi_get_cached_rows($frequencySql, [$schedule_id]);
            $frequency = !empty($frequencyRow) ? $frequencyRow[0][0] : '1d'; // Default to 1 day
        }

        // Calculate remaining days based on doses per day
        $secondsPerDose = frequencyToSeconds($frequency);
        $dosesPerDay = 86400 / $secondsPerDose; // Calculate how many doses are supposed to be taken in a day
        $remainingDays = $remainingDoses / $dosesPerDay;

        $ret['remainingDoses'] = max(0, $remainingDoses); // Ensure it doesn't go below zero
        $ret['remainingDays'] = max(0, floor($remainingDays)); // Ensure it doesn't go below zero and round down to complete days
    }

    return $ret;
}
?>