<?php
/**
 * QBO Connect - Invoices Browse Page
 */
require_once '../../../../../users/init.php';

include_once $abs_us_root . $us_url_root . 'usersc/plugins/qbo_connect/functions.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!$user->isLoggedIn() || !in_array($user->data()->id, $master_account)) {
  Redirect::to($us_url_root . 'users/admin.php');
  die();
}

$configUrl = $us_url_root . 'users/admin.php?view=plugins_config&plugin=qbo_connect';

if (!qbo_is_connected()) {
  usError('Not connected to QuickBooks Online. Please connect first.');
  Redirect::to($configUrl);
  die();
}

$syncMessage = '';
$syncError = '';

if (!empty($_POST)) {
  if (!Token::check(Input::get('csrf'))) {
    include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
  }
  if (Input::get('action') === 'sync_invoices') {
    $result = qbo_sync_invoices();
    if (isset($result['error'])) {
      $syncError = 'Sync failed: ' . htmlspecialchars($result['error']);
    } else {
      $syncMessage = 'Sync complete! ' . $result['count'] . ' invoice(s) synced.';
    }
  }
}

$invoices = qbo_get_local_invoices();
$lastSync = qbo_get_last_sync('Invoice');
?>
    <a href="<?= htmlspecialchars($configUrl) ?>" class="btn btn-sm btn-outline-secondary mt-4 mb-3">
      <i class="fa fa-arrow-left"></i> Back to QBO Settings
    </a>

    <h2><i class="fa fa-file-invoice"></i> QBO Invoices</h2>

    <?php if ($syncMessage) { ?>
      <div class="alert alert-success"><?= $syncMessage ?></div>
    <?php } ?>
    <?php if ($syncError) { ?>
      <div class="alert alert-danger"><?= $syncError ?></div>
    <?php } ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <?php if ($lastSync) { ?>
          <span class="text-muted">Last synced: <?= htmlspecialchars($lastSync->last_sync_at) ?> (<?= $lastSync->records_synced ?> records)</span>
        <?php } else { ?>
          <span class="text-muted">Never synced</span>
        <?php } ?>
      </div>
      <form method="POST" class="d-inline">
        <?= tokenHere(); ?>
        <input type="hidden" name="action" value="sync_invoices">
        <button type="submit" class="btn btn-success" onclick="this.innerHTML='<i class=\'fa fa-spinner fa-spin\'></i> Syncing...';this.disabled=true;this.form.submit();">
          <i class="fa fa-sync"></i> Sync from QBO
        </button>
      </form>
    </div>

    <?php if (count($invoices) > 0) { ?>
    <div class="card">
      <div class="card-body table-responsive">
        <table id="invoicesTable" class="table table-striped table-hover table-bordered w-100">
          <thead>
            <tr>
              <th>Doc #</th>
              <th>Customer</th>
              <th>Date</th>
              <th>Due Date</th>
              <th>Total</th>
              <th>Balance</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($invoices as $inv) { ?>
            <tr>
              <td><?= htmlspecialchars($inv->doc_number) ?></td>
              <td><?= htmlspecialchars($inv->customer_name) ?></td>
              <td><?= htmlspecialchars($inv->txn_date) ?></td>
              <td><?= htmlspecialchars($inv->due_date) ?></td>
              <td>$<?= number_format($inv->total_amt, 2) ?></td>
              <td>$<?= number_format($inv->balance, 2) ?></td>
              <td>
                <?php
                  $badge = 'secondary';
                  if ($inv->status === 'Paid') $badge = 'success';
                  elseif ($inv->status === 'Overdue') $badge = 'danger';
                  elseif ($inv->status === 'Open') $badge = 'warning';
                ?>
                <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($inv->status) ?></span>
              </td>
            </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php } else { ?>
      <div class="alert alert-info">No invoices cached locally. Click "Sync from QBO" to pull invoice data.</div>
    <?php } ?>

<script src="<?= $us_url_root ?>users/js/pagination/datatables.min.js"></script>
<script>
  $(document).ready(function(){
    $('#invoicesTable').DataTable({
      "pageLength": 25,
      "stateSave": true,
      "autoWidth": true,
      "order": [[2, "desc"]],
      "aLengthMenu": [[25, 50, 100, -1], [25, 50, 100, "All"]]
    });
  });
</script>
<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
