<?php
require_once 'includes/init.php';

// Get the medication ID from the POST request
$medicine_id = isset($_POST['medicine_id']) ? intval($_POST['medicine_id']) : 0;

// Initialize the response array
$response = array();

if ($medicine_id > 0) {
    // Prepare SQL to fetch the most recent inventory entry for the given medication
    $sql = "SELECT quantity, current_stock, note 
            FROM hc_medicine_inventory 
            WHERE medicine_id = ?
            ORDER BY recorded_at DESC 
            LIMIT 1";

    $result = dbi_execute($sql, [$medicine_id]);

    if ($result && $row = dbi_fetch_row($result)) {
        // Populate the response array with inventory data
        $response['quantity'] = $row[0];
        $response['current_stock'] = $row[1];
        $response['note'] = $row[2];
    } else {
        // Handle the case where there is no inventory entry or an error occurred
        $response['error'] = "No inventory found or query failed: " . dbi_error();
    }
} else {
    // Handle the case where no valid medication ID is provided
    $response['error'] = "Invalid medication ID";
}

// Output the JSON encoded response
header('Content-Type: application/json');
echo json_encode($response);
?>

