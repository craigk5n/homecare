<?php
/**
 * HC-131: CSV import for schedules + intakes.
 *
 * Two-step flow: upload CSV → preview validation → confirm to commit.
 * Admin-only.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_role('admin');

use HomeCare\Database\DbiAdapter;
use HomeCare\Import\CsvImportService;

$db = new DbiAdapter();
$error = '';
$preview = null;
$importResult = null;
$importType = getPostValue('import_type') ?: getGetValue('import_type') ?: 'intakes';
$patientId = (int) (getPostValue('patient_id') ?: getIntValue('patient_id') ?: 0);
$createMissing = !empty(getPostValue('create_missing'));
$action = getPostValue('action') ?: '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'preview') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please select a CSV file.';
    } else {
        $csvContent = (string) file_get_contents($_FILES['csv_file']['tmp_name']);
        $_SESSION['hc_import_csv'] = $csvContent;
        $_SESSION['hc_import_type'] = $importType;
        $_SESSION['hc_import_patient_id'] = $patientId;
        $_SESSION['hc_import_create_missing'] = $createMissing;

        try {
            $service = new CsvImportService($db, $createMissing);
            if ($importType === 'schedules') {
                $preview = $service->previewSchedules($csvContent);
            } else {
                if ($patientId < 1) {
                    $error = 'Please select a patient for intake import.';
                } else {
                    $preview = $service->previewIntakes($csvContent, $patientId);
                }
            }
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirm') {
    $csvContent = $_SESSION['hc_import_csv'] ?? '';
    $importType = $_SESSION['hc_import_type'] ?? 'intakes';
    $patientId = (int) ($_SESSION['hc_import_patient_id'] ?? 0);
    $createMissing = (bool) ($_SESSION['hc_import_create_missing'] ?? false);

    if ($csvContent === '') {
        $error = 'Session expired. Please upload the file again.';
    } else {
        try {
            $service = new CsvImportService($db, $createMissing);
            if ($importType === 'schedules') {
                $importResult = $service->importSchedules($csvContent);
            } else {
                $importResult = $service->importIntakes($csvContent, $patientId);
            }

            audit_log('data.imported', 'import', null, [
                'type' => $importType,
                'patient_id' => $patientId ?: null,
                'total' => $importResult['total'],
                'imported' => $importResult['imported'],
                'skipped' => $importResult['skipped'],
                'errors' => $importResult['errors'],
            ]);

            unset($_SESSION['hc_import_csv'], $_SESSION['hc_import_type'],
                  $_SESSION['hc_import_patient_id'], $_SESSION['hc_import_create_missing']);
        } catch (\Throwable $e) {
            $error = 'Import failed: ' . $e->getMessage();
        }
    }
}

// Fetch patients for dropdown
$patientsSql = "SELECT id, name FROM hc_patients WHERE is_active = TRUE ORDER BY name ASC";
$patientsResult = dbi_query($patientsSql);
$patients = [];
while ($row = dbi_fetch_row($patientsResult)) {
    $patients[] = ['id' => (int) $row[0], 'name' => (string) $row[1]];
}

print_header();

echo "<div class='container mt-3'>\n";
echo "<h2>Import CSV</h2>\n";

if ($error !== '') {
    echo "<div class='alert alert-danger'>" . htmlspecialchars($error) . "</div>\n";
}

// Show import result
if ($importResult !== null) {
    $cls = $importResult['errors'] > 0 ? 'warning' : 'success';
    echo "<div class='alert alert-$cls'>\n";
    echo "<strong>Import complete.</strong> ";
    echo "Total: {$importResult['total']}, ";
    echo "Imported: {$importResult['imported']}, ";
    echo "Skipped: {$importResult['skipped']}, ";
    echo "Errors: {$importResult['errors']}\n";
    echo "</div>\n";
    renderResultTable($importResult);
    echo "<a href='import_schedules.php' class='btn btn-primary mt-3'>Import another file</a>\n";
    echo "</div>\n";
    echo print_trailer();
    exit;
}

// Show preview + confirm
if ($preview !== null) {
    echo "<div class='alert alert-info'>\n";
    echo "<strong>Preview:</strong> ";
    echo "Total: {$preview['total']}, ";
    echo "Will import: {$preview['imported']}, ";
    echo "Will skip: {$preview['skipped']}, ";
    echo "Errors: {$preview['errors']}\n";
    echo "</div>\n";
    renderResultTable($preview);

    if ($preview['imported'] > 0) {
        echo "<form method='POST' class='mt-3'>\n";
        print_form_key();
        echo "<input type='hidden' name='action' value='confirm'>\n";
        echo "<button type='submit' class='btn btn-success'>Confirm Import ({$preview['imported']} rows)</button>\n";
        echo " <a href='import_schedules.php' class='btn btn-secondary'>Cancel</a>\n";
        echo "</form>\n";
    } else {
        echo "<p class='text-muted mt-3'>Nothing to import.</p>\n";
        echo "<a href='import_schedules.php' class='btn btn-secondary'>Back</a>\n";
    }

    echo "</div>\n";
    echo print_trailer();
    exit;
}

// Upload form
echo "<form method='POST' enctype='multipart/form-data'>\n";
print_form_key();
echo "<input type='hidden' name='action' value='preview'>\n";

echo "<div class='form-group'>\n";
echo "<label for='import_type'>Import type:</label>\n";
echo "<select name='import_type' id='import_type' class='form-control'>\n";
$intSel = $importType === 'intakes' ? ' selected' : '';
$schSel = $importType === 'schedules' ? ' selected' : '';
echo "<option value='intakes'$intSel>Intakes (Date, Time, Medication, Dosage, Frequency, UnitPerDose, Notes)</option>\n";
echo "<option value='schedules'$schSel>Schedules (PatientName, Medication, Dosage, Frequency, UnitPerDose, StartDate, EndDate)</option>\n";
echo "</select>\n";
echo "</div>\n";

echo "<div class='form-group' id='patient-group'" . ($importType === 'schedules' ? " style='display:none'" : "") . ">\n";
echo "<label for='patient_id'>Patient (for intake import):</label>\n";
echo "<select name='patient_id' id='patient_id' class='form-control'>\n";
echo "<option value=''>-- Select patient --</option>\n";
foreach ($patients as $p) {
    $sel = $p['id'] === $patientId ? ' selected' : '';
    echo "<option value='" . $p['id'] . "'" . $sel . ">" . htmlspecialchars($p['name']) . "</option>\n";
}
echo "</select>\n";
echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='csv_file'>CSV file:</label>\n";
echo "<input type='file' name='csv_file' id='csv_file' class='form-control-file' accept='.csv,text/csv' required>\n";
echo "</div>\n";

echo "<div class='form-group form-check'>\n";
echo "<input type='checkbox' class='form-check-input' name='create_missing' id='create_missing' value='1'>\n";
echo "<label class='form-check-label' for='create_missing'>Create missing patients/medicines (if not found)</label>\n";
echo "</div>\n";

echo "<button type='submit' class='btn btn-primary'>Preview</button>\n";
echo " <a href='index.php' class='btn btn-secondary'>Cancel</a>\n";

echo "</form>\n";
echo "</div>\n";

?>
<script>
document.getElementById('import_type').addEventListener('change', function() {
    document.getElementById('patient-group').style.display =
        this.value === 'schedules' ? 'none' : '';
});
</script>
<?php

echo print_trailer();

/**
 * @param array{total:int, imported:int, skipped:int, errors:int, rows:list<array{line:int, status:string, message:string, data:array<string,string>}>} $result
 */
function renderResultTable(array $result): void
{
    if ($result['rows'] === []) {
        return;
    }
    echo "<div class='table-responsive mt-2'>\n";
    echo "<table class='table table-sm table-bordered'>\n";
    echo "<thead class='thead-light'><tr><th>Line</th><th>Status</th><th>Message</th><th>Data</th></tr></thead>\n";
    echo "<tbody>\n";
    foreach ($result['rows'] as $row) {
        $cls = match ($row['status']) {
            'ok' => 'table-success',
            'skipped' => 'table-warning',
            'error' => 'table-danger',
            default => '',
        };
        $dataSummary = implode(', ', array_map(
            fn($k, $v) => htmlspecialchars("$k=$v"),
            array_keys($row['data']),
            array_values($row['data'])
        ));
        echo "<tr class='$cls'>";
        echo "<td>{$row['line']}</td>";
        echo "<td>{$row['status']}</td>";
        echo "<td>" . htmlspecialchars($row['message']) . "</td>";
        echo "<td><small>" . $dataSummary . "</small></td>";
        echo "</tr>\n";
    }
    echo "</tbody></table></div>\n";
}
