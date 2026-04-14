<?php
/**
 * HC-061: list_medications.php using the shared page shell
 * (.page-sticky-header, dual desktop-table / mobile-card layout,
 * .noprint chrome). No patient context here -- this is the global
 * medicine catalog.
 */

require_once 'includes/init.php';

print_header();

$sql = "SELECT id, name, dosage FROM hc_medicines ORDER BY name ASC";
$result = dbi_query($sql);
if (!$result) {
    echo "<p class='alert alert-danger'>Error retrieving medications: " . dbi_error() . "</p>";
    echo print_trailer();
    exit;
}

// Materialize once so we can render twice (table + cards).
$rows = [];
while ($row = dbi_fetch_row($result)) {
    $rows[] = ['id' => (int) $row[0], 'name' => (string) $row[1], 'dosage' => (string) $row[2]];
}

// ── Sticky header ──
echo '<div class="page-sticky-header noprint">';
echo '  <div class="container-fluid d-flex justify-content-between align-items-center">';
echo '    <h5 class="page-title mb-0">Medications</h5>';
echo '    <div class="page-actions">';
echo '      <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">Print</button>';
echo '      <a href="edit_medication.php" class="btn btn-primary btn-sm">+ Add Medication</a>';
echo '    </div>';
echo '  </div>';
echo '</div>';

if ($rows === []) {
    echo "<p class='text-muted mt-3'>No medications recorded yet.</p>";
    echo print_trailer();
    exit;
}

// ── Desktop table (md+) ──
echo '<div class="d-none d-md-block mt-3">';
echo '  <div class="table-responsive">';
echo '    <table class="table table-hover page-table">';
echo '      <thead class="thead-light"><tr>';
echo '        <th>Name</th><th>Dosage</th><th class="actions-cell">Actions</th>';
echo '      </tr></thead>';
echo '      <tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars($r['name']) . '</td>';
    echo '<td>' . htmlspecialchars($r['dosage']) . '</td>';
    echo '<td class="actions-cell"><a href="edit_medication.php?id=' . $r['id']
        . '" class="btn btn-sm btn-outline-secondary">Edit</a></td>';
    echo '</tr>';
}
echo '      </tbody>';
echo '    </table>';
echo '  </div>';
echo '</div>';

// ── Mobile cards (<md) ──
echo '<div class="d-md-none mt-3">';
foreach ($rows as $r) {
    echo '<div class="page-card">';
    echo '  <div class="card-title-row">';
    echo '    <span class="card-primary">' . htmlspecialchars($r['name']) . '</span>';
    echo '  </div>';
    if ($r['dosage'] !== '') {
        echo '  <div class="card-meta">' . htmlspecialchars($r['dosage']) . '</div>';
    }
    echo '  <div class="card-actions noprint">';
    echo '    <a href="edit_medication.php?id=' . $r['id']
        . '" class="btn btn-sm btn-outline-secondary">Edit</a>';
    echo '  </div>';
    echo '</div>';
}
echo '</div>';

echo print_trailer();
