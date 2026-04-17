<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_role('admin');

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\WebhookLogRepository;

$db = new DbiAdapter();
$repo = new WebhookLogRepository($db);

$perPage = 50;
$page = max(1, (int) (getGetValue('page') ?? 1));

$filters = [
    'success' => trim((string) (getGetValue('success') ?? '')),
    'http_status' => trim((string) (getGetValue('http_status') ?? '')),
    'date_from' => trim((string) (getGetValue('date_from') ?? '')),
    'date_to' => trim((string) (getGetValue('date_to') ?? '')),
];
$filters = array_filter($filters, static fn(string $v): bool => $v !== '');

$total = $repo->count($filters);
$rows = $repo->search($filters, $page, $perPage);
$totalPages = (int) ceil($total / $perPage);

$statuses = $repo->getDistinctStatuses();

function webhook_filter_qs(array $overrides = []): string
{
    $params = array_merge([
        'success' => getGetValue('success') ?? '',
        'http_status' => getGetValue('http_status') ?? '',
        'date_from' => getGetValue('date_from') ?? '',
        'date_to' => getGetValue('date_to') ?? '',
    ], $overrides);
    $parts = [];
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null) {
            $parts[] = urlencode($k) . '=' . urlencode((string) $v);
        }
    }

    return $parts === [] ? '' : '&' . implode('&', $parts);
}

print_header();
?>

<div class="page-sticky-header noprint">
  <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap">
    <h5 class="page-title mb-0">Webhook Delivery Log</h5>
    <div class="page-actions">
      <button class="btn btn-sm btn-outline-secondary" data-print>Print</button>
    </div>
  </div>
</div>

<form method="get" class="mb-3 mt-2 noprint">
  <div class="form-row align-items-end">
    <div class="col-md-2 mb-2">
      <label class="small" for="f_success">Result</label>
      <select name="success" id="f_success" class="form-control form-control-sm">
        <option value="">-- all --</option>
        <option value="1" <?= (getGetValue('success') ?? '') === '1' ? 'selected' : '' ?>>Success</option>
        <option value="0" <?= (getGetValue('success') ?? '') === '0' ? 'selected' : '' ?>>Failed</option>
      </select>
    </div>
    <div class="col-md-2 mb-2">
      <label class="small" for="f_status">HTTP Status</label>
      <select name="http_status" id="f_status" class="form-control form-control-sm">
        <option value="">-- all --</option>
        <?php foreach ($statuses as $s): ?>
          <option value="<?= $s ?>" <?= (getGetValue('http_status') ?? '') === (string) $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 mb-2">
      <label class="small" for="f_from">From</label>
      <input type="date" name="date_from" id="f_from" class="form-control form-control-sm"
             value="<?= htmlspecialchars(getGetValue('date_from') ?? '') ?>">
    </div>
    <div class="col-md-2 mb-2">
      <label class="small" for="f_to">To</label>
      <input type="date" name="date_to" id="f_to" class="form-control form-control-sm"
             value="<?= htmlspecialchars(getGetValue('date_to') ?? '') ?>">
    </div>
    <div class="col-md-2 mb-2">
      <button type="submit" class="btn btn-sm btn-primary mr-1">Filter</button>
      <a href="webhook_log.php" class="btn btn-sm btn-outline-secondary">Reset</a>
    </div>
  </div>
</form>

<?php
$startRow = ($page - 1) * $perPage + 1;
$endRow = min($page * $perPage, $total);
?>
<p class="text-muted small mb-2">
  Showing <?= $startRow ?>–<?= $endRow ?> of <?= $total ?>
</p>

<?php if (empty($rows)): ?>
  <p class="text-muted">No webhook deliveries recorded yet.</p>
<?php else: ?>

<!-- Desktop table -->
<div class="table-responsive d-none d-md-block">
  <table class="table table-sm table-bordered table-hover">
    <thead class="thead-light">
      <tr>
        <th style="width:12%;">Time</th>
        <th style="width:8%;">Result</th>
        <th style="width:6%;">Attempt</th>
        <th style="width:6%;">Status</th>
        <th style="width:8%;">Elapsed</th>
        <th style="width:20%;">Message ID</th>
        <th>Error / Payload</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $i => $row): ?>
        <?php
          $badgeClass = $row['success'] ? 'badge-success' : 'badge-danger';
          $badgeLabel = $row['success'] ? 'OK' : 'FAIL';
          $statusClass = '';
          if ($row['http_status'] !== null) {
              if ($row['http_status'] >= 200 && $row['http_status'] < 300) {
                  $statusClass = 'text-success';
              } elseif ($row['http_status'] >= 400 && $row['http_status'] < 500) {
                  $statusClass = 'text-warning';
              } elseif ($row['http_status'] >= 500) {
                  $statusClass = 'text-danger';
              }
          }
          $collapseId = 'detail-' . $row['id'];
        ?>
        <tr>
          <td class="small"><?= htmlspecialchars($row['created_at']) ?></td>
          <td><span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
          <td class="text-center"><?= $row['attempt'] ?>/<?= $row['max_attempts'] ?></td>
          <td class="<?= $statusClass ?>"><?= $row['http_status'] !== null ? $row['http_status'] : '—' ?></td>
          <td class="text-right"><?= $row['elapsed_ms'] !== null ? number_format($row['elapsed_ms']) . 'ms' : '—' ?></td>
          <td class="small text-monospace"><?= htmlspecialchars(substr($row['message_id'], 0, 24)) ?></td>
          <td>
            <?php if ($row['error_message']): ?>
              <span class="text-danger small"><?= htmlspecialchars($row['error_message']) ?></span>
            <?php endif; ?>
            <a class="small" data-toggle="collapse" href="#<?= $collapseId ?>" role="button">payload</a>
            <div class="collapse" id="<?= $collapseId ?>">
              <pre class="small bg-light p-2 mt-1 mb-0" style="max-height:200px;overflow:auto;"><?php
                $decoded = json_decode($row['request_body'], true);
                echo htmlspecialchars($decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $row['request_body']);
              ?></pre>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Mobile cards -->
<div class="d-md-none">
  <?php foreach ($rows as $row): ?>
    <?php
      $badgeClass = $row['success'] ? 'badge-success' : 'badge-danger';
      $badgeLabel = $row['success'] ? 'OK' : 'FAIL';
      $mCollapseId = 'mdetail-' . $row['id'];
    ?>
    <div class="card mb-2">
      <div class="card-body p-2">
        <div class="d-flex justify-content-between align-items-center">
          <span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
          <span class="small text-muted"><?= htmlspecialchars($row['created_at']) ?></span>
        </div>
        <div class="small mt-1">
          Attempt <?= $row['attempt'] ?>/<?= $row['max_attempts'] ?>
          <?php if ($row['http_status'] !== null): ?> · HTTP <?= $row['http_status'] ?><?php endif; ?>
          <?php if ($row['elapsed_ms'] !== null): ?> · <?= number_format($row['elapsed_ms']) ?>ms<?php endif; ?>
        </div>
        <?php if ($row['error_message']): ?>
          <div class="small text-danger"><?= htmlspecialchars($row['error_message']) ?></div>
        <?php endif; ?>
        <a class="small" data-toggle="collapse" href="#<?= $mCollapseId ?>">payload</a>
        <div class="collapse" id="<?= $mCollapseId ?>">
          <pre class="small bg-light p-2 mt-1 mb-0" style="max-height:150px;overflow:auto;"><?php
            $decoded = json_decode($row['request_body'], true);
            echo htmlspecialchars($decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $row['request_body']);
          ?></pre>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<?php if ($totalPages > 1): ?>
<nav aria-label="Webhook log pagination" class="mt-3">
  <ul class="pagination pagination-sm justify-content-center flex-wrap">
    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
      <a class="page-link" href="?page=<?= $page - 1 ?><?= webhook_filter_qs() ?>">Previous</a>
    </li>
    <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>">
        <a class="page-link" href="?page=<?= $p ?><?= webhook_filter_qs() ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>
    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
      <a class="page-link" href="?page=<?= $page + 1 ?><?= webhook_filter_qs() ?>">Next</a>
    </li>
  </ul>
</nav>
<?php endif; ?>

<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
document.addEventListener('click', function(e) {
  if (e.target.closest('[data-print]')) window.print();
});
</script>

<?php echo print_trailer(); ?>
