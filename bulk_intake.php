<?php
require_once 'includes/init.php';

$patient_id = getIntValue('patient_id');
$errorMessage = '';
$previousInput = '';
if (empty($patient_id)) {
  die_miserable_death('Missing patient_id');
}
$patient = getPatient($patient_id);

$meds = getMedicationsForPatient($patient_id);

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputText = getPostValue('medication_data');
    $previousInput = $inputText;
    $lines = explode("\n", $inputText);
    $errors = [];
    $valid = [];
    $lastDate = null; // Track the last seen date

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // Parse the line (optional date, flexible time format)
        if (preg_match('/^(?:(\d{1,2}\/\d{1,2})\s+)?(\d{1,2}(?::?\d{2})?\s*(?:AM|PM|am|pm))\s+(.+)/i', $line, $matches)) {
            $date = $matches[1] ?? null;
            $time = $matches[2];
            $medicines = $matches[3];

            // Normalize time format
            $time = str_replace(' ', '', $time); // Remove spaces
            $timePeriod = substr($time, -2); // AM or PM
            $timeDigits = substr($time, 0, -2); // Numeric part

            // Handle different time formats
            if (preg_match('/^(\d{1,2}):?(\d{2})$/', $timeDigits, $timeMatch)) {
                // Time with or without colon (e.g., 7:20, 720)
                $hours = $timeMatch[1];
                $minutes = $timeMatch[2];
                if ($hours > 12 || $minutes > 59) {
                    $errors[] = "Invalid time format in line: $line (hours must be 1-12, minutes 0-59)";
                    continue;
                }
                $time = "$hours:$minutes$timePeriod";
            } elseif (preg_match('/^(\d{1,2})$/', $timeDigits)) {
                // Single or two-digit hour (e.g., 7, 11)
                $hours = $timeDigits;
                if ($hours > 12) {
                    $errors[] = "Invalid time format in line: $line (hours must be 1-12)";
                    continue;
                }
                $time = "$hours:00$timePeriod";
            } else {
                $errors[] = "Invalid time format in line: $line (e.g., use '7:20AM', '720AM', or '7AM')";
                continue;
            }

            // Use last date if no date provided, otherwise update last date
            if ($date) {
                $lastDate = $date;
            } elseif (!$lastDate) {
                $errors[] = "No date provided and no previous date available in line: $line";
                continue;
            }

            // Validate date and time
            $dateTime = DateTime::createFromFormat('m/d g:iA', $lastDate . ' ' . $time);
            if (!$dateTime) {
                $errors[] = "Invalid date or time format in line: $line (e.g., use '7:20AM', '720AM', or '7AM')";
                continue;
            }

            // Parse medicines (split by comma or &)
            $medicineList = preg_split('/\s*[,&]\s*/', $medicines, -1, PREG_SPLIT_NO_EMPTY);
            if (empty($medicineList)) {
                $errors[] = "No medicines specified in line: $line";
                continue;
            }

            // Validate medicines
            foreach ($medicineList as $medicineName) {
                $medicineName = trim($medicineName);
                if (empty($medicineName)) continue;
                $medRet = validateMedicine($medicineName, $meds, $patient_id);
                if (!empty($medRet['error'])) {
                    $errors[] = $medRet['error'];
                } else {
                    $valid[] = [
                        'time' => $dateTime,
                        'medicine_id' => $medRet['medicine_id'],
                        'schedule_id' => $medRet['schedule_id']
                    ];
                }
            }
        } else {
            $errors[] = "Invalid format in line: $line (expected: '[MM/DD] HH:MM AM/PM medicine1, medicine2' or '[MM/DD] HHMM AM/PM medicine1, medicine2')";
        }
    }

    if (empty($errors) && count($valid) > 0) {
        $num = 0;
        $c->autocommit(false);
        foreach ($valid as $m) {
            $sql = 'INSERT INTO hc_medicine_intake (schedule_id, taken_time) VALUES (?, ?)';
            $params = [$m['schedule_id'], $m['time']->format(DateTime::ATOM)];
            if (!dbi_execute($sql, $params)) {
                $errorMessage = "Error recording intake: " . dbi_error();
                $c->rollback();
                break;
            } else {
                $num++;
            }
        }
        if (empty($errorMessage)) {
            $c->commit();
        }
        $c->autocommit(true);
        $message = "$num medications added.";
        $previousInput = '';
    } else if (empty($errors) && count($valid) == 0) {
        $errorMessage = 'No medications specified';
    } else {
        $errorMessage = implode('<br>', $errors);
    }
}

