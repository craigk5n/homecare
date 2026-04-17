<?php

declare(strict_types=1);

require_once 'includes/init.php';
require_role('caregiver');

$patient_id = getIntValue('id');
$isEdit = !empty($patient_id);

$species_options = ['', 'cat', 'dog', 'horse', 'rabbit', 'bird', 'reptile', 'other', 'human'];

if ($isEdit) {
    $patient = getPatient($patient_id);
    $name = $patient['name'];
    $species = $patient['species'] ?? '';
    $weight_kg = $patient['weight_kg'];
    $weight_as_of = $patient['weight_as_of'] ?? '';
    $is_active = (int) $patient['is_active'];
} else {
    $name = '';
    $species = '';
    $weight_kg = null;
    $weight_as_of = '';
    $is_active = 1;
}

print_header();

$title = $isEdit ? 'Edit Patient' : 'Add Patient';
echo "<div class='container mt-3'>\n";
echo "<h2>" . htmlspecialchars($title) . "</h2>\n";

echo "<form action='edit_patient_handler.php' method='POST'>\n";
print_form_key();
if ($isEdit) {
    echo "<input type='hidden' name='id' value='" . htmlspecialchars((string) $patient_id, ENT_QUOTES, 'UTF-8') . "'>\n";
}

echo "<div class='form-group'>\n";
echo "<label for='name'>Name:</label>\n";
echo "<input type='text' name='name' id='name' class='form-control' required value='" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "'>\n";
echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='species'>Species:</label>\n";
echo "<select name='species' id='species' class='form-control'>\n";
foreach ($species_options as $opt) {
    $label = $opt === '' ? '— Not specified —' : ucfirst($opt);
    $selected = ($opt === $species) ? ' selected' : '';
    echo "<option value='" . htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') . "'{$selected}>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</option>\n";
}
echo "</select>\n";
echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='weight_kg'>Weight (kg):</label>\n";
echo "<input type='number' step='0.01' min='0' name='weight_kg' id='weight_kg' class='form-control'"
    . ($weight_kg !== null ? " value='" . htmlspecialchars((string) $weight_kg, ENT_QUOTES, 'UTF-8') . "'" : '')
    . ">\n";
echo "<small class='form-text text-muted'>Required for per-kg dosing schedules.</small>\n";
echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='weight_as_of'>Weight recorded on:</label>\n";
echo "<input type='date' name='weight_as_of' id='weight_as_of' class='form-control'"
    . (!empty($weight_as_of) ? " value='" . htmlspecialchars($weight_as_of, ENT_QUOTES, 'UTF-8') . "'" : '')
    . ">\n";
echo "</div>\n";

if ($isEdit) {
    echo "<div class='form-group form-check'>\n";
    $activeChecked = $is_active ? ' checked' : '';
    echo "<input type='checkbox' class='form-check-input' name='is_active' id='is_active' value='1'{$activeChecked}>\n";
    echo "<label class='form-check-label' for='is_active'>Active</label>\n";
    echo "</div>\n";
}

echo "<button type='submit' class='btn btn-primary'>" . ($isEdit ? 'Update Patient' : 'Add Patient') . "</button>\n";
echo " <a href='index.php' class='btn btn-secondary'>Cancel</a>\n";
echo "</form>\n";
echo "</div>\n";

echo print_trailer();
