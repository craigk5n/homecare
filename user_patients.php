<?php
/**
 * Admin: limit caregivers / viewers to assigned patients (HC-237).
 *
 * Lists every user with their current patient limit; `?login=X` opens
 * the checkbox form for one user. Saving posts to
 * user_patients_handler.php. A user with no boxes ticked sees every
 * patient (the default); admins always see every patient.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_role('admin');

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\UserPatientRepository;

$repo = new UserPatientRepository(new DbiAdapter());
$users = $repo->listUsers();
$counts = $repo->countsByUser();

$selectedLogin = (string) (getGetValue('login') ?? '');
$selected = null;
foreach ($users as $u) {
    if ($u['login'] === $selectedLogin) {
        $selected = $u;
        break;
    }
}
$assigned = $selected !== null ? $repo->getPatientIds($selected['login']) : [];
$patients = getPatients(true);
$saved = getGetValue('saved') === '1';

print_header();
?>

<div class="page-sticky-header noprint">
  <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap">
    <h5 class="page-title mb-0"><?php etranslate('Patient Access'); ?></h5>
  </div>
</div>

<div class="container mt-3">
  <p class="text-muted">
    <?php etranslate('Limit a caregiver or viewer to the patients they look after. A user with no patients ticked sees every patient. Admins always see every patient.'); ?>
  </p>

  <?php if ($saved && $selected !== null): ?>
    <div class="alert alert-success"><?php etranslate('Patient access saved.'); ?></div>
  <?php endif; ?>

  <div class="table-responsive">
    <table class="table table-sm table-bordered table-hover">
      <thead class="thead-light">
        <tr>
          <th><?php etranslate('User'); ?></th>
          <th><?php etranslate('Role'); ?></th>
          <th><?php etranslate('Patients'); ?></th>
          <th class="noprint"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <?php
            $name = trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? ''));
            $n = $counts[$u['login']] ?? 0;
          ?>
          <tr<?= $selected !== null && $u['login'] === $selected['login'] ? ' class="table-active"' : '' ?>>
            <td>
              <?= htmlspecialchars($u['login']) ?>
              <?php if ($name !== ''): ?>
                <span class="text-muted">(<?= htmlspecialchars($name) ?>)</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($u['is_admin'] ? 'admin' : $u['role']) ?></td>
            <td>
              <?php if ($u['is_admin'] || $n === 0): ?>
                <?php etranslate('All patients'); ?>
              <?php else: ?>
                <?= (int) $n ?> <?php etranslate('assigned'); ?>
              <?php endif; ?>
            </td>
            <td class="noprint">
              <?php if (!$u['is_admin']): ?>
                <a class="btn btn-sm btn-outline-primary"
                   href="user_patients.php?login=<?= urlencode($u['login']) ?>"><?php etranslate('Edit'); ?></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($selected !== null && !$selected['is_admin']): ?>
    <h4 class="mt-4"><?php etranslate('Patients for'); ?> <?= htmlspecialchars($selected['login']) ?></h4>
    <form action="user_patients_handler.php" method="POST">
      <?php print_form_key(); ?>
      <input type="hidden" name="login" value="<?= htmlspecialchars($selected['login'], ENT_QUOTES, 'UTF-8') ?>">
      <?php if ($patients === []): ?>
        <p class="text-muted"><?php etranslate('No patients yet.'); ?></p>
      <?php endif; ?>
      <?php foreach ($patients as $p): ?>
        <?php $pid = (int) $p['id']; ?>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="patient_ids[]"
                 id="patient_<?= $pid ?>" value="<?= $pid ?>"<?= in_array($pid, $assigned, true) ? ' checked' : '' ?>>
          <label class="form-check-label" for="patient_<?= $pid ?>"><?= htmlspecialchars((string) $p['name']) ?></label>
        </div>
      <?php endforeach; ?>
      <small class="form-text text-muted mb-2">
        <?php etranslate('Leave every box unticked to let this user see all patients.'); ?>
      </small>
      <button type="submit" class="btn btn-primary"><?php etranslate('Save'); ?></button>
      <a href="user_patients.php" class="btn btn-secondary"><?php etranslate('Cancel'); ?></a>
    </form>
  <?php endif; ?>
</div>

<?php echo print_trailer(); ?>