function validateMedicine($medicineName, $allMeds, $patient_id) {
    $matches = [];
    $ret = ['error' => ''];
    
    // Find all matching medications in schedule
    foreach ($allMeds as $m) {
        if (string_found($m['medicine_name'], $medicineName)) {
            $matches[] = [
                'medicine_id' => $m['medicine_id'],
                'schedule_id' => $m['schedule_id'],
                'created_at' => $m['created_at'],
                'medicine_name' => $m['medicine_name'],
                'start_date' => $m['start_date'],
                'end_date' => $m['end_date']
            ];
        }
    }

    if (empty($matches)) {
        $ret['error'] = 'Medicine not found in schedule: ' . htmlentities($medicineName);
        return $ret;
    }

    if (count($matches) === 1) {
        // Single match found, use it
        $ret['medicine_id'] = $matches[0]['medicine_id'];
        $ret['schedule_id'] = $matches[0]['schedule_id'];
        return $ret;
    }

    // Multiple matches found, provide detailed error
    $errorDetails = "Medicine matched " . count($matches) . " in schedule for: " . htmlentities($medicineName) . "<ul>";
    $valid_match = null;
    $latest_created = null;

    foreach ($matches as $match) {
        // Get inventory details
        $sql = 'SELECT current_stock FROM hc_medicine_inventory 
                WHERE medicine_id = ? AND current_stock > 0 
                ORDER BY recorded_at DESC LIMIT 1';
        $params = [$match['medicine_id']];
        $inventory = dbi_get_cached_rows($sql, $params);
        $stock = !empty($inventory) ? $inventory[0][0] : 0;

        // Build error message details
        $end_date_str = $match['end_date'] ? $match['end_date'] : 'No end date';
        $errorDetails .= "<li>Medication: " . htmlentities($match['medicine_name']) . 
                         ", Start: " . $match['start_date'] . 
                         ", End: " . $end_date_str . 
                         ", Stock: " . $stock . " units</li>";

        // Check if this is a valid match (has stock)
        if ($stock > 0) {
            $created_at = strtotime($match['created_at']);
            if ($latest_created === null || $created_at > $latest_created) {
                $latest_created = $created_at;
                $valid_match = $match;
            }
        }
    }
    $errorDetails .= "</ul>";

    if ($valid_match) {
        $ret['medicine_id'] = $valid_match['medicine_id'];
        $ret['schedule_id'] = $valid_match['schedule_id'];
    } else {
        $ret['error'] = $errorDetails . 'No available inventory for matching medications.';
    }

    return $ret;
}

function string_found(string $haystack, string $needle): bool
{
    $lowercase_haystack = strtolower($haystack);
    $lowercase_needle = strtolower($needle);
    return strpos($lowercase_haystack, $lowercase_needle) !== false;
}

function processMedicationLine($line, $patientId) {
    // Insert or update database with the validated line of medication intake
}

function getMedicationsForPatient($patient_id) {
    $sql = 'SELECT ms.id, ms.medicine_id, ms.start_date, ms.end_date, ms.frequency, ms.created_at, m.name 
            FROM hc_medicine_schedules ms 
            JOIN hc_medicines m ON m.id = ms.medicine_id 
            WHERE patient_id = ? 
            AND ms.start_date <= CURDATE() 
            AND (ms.end_date IS NULL OR ms.end_date >= CURDATE())';
    $params = [$patient_id];
    $rows = dbi_get_cached_rows($sql, $params);
    $ret = [];
    if (!empty($rows)) {
        foreach ($rows as $row) {
            $ret[] = [
                'schedule_id' => $row[0],
                'medicine_id' => $row[1],
                'start_date' => $row[2],
                'end_date' => $row[3],
                'frequency' => $row[4],
                'created_at' => $row[5],
                'medicine_name' => $row[6]
            ];
        }
    }
    return $ret;
}

print_header();
?>

<h2>Bulk Medicine Input: <?= htmlspecialchars($patient['name']) ?></h2>

<?php if (!empty($message)): ?>
<div class="alert alert-info alert-dismissible fade show" role="alert">
  <?php echo $message; ?>
  <button type="button" class="close" data-dismiss="alert" aria-label="Close">
    <span aria-hidden="true">×</span>
  </button>
</div>
<?php endif; ?>

<?php if (!empty($errorMessage)): ?>
    <div class="alert alert-danger"><?= $errorMessage ?></div>
<?php endif; ?>

<div>
    <p>
        <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#medicationList" aria-expanded="false" aria-controls="medicationList">
            Available Medications
        </button>
    </p>

    <div class="collapse" id="medicationList">
        <div class="card card-body">
            <ul>
<?php
  foreach ($meds as $m) {
    echo "<li>" . htmlentities($m['medicine_name']) . "</li>\n";
  }
?>
            </ul>
        </div>
    </div>
</div>

<form action="bulk_intake.php?patient_id=<?= htmlspecialchars($patient_id) ?>" method="POST">
<?php print_form_key(); ?>
    <div class="form-group">
        <label for="medication_data">Enter medication data (e.g., "2/19 7:20AM Theophylline, Proviable\n3:25PM Sild, Allertec\n1140PM Sild & Pepcid"):</label>
        <textarea name="medication_data" id="medication_data" class="form-control" rows="10"><?= htmlspecialchars($previousInput) ?></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Submit</button>
</form>

<?php
echo print_trailer();
?>