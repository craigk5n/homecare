<?php
require_once 'includes/init.php';

print_header();

$medicine_id = getIntValue('medicine_id');
if (empty($medicine_id)) {
    die_miserable_death('Missing medicine_id');
}

// Get medicine name
$sql = "SELECT name, dosage FROM hc_medicines WHERE id = ?";
$rows = dbi_get_cached_rows($sql, [$medicine_id]);
if (empty($rows)) {
    die_miserable_death('Medicine not found');
}
$medicineName = $rows[0][0];
$dosage = $rows[0][1];

// Get current stock from most recent inventory
$sql = "SELECT current_stock, recorded_at FROM hc_medicine_inventory
        WHERE medicine_id = ? ORDER BY recorded_at DESC LIMIT 1";
$invRows = dbi_get_cached_rows($sql, [$medicine_id]);
$currentStock = 0;
if (!empty($invRows) && !empty($invRows[0])) {
    $currentStock = floatval($invRows[0][0]);
}

echo "<h2>Adjust Stock</h2>\n";
echo "<div class='container mt-3'>\n";

echo "<div class='card mb-4'>\n";
echo "<div class='card-header'><strong>" . htmlspecialchars($medicineName) . "</strong></div>\n";
echo "<div class='card-body'>\n";
echo "<p class='mb-1'><strong>Dosage:</strong> " . htmlspecialchars($dosage) . "</p>\n";
echo "<p class='mb-0'><strong>System stock:</strong> " . number_format($currentStock, 2) . "</p>\n";
echo "</div>\n";
echo "</div>\n";

echo "<form action='inventory_adjust_handler.php' method='POST'>\n";
print_form_key();
echo "<input type='hidden' name='medicine_id' value='" . intval($medicine_id) . "'>\n";

echo "<div class='form-group'>\n";
echo "<label for='actual_count'>Actual Count:</label>\n";
echo "<input type='number' step='0.25' min='0' id='actual_count' name='actual_count' class='form-control' required>\n";
echo "<small class='form-text text-muted'>What did you physically count?</small>\n";
echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='note'>Reason:</label>\n";
echo "<input type='text' id='note' name='note' class='form-control' value='Stock adjustment'>\n";
echo "</div>\n";

echo "<div class='mt-4'>\n";
echo "<a href='inventory_dashboard.php' class='btn btn-secondary mr-2'>Cancel</a>\n";
echo "<button type='submit' class='btn btn-primary'>Save Adjustment</button>\n";
echo "</div>\n";

echo "</form>\n";
echo "</div>\n";

echo print_trailer();
?>
