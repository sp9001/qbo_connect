<?php if (!in_array($user->data()->id, $master_account)) {
  Redirect::to($us_url_root . 'users/admin.php');
} //only allow master accounts to manage plugins!
?>

<?php
include "plugin_info.php";
pluginActive($plugin_name);

// Ensure our functions are available (configure.php is included from admin.php context)
include_once $abs_us_root . $us_url_root . 'usersc/plugins/' . $plugin_name . '/functions.php';

$errors = $successes = [];

$qboSettings = qbo_get_settings();
$qboTokens = qbo_get_tokens();
$connected = qbo_is_connected();

// Handle form submissions
if (!empty($_POST)) {
  if (!Token::check(Input::get('csrf'))) {
    include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
  }

  // Save settings
  if (Input::get('action') === 'save_settings') {
    $fields = [
      'client_id' => Input::get('client_id'),
      'client_secret' => Input::get('client_secret'),
      'redirect_uri' => Input::get('redirect_uri'),
      'environment' => Input::get('environment'),
      'webhook_verifier_token' => Input::get('webhook_verifier_token'),
      'cron_allowed_ips' => Input::get('cron_allowed_ips'),
      'cron_access_code' => Input::get('cron_access_code'),
    ];
    $db->update('plg_qbo_settings', $qboSettings->id, $fields);
    if (!$db->error()) {
      $successes[] = 'QBO settings saved successfully!';
      logger($user->data()->id, "QBO Connect", "Settings updated");
      // Refresh settings
      $qboSettings = qbo_get_settings();
    } else {
      $errors[] = 'Failed to save settings: ' . $db->errorString();
    }
  }

  // Sync All
  if (Input::get('action') === 'sync_all') {
    $syncResults = qbo_sync_all();
    $syncSummary = [];
    foreach ($syncResults as $entity => $res) {
      if (isset($res['error'])) {
        $errors[] = "$entity sync failed: " . $res['error'];
      } else {
        $syncSummary[] = "$entity: " . $res['count'];
      }
    }
    if (!empty($syncSummary)) {
      $successes[] = 'Sync complete! ' . implode(', ', $syncSummary);
    }
  }

  // Disconnect
  if (Input::get('action') === 'disconnect') {
    qbo_delete_tokens();
    $connected = false;
    $qboTokens = null;
    $successes[] = 'Disconnected from QuickBooks Online.';
    logger($user->data()->id, "QBO Connect", "Disconnected from QBO");
  }
}
?>

