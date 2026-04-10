<?php
require_once 'includes/init.php';

print_header();

// Fetch all medications
$sql = "SELECT id, name FROM hc_medicines ORDER BY name ASC";
$medications = dbi_query($sql);

if (!$medications) {
    echo "<p class='alert alert-danger'>Error retrieving medications: " . dbi_error() . "</p>";
    exit;
}

echo "<h2>Update Medication Inventory</h2>\n";
echo "<div class='container'>\n";
echo "<form id='inventoryForm' action='edit_inventory_handler.php' method='POST'>\n";
print_form_key();

// Dropdown for selecting medication
echo "<div class='form-group'>";
echo "<label for='medicine_id'>Select Medication:</label>";
echo "<select id='medicine_id' name='medicine_id' class='form-control'>";
echo "<option value=''>-- Select One --</option>";
while ($row = dbi_fetch_row($medications)) {
    echo "<option value='" . $row[0] . "'>" . htmlspecialchars($row[1]) . "</option>";
}
echo "</select>";
echo "</div>\n";

// Fields for quantity, current stock, and note
echo "<div class='form-group'>";
echo "<label for='quantity'>Quantity (original):</label>";
echo "<input type='number' step='0.25' id='quantity' name='quantity' class='form-control'>";
echo "</div>\n";

echo "<div class='form-group'>";
echo "<label for='current_stock'>Current Stock:</label>";
echo "<input type='number' step='0.25' id='current_stock' name='current_stock' class='form-control'>";
echo "</div>\n";

echo "<div class='form-group'>";
echo "<label for='note'>Note:</label>";
echo "<input type='text' id='note' name='note' class='form-control'>";
echo "</div>\n";

echo "<button type='submit' class='btn btn-primary'>Save Inventory</button>\n";
echo "</form>\n";
echo "</div>\n";

?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    $('#medicine_id').change(function() {
        var medicineId = $(this).val();
        if (medicineId) {
            $.ajax({
                url: 'fetch_medicine_inventory.php',
                type: 'POST',
                data: {medicine_id: medicineId},
                dataType: 'json',
                success: function(data) {
                    if (data) {
                        $('#quantity').val(data.quantity);
                        $('#current_stock').val(data.current_stock);
                        $('#note').val(data.note);
                    } else {
                        $('#quantity').val('');
                        $('#current_stock').val('');
                        $('#note').val('');
                    }
                }
            });
        } else {
            $('#quantity').val('');
            $('#current_stock').val('');
            $('#note').val('');
        }
    });
});
</script>
<?php
echo print_trailer();
?>
