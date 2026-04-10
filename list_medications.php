<?php
require_once 'includes/init.php';

print_header();

$sql = "SELECT id, name, dosage, unit_per_dose, frequency, created_at, updated_at FROM hc_medicines ORDER BY name ASC";
$result = dbi_query($sql);

if (!$result) {
    echo "<p class='alert alert-danger'>Error retrieving medications: " . dbi_error() . "</p>";
    exit;
}

echo "<h2>Current Medications</h2>\n";

echo "<div class='table-responsive'>\n";
echo "<table class='table table-bordered table-striped medications-table'>\n"; 
echo "<thead class='thead-dark'>";
echo "<tr><th class='name-col'>Name</th><th class='dosage-col'>Dosage</th><th class='frequency-col'>Frequency</th><th class='unit-per-dose-col'>Unit Per Dose</th></tr>";
echo "</thead>\n";
echo "<tbody>\n";

while ($row = dbi_fetch_row($result)) {
    echo "<tr>";
    echo "<td class='name-col'>" . htmlspecialchars($row[1]) . "</td>";
    echo "<td class='dosage-col'>" . htmlspecialchars($row[2]) . "</td>";
    echo "<td class='frequency-col'>" . htmlspecialchars($row[4]) . "</td>";
    echo "<td class='unit-per-dose-col'>" . htmlspecialchars($row[3]) . "</td>";
    echo "</tr>\n";
}

echo "</tbody>";
echo "</table>\n";
echo "</div>\n";

// Link to add a new medication styled as a Bootstrap button
echo "<p><a href='edit_medication.php' class='btn btn-primary'>Add New Medication</a></p>\n";

echo print_trailer();
?>