<div class="content mt-3">
  <div class="row">
    <div class="col-12">
      <a href="<?= $us_url_root ?>users/admin.php?view=plugins">Return to the Plugin Manager</a>
      <h1><i class="fa fa-book"></i> QBO Connect</h1>
      <p>Connect your UserSpice application to QuickBooks Online via OAuth 2.0.</p>

      <?= resultBlock($errors, $successes); ?>

      <!-- Connection Status -->
      <div class="card mb-4">
        <div class="card-header">
          <h3 class="h5 mb-0">Connection Status</h3>
        </div>
        <div class="card-body">
          <?php if ($connected) { ?>
            <div class="alert alert-success">
              <strong>Connected to QuickBooks Online</strong><br>
              Realm ID: <?= htmlspecialchars($qboTokens->realm_id) ?><br>
              Access Token Expires: <?= htmlspecialchars($qboTokens->access_token_expires_at) ?><br>
              Refresh Token Expires: <?= htmlspecialchars($qboTokens->refresh_token_expires_at) ?>
            </div>
            <form method="POST" class="d-inline">
              <?= tokenHere(); ?>
              <input type="hidden" name="action" value="disconnect">
              <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to disconnect from QuickBooks Online?')">
                <i class="fa fa-times"></i> Disconnect from QuickBooks
              </button>
            </form>
          <?php } else { ?>
            <div class="alert alert-warning">
              <strong>Not Connected</strong> - Configure your credentials below, then click "Connect to QuickBooks."
            </div>
            <?php
            $auth_url = qbo_get_auth_url();
            if ($auth_url) { ?>
              <a href="<?= htmlspecialchars($auth_url) ?>" class="btn btn-success">
                <i class="fa fa-plug"></i> Connect to QuickBooks
              </a>
            <?php } else { ?>
              <p class="text-muted">Please save your Client ID and Redirect URI below before connecting.</p>
            <?php } ?>
          <?php } ?>
        </div>
      </div>

      <!-- Browse Data -->
      <?php if ($connected) { ?>
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="h5 mb-0">Browse Data</h3>
          <form method="POST" class="d-inline">
            <?= tokenHere(); ?>
            <input type="hidden" name="action" value="sync_all">
            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Sync all entity types from QBO?')">
              <i class="fa fa-sync"></i> Sync All
            </button>
          </form>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <a href="<?= $us_url_root ?>usersc/plugins/qbo_connect/assets/includes/company_info.php" class="btn btn-secondary mb-2">
              <i class="fa fa-building"></i> Company Info
            </a>
            <a href="<?= $us_url_root ?>usersc/plugins/qbo_connect/assets/includes/customers.php" class="btn btn-primary mb-2">
              <i class="fa fa-users"></i> Customers
            </a>
            <a href="<?= $us_url_root ?>usersc/plugins/qbo_connect/assets/includes/invoices.php" class="btn btn-info mb-2">
              <i class="fa fa-file-invoice"></i> Invoices
            </a>
            <a href="<?= $us_url_root ?>usersc/plugins/qbo_connect/assets/includes/estimates.php" class="btn btn-warning mb-2">
              <i class="fa fa-file-alt"></i> Estimates
            </a>
            <a href="<?= $us_url_root ?>usersc/plugins/qbo_connect/assets/includes/projects.php" class="btn btn-dark mb-2">
              <i class="fa fa-project-diagram"></i> Projects
            </a>
            <a href="<?= $us_url_root ?>usersc/plugins/qbo_connect/assets/includes/webhook_queue.php" class="btn btn-outline-danger mb-2">
              <i class="fa fa-exchange-alt"></i> Webhook Queue
            </a>
          </div>
          <?php
          $entityTypes = [
            'Customer' => ['table' => 'plg_qbo_customers', 'label' => 'Customers'],
            'Invoice' => ['table' => 'plg_qbo_invoices', 'label' => 'Invoices'],
            'Estimate' => ['table' => 'plg_qbo_estimates', 'label' => 'Estimates'],
            'Project' => ['table' => 'plg_qbo_projects', 'label' => 'Projects'],
            'CompanyInfo' => ['table' => 'plg_qbo_company_info', 'label' => 'Company Info'],
          ];
          $hasAnyData = false;
          $entityCounts = [];
          foreach ($entityTypes as $et => $info) {
            try {
              $countResult = @$db->query("SELECT COUNT(*) as cnt FROM {$info['table']}");
              $entityCounts[$et] = ($countResult && $countResult->count() > 0) ? $countResult->first()->cnt : 0;
              if ($entityCounts[$et] > 0) $hasAnyData = true;
            } catch (Exception $e) {
              $entityCounts[$et] = 0;
            }
          }
          if ($hasAnyData) { ?>
          <table class="table table-sm table-bordered mt-2">
            <thead><tr><th>Entity</th><th>Records</th><th>Last Synced</th></tr></thead>
            <tbody>
              <?php foreach ($entityTypes as $et => $info) {
                $ls = qbo_get_last_sync($et);
              ?>
              <tr>
                <td><?= $info['label'] ?></td>
                <td><?= $entityCounts[$et] ?></td>
                <td><?= $ls ? htmlspecialchars($ls->last_sync_at) : '<span class="text-muted">Never</span>' ?></td>
              </tr>
              <?php } ?>
            </tbody>
          </table>
          <?php } ?>
        </div>
      </div>
      <?php } ?>

      <!-- API Credentials -->
      <div class="card mb-4">
        <div class="card-header">
          <h3 class="h5 mb-0">API Credentials</h3>
        </div>
        <div class="card-body">
          <form method="POST">
            <?= tokenHere(); ?>
            <input type="hidden" name="action" value="save_settings">

            <div class="form-group mb-3">
              <label for="client_id"><strong>Client ID</strong></label>
              <input type="text" class="form-control" id="client_id" name="client_id"
                     value="<?= htmlspecialchars($qboSettings->client_id ?? '') ?>"
                     placeholder="Enter your Intuit Client ID">
            </div>

            <div class="form-group mb-3">
              <label for="client_secret"><strong>Client Secret</strong></label>
              <input type="password" class="form-control" id="client_secret" name="client_secret"
                     value="<?= htmlspecialchars($qboSettings->client_secret ?? '') ?>"
                     placeholder="Enter your Intuit Client Secret">
            </div>

            <div class="form-group mb-3">
              <label for="redirect_uri"><strong>Redirect URI (OAuth Callback)</strong></label>
              <?php
              $default_redirect = (isHTTPSConnection() ? 'https' : 'http') . '://' . Server::get('HTTP_HOST') . $us_url_root . 'usersc/plugins/qbo_connect/assets/includes/callback.php';
              $redirect_value = !empty($qboSettings->redirect_uri) ? $qboSettings->redirect_uri : $default_redirect;
              ?>
              <input type="text" class="form-control" id="redirect_uri" name="redirect_uri"
                     value="<?= htmlspecialchars($redirect_value) ?>">
              <small class="form-text text-muted">
                This must match the Redirect URI configured in your Intuit Developer app.
              </small>
            </div>

            <div class="form-group mb-3">
              <label for="environment"><strong>Environment</strong></label>
              <select class="form-control" id="environment" name="environment">
                <option value="sandbox" <?= ($qboSettings->environment ?? '') === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
                <option value="production" <?= ($qboSettings->environment ?? '') === 'production' ? 'selected' : '' ?>>Production</option>
              </select>
            </div>

            <hr>
            <h5>Webhook Settings</h5>

            <div class="form-group mb-3">
              <label for="webhook_verifier_token"><strong>Webhook Verifier Token</strong></label>
              <input type="password" class="form-control" id="webhook_verifier_token" name="webhook_verifier_token"
                     value="<?= htmlspecialchars($qboSettings->webhook_verifier_token ?? '') ?>"
                     placeholder="Paste the Verifier Token from Intuit Developer Portal">
              <small class="form-text text-muted">
                Found in your Intuit Developer app under Webhooks. Used to verify incoming webhook signatures.
              </small>
            </div>

            <hr>
            <h5>Cron Security</h5>

            <div class="form-group mb-3">
              <label for="cron_allowed_ips"><strong>Allowed IPs for Cron Access</strong></label>
              <input type="text" class="form-control" id="cron_allowed_ips" name="cron_allowed_ips"
                     value="<?= htmlspecialchars($qboSettings->cron_allowed_ips ?? '::1,127.0.0.1') ?>"
                     placeholder="::1,127.0.0.1">
              <small class="form-text text-muted">
                Comma-separated list of IPs allowed to run the webhook cron via web (in addition to logged-in admins). Localhost is always recommended.
              </small>
            </div>

            <div class="form-group mb-3">
              <label for="cron_access_code"><strong>Cron Access Code</strong></label>
              <div class="input-group">
                <input type="text" class="form-control" id="cron_access_code" name="cron_access_code"
                       value="<?= htmlspecialchars($qboSettings->cron_access_code ?? '') ?>">
              </div>
              <small class="form-text text-muted">
                Required as <code>?code=YOUR_CODE</code> when calling the cron via web from a non-admin session. Auto-generated on first setup.
              </small>
            </div>

            <hr>

            <div class="form-group mb-3">
              <label><strong>Webhook Endpoint URL</strong></label>
              <?php
              $webhookUrl = (isHTTPSConnection() ? 'https' : 'http') . '://' . Server::get('HTTP_HOST') . $us_url_root . 'usersc/plugins/qbo_connect/assets/includes/webhook.php';
              ?>
              <input type="text" class="form-control" value="<?= htmlspecialchars($webhookUrl) ?>" readonly onclick="this.select()">
              <small class="form-text text-muted">
                Copy this URL into the Webhooks section of your Intuit Developer app. Must be HTTPS.
              </small>
            </div>

            <button type="submit" class="btn btn-primary">
              <i class="fa fa-save"></i> Save Settings
            </button>
          </form>
        </div>
      </div>

      <!-- Setup Instructions -->
      <div class="card mb-4">
        <div class="card-header">
          <h3 class="h5 mb-0">Setup Instructions</h3>
        </div>
        <div class="card-body">
          <h5>Step 1: Create an Intuit Developer App</h5>
          <ol>
            <li class="mb-2">Go to <a href="https://developer.intuit.com">developer.intuit.com</a> and sign in or create an account.</li>
            <li class="mb-2">Create a new app and select "QuickBooks Online and Payments" as the platform.</li>
            <li class="mb-2">In your app's settings, find your <strong>Client ID</strong> and <strong>Client Secret</strong> (under Keys & credentials).</li>
            <li class="mb-2">Add your <strong>Redirect URI</strong> to the app's allowed redirect URIs. Use:<br>
              <code><?= htmlspecialchars(($qboSettings->redirect_uri ?? 'https://yoursite.com/usersc/plugins/qbo_connect/assets/includes/callback.php')) ?></code>
            </li>
            <li class="mb-2">Enter the credentials above and click <strong>Save Settings</strong>.</li>
            <li class="mb-2">Click <strong>Connect to QuickBooks</strong> to authorize access to your QBO company.</li>
          </ol>

          <hr>

          <h5>Step 2: Set Up Webhooks (Optional)</h5>
          <p>Webhooks allow QBO to automatically notify this plugin when records change, so your local data stays up to date without manual syncing.</p>
          <ol>
            <li class="mb-2">In your Intuit Developer app, go to the <strong>Webhooks</strong> section.</li>
            <li class="mb-2">Enter the <strong>Webhook Endpoint URL</strong> shown in the settings above:<br>
              <?php
              $webhookUrlInstructions = (isHTTPSConnection() ? 'https' : 'http') . '://' . Server::get('HTTP_HOST') . $us_url_root . 'usersc/plugins/qbo_connect/assets/includes/webhook.php';
              ?>
              <code><?= htmlspecialchars($webhookUrlInstructions) ?></code>
            </li>
            <li class="mb-2">Select the entities you want to track: <strong>Customer</strong>, <strong>Invoice</strong>, <strong>Estimate</strong>. Select all operations (Create, Update, Delete).</li>
            <li class="mb-2">Save the webhook configuration. Intuit will display a <strong>Verifier Token</strong>.</li>
            <li class="mb-2">Copy the Verifier Token and paste it into the <strong>Webhook Verifier Token</strong> field above, then click Save Settings.</li>
            <li class="mb-2">Set up a cron job to process the webhook queue:<br>
              <code>*/5 * * * * php <?= htmlspecialchars($abs_us_root . $us_url_root) ?>usersc/plugins/qbo_connect/assets/includes/webhook_cron.php</code><br>
              <small class="text-muted">This runs every 5 minutes. Adjust the interval to your needs.</small>
            </li>
          </ol>

          <hr>

          <h5>Important Notes</h5>
          <ul>
            <li class="mb-2"><strong>HTTPS Required:</strong> Your webhook endpoint must be served over HTTPS with TLS 1.2 or higher. QBO will not send notifications to plain HTTP URLs.</li>
            <li class="mb-2"><strong>Webhooks are metadata only:</strong> QBO sends the entity type, ID, and operation — not the actual record data. The cron job fetches the full record from the QBO API when processing the queue.</li>
            <li class="mb-2"><strong>Notifications are batched:</strong> QBO aggregates changes and sends them in batches, not in real-time. There may be a short delay between a change in QBO and the webhook notification arriving.</li>
            <li class="mb-2"><strong>Retry behavior:</strong> If a queue item fails, it will be retried up to 3 times. After that it is marked as failed. Check the webhook queue log for details.</li>
            <li class="mb-2"><strong>Manual sync still works:</strong> You can always use the Sync buttons on the browse pages or the "Sync All" button to pull data manually, regardless of whether webhooks are configured.</li>
            <li class="mb-2"><strong>Projects:</strong> QBO does not currently support webhooks for Projects. Project data must be synced manually.</li>
            <li class="mb-2"><strong>Refresh tokens expire:</strong> QBO refresh tokens are valid for 100 days. If the connection expires, webhooks will still queue events, but the cron job won't be able to fetch data until you reconnect.</li>
          </ul>
        </div>
      </div>

    </div>
  </div>

  <!-- Do not close the content mt-3 div in this file -->
