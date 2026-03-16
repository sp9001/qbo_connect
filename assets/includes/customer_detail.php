<?php
/**
 * QBO Connect - Customer Detail / Diagnostic Page
 * Fetches and displays ALL raw data from the QBO API for a single customer.
 */
require_once '../../../../../users/init.php';

include_once $abs_us_root . $us_url_root . 'usersc/plugins/qbo_connect/functions.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!$user->isLoggedIn() || !in_array($user->data()->id, $master_account)) {
  Redirect::to($us_url_root . 'users/admin.php');
  die();
}

$customersUrl = $us_url_root . 'usersc/plugins/qbo_connect/assets/includes/customers.php';

if (!qbo_is_connected()) {
  usError('Not connected to QuickBooks Online. Please connect first.');
  Redirect::to($us_url_root . 'users/admin.php?view=plugins_config&plugin=qbo_connect');
  die();
}

$qboId = isset($_GET['id']) ? trim($_GET['id']) : '';
if (empty($qboId)) {
  usError('No customer ID specified.');
  Redirect::to($customersUrl);
  die();
}

// Fetch live data from QBO API
$result = qbo_api_request("/v3/company/{realmId}/customer/$qboId");

$error = '';
$customer = null;
if (isset($result['error'])) {
  $error = $result['error'];
} elseif (isset($result['fault'])) {
  $error = isset($result['fault']['error'][0]['message']) ? $result['fault']['error'][0]['message'] : 'QBO API fault';
} elseif (isset($result['Customer'])) {
  $customer = $result['Customer'];
} else {
  $error = 'Unexpected API response format.';
}

