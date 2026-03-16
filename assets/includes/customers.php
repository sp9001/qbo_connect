<?php
/**
 * QBO Connect - Customers Browse Page
 * Displays locally cached customers with DataTables and sync capability.
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

// Handle sync POST
if (!empty($_POST)) {
  if (!Token::check(Input::get('csrf'))) {
    include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
  }
  if (Input::get('action') === 'sync_customers') {
    $result = qbo_sync_customers();
    if (isset($result['error'])) {
      $syncError = 'Sync failed: ' . htmlspecialchars($result['error']);
    } else {
      $syncMessage = 'Sync complete! ' . $result['count'] . ' customer(s) synced.';
    }
  }
}

$customers = qbo_get_local_customers();
$lastSync = qbo_get_last_sync('Customer');
?>
    <a href="<?= htmlspecialchars($configUrl) ?>" class="btn btn-sm btn-outline-secondary mt-4 mb-3">
      <i class="fa fa-arrow-left"></i> Back to QBO Settings
    </a>

    <h2><i class="fa fa-users"></i> QBO Customers</h2>

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
        <input type="hidden" name="action" value="sync_customers">
        <button type="submit" class="btn btn-success" onclick="this.innerHTML='<i class=\'fa fa-spinner fa-spin\'></i> Syncing...';this.disabled=true;this.form.submit();">
          <i class="fa fa-sync"></i> Sync from QBO
        </button>
      </form>
    </div>

    <?php if (count($customers) > 0) { ?>
    <div class="card">
      <div class="card-body table-responsive">
        <table id="customersTable" class="table table-striped table-hover table-bordered">
          <thead>
            <tr>
              <th>QBO ID</th>
              <th>Display Name</th>
              <th>Company</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Balance</th>
              <th>Active</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($customers as $c) { ?>
            <tr>
              <td><?= htmlspecialchars($c->qbo_id) ?></td>
              <td><?= htmlspecialchars($c->display_name) ?></td>
              <td><?= htmlspecialchars($c->company_name) ?></td>
              <td><?= htmlspecialchars($c->email) ?></td>
              <td><?= htmlspecialchars($c->phone) ?></td>
              <td>$<?= number_format($c->balance, 2) ?></td>
              <td><?= $c->active ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>' ?></td>
              <td>
                <a href="<?= $us_url_root ?>usersc/plugins/qbo_connect/assets/includes/customer_detail.php?id=<?= htmlspecialchars($c->qbo_id) ?>" class="btn btn-outline-primary btn-sm py-0 px-1" title="View full details">
                  <i class="fa fa-search"></i>
                </a>
              </td>
            </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php } else { ?>
      <div class="alert alert-info">No customers cached locally. Click "Sync from QBO" to pull customer data.</div>
    <?php } ?>

<script src="<?= $us_url_root ?>users/js/pagination/datatables.min.js"></script>
<script>
  $(document).ready(function(){
    $('#customersTable').DataTable({
      "pageLength": 25,
      "stateSave": true,
      "order": [[1, "asc"]],
      "aLengthMenu": [[25, 50, 100, -1], [25, 50, 100, "All"]],
      "columnDefs": [
        { "width": "40px", "orderable": false, "targets": 7 }
      ]
    });
  });
</script>
<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
