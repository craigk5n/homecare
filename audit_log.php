<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_role('admin');

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\AuditRepository;

$db = new DbiAdapter();
$audit = new AuditRepository($db);

$perPage = 50;
$page = max(1, (int) (getGetValue('page') ?? 1));

$filters = [
    'user_login' => trim((string) (getGetValue('user_login') ?? '')),
    'action' => trim((string) (getGetValue('action') ?? '')),
    'entity_type' => trim((string) (getGetValue('entity_type') ?? '')),
    'date_from' => trim((string) (getGetValue('date_from') ?? '')),
    'date_to' => trim((string) (getGetValue('date_to') ?? '')),
];
$filters = array_filter($filters, static fn (string $v): bool => $v !== '');

$total = $audit->count($filters);
$rows = $audit->search($filters, $page, $perPage);
$totalPages = (int) ceil($total / $perPage);

$users = $audit->getDistinctValues('user_login');
$actions = $audit->getDistinctValues('action');
$entityTypes = $audit->getDistinctValues('entity_type');

function audit_filter_qs(array $overrides = []): string
{
    $params = array_merge([
        'user_login' => getGetValue('user_login') ?? '',
        'action' => getGetValue('action') ?? '',
        'entity_type' => getGetValue('entity_type') ?? '',
        'date_from' => getGetValue('date_from') ?? '',
        'date_to' => getGetValue('date_to') ?? '',
    ], $overrides);
    $parts = [];
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null) {
            $parts[] = urlencode($k) . '=' . urlencode($v);
        }
    }

    return $parts === [] ? '' : '&' . implode('&', $parts);
}

print_header();
?>

<div class="page-sticky-header noprint">
  <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap">
    <h5 class="page-title mb-0">Audit Log</h5>
    <div class="page-actions">
      <button class="btn btn-sm btn-outline-secondary" data-print>Print</button>
    </div>
  </div>
</div>

<form method="get" class="mb-3 mt-2 noprint">
  <div class="form-row align-items-end">
    <div class="col-md-2 mb-2">
      <label class="small" for="f_user">User</label>
      <select name="user_login" id="f_user" class="form-control form-control-sm">
        <option value="">-- all --</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= htmlspecialchars($u) ?>" <?= ($filters['user_login'] ?? '') === $u ? 'selected' : '' ?>>
            <?= htmlspecialchars($u) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 mb-2">
      <label class="small" for="f_action">Action</label>
      <select name="action" id="f_action" class="form-control form-control-sm">
        <option value="">-- all --</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?= htmlspecialchars($a) ?>" <?= ($filters['action'] ?? '') === $a ? 'selected' : '' ?>>
            <?= htmlspecialchars($a) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 mb-2">
      <label class="small" for="f_entity">Entity Type</label>
      <select name="entity_type" id="f_entity" class="form-control form-control-sm">
        <option value="">-- all --</option>
        <?php foreach ($entityTypes as $et): ?>
          <option value="<?= htmlspecialchars($et) ?>" <?= ($filters['entity_type'] ?? '') === $et ? 'selected' : '' ?>>
            <?= htmlspecialchars($et) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 mb-2">
      <label class="small" for="f_from">From</label>
      <input type="date" name="date_from" id="f_from" class="form-control form-control-sm"
             value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
    </div>
    <div class="col-md-2 mb-2">
      <label class="small" for="f_to">To</label>
      <input type="date" name="date_to" id="f_to" class="form-control form-control-sm"
             value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
    </div>
    <div class="col-md-2 mb-2">
      <button type="submit" class="btn btn-sm btn-primary mr-1">Filter</button>
      <a href="audit_log.php" class="btn btn-sm btn-outline-secondary">Reset</a>
    </div>
  </div>
</form>

<?php if ($rows === []): ?>
  <p class="text-muted">No audit log entries found.</p>
<?php else: ?>

<?php
$showing = count($rows);
$first = ($page - 1) * $perPage + 1;
$last = $first + $showing - 1;
?>
<p class="small text-muted noprint">
  Showing <?= $first ?>-<?= $last ?> of <?= $total ?>
</p>

<div class="d-none d-md-block">
  <div class="table-responsive">
    <table class="table table-hover table-sm page-table">
      <thead class="thead-light">
        <tr>
          <th>Time</th>
          <th>User</th>
          <th>Action</th>
          <th>Entity</th>
          <th>Details</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
          $entityLabel = '';
          if ($r['entity_type'] !== null) {
              $entityLabel = ucfirst(htmlspecialchars($r['entity_type']));
              if ($r['entity_id'] !== null) {
                  $entityLabel .= ' #' . $r['entity_id'];
              }
              if ($r['patient_name'] !== null) {
                  $entityLabel .= ' <span class="text-muted">(' . htmlspecialchars($r['patient_name']);
                  if ($r['medicine_name'] !== null) {
                      $entityLabel .= ' / ' . htmlspecialchars($r['medicine_name']);
                  }
                  $entityLabel .= ')</span>';
              } elseif ($r['medicine_name'] !== null) {
                  $entityLabel .= ' <span class="text-muted">(' . htmlspecialchars($r['medicine_name']) . ')</span>';
              }
          }

          $detailId = 'det-' . $r['id'];
          $detailsJson = $r['details'] !== null
              ? json_encode(json_decode($r['details'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
              : null;
      ?>
        <tr>
          <td class="text-nowrap small"><?= htmlspecialchars($r['created_at']) ?></td>
          <td class="small"><?= htmlspecialchars($r['user_login'] ?? '') ?></td>
          <td class="small"><code><?= htmlspecialchars($r['action']) ?></code></td>
          <td class="small"><?= $entityLabel ?></td>
          <td class="small">
            <?php if ($detailsJson !== null): ?>
              <button class="btn btn-link btn-sm p-0" data-toggle="collapse"
                      data-target="#<?= $detailId ?>"
                      aria-expanded="false" aria-controls="<?= $detailId ?>">&#9654;</button>
              <div id="<?= $detailId ?>" class="collapse">
                <pre class="small mb-0 mt-1" style="white-space:pre-wrap;word-break:break-all;"><?= htmlspecialchars($detailsJson) ?></pre>
              </div>
            <?php else: ?>
              <span class="text-muted">&mdash;</span>
            <?php endif; ?>
          </td>
          <td class="small text-muted"><?= htmlspecialchars($r['ip_address'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="d-md-none">
<?php foreach ($rows as $r):
    $detailId = 'm-det-' . $r['id'];
    $detailsJson = $r['details'] !== null
        ? json_encode(json_decode($r['details'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : null;

    $entityLabel = '';
    if ($r['entity_type'] !== null) {
        $entityLabel = ucfirst(htmlspecialchars($r['entity_type']));
        if ($r['entity_id'] !== null) {
            $entityLabel .= ' #' . $r['entity_id'];
        }
        $parts = [];
        if ($r['patient_name'] !== null) {
            $parts[] = htmlspecialchars($r['patient_name']);
        }
        if ($r['medicine_name'] !== null) {
            $parts[] = htmlspecialchars($r['medicine_name']);
        }
        if ($parts !== []) {
            $entityLabel .= ' (' . implode(' / ', $parts) . ')';
        }
    }
?>
  <div class="page-card">
    <div class="card-meta">
      <span class="font-weight-bold"><code><?= htmlspecialchars($r['action']) ?></code></span>
      &mdash; <?= htmlspecialchars($r['user_login'] ?? 'anonymous') ?>
      <span class="text-muted float-right"><?= htmlspecialchars($r['created_at']) ?></span>
    </div>
    <?php if ($entityLabel !== ''): ?>
      <div class="card-detail small"><?= $entityLabel ?></div>
    <?php endif; ?>
    <?php if ($detailsJson !== null): ?>
      <div class="card-detail">
        <button class="btn btn-link btn-sm p-0" data-toggle="collapse"
                data-target="#<?= $detailId ?>"
                aria-expanded="false" aria-controls="<?= $detailId ?>">&#9654; Details</button>
        <div id="<?= $detailId ?>" class="collapse">
          <pre class="small mb-0 mt-1" style="white-space:pre-wrap;word-break:break-all;"><?= htmlspecialchars($detailsJson) ?></pre>
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<?php if ($totalPages > 1): ?>
<nav aria-label="Audit log pagination" class="mt-3">
  <ul class="pagination pagination-sm">
    <?php if ($page > 1): ?>
      <li class="page-item">
        <a class="page-link" href="?page=<?= $page - 1 ?><?= audit_filter_qs() ?>">&laquo;</a>
      </li>
    <?php else: ?>
      <li class="page-item disabled"><span class="page-link">&laquo;</span></li>
    <?php endif; ?>

    <?php
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    if ($start > 1) {
        echo '<li class="page-item"><a class="page-link" href="?page=1' . audit_filter_qs() . '">1</a></li>';
        if ($start > 2) {
            echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $page ? ' active' : '';
        echo '<li class="page-item' . $active . '">'
           . '<a class="page-link" href="?page=' . $i . audit_filter_qs() . '">' . $i . '</a></li>';
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
        echo '<li class="page-item"><a class="page-link" href="?page=' . $totalPages . audit_filter_qs() . '">' . $totalPages . '</a></li>';
    }
    ?>

    <?php if ($page < $totalPages): ?>
      <li class="page-item">
        <a class="page-link" href="?page=<?= $page + 1 ?><?= audit_filter_qs() ?>">&raquo;</a>
      </li>
    <?php else: ?>
      <li class="page-item disabled"><span class="page-link">&raquo;</span></li>
    <?php endif; ?>
  </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
document.addEventListener('click', function(e) {
  if (e.target.closest('[data-print]')) window.print();
});
</script>
<?= print_trailer() ?>
