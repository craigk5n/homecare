<?php
require_once 'includes/init.php';

$schedule_id = getIntValue('schedule_id');
$patient_id = getIntValue('patient_id');

function formatDate($datetime) {
  date_default_timezone_set('America/New_York');
  $timestamp = strtotime($datetime);
  $today = strtotime(date('Y-m-d 00:00:00'));
  $tomorrow = strtotime('+1 day', $today);
  $yesterday = strtotime('-1 day', $today);
  $timePart = date('g:i A', $timestamp);

  if ($timestamp >= $today && $timestamp < $tomorrow) {
      return "Today";
  } elseif ($timestamp >= $tomorrow && $timestamp < strtotime('+2 days', $today)) {
      return "Tomorrow";
  } elseif ($timestamp >= $yesterday && $timestamp < $today) {
      return "Yesterday";
  } else {
      return date('F j', $timestamp);
  }
}

if(empty($schedule_id)) {
  die_miserable_death('Missing schedule_id parameter');
}
$id = getIntValue('id');
$taken_time = $note = '';
$editing = false;
if (!empty($id)) {
  // We are edting an entry
  $title = translate('Update Medicine Intake');
  $hidden_id = '<input type="hidden" name="id" value="' . $id . '">';
  $sql = 'SELECT taken_time, note FROM hc_medicine_intake WHERE id = ?';
  $rows = dbi_get_cached_rows($sql, [$id]);
  if (empty($rows) || empty($rows[0])) {
    die_miserable_death('No such intake id ' . htmlentities($id));
  }
  $taken_time = $rows[0][0];
  $note = $rows[0][1];
  //echo "SQL: $sql <br>id: $id <br>";
  //echo "<pre>"; print_r($rows); echo "</pre>";
  $editing = true;
} else {
  // Adding an entry
  $title = translate('Add Medicine Intake');
  $hidden_id = '';
}

// Fetch schedule and patient details
$scheduleSql = "SELECT ms.id, ms.medicine_id, m.name AS medicine_name "
  . "FROM hc_medicine_schedules ms JOIN hc_medicines m ON ms.medicine_id = m.id WHERE ms.id = ?";
$scheduleResult = dbi_execute($scheduleSql, [$schedule_id]);
$medicineName = '';
$medicine_id = -1;
// Medication details (non-editable)
if ($row = dbi_fetch_row($scheduleResult)) {
  $medicineName = $row[2];
  $medicine_id = $row[1];
}


$patientSql = "SELECT name FROM hc_patients WHERE id = ?";
$patientResult = dbi_execute($patientSql, [$patient_id]);
$patientName = dbi_fetch_row($patientResult)[0];

// Current time in seconds since the epoch
$currentTimestamp = time();
// Find the number of seconds to subtract to round down to the nearest 5 minutes
$secondsToNearestFive = $currentTimestamp % 300;  // 300 seconds = 5 minutes
// Subtract those seconds to get rounded down time
$roundedTimestamp = $currentTimestamp - $secondsToNearestFive;
// To round to the nearest rather than rounding down
// Check if you should add 300 seconds to round it up
if ($secondsToNearestFive > 150) { // More than half way to the next 5 minute mark
    $roundedTimestamp += 300;
}
// Format for datetime-local input (which needs a specific string format: "Y-m-d\TH:i")
if (empty($taken_time)) {
  $formattedDateTime = date('Y-m-d\TH:i', $roundedTimestamp);
} else {
  $formattedDateTime = $taken_time;
}

print_header();

?>
<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
function copyDueDateToInput(timeSupposedToTake, event) {
  const intakeTimeInput = document.getElementById('taken_time');
  intakeTimeInput.value = timeSupposedToTake;
}

function confirmDelete() {
    return confirm("Are you sure you want to delete this intake record? This action cannot be undone.");
}

</script>

<?php
echo "<h2>" . $title . ': ' . htmlentities($patientName) . "</h2>\n";
echo "<div class='container mt-3'>\n";
echo "<form action='record_intake_handler.php' method='POST'>\n";
print_form_key();
echo $hidden_id;
echo "<input type='hidden' name='schedule_id' value='" . htmlspecialchars($schedule_id) . "'>\n";
echo "<input type='hidden' name='patient_id' value='" . htmlspecialchars($patient_id) . "'>\n";

// Medication details (non-editable)
if (!empty($medicineName)) {
    echo "<div class='form-group'><label>Medication:</label><p class='form-control-static'>" . htmlspecialchars($medicineName) . "</p></div>\n";
}

// Record the datetime of intake
echo "<div class='form-group'>\n";
echo "<label for='taken_time'>Time of Intake:</label>\n";
echo "<input type='datetime-local' name='taken_time' id='taken_time' class='form-control' required value='" . $formattedDateTime . "'>\n";
echo "<br>";
$timeSupposedToTake = getDueDateTimeInSeconds($patient_id, $schedule_id, $medicine_id, $schedule_id, true);
if (!empty($timeSupposedToTake)) {
  $when = time() + $timeSupposedToTake;
  $dates = [];
  $timesByDate = [];
  $whenFormatted = date('Y-m-d\TH:i', $when);

  // Generate buttons for 30 minutes back and forward in 5-minute intervals
  for ($i = -30; $i <= 30; $i += 5) {
    $currentTime = date('Y-m-d H:i', $when + ($i * 60));
    $formattedDate = formatDateNicely($currentTime);
    
    // Extract just the date part
    $datePart = preg_replace('/ at .*/', '', $formattedDate);
    $timePart = date('g:i A', strtotime($currentTime));

    if (!isset($dates[$datePart])) {
      $dates[$datePart] = [];
    }
    $dates[$datePart][] = $timePart;
  }

  // Display dates and times
  foreach ($dates as $date => $times) {
    echo "<div class='date-time-group'>";
    echo "<strong>$date:</strong> ";
    foreach ($times as $time) {
      $timeFormatted = date('Y-m-d\TH:i', strtotime("$date $time")); // Format for JS use
      if ($timeFormatted === $whenFormatted) {
        $buttonStyle = 'btn-primary';
      } else {
        $buttonStyle = 'btn-secondary';
      }
      echo "<button type='button' class='btn $buttonStyle' onclick='copyDueDateToInput(\"$timeFormatted\",event)'>$time</button> ";
    }
    echo "</div>\n";
  }
}

// Put some buttons for the most recent %5 minute time.
$min = date('i');
$min = $min - ($min % 5);
$lessThan5 = date('Y-m-d H:') . sprintf("%02d", $min);
$nice = formatDateNicely($lessThan5);
echo "<button type='button' class='btn btn-secondary' onclick='copyDueDateToInput(\"$lessThan5\",event)'>" . $nice . "</button>\n";

echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='note'>Notes:</label>\n";
echo "<textarea name='note' id='note' class='form-control'>" . htmlentities($note) . "</textarea>\n";
echo "</div>\n";

echo "<button type='submit' class='btn btn-success'>$title</button>\n";
if ($editing && !empty($id)) {
  // Add a delete button with a confirmation dialog
  echo "<button type='submit' name='delete' value='yes' class='btn btn-danger' onclick='return confirmDelete();'>Delete Entry</button>\n";
}
echo "</form>\n";
echo "</div>\n";

?>
<?php
echo print_trailer();
?>

