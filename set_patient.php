<?php
/**
 * Switch the active patient context and redirect to their schedule.
 *
 * Tiny helper — writes `$_SESSION['active_patient_id']` when the id
 * looks plausible and sends the browser to `list_schedule.php?patient_id=N`.
 * Used by the patient chip in the top nav.
 */

require_once 'includes/init.php';

$id = getIntValue('id');
if (empty($id) || !patientExistsById((int) $id)) {
    do_redirect('list_medications.php');
    exit;
}

$_SESSION['active_patient_id'] = (int) $id;

do_redirect('list_schedule.php?patient_id=' . urlencode((string) (int) $id));
