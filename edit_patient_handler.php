<?php

declare(strict_types=1);

require_once 'includes/init.php';
require_role('caregiver');

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\WeightRepository;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = getPostValue('id');
$name = trim((string) getPostValue('name'));
$species = getPostValue('species');
$weight_kg = getPostValue('weight_kg');
$weight_as_of = getPostValue('weight_as_of');
$is_active = !empty(getPostValue('is_active')) ? 1 : 0;

if ($name === '') {
    echo '<p>Patient name is required.</p>';
    exit;
}

if (empty($species)) {
    $species = null;
}
if ($weight_kg === '' || $weight_kg === null) {
    $weight_kg = null;
    $weight_as_of = null;
} else {
    $weight_kg = inputWeightToKg((float) $weight_kg);
    if (empty($weight_as_of)) {
        $weight_as_of = date('Y-m-d');
    }
}

$weightRepo = new WeightRepository(new DbiAdapter());

if (!empty($id)) {
    // Check if weight changed from previous value.
    $oldPatient = getPatient((int) $id);
    $oldWeight = $oldPatient['weight_kg'] ?? null;

    // Update non-weight fields. The weight columns are synced below from
    // hc_weight_history so the displayed weight is always the most recent
    // by recorded_at, not by data-entry order.
    $sql = 'UPDATE hc_patients SET name = ?, species = ?, is_active = ? WHERE id = ?';
    if (!dbi_execute($sql, [$name, $species, $is_active, (int) $id])) {
        echo '<p>Error updating patient: ' . htmlspecialchars(dbi_error()) . '</p>';
        exit;
    }
    audit_log('patient.updated', 'patient', (int) $id, [
        'name' => $name,
        'species' => $species,
        'weight_kg' => $weight_kg,
        'is_active' => $is_active,
    ]);

    // Record weight history if weight changed, then sync the patient's
    // current weight to the most recent history entry by date.
    if ($weight_kg !== null && (float) $weight_kg !== (float) ($oldWeight ?? 0)) {
        $weightRepo->insert((int) $id, (float) $weight_kg, (string) $weight_as_of);
    }
    $weightRepo->syncCurrentWeight((int) $id);
} else {
    $sql = 'INSERT INTO hc_patients (name, species, weight_kg, weight_as_of, is_active) VALUES (?, ?, ?, ?, ?)';
    if (!dbi_execute($sql, [$name, $species, $weight_kg, $weight_as_of, 1])) {
        echo '<p>Error adding patient: ' . htmlspecialchars(dbi_error()) . '</p>';
        exit;
    }
    $newId = (int) ($GLOBALS['phpdbiConnection']->insert_id ?? 0);
    audit_log('patient.created', 'patient', $newId ?: null, [
        'name' => $name,
        'species' => $species,
        'weight_kg' => $weight_kg,
    ]);

    // Record initial weight history for new patient.
    if ($weight_kg !== null && $newId > 0) {
        $weightRepo->insert($newId, (float) $weight_kg, (string) ($weight_as_of ?? date('Y-m-d')));
    }
}

do_redirect('index.php');
