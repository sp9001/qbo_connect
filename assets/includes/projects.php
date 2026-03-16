<?php
/**
 * QBO Connect - Projects Browse Page
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
  if (Input::get('action') === 'sync_projects') {
    $result = qbo_sync_projects();
    if (isset($result['error'])) {
      $syncError = 'Sync failed: ' . htmlspecialchars($result['error']);
    } else {
      $syncMessage = 'Sync complete! ' . $result['count'] . ' project(s) synced.';
    }
  }
}

$projects = qbo_get_local_projects();
$lastSync = qbo_get_last_sync('Project');
?>
    <a href="<?= htmlspecialchars($configUrl) ?>" class="btn btn-sm btn-outline-secondary mt-4 mb-3">
      <i class="fa fa-arrow-left"></i> Back to QBO Settings
    </a>

    <h2><i class="fa fa-project-diagram"></i> QBO Projects</h2>

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
        <input type="hidden" name="action" value="sync_projects">
        <button type="submit" class="btn btn-success" onclick="this.innerHTML='<i class=\'fa fa-spinner fa-spin\'></i> Syncing...';this.disabled=true;this.form.submit();">
          <i class="fa fa-sync"></i> Sync from QBO
        </button>
      </form>
    </div>

    <?php if (count($projects) > 0) { ?>
    <div class="card">
      <div class="card-body table-responsive">
        <table id="projectsTable" class="table table-striped table-hover table-bordered">
          <thead>
            <tr>
              <th>QBO ID</th>
              <th>Name</th>
              <th>Customer</th>
              <th>Description</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($projects as $proj) { ?>
            <tr>
              <td><?= htmlspecialchars($proj->qbo_id) ?></td>
              <td><?= htmlspecialchars($proj->name) ?></td>
              <td>
                <?php if (!empty($proj->customer_id)) { ?>
                  <a href="<?= $us_url_root ?>usersc/plugins/qbo_connect/assets/includes/customer_detail.php?id=<?= htmlspecialchars($proj->customer_id) ?>">
                    <?= htmlspecialchars($proj->customer_name ?: 'Customer #' . $proj->customer_id) ?>
                  </a>
                <?php } else { ?>
                  <span class="text-muted">-</span>
                <?php } ?>
              </td>
              <td><?= htmlspecialchars(mb_strimwidth($proj->description, 0, 100, '...')) ?></td>
              <td>
                <?php
                  $badge = 'secondary';
                  if ($proj->status === 'InProgress') $badge = 'primary';
                  elseif ($proj->status === 'Complete') $badge = 'success';
                ?>
                <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($proj->status) ?></span>
              </td>
            </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php } else { ?>
      <div class="alert alert-info">No projects cached locally. Click "Sync from QBO" to pull project data.</div>
    <?php } ?>

<script src="<?= $us_url_root ?>users/js/pagination/datatables.min.js"></script>
<script>
  $(document).ready(function(){
    $('#projectsTable').DataTable({
      "pageLength": 25,
      "stateSave": true,
      "order": [[1, "asc"]],
      "aLengthMenu": [[25, 50, 100, -1], [25, 50, 100, "All"]]
    });
  });
</script>
<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
