<?php
/**
 * QBO Connect - OAuth 2.0 Callback Handler
 *
 * This page receives the authorization code from Intuit's OAuth flow
 * and exchanges it for access and refresh tokens.
 */
require_once '../../../../../users/init.php';

// Ensure our functions are available
include_once $abs_us_root . $us_url_root . 'usersc/plugins/qbo_connect/functions.php';

// Only master accounts can authorize QBO
if (!$user->isLoggedIn() || !in_array($user->data()->id, $master_account)) {
  Redirect::to($us_url_root . 'users/admin.php');
  die();
}

$configUrl = $us_url_root . 'users/admin.php?view=plugins_config&plugin=qbo_connect';

// Check for errors from Intuit
if (!empty($_GET['error'])) {
  usError('QBO Authorization error: ' . htmlspecialchars(Input::get('error')));
  logger($user->data()->id, "QBO Connect", "OAuth error: " . Input::get('error'));
  Redirect::to($configUrl);
  die();
}

// Verify we received a code
$code = Input::get('code');
$realm_id = Input::get('realmId');
$state = Input::get('state');

if (empty($code) || empty($realm_id)) {
  usError('Missing authorization code or Realm ID from QuickBooks.');
  Redirect::to($configUrl);
  die();
}

// Verify state parameter to prevent CSRF
if (empty($state) || !isset($_SESSION['qbo_oauth_state']) || $state !== $_SESSION['qbo_oauth_state']) {
  usError('Invalid OAuth state. Please try connecting again.');
  unset($_SESSION['qbo_oauth_state']);
  Redirect::to($configUrl);
  die();
}

// Clear the state token
unset($_SESSION['qbo_oauth_state']);

// Exchange the authorization code for tokens
$result = qbo_exchange_code($code, $realm_id);

if (isset($result['error'])) {
  usError('Failed to connect to QuickBooks: ' . htmlspecialchars($result['error']));
  logger($user->data()->id, "QBO Connect", "Token exchange failed: " . $result['error']);
} else {
  usSuccess('Successfully connected to QuickBooks Online! Realm ID: ' . htmlspecialchars($realm_id));
  logger($user->data()->id, "QBO Connect", "Successfully connected to QBO. Realm ID: " . $realm_id);
}

// Since this opened in a new tab, close it and refresh the parent window
?>
<!DOCTYPE html>
<html>
<head><title>QBO Connect</title></head>
<body>
<script>
  if (window.opener) {
    window.opener.location.href = '<?= $configUrl ?>';
    window.close();
  } else {
    window.location.href = '<?= $configUrl ?>';
  }
</script>
<p>Connection complete. <a href="<?= htmlspecialchars($configUrl) ?>">Click here</a> if you are not redirected automatically.</p>
</body>
</html>
