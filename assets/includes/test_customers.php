<?php
/**
 * QBO Connect - Customer List Test
 * Tests API connectivity by querying customers from QuickBooks Online.
 */
require_once '../../../../../users/init.php';

include_once $abs_us_root . $us_url_root . 'usersc/plugins/qbo_connect/functions.php';

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

// Query customers
$result = qbo_api_request('/v3/company/{realmId}/query?query=' . urlencode('SELECT * FROM Customer MAXRESULTS 25'));
?>
<!DOCTYPE html>
<html>
<head>
  <title>QBO Connect - Customer Test</title>
  <link rel="stylesheet" href="<?= $us_url_root ?>users/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= $us_url_root ?>users/css/font-awesome.min.css">
  <style>
    body { padding: 20px; }
  </style>
</head>
<body>
  <div class="container">
    <a href="<?= htmlspecialchars($configUrl) ?>" class="btn btn-sm btn-outline-secondary mb-3">
      <i class="fa fa-arrow-left"></i> Back to QBO Settings
    </a>

    <h2><i class="fa fa-users"></i> QBO Customer List Test</h2>
    <p class="text-muted">This page tests API connectivity by querying up to 25 customers from your QuickBooks Online account.</p>

    <?php if (isset($result['error'])) { ?>
      <div class="alert alert-danger">
        <strong>API Error:</strong> <?= htmlspecialchars($result['error']) ?>
      </div>
    <?php } elseif (isset($result['QueryResponse']['Customer'])) {
      $customers = $result['QueryResponse']['Customer'];
    ?>
      <div class="alert alert-success">
        <strong>Success!</strong> Retrieved <?= count($customers) ?> customer(s).
      </div>
      <table class="table table-striped table-bordered">
        <thead class="thead-dark">
          <tr>
            <th>ID</th>
            <th>Display Name</th>
            <th>Company</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Balance</th>
            <th>Active</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($customers as $c) { ?>
          <tr>
            <td><?= htmlspecialchars($c['Id']) ?></td>
            <td><?= htmlspecialchars($c['DisplayName'] ?? '') ?></td>
            <td><?= htmlspecialchars($c['CompanyName'] ?? '') ?></td>
            <td><?= htmlspecialchars($c['PrimaryEmailAddr']['Address'] ?? '') ?></td>
            <td><?= htmlspecialchars($c['PrimaryPhone']['FreeFormNumber'] ?? '') ?></td>
            <td><?= isset($c['Balance']) ? '$' . number_format($c['Balance'], 2) : '' ?></td>
            <td><?= ($c['Active'] ?? false) ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>' ?></td>
          </tr>
          <?php } ?>
        </tbody>
      </table>
    <?php } elseif (isset($result['QueryResponse'])) { ?>
      <div class="alert alert-info">
        <strong>Connected successfully!</strong> No customers found in this QuickBooks account.
      </div>
    <?php } else { ?>
      <div class="alert alert-warning">
        <strong>Unexpected response:</strong>
        <pre><?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT)) ?></pre>
      </div>
    <?php } ?>

    <?php if (isset($result['fault'])) { ?>
      <div class="alert alert-danger">
        <strong>QBO Fault:</strong>
        <pre><?= htmlspecialchars(json_encode($result['fault'], JSON_PRETTY_PRINT)) ?></pre>
      </div>
    <?php } ?>
  </div>
</body>
</html>
