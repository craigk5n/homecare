<?php
require_once 'includes/init.php';

print_header();

$data = getInventoryDashboardData();

echo "<h2>Inventory Dashboard</h2>\n";
echo "<div class='container mt-3'>\n";

if (empty($data)) {
    echo "<div class='alert alert-info'>No medicines found.</div>\n";
    echo "</div>\n";
    echo print_trailer();
    exit;
}

// Filter: medicines with inventory only, or all
$showAll = getGetValue('show_all');
$hasInventory = array_filter($data, function($d) { return $d['current_stock'] !== null; });
$noInventory = array_filter($data, function($d) { return $d['current_stock'] === null; });

// Desktop table
echo "<div class='d-none d-md-block'>\n";
echo "<div class='table-responsive'>\n";
echo "<table class='table table-bordered table-hover'>\n";
echo "<thead class='thead-light'><tr>";
echo "<th>Medication</th>";
echo "<th class='text-right'>Current Stock</th>";
echo "<th class='text-right'>Days Supply</th>";
echo "<th>Last Updated</th>";
echo "<th>Actions</th>";
echo "</tr></thead>\n";
echo "<tbody>\n";

foreach ($hasInventory as $item) {
    $rowClass = '';
    if ($item['days_supply'] !== null) {
        if ($item['days_supply'] < 3) {
            $rowClass = ' class="table-danger"';
        } elseif ($item['days_supply'] < 7) {
            $rowClass = ' class="table-warning"';
        }
    }

    $medId = intval($item['medicine_id']);
    $lastUpdated = $item['last_updated'] ? date('M j, Y', strtotime($item['last_updated'])) : 'Never';
    $stockDisplay = $item['current_stock'] !== null ? number_format($item['current_stock'], 1) : 'N/A';
    $daysDisplay = $item['days_supply'] !== null ? intval($item['days_supply']) . ' days' : 'N/A';

    echo "<tr$rowClass>";
    echo "<td>" . htmlspecialchars($item['name']) . "</td>";
    echo "<td class='text-right'>$stockDisplay</td>";
    echo "<td class='text-right'>$daysDisplay</td>";
    echo "<td>$lastUpdated</td>";
    echo "<td>";
    echo "<a href='inventory_refill.php?medicine_id=$medId' class='btn btn-sm btn-success mr-1'>Refill</a>";
    echo "<a href='inventory_adjust.php?medicine_id=$medId' class='btn btn-sm btn-outline-secondary mr-1'>Adjust</a>";
    echo "<a href='inventory_history.php?medicine_id=$medId' class='btn btn-sm btn-outline-info'>History</a>";
    echo "</td>";
    echo "</tr>\n";
}

// Medicines without inventory
if (!empty($noInventory)) {
    echo "<tr><td colspan='5' class='text-muted text-center'><em>";
    echo count($noInventory) . " medicine(s) with no inventory recorded";
    if (empty($showAll)) {
        echo " &mdash; <a href='?show_all=1'>show all</a>";
    }
    echo "</em></td></tr>\n";

    if (!empty($showAll)) {
        foreach ($noInventory as $item) {
            $medId = intval($item['medicine_id']);
            echo "<tr class='text-muted'>";
            echo "<td>" . htmlspecialchars($item['name']) . "</td>";
            echo "<td class='text-right'>N/A</td>";
            echo "<td class='text-right'>N/A</td>";
            echo "<td>Never</td>";
            echo "<td>";
            echo "<a href='inventory_refill.php?medicine_id=$medId' class='btn btn-sm btn-success mr-1'>Refill</a>";
            echo "</td>";
            echo "</tr>\n";
        }
    }
}

echo "</tbody></table>\n";
echo "</div>\n";
echo "</div>\n";

// Mobile card view
echo "<div class='d-md-none'>\n";

foreach ($hasInventory as $item) {
    $medId = intval($item['medicine_id']);
    $lastUpdated = $item['last_updated'] ? date('M j, Y', strtotime($item['last_updated'])) : 'Never';
    $stockDisplay = $item['current_stock'] !== null ? number_format($item['current_stock'], 1) : 'N/A';
    $daysDisplay = $item['days_supply'] !== null ? intval($item['days_supply']) . ' days' : 'N/A';

    $cardClass = 'card mb-3';
    if ($item['days_supply'] !== null) {
        if ($item['days_supply'] < 3) {
            $cardClass .= ' border-danger';
        } elseif ($item['days_supply'] < 7) {
            $cardClass .= ' border-warning';
        }
    }

    echo "<div class='$cardClass'>\n";
    echo "<div class='card-body p-3'>\n";
    echo "<h6 class='card-title mb-1'>" . htmlspecialchars($item['name']) . "</h6>\n";
    echo "<div class='d-flex justify-content-between mb-2'>\n";
    echo "<span>Stock: <strong>$stockDisplay</strong></span>\n";
    echo "<span>Supply: <strong>$daysDisplay</strong></span>\n";
    echo "</div>\n";
    echo "<small class='text-muted d-block mb-2'>Updated: $lastUpdated</small>\n";
    echo "<div>\n";
    echo "<a href='inventory_refill.php?medicine_id=$medId' class='btn btn-sm btn-success mr-1'>Refill</a>";
    echo "<a href='inventory_adjust.php?medicine_id=$medId' class='btn btn-sm btn-outline-secondary mr-1'>Adjust</a>";
    echo "<a href='inventory_history.php?medicine_id=$medId' class='btn btn-sm btn-outline-info'>History</a>";
    echo "</div>\n";
    echo "</div>\n";
    echo "</div>\n";
}

echo "</div>\n";

echo "</div>\n";

echo print_trailer();
?>
