<?php
require_once 'includes/init.php';  // Assuming init.php sets up the environment, database connection, etc.

// Get patient ID from the URL
$patient_id = getIntValue('patient_id');
$patient = getPatient($patient_id);
$order = getGetValue('sort');
if (empty($order) || $order == 'f') {
    $orderParam = 'f';
    $order = 'DESC';
} else {
    $orderParam = 'r';
    $order = 'ASC';
}

// Pagination and date handling
$current_date = getValue('date');
$today = date('Y-m-d');
if (empty($current_date)) {
    $current_date = $today;
}
$start_of_month = date('Y-m-01', strtotime($current_date)); 

$start_date = date('Y-m-d', strtotime('-30 days', strtotime($current_date)));
$end_date = $current_date;

// Fetch intake records
$sql = "SELECT mi.taken_time, m.name as medicine_name, mi.note, mi.created_at, ms.id as schedule_id, mi.id as intake_id 
        FROM hc_medicine_intake mi 
        JOIN hc_medicine_schedules ms ON mi.schedule_id = ms.id 
        JOIN hc_medicines m ON ms.medicine_id = m.id
        WHERE ms.patient_id = ? AND DATE_FORMAT(mi.taken_time, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')
        ORDER BY mi.taken_time $order";
$params = [$patient_id, $start_of_month];
//echo "SQL: $sql <br><pre>"; print_r($params); echo "</pre>";
$res = dbi_execute($sql, $params);

// Print header and table
print_header();
echo "<h2>Medicine Intake: " . htmlentities($patient['name']) . "</h2>\n";
echo "<div class='table-responsive'>\n";
echo "<table class='table table-bordered table-striped report-intake-table'>\n"; 
echo "<thead><tr><th>Date &amp; Time ";
$baseUrl = 'report_intake.php?patient_id=' . $patient_id . '&date=' . $current_date;
if ($orderParam == "f" ) {
  echo  '<a href="' . $baseUrl . '&sort=r"><span class="tooltip-icon" data-toggle="tooltip" title="' . htmlspecialchars("Currently sorted most recent.  Click to reverse.") .
    '"><img src="images/bootstrap-icons/sort-up.svg" alt="Sort Forward"></span></a>';
} else {
    echo  '<a href="' . $baseUrl . '&sort=f"><span class="tooltip-icon" data-toggle="tooltip" title="' . htmlspecialchars("Currently sorted oldest first.  Click to reverse.") .
    '"><img src="images/bootstrap-icons/sort-down.svg" alt="Sort Reverse"></span></a>';
}
echo "</th><th width=\"80%\">Medicine Name</th></tr></thead>";
echo "<tbody>";

$output = '';
$lastTime = '';
$medicationsAtSameTime = [];

if ($res) {
    while ($row = dbi_fetch_row($res)) {
        $intake = [
            'name' => htmlspecialchars($row[1]),
            'id' => $row[5],
            'schedule_id' => $row[4],
            'time' => formatDateNicely(date('Y-m-d g:i A', strtotime($row[0]))),
            'note' => htmlspecialchars($row[2]),
        ];
        $thisMedicine = htmlspecialchars($row[1]);
        $thisId = $row[5];
        $thisTime = formatDateNicely(date('Y-m-d g:i A', strtotime($row[0])));
        $schedule_id = $row[4];
        $editLink = "record_intake.php?patient_id=" . $patient_id . "&schedule_id=" . $schedule_id . "&id=" . $thisId;

        if ($thisTime == $lastTime) {
            $medicationsAtSameTime[] = [
                'name' => $thisMedicine,
                'link' => $editLink,
                'note' => $intake['note'],
            ];
        } else {
            if (!empty($medicationsAtSameTime)) {
                $output .= "<tr>";
                $output .= '<td>' . $lastTime . "</td><td>";
                foreach ($medicationsAtSameTime as $medication) {
                    $output .= "<a href='" . $medication['link'] . "'><img src='images/bootstrap-icons/pencil.svg' alt='Edit' class='edit-icon'></a> " . $medication['name'];
                    if (!empty($medication['note'])) {
                        $output .= ' <span class="tooltip-icon" data-toggle="tooltip" title="' . htmlspecialchars($medication['note']) .
                            '"><img src="images/bootstrap-icons/sticky.svg" alt="Note"></span>';
                    }
                    $output .= "<br>";
                }
                $output .= "</td></tr>\n";
                $medicationsAtSameTime = [];
            }
            $medicationsAtSameTime[] = [
                'name' => $thisMedicine,
                'link' => $editLink,
                'note' => $intake['note'],
            ];
            $lastTime = $thisTime;
        }
    }
    if (!empty($medicationsAtSameTime)) {
        $output .= "<tr>";
        $output .= '<td>' . $lastTime . "</td><td>";
        foreach ($medicationsAtSameTime as $medication) {
            $output .= "<a href='" . $medication['link'] . "'><img src='images/bootstrap-icons/pencil.svg' alt='Edit' class='edit-icon'></a> " . $medication['name'];
            if (!empty($medication['note'])) {
                $output .= ' <span class="tooltip-icon" data-toggle="tooltip" title="' . htmlspecialchars($medication['note']) .
                    '"><img src="images/bootstrap-icons/sticky.svg" alt="Note"></span>';
            }
            $output .= "<br>";
        }
        $output .= "</td></tr>\n";
    }
} else {
    $output .= "<tr><td colspan='4'>No records found.</td></tr>\n";
}

echo $output;
echo "</tbody></table>\n\n";

// Pagination links
$previous_month = date('Y-m-d', strtotime('-1 month', strtotime($start_of_month)));
$next_month = date('Y-m-d', strtotime('+1 month', strtotime($start_of_month)));

echo "<a href='report_intake.php?patient_id=$patient_id&date=$previous_month'>Previous Month</a> | ";
echo "<a href='report_intake.php?patient_id=$patient_id&date=$next_month'>Next Month</a>\n";

?>
<script>
$(document).ready(function(){
    $('[data-toggle="tooltip"]').tooltip(); // Initialize Bootstrap tooltips
});
</script>
<?php
echo print_trailer();
?>
