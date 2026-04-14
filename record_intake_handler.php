<?php
require_once 'includes/init.php';
require_role('caregiver');

$schedule_id = getPostValue('schedule_id');
$patient_id = getPostValue('patient_id');
$taken_time = getPostValue('taken_time');
$edit_id = getPostValue('id');
$note = getPostValue('note');
$delete = getPostValue('delete');

if (!empty($delete) && $delete === 'yes') {
  // Delete the intake record
  $deleteSql = "DELETE FROM hc_medicine_intake WHERE id = ?";
  if (!dbi_execute($deleteSql, [$edit_id])) {
      echo "Error deleting intake: " . dbi_error();
  } else {
      audit_log('intake.deleted', 'intake', (int) $edit_id, [
          'schedule_id' => (int) $schedule_id,
          'patient_id' => (int) $patient_id,
      ]);
  }
} elseif (empty($edit_id)) {
  // Insert intake record into database
  $insertSql = "INSERT INTO hc_medicine_intake (schedule_id, taken_time, note) VALUES (?, ?, ?)";
  $params = [$schedule_id, $taken_time, $note];
  if (!dbi_execute($insertSql, $params)) {
      echo "Error recording intake: " . dbi_error();
  } else {
      $newId = (int) mysqli_insert_id($GLOBALS['c']);
      audit_log('intake.recorded', 'intake', $newId ?: null, [
          'schedule_id' => (int) $schedule_id,
          'patient_id' => (int) $patient_id,
          'taken_time' => $taken_time,
          'note' => $note,
      ]);
  }
} else {
  // Edit existing entry
  $insertSql = "UPDATE hc_medicine_intake SET taken_time = ?, note = ? WHERE id = ?";
  $params = [$taken_time, $note, $edit_id];
  //echo "SQL: $insertSql <br>Params: "; print_r($params); echo "<br>"; exit;
  if (!dbi_execute($insertSql, $params)) {
      echo "Error recording intake: " . dbi_error();
  } else {
      audit_log('intake.updated', 'intake', (int) $edit_id, [
          'taken_time' => $taken_time,
          'note' => $note,
      ]);
  }
}
do_redirect("list_schedule.php?patient_id=" . urlencode($patient_id));
?>