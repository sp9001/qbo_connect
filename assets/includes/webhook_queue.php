<?php
/**
 * QBO Connect - Webhook Queue Browse Page
 */
require_once '../../../../../users/init.php';

include_once $abs_us_root . $us_url_root . 'usersc/plugins/qbo_connect/functions.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!$user->isLoggedIn() || !in_array($user->data()->id, $master_account)) {
  Redirect::to($us_url_root . 'users/admin.php');
  die();
}

$configUrl = $us_url_root . 'users/admin.php?view=plugins_config&plugin=qbo_connect';

$actionMessage = '';
$actionError = '';

if (!empty($_POST)) {
  if (!Token::check(Input::get('csrf'))) {
    include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
  }

  // Retry a single failed item
  if (Input::get('action') === 'retry_item') {
    $itemId = intval(Input::get('item_id'));
    $db->query("UPDATE plg_qbo_webhook_queue SET status = 'pending', attempts = 0, error_message = NULL WHERE id = ?", [$itemId]);
    $actionMessage = "Item #$itemId reset to pending for retry.";
  }

  // Retry all failed items
  if (Input::get('action') === 'retry_all_failed') {
    $db->query("UPDATE plg_qbo_webhook_queue SET status = 'pending', attempts = 0, error_message = NULL WHERE status = 'failed'");
    $actionMessage = "All failed items reset to pending.";
  }

  // Clear completed items
  if (Input::get('action') === 'clear_completed') {
    $db->query("DELETE FROM plg_qbo_webhook_queue WHERE status IN ('complete','skipped')");
    $actionMessage = "Completed and skipped items cleared.";
  }

  // Manual pull for a single item
  if (Input::get('action') === 'pull_item') {
    $itemId = intval(Input::get('item_id'));
    $itemResult = $db->query("SELECT * FROM plg_qbo_webhook_queue WHERE id = ? LIMIT 1", [$itemId]);
    if ($itemResult->count() > 0) {
      $pullItem = $itemResult->first();
      $now = date('Y-m-d H:i:s');

      // Include the cron handler functions
      require_once __DIR__ . '/webhook_cron_functions.php';

      // Reset attempts for manual pull so it gets a fresh try
      $db->query("UPDATE plg_qbo_webhook_queue SET status = 'processing', attempts = 1, last_attempt_at = ? WHERE id = ?", [$now, $itemId]);

      if (in_array($pullItem->operation, ['Delete', 'Void'])) {
        $pullResult = qbo_webhook_delete_entity($pullItem->entity_type, $pullItem->entity_id);
        if ($pullResult) {
          $db->query("UPDATE plg_qbo_webhook_queue SET status = 'complete', processed_at = ?, error_message = NULL WHERE id = ?", [$now, $itemId]);
          $actionMessage = "Item #$itemId: {$pullItem->entity_type} #{$pullItem->entity_id} deleted locally.";
        } else {
          $db->query("UPDATE plg_qbo_webhook_queue SET status = 'skipped', processed_at = ?, error_message = ? WHERE id = ?", [$now, "Entity type not tracked locally", $itemId]);
          $actionError = "Item #$itemId: entity type '{$pullItem->entity_type}' not tracked locally.";
        }
      } else {
        $entityMap = [
          'Customer'    => 'qbo_process_webhook_customer',
          'Invoice'     => 'qbo_process_webhook_invoice',
          'Estimate'    => 'qbo_process_webhook_estimate',
          'CompanyInfo' => 'qbo_process_webhook_company_info',
        ];

        if (!isset($entityMap[$pullItem->entity_type])) {
          $db->query("UPDATE plg_qbo_webhook_queue SET status = 'skipped', processed_at = ?, error_message = ? WHERE id = ?", [$now, "Entity type not supported", $itemId]);
          $actionError = "Item #$itemId: entity type '{$pullItem->entity_type}' not supported for sync.";
        } else {
          $handler = $entityMap[$pullItem->entity_type];
          $handlerResult = $handler($pullItem->entity_id, $pullItem->realm_id);

          if (isset($handlerResult['error'])) {
            $db->query("UPDATE plg_qbo_webhook_queue SET status = 'failed', error_message = ? WHERE id = ?", [$handlerResult['error'], $itemId]);
            $actionError = "Item #$itemId pull failed: " . htmlspecialchars($handlerResult['error']);
          } else {
            $db->query("UPDATE plg_qbo_webhook_queue SET status = 'complete', processed_at = ?, error_message = NULL WHERE id = ?", [$now, $itemId]);
            $actionMessage = "Item #$itemId: {$pullItem->entity_type} #{$pullItem->entity_id} pulled successfully.";
          }
        }
      }
    } else {
      $actionError = "Queue item #$itemId not found.";
    }
  }
}

// Get queue stats
$stats = [];
$statuses = ['pending', 'processing', 'complete', 'failed', 'skipped'];
foreach ($statuses as $s) {
  try {
    $r = @$db->query("SELECT COUNT(*) as cnt FROM plg_qbo_webhook_queue WHERE status = ?", [$s]);
    $stats[$s] = ($r && $r->count() > 0) ? $r->first()->cnt : 0;
  } catch (Exception $e) {
    $stats[$s] = 0;
  }
}

// Get queue items (most recent first)
try {
  $queueResult = @$db->query("SELECT * FROM plg_qbo_webhook_queue ORDER BY received_at DESC LIMIT 500");
  $queueItems = ($queueResult && $queueResult->count() > 0) ? $queueResult->results() : [];
} catch (Exception $e) {
  $queueItems = [];
}
?>
    <a href="<?= htmlspecialchars($configUrl) ?>" class="btn btn-sm btn-outline-secondary mt-4 mb-3">
      <i class="fa fa-arrow-left"></i> Back to QBO Settings
    </a>

    <h2><i class="fa fa-exchange-alt"></i> Webhook Queue</h2>

    <?php if ($actionMessage) { ?>
      <div class="alert alert-success"><?= $actionMessage ?></div>
    <?php } ?>
    <?php if ($actionError) { ?>
      <div class="alert alert-danger"><?= $actionError ?></div>
    <?php } ?>

    <!-- Queue Stats -->
    <div class="row mb-3">
      <div class="col">
        <div class="card text-center">
          <div class="card-body py-2">
            <span class="badge bg-warning text-dark fs-6"><?= $stats['pending'] ?></span>
            <div class="small text-muted">Pending</div>
          </div>
        </div>
      </div>
      <div class="col">
        <div class="card text-center">
          <div class="card-body py-2">
            <span class="badge bg-info fs-6"><?= $stats['processing'] ?></span>
            <div class="small text-muted">Processing</div>
          </div>
        </div>
      </div>
      <div class="col">
        <div class="card text-center">
          <div class="card-body py-2">
            <span class="badge bg-success fs-6"><?= $stats['complete'] ?></span>
            <div class="small text-muted">Complete</div>
          </div>
        </div>
      </div>
      <div class="col">
        <div class="card text-center">
          <div class="card-body py-2">
            <span class="badge bg-danger fs-6"><?= $stats['failed'] ?></span>
            <div class="small text-muted">Failed</div>
          </div>
        </div>
      </div>
      <div class="col">
        <div class="card text-center">
          <div class="card-body py-2">
            <span class="badge bg-secondary fs-6"><?= $stats['skipped'] ?></span>
            <div class="small text-muted">Skipped</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Action Buttons -->
    <div class="d-flex gap-2 mb-3">
      <?php if ($stats['failed'] > 0) { ?>
      <form method="POST" class="d-inline">
        <?= tokenHere(); ?>
        <input type="hidden" name="action" value="retry_all_failed">
        <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Reset all failed items to pending?')">
          <i class="fa fa-redo"></i> Retry All Failed
        </button>
      </form>
      <?php } ?>
      <?php if ($stats['complete'] > 0 || $stats['skipped'] > 0) { ?>
      <form method="POST" class="d-inline">
        <?= tokenHere(); ?>
        <input type="hidden" name="action" value="clear_completed">
        <button type="submit" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Remove all completed and skipped items from the queue?')">
          <i class="fa fa-broom"></i> Clear Completed
        </button>
      </form>
      <?php } ?>
    </div>

    <?php if (count($queueItems) > 0) { ?>
    <div class="card">
      <div class="card-body table-responsive">
        <table id="webhookQueueTable" class="table table-striped table-hover table-bordered w-100">
          <thead>
            <tr>
              <th>ID</th>
              <th>Entity</th>
              <th>Entity ID</th>
              <th>Operation</th>
              <th>Status</th>
              <th>Attempts</th>
              <th>Received</th>
              <th>Processed</th>
              <th>Error</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $supportedEntities = ['Customer', 'Invoice', 'Estimate', 'CompanyInfo'];
            foreach ($queueItems as $item) {
              $isSupported = in_array($item->entity_type, $supportedEntities);
              $rowClass = !$isSupported ? ' class="text-muted opacity-50"' : '';
            ?>
            <tr<?= $rowClass ?>>
              <td><?= htmlspecialchars($item->id) ?></td>
              <td><?= htmlspecialchars($item->entity_type) ?><?php if (!$isSupported) { ?> <small>(unsupported)</small><?php } ?></td>
              <td><?= htmlspecialchars($item->entity_id) ?></td>
              <td>
                <?php
                  $opBadge = 'secondary';
                  if ($item->operation === 'Create') $opBadge = 'success';
                  elseif ($item->operation === 'Update') $opBadge = 'info';
                  elseif ($item->operation === 'Delete') $opBadge = 'danger';
                  elseif ($item->operation === 'Void') $opBadge = 'dark';
                  elseif ($item->operation === 'Merge') $opBadge = 'warning';
                ?>
                <span class="badge bg-<?= $opBadge ?>"><?= htmlspecialchars($item->operation) ?></span>
              </td>
              <td>
                <?php
                  $statusBadge = 'secondary';
                  if ($item->status === 'pending') $statusBadge = 'warning';
                  elseif ($item->status === 'processing') $statusBadge = 'info';
                  elseif ($item->status === 'complete') $statusBadge = 'success';
                  elseif ($item->status === 'failed') $statusBadge = 'danger';
                  elseif ($item->status === 'skipped') $statusBadge = 'secondary';
                ?>
                <span class="badge bg-<?= $statusBadge ?>"><?= htmlspecialchars($item->status) ?></span>
              </td>
              <td><?= htmlspecialchars($item->attempts) ?>/<?= htmlspecialchars($item->max_attempts) ?></td>
              <td><?= htmlspecialchars($item->received_at) ?></td>
              <td><?= $item->processed_at ? htmlspecialchars($item->processed_at) : '<span class="text-muted">-</span>' ?></td>
              <td>
                <?php if ($item->error_message) { ?>
                  <span class="text-danger" title="<?= htmlspecialchars($item->error_message) ?>" style="cursor:help;">
                    <?= htmlspecialchars(mb_strimwidth($item->error_message, 0, 60, '...')) ?>
                  </span>
                <?php } else { ?>
                  <span class="text-muted">-</span>
                <?php } ?>
              </td>
              <td class="text-nowrap">
                <?php if ($isSupported && !in_array($item->status, ['complete', 'skipped'])) { ?>
                <form method="POST" class="d-inline">
                  <?= tokenHere(); ?>
                  <input type="hidden" name="action" value="pull_item">
                  <input type="hidden" name="item_id" value="<?= $item->id ?>">
                  <button type="submit" class="btn btn-outline-primary btn-sm py-0 px-1" title="Pull from QBO now">
                    <i class="fa fa-download"></i>
                  </button>
                </form>
                <?php } ?>
                <?php if ($item->status === 'failed') { ?>
                <form method="POST" class="d-inline">
                  <?= tokenHere(); ?>
                  <input type="hidden" name="action" value="retry_item">
                  <input type="hidden" name="item_id" value="<?= $item->id ?>">
                  <button type="submit" class="btn btn-outline-warning btn-sm py-0 px-1" title="Reset to pending">
                    <i class="fa fa-redo"></i>
                  </button>
                </form>
                <?php } ?>
              </td>
            </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php } else { ?>
      <div class="alert alert-info">No webhook events in the queue. Events will appear here when QBO sends webhook notifications.</div>
    <?php } ?>

<script src="<?= $us_url_root ?>users/js/pagination/datatables.min.js"></script>
<script>
  $(document).ready(function(){
    $('#webhookQueueTable').DataTable({
      "pageLength": 25,
      "stateSave": true,
      "order": [[0, "desc"]],
      "autoWidth": true,
      "aLengthMenu": [[25, 50, 100, -1], [25, 50, 100, "All"]],
      "columnDefs": [
        { "width": "50px", "targets": 0 },
        { "width": "40px", "targets": 5 },
        { "width": "60px", "targets": 9, "orderable": false }
      ]
    });
  });
</script>
<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
