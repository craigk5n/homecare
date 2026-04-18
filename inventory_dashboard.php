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

// Split medicines into groups:
//   - onSchedule: has inventory AND an active schedule (daily_consumption > 0)
//   - offSchedule: has inventory but NO active schedule
//   - noInventory: no inventory record at all
$showAll = getGetValue('show_all');
$showOff = getGetValue('show_off');

$onSchedule = [];
$offSchedule = [];
$noInventory = [];
foreach ($data as $d) {
    if ($d['current_stock'] === null) {
        $noInventory[] = $d;
    } elseif ($d['daily_consumption'] > 0) {
        $onSchedule[] = $d;
    } else {
        $offSchedule[] = $d;
    }
}
$hasInventory = array_merge($onSchedule, $showOff ? $offSchedule : []);

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

// Medicines with inventory but not on any active schedule
if (!empty($offSchedule)) {
    $offQs = $showOff ? '' : '?show_off=1';
    $offLabel = $showOff ? 'hide' : 'show';
    echo "<tr><td colspan='5' class='text-muted text-center'><em>";
    echo count($offSchedule) . " medicine(s) not on an active schedule";
    echo " &mdash; <a href='{$offQs}'>{$offLabel}</a>";
    echo "</em></td></tr>\n";

    if (!empty($showOff)) {
        foreach ($offSchedule as $item) {
            $medId = intval($item['medicine_id']);
            $lastUpdated = $item['last_updated'] ? date('M j, Y', strtotime($item['last_updated'])) : 'Never';
            $stockDisplay = number_format($item['current_stock'], 1);
            echo "<tr class='text-muted'>";
            echo "<td>" . htmlspecialchars($item['name']) . " <small>(no active schedule)</small></td>";
            echo "<td class='text-right'>$stockDisplay</td>";
            echo "<td class='text-right'>N/A</td>";
            echo "<td>$lastUpdated</td>";
            echo "<td>";
            echo "<a href='inventory_refill.php?medicine_id=$medId' class='btn btn-sm btn-success mr-1'>Refill</a>";
            echo "<a href='inventory_adjust.php?medicine_id=$medId' class='btn btn-sm btn-outline-secondary mr-1'>Adjust</a>";
            echo "<a href='inventory_history.php?medicine_id=$medId' class='btn btn-sm btn-outline-info'>History</a>";
            echo "</td>";
            echo "</tr>\n";
        }
    }
}

// Medicines without any inventory record
if (!empty($noInventory)) {
    $allQs = $showAll ? ($showOff ? '?show_off=1' : '') : ('?show_all=1' . ($showOff ? '&show_off=1' : ''));
    $allLabel = $showAll ? 'hide' : 'show';
    echo "<tr><td colspan='5' class='text-muted text-center'><em>";
    echo count($noInventory) . " medicine(s) with no inventory recorded";
    echo " &mdash; <a href='{$allQs}'>{$allLabel}</a>";
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

// Mobile: off-schedule medicines
if (!empty($showOff) && !empty($offSchedule)) {
    foreach ($offSchedule as $item) {
        $medId = intval($item['medicine_id']);
        $lastUpdated = $item['last_updated'] ? date('M j, Y', strtotime($item['last_updated'])) : 'Never';
        $stockDisplay = number_format($item['current_stock'], 1);
        echo "<div class='card mb-3 border-light'>\n";
        echo "<div class='card-body p-3 text-muted'>\n";
        echo "<h6 class='card-title mb-1'>" . htmlspecialchars($item['name']) . " <small>(no active schedule)</small></h6>\n";
        echo "<div class='d-flex justify-content-between mb-2'>\n";
        echo "<span>Stock: <strong>$stockDisplay</strong></span>\n";
        echo "<span>Supply: <strong>N/A</strong></span>\n";
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
}

echo "</div>\n";

echo "</div>\n";

echo print_trailer();
?>