// Get local cached record for comparison
$localResult = $db->query("SELECT * FROM plg_qbo_customers WHERE qbo_id = ? LIMIT 1", [$qboId]);
$localCustomer = ($localResult && $localResult->count() > 0) ? $localResult->first() : null;
?>
    <a href="<?= htmlspecialchars($customersUrl) ?>" class="btn btn-sm btn-outline-secondary mt-4 mb-3">
      <i class="fa fa-arrow-left"></i> Back to Customers
    </a>

    <h2><i class="fa fa-user"></i> Customer Detail — #<?= htmlspecialchars($qboId) ?></h2>

    <?php if ($error) { ?>
      <div class="alert alert-danger"><strong>API Error:</strong> <?= htmlspecialchars($error) ?></div>
    <?php } ?>

    <?php if ($customer) { ?>
      <div class="row">
        <!-- Summary Card -->
        <div class="col-md-6 mb-4">
          <div class="card">
            <div class="card-header"><h5 class="mb-0">Summary</h5></div>
            <div class="card-body">
              <table class="table table-sm table-borderless mb-0">
                <?php
                $summaryFields = [
                  'Id' => 'QBO ID',
                  'DisplayName' => 'Display Name',
                  'CompanyName' => 'Company Name',
                  'GivenName' => 'First Name',
                  'FamilyName' => 'Last Name',
                  'Active' => 'Active',
                  'Balance' => 'Balance',
                  'BalanceWithJobs' => 'Balance (with Jobs)',
                  'Taxable' => 'Taxable',
                  'PrintOnCheckName' => 'Print on Check Name',
                ];
                foreach ($summaryFields as $key => $label) {
                  if (isset($customer[$key])) {
                    $val = $customer[$key];
                    if (is_bool($val)) $val = $val ? 'Yes' : 'No';
                    if ($key === 'Balance' || $key === 'BalanceWithJobs') $val = '$' . number_format((float)$val, 2);
                ?>
                <tr>
                  <td class="text-muted" style="width:40%"><?= htmlspecialchars($label) ?></td>
                  <td><strong><?= htmlspecialchars((string)$val) ?></strong></td>
                </tr>
                <?php }} ?>
              </table>
            </div>
          </div>
        </div>

        <!-- Contact Info -->
        <div class="col-md-6 mb-4">
          <div class="card">
            <div class="card-header"><h5 class="mb-0">Contact Info</h5></div>
            <div class="card-body">
              <table class="table table-sm table-borderless mb-0">
                <?php if (isset($customer['PrimaryEmailAddr']['Address'])) { ?>
                <tr><td class="text-muted" style="width:40%">Email</td><td><?= htmlspecialchars($customer['PrimaryEmailAddr']['Address']) ?></td></tr>
                <?php } ?>
                <?php if (isset($customer['PrimaryPhone']['FreeFormNumber'])) { ?>
                <tr><td class="text-muted">Phone</td><td><?= htmlspecialchars($customer['PrimaryPhone']['FreeFormNumber']) ?></td></tr>
                <?php } ?>
                <?php if (isset($customer['Mobile']['FreeFormNumber'])) { ?>
                <tr><td class="text-muted">Mobile</td><td><?= htmlspecialchars($customer['Mobile']['FreeFormNumber']) ?></td></tr>
                <?php } ?>
                <?php if (isset($customer['Fax']['FreeFormNumber'])) { ?>
                <tr><td class="text-muted">Fax</td><td><?= htmlspecialchars($customer['Fax']['FreeFormNumber']) ?></td></tr>
                <?php } ?>
                <?php if (isset($customer['AlternatePhone']['FreeFormNumber'])) { ?>
                <tr><td class="text-muted">Alt Phone</td><td><?= htmlspecialchars($customer['AlternatePhone']['FreeFormNumber']) ?></td></tr>
                <?php } ?>
                <?php if (isset($customer['WebAddr']['URI'])) { ?>
                <tr><td class="text-muted">Website</td><td><?= htmlspecialchars($customer['WebAddr']['URI']) ?></td></tr>
                <?php } ?>
              </table>

              <?php if (isset($customer['BillAddr'])) { ?>
              <h6 class="mt-3">Billing Address</h6>
              <address class="mb-0">
                <?php
                $ba = $customer['BillAddr'];
                $parts = [];
                if (!empty($ba['Line1'])) $parts[] = htmlspecialchars($ba['Line1']);
                if (!empty($ba['Line2'])) $parts[] = htmlspecialchars($ba['Line2']);
                $cityLine = [];
                if (!empty($ba['City'])) $cityLine[] = htmlspecialchars($ba['City']);
                if (!empty($ba['CountrySubDivisionCode'])) $cityLine[] = htmlspecialchars($ba['CountrySubDivisionCode']);
                if (!empty($ba['PostalCode'])) $cityLine[] = htmlspecialchars($ba['PostalCode']);
                if (!empty($cityLine)) $parts[] = implode(', ', $cityLine);
                if (!empty($ba['Country'])) $parts[] = htmlspecialchars($ba['Country']);
                echo implode('<br>', $parts);
                ?>
              </address>
              <?php } ?>

              <?php if (isset($customer['ShipAddr'])) { ?>
              <h6 class="mt-3">Shipping Address</h6>
              <address class="mb-0">
                <?php
                $sa = $customer['ShipAddr'];
                $parts = [];
                if (!empty($sa['Line1'])) $parts[] = htmlspecialchars($sa['Line1']);
                if (!empty($sa['Line2'])) $parts[] = htmlspecialchars($sa['Line2']);
                $cityLine = [];
                if (!empty($sa['City'])) $cityLine[] = htmlspecialchars($sa['City']);
                if (!empty($sa['CountrySubDivisionCode'])) $cityLine[] = htmlspecialchars($sa['CountrySubDivisionCode']);
                if (!empty($sa['PostalCode'])) $cityLine[] = htmlspecialchars($sa['PostalCode']);
                if (!empty($cityLine)) $parts[] = implode(', ', $cityLine);
                if (!empty($sa['Country'])) $parts[] = htmlspecialchars($sa['Country']);
                echo implode('<br>', $parts);
                ?>
              </address>
              <?php } ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Metadata -->
      <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Metadata</h5></div>
        <div class="card-body">
          <table class="table table-sm table-borderless mb-0">
            <?php if (isset($customer['MetaData']['CreateTime'])) { ?>
            <tr><td class="text-muted" style="width:20%">Created</td><td><?= htmlspecialchars($customer['MetaData']['CreateTime']) ?></td></tr>
            <?php } ?>
            <?php if (isset($customer['MetaData']['LastUpdatedTime'])) { ?>
            <tr><td class="text-muted">Last Updated</td><td><?= htmlspecialchars($customer['MetaData']['LastUpdatedTime']) ?></td></tr>
            <?php } ?>
            <?php if (isset($customer['SyncToken'])) { ?>
            <tr><td class="text-muted">Sync Token</td><td><?= htmlspecialchars($customer['SyncToken']) ?></td></tr>
            <?php } ?>
            <?php if (isset($customer['PreferredDeliveryMethod'])) { ?>
            <tr><td class="text-muted">Preferred Delivery</td><td><?= htmlspecialchars($customer['PreferredDeliveryMethod']) ?></td></tr>
            <?php } ?>
            <?php if (isset($customer['CurrencyRef']['value'])) { ?>
            <tr><td class="text-muted">Currency</td><td><?= htmlspecialchars($customer['CurrencyRef']['value']) ?></td></tr>
            <?php } ?>
            <?php if ($localCustomer) { ?>
            <tr><td class="text-muted">Local Cache Synced</td><td><?= htmlspecialchars($localCustomer->synced_at) ?></td></tr>
            <?php } ?>
          </table>
        </div>
      </div>

      <!-- Raw API Response -->
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Raw QBO API Response</h5>
          <button class="btn btn-sm btn-outline-secondary" onclick="copyRaw()"><i class="fa fa-copy"></i> Copy JSON</button>
        </div>
        <div class="card-body">
          <pre id="rawJson" style="max-height:500px;overflow:auto;background:#f8f9fa;padding:1rem;border-radius:.25rem;font-size:.85rem;"><?= htmlspecialchars(json_encode($customer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
      </div>
    <?php } ?>

<script>
function copyRaw() {
  var text = document.getElementById('rawJson').innerText;
  navigator.clipboard.writeText(text).then(function() {
    var btn = event.target.closest('button');
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-check"></i> Copied!';
    setTimeout(function(){ btn.innerHTML = orig; }, 2000);
  });
}
</script>
<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
