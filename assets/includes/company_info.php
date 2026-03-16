<?php
/**
 * QBO Connect - Company Info Page
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
  if (Input::get('action') === 'sync_company_info') {
    $result = qbo_sync_company_info();
    if (isset($result['error'])) {
      $syncError = 'Sync failed: ' . htmlspecialchars($result['error']);
    } else {
      $syncMessage = 'Company info synced successfully!';
    }
  }
}

$companyInfo = qbo_get_company_info();
$lastSync = qbo_get_last_sync('CompanyInfo');
?>
    <a href="<?= htmlspecialchars($configUrl) ?>" class="btn btn-sm btn-outline-secondary mt-4 mb-3">
      <i class="fa fa-arrow-left"></i> Back to QBO Settings
    </a>

    <h2><i class="fa fa-building"></i> QBO Company Info</h2>

    <?php if ($syncMessage) { ?>
      <div class="alert alert-success"><?= $syncMessage ?></div>
    <?php } ?>
    <?php if ($syncError) { ?>
      <div class="alert alert-danger"><?= $syncError ?></div>
    <?php } ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <?php if ($lastSync) { ?>
          <span class="text-muted">Last synced: <?= htmlspecialchars($lastSync->last_sync_at) ?></span>
        <?php } else { ?>
          <span class="text-muted">Never synced</span>
        <?php } ?>
      </div>
      <form method="POST" class="d-inline">
        <?= tokenHere(); ?>
        <input type="hidden" name="action" value="sync_company_info">
        <button type="submit" class="btn btn-success" onclick="this.innerHTML='<i class=\'fa fa-spinner fa-spin\'></i> Syncing...';this.disabled=true;this.form.submit();">
          <i class="fa fa-sync"></i> Sync from QBO
        </button>
      </form>
    </div>

    <?php if ($companyInfo) { ?>
    <div class="card">
      <div class="card-header">
        <h3 class="h5 mb-0"><?= htmlspecialchars($companyInfo->company_name) ?></h3>
      </div>
      <div class="card-body">
        <table class="table table-bordered mb-0">
          <tbody>
            <tr><th style="width:200px">QBO ID</th><td><?= htmlspecialchars($companyInfo->qbo_id) ?></td></tr>
            <tr><th>Company Name</th><td><?= htmlspecialchars($companyInfo->company_name) ?></td></tr>
            <tr><th>Legal Name</th><td><?= htmlspecialchars($companyInfo->legal_name) ?></td></tr>
            <tr><th>Email</th><td><?= htmlspecialchars($companyInfo->email) ?></td></tr>
            <tr><th>Phone</th><td><?= htmlspecialchars($companyInfo->phone) ?></td></tr>
            <tr><th>Address</th><td>
              <?= htmlspecialchars($companyInfo->address_line1) ?><br>
              <?= htmlspecialchars($companyInfo->address_city) ?>, <?= htmlspecialchars($companyInfo->address_state) ?> <?= htmlspecialchars($companyInfo->address_postal) ?><br>
              <?= htmlspecialchars($companyInfo->address_country) ?>
            </td></tr>
            <tr><th>Industry</th><td><?= htmlspecialchars($companyInfo->industry) ?></td></tr>
            <tr><th>Fiscal Year Start</th><td><?= htmlspecialchars($companyInfo->fiscal_year_start) ?></td></tr>
            <tr><th>Last Synced</th><td><?= htmlspecialchars($companyInfo->synced_at) ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <?php } else { ?>
      <div class="alert alert-info">No company info cached locally. Click "Sync from QBO" to pull company data.</div>
    <?php } ?>
  </div>
<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>