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

$deleted = getGetValue('deleted');

echo "<h2>Inventory History</h2>\n";
echo "<div class='container mt-3'>\n";

if (!empty($deleted)) {
    echo "<div class='alert alert-success'>Entry deleted.</div>\n";
}

echo "<h5>" . htmlspecialchars($medicineName) . " <small class='text-muted'>" . htmlspecialchars($dosage) . "</small></h5>\n";

// Fetch all inventory rows
$sql = "SELECT id, quantity, current_stock, note, recorded_at
        FROM hc_medicine_inventory WHERE medicine_id = ?
        ORDER BY recorded_at DESC";
$invRows = dbi_get_cached_rows($sql, [$medicine_id]);

if (empty($invRows)) {
    echo "<div class='alert alert-info'>No inventory records found for this medicine.</div>\n";
} else {
    echo "<div class='table-responsive'>\n";
    echo "<table class='table table-bordered table-hover'>\n";
    echo "<thead class='thead-light'><tr>";
    echo "<th>Date</th>";
    echo "<th class='text-right'>Refill Qty</th>";
    echo "<th class='text-right'>Stock Snapshot</th>";
    echo "<th>Note</th>";
    echo "<th>Actions</th>";
    echo "</tr></thead>\n";
    echo "<tbody>\n";

    foreach ($invRows as $inv) {
        $invId = intval($inv[0]);
        $qty = number_format(floatval($inv[1]), 2);
        $stock = number_format(floatval($inv[2]), 2);
        $note = $inv[3] ? htmlspecialchars($inv[3]) : '<em class="text-muted">none</em>';
        $date = date('M j, Y g:i A', strtotime($inv[4]));

        echo "<tr>";
        echo "<td>$date</td>";
        echo "<td class='text-right'>$qty</td>";
        echo "<td class='text-right'>$stock</td>";
        echo "<td>$note</td>";
        echo "<td>";
        echo "<form action='inventory_history_handler.php' method='POST' style='display:inline' ";
        echo "data-confirm=\"Delete this inventory entry?\">\n";
        print_form_key();
        echo "<input type='hidden' name='inventory_id' value='$invId'>";
        echo "<input type='hidden' name='medicine_id' value='" . intval($medicine_id) . "'>";
        echo "<input type='hidden' name='delete' value='yes'>";
        echo "<button type='submit' class='btn btn-sm btn-outline-danger'>Delete</button>";
        echo "</form>";
        echo "</td>";
        echo "</tr>\n";
    }

    echo "</tbody></table>\n";
    echo "</div>\n";
}

echo "<div class='mt-3'>\n";
echo "<a href='inventory_dashboard.php' class='btn btn-secondary'>Back to Dashboard</a>\n";
echo "</div>\n";

echo "</div>\n";

?>
<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
document.addEventListener('submit', function(e) {
  var form = e.target.closest('[data-confirm]');
  if (form && !confirm(form.getAttribute('data-confirm'))) {
    e.preventDefault();
  }
});
</script>
<?php
echo print_trailer();
?>
