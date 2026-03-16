<?php
// QBO Connect - functions.php
// These functions are loaded on every page when the plugin is active.

if(!function_exists('qbo_log')) {
  /**
   * Centralized logging for QBO Connect plugin.
   * Uses UserSpice's logger() function.
   */
  function qbo_log($note, $metadata = null) {
    global $user;
    $uid = (isset($user) && $user->isLoggedIn()) ? $user->data()->id : 0;
    if($metadata !== null){
      logger($uid, "QBO Connect", $note, $metadata);
    } else {
      logger($uid, "QBO Connect", $note);
    }
  }
}

if(!function_exists('qbo_get_settings')) {
  function qbo_get_settings() {
    global $db;
    $result = $db->query("SELECT * FROM plg_qbo_settings LIMIT 1");
    if($result->count() > 0){
      return $result->first();
    }
    qbo_log("No settings found in plg_qbo_settings");
    return null;
  }
}

if(!function_exists('qbo_get_tokens')) {
  function qbo_get_tokens() {
    global $db;
    $result = $db->query("SELECT * FROM plg_qbo_tokens ORDER BY id DESC LIMIT 1");
    if($result->count() > 0){
      return $result->first();
    }
    return null;
  }
}

if(!function_exists('qbo_save_tokens')) {
  function qbo_save_tokens($realm_id, $access_token, $refresh_token, $access_expires_in = 3600, $refresh_expires_in = 8726400) {
    global $db;
    qbo_log("Saving tokens for realm: $realm_id", [
      'access_expires_in' => $access_expires_in,
      'refresh_expires_in' => $refresh_expires_in,
    ]);

    $access_expires_at = date('Y-m-d H:i:s', time() + $access_expires_in);
    $refresh_expires_at = date('Y-m-d H:i:s', time() + $refresh_expires_in);

    $fields = [
      'realm_id' => $realm_id,
      'access_token' => $access_token,
      'refresh_token' => $refresh_token,
      'access_token_expires_at' => $access_expires_at,
      'refresh_token_expires_at' => $refresh_expires_at,
    ];

    $existing = $db->query("SELECT * FROM plg_qbo_tokens ORDER BY id DESC LIMIT 1");
    if($existing->count() > 0){
      qbo_log("Updating existing token row id: " . $existing->first()->id);
      $db->update('plg_qbo_tokens', $existing->first()->id, $fields);
    } else {
      qbo_log("Inserting new token row");
      $db->insert('plg_qbo_tokens', $fields);
    }

    if($db->error()){
      qbo_log("DB error saving tokens: " . $db->errorString());
      return false;
    }

    qbo_log("Tokens saved successfully for realm: $realm_id");
    return true;
  }
}

if(!function_exists('qbo_delete_tokens')) {
  function qbo_delete_tokens() {
    global $db;
    qbo_log("Deleting all QBO tokens");
    $db->query("DELETE FROM plg_qbo_tokens");
    return !$db->error();
  }
}

if(!function_exists('qbo_curl_post')) {
  function qbo_curl_post($url, $post_fields, $headers = []) {
    qbo_log("cURL POST request to: $url", [
      'post_fields' => array_keys($post_fields),
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $primary_ip = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
    curl_close($ch);

    qbo_log("cURL response from: $url", [
      'http_code' => $http_code,
      'curl_errno' => $curl_errno,
      'curl_error' => $curl_error,
      'total_time' => $total_time,
      'primary_ip' => $primary_ip,
      'response_length' => strlen($response),
    ]);

    if($curl_error){
      qbo_log("cURL FAILED: ($curl_errno) $curl_error | IP: $primary_ip | Time: {$total_time}s");
      return ['error' => 'cURL error (' . $curl_errno . '): ' . $curl_error, 'http_code' => 0];
    }

    $data = json_decode($response, true);
    if($data === null){
      qbo_log("Invalid JSON response", ['http_code' => $http_code, 'response_preview' => substr($response, 0, 500)]);
      return ['error' => 'Invalid JSON response (HTTP ' . $http_code . '): ' . substr($response, 0, 500), 'http_code' => $http_code];
    }

    qbo_log("cURL success: HTTP $http_code in {$total_time}s");
    return ['data' => $data, 'http_code' => $http_code];
  }
}

if(!function_exists('qbo_get_auth_url')) {
  function qbo_get_auth_url() {
    $qboSettings = qbo_get_settings();
    if(!$qboSettings || empty($qboSettings->client_id) || empty($qboSettings->redirect_uri)){
      qbo_log("Cannot build auth URL - missing client_id or redirect_uri");
      return null;
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['qbo_oauth_state'] = $state;

    $base_url = 'https://appcenter.intuit.com/connect/oauth2';

    $params = [
      'client_id' => $qboSettings->client_id,
      'response_type' => 'code',
      'scope' => 'com.intuit.quickbooks.accounting',
      'redirect_uri' => $qboSettings->redirect_uri,
      'state' => $state,
    ];

    $url = $base_url . '?' . http_build_query($params);
    qbo_log("Auth URL generated", ['redirect_uri' => $qboSettings->redirect_uri, 'environment' => $qboSettings->environment]);
    return $url;
  }
}

if(!function_exists('qbo_exchange_code')) {
  function qbo_exchange_code($code, $realm_id) {
    qbo_log("Starting token exchange for realm: $realm_id", ['code_length' => strlen($code)]);

    $qboSettings = qbo_get_settings();
    if(!$qboSettings){
      qbo_log("Token exchange failed - no settings configured");
      return ['error' => 'QBO settings not configured'];
    }

    $token_url = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    $auth = base64_encode($qboSettings->client_id . ':' . $qboSettings->client_secret);

    $post_fields = [
      'grant_type' => 'authorization_code',
      'code' => $code,
      'redirect_uri' => $qboSettings->redirect_uri,
    ];

    qbo_log("Exchanging code at: $token_url", ['redirect_uri' => $qboSettings->redirect_uri]);

    $result = qbo_curl_post($token_url, $post_fields, [
      'Authorization: Basic ' . $auth,
      'Content-Type: application/x-www-form-urlencoded',
      'Accept: application/json',
    ]);

    if(isset($result['error'])){
      qbo_log("Token exchange cURL error: " . $result['error']);
      return ['error' => $result['error']];
    }

    $data = $result['data'];

    if($result['http_code'] !== 200 || isset($data['error'])){
      $err_msg = isset($data['error']) ? $data['error'] : 'HTTP ' . $result['http_code'];
      $err_desc = isset($data['error_description']) ? $data['error_description'] : '';
      qbo_log("Token exchange API error: $err_msg - $err_desc", $data);
      return ['error' => 'Token exchange failed: ' . $err_msg . ($err_desc ? ' - ' . $err_desc : '')];
    }

    qbo_log("Token exchange successful, saving tokens", [
      'expires_in' => isset($data['expires_in']) ? $data['expires_in'] : 'not set',
      'token_type' => isset($data['token_type']) ? $data['token_type'] : 'not set',
    ]);

    $saved = qbo_save_tokens(
      $realm_id,
      $data['access_token'],
      $data['refresh_token'],
      isset($data['expires_in']) ? $data['expires_in'] : 3600,
      isset($data['x_refresh_token_expires_in']) ? $data['x_refresh_token_expires_in'] : 8726400
    );

    if(!$saved){
      qbo_log("Failed to save tokens to database after successful exchange");
      return ['error' => 'Failed to save tokens to database'];
    }

    qbo_log("OAuth flow completed successfully for realm: $realm_id");
    return $data;
  }
}

if(!function_exists('qbo_refresh_access_token')) {
  function qbo_refresh_access_token() {
    qbo_log("Starting token refresh");

    $qboSettings = qbo_get_settings();
    $tokens = qbo_get_tokens();

    if(!$qboSettings || !$tokens || empty($tokens->refresh_token)){
      qbo_log("Token refresh failed - no settings or refresh token available");
      return ['error' => 'No refresh token available'];
    }

    $token_url = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    $auth = base64_encode($qboSettings->client_id . ':' . $qboSettings->client_secret);

    $post_fields = [
      'grant_type' => 'refresh_token',
      'refresh_token' => $tokens->refresh_token,
    ];

    qbo_log("Refreshing token for realm: " . $tokens->realm_id);

    $result = qbo_curl_post($token_url, $post_fields, [
      'Authorization: Basic ' . $auth,
      'Content-Type: application/x-www-form-urlencoded',
      'Accept: application/json',
    ]);

    if(isset($result['error'])){
      qbo_log("Token refresh cURL error: " . $result['error']);
      return ['error' => $result['error']];
    }

    $data = $result['data'];

    if($result['http_code'] !== 200 || isset($data['error'])){
      $err_msg = isset($data['error']) ? $data['error'] : 'HTTP ' . $result['http_code'];
      qbo_log("Token refresh API error: $err_msg", $data);
      return ['error' => 'Token refresh failed: ' . $err_msg];
    }

    qbo_log("Token refresh successful");

    qbo_save_tokens(
      $tokens->realm_id,
      $data['access_token'],
      $data['refresh_token'],
      isset($data['expires_in']) ? $data['expires_in'] : 3600,
      isset($data['x_refresh_token_expires_in']) ? $data['x_refresh_token_expires_in'] : 8726400
    );

    return $data;
  }
}

if(!function_exists('qbo_is_connected')) {
  function qbo_is_connected() {
    $tokens = qbo_get_tokens();
    if(!$tokens || empty($tokens->refresh_token)){
      return false;
    }
    if(strtotime($tokens->refresh_token_expires_at) < time()){
      return false;
    }
    return true;
  }
}

if(!function_exists('qbo_get_valid_access_token')) {
  function qbo_get_valid_access_token() {
    $tokens = qbo_get_tokens();
    if(!$tokens){
      return null;
    }

    if(strtotime($tokens->access_token_expires_at) > time()){
      return $tokens->access_token;
    }

    qbo_log("Access token expired, attempting refresh");
    $result = qbo_refresh_access_token();
    if(isset($result['error'])){
      qbo_log("Failed to refresh access token: " . $result['error']);
      return null;
    }

    return $result['access_token'];
  }
}

if(!function_exists('qbo_api_request')) {
  function qbo_api_request($endpoint, $method = 'GET', $body = null) {
    $access_token = qbo_get_valid_access_token();
    $tokens = qbo_get_tokens();

    if(!$access_token || !$tokens){
      qbo_log("API request failed - not connected", ['endpoint' => $endpoint]);
      return ['error' => 'Not connected to QuickBooks'];
    }

    $qboSettings = qbo_get_settings();
    $base_url = ($qboSettings->environment === 'production')
      ? 'https://quickbooks.api.intuit.com'
      : 'https://sandbox-quickbooks.api.intuit.com';

    $endpoint = str_replace('{realmId}', $tokens->realm_id, $endpoint);
    $url = $base_url . $endpoint;

    qbo_log("API $method request", ['url' => $url, 'realm_id' => $tokens->realm_id]);

    $headers = [
      'Authorization: Bearer ' . $access_token,
      'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    if($method === 'POST'){
      curl_setopt($ch, CURLOPT_POST, true);
      if($body){
        $json_body = is_string($body) ? $body : json_encode($body);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
        $headers[] = 'Content-Type: application/json';
      }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);

    if($curl_error){
      qbo_log("API request cURL error", ['url' => $url, 'error' => $curl_error, 'time' => $total_time]);
      return ['error' => 'cURL error: ' . $curl_error];
    }

    qbo_log("API response: HTTP $http_code in {$total_time}s", ['url' => $url, 'response_length' => strlen($response)]);

    return json_decode($response, true);
  }
}

// =============================================================================
// Sync Log Functions
// =============================================================================

if(!function_exists('qbo_get_last_sync')) {
  function qbo_get_last_sync($entity_type) {
    global $db;
    $result = $db->query("SELECT * FROM plg_qbo_sync_log WHERE entity_type = ? ORDER BY id DESC LIMIT 1", [$entity_type]);
    if($result->count() > 0){
      return $result->first();
    }
    return null;
  }
}

if(!function_exists('qbo_log_sync')) {
  function qbo_log_sync($entity_type, $count, $status = 'success', $error = null) {
    global $db;
    $db->insert('plg_qbo_sync_log', [
      'entity_type' => $entity_type,
      'last_sync_at' => date('Y-m-d H:i:s'),
      'records_synced' => $count,
      'status' => $status,
      'error_message' => $error,
    ]);
  }
}

// =============================================================================
// QBO Query Helper (paginated)
// =============================================================================

if(!function_exists('qbo_query_all')) {
  function qbo_query_all($entity, $where = '') {
    $all = [];
    $start = 1;
    $batch = 1000;
    do {
      $query = "SELECT * FROM $entity $where STARTPOSITION $start MAXRESULTS $batch";
      $result = qbo_api_request('/v3/company/{realmId}/query?query=' . urlencode($query));
      if(isset($result['error'])){
        qbo_log("qbo_query_all failed for $entity", ['error' => $result['error']]);
        return ['error' => $result['error']];
      }
      if(isset($result['fault'])){
        $fault_msg = isset($result['fault']['error'][0]['message']) ? $result['fault']['error'][0]['message'] : 'Unknown fault';
        qbo_log("qbo_query_all fault for $entity: $fault_msg");
        return ['error' => "QBO API fault: $fault_msg"];
      }
      $records = isset($result['QueryResponse'][$entity]) ? $result['QueryResponse'][$entity] : [];
      $all = array_merge($all, $records);
      $start += $batch;
    } while (count($records) === $batch);
    qbo_log("qbo_query_all fetched " . count($all) . " $entity records");
    return $all;
  }
}

// =============================================================================
// Customer Functions
// =============================================================================

if(!function_exists('qbo_sync_customers')) {
  function qbo_sync_customers() {
    global $db;
    qbo_log("Starting customer sync");

    $where = '';
    $lastSync = qbo_get_last_sync('Customer');
    if($lastSync && $lastSync->status === 'success'){
      $where = "WHERE MetaData.LastUpdatedTime > '" . $lastSync->last_sync_at . "'";
    }

    $records = qbo_query_all('Customer', $where);
    if(isset($records['error'])){
      qbo_log_sync('Customer', 0, 'error', $records['error']);
      return ['error' => $records['error']];
    }

    $count = 0;
    $now = date('Y-m-d H:i:s');
    foreach($records as $c){
      // Skip projects — they come through the Customer endpoint but belong in projects table
      if (isset($c['IsProject']) && $c['IsProject']) {
        continue;
      }

      $qbo_id = $c['Id'];
      $display_name = isset($c['DisplayName']) ? $c['DisplayName'] : '';
      $company_name = isset($c['CompanyName']) ? $c['CompanyName'] : '';
      $given_name = isset($c['GivenName']) ? $c['GivenName'] : '';
      $family_name = isset($c['FamilyName']) ? $c['FamilyName'] : '';
      $email = isset($c['PrimaryEmailAddr']['Address']) ? $c['PrimaryEmailAddr']['Address'] : '';
      $phone = isset($c['PrimaryPhone']['FreeFormNumber']) ? $c['PrimaryPhone']['FreeFormNumber'] : '';
      $balance = isset($c['Balance']) ? $c['Balance'] : 0;
      $active = isset($c['Active']) ? ($c['Active'] ? 1 : 0) : 1;
      $last_updated = isset($c['MetaData']['LastUpdatedTime']) ? date('Y-m-d H:i:s', strtotime($c['MetaData']['LastUpdatedTime'])) : null;

      $db->query("INSERT INTO plg_qbo_customers (qbo_id, display_name, company_name, given_name, family_name, email, phone, balance, active, qbo_last_updated, synced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), company_name=VALUES(company_name), given_name=VALUES(given_name), family_name=VALUES(family_name), email=VALUES(email), phone=VALUES(phone), balance=VALUES(balance), active=VALUES(active), qbo_last_updated=VALUES(qbo_last_updated), synced_at=VALUES(synced_at)",
        [$qbo_id, $display_name, $company_name, $given_name, $family_name, $email, $phone, $balance, $active, $last_updated, $now]);
      $count++;
    }

    qbo_log("Customer sync complete: $count records");
    qbo_log_sync('Customer', $count);
    return ['success' => true, 'count' => $count];
  }
}

if(!function_exists('qbo_get_local_customers')) {
  function qbo_get_local_customers() {
    global $db;
    $result = $db->query("SELECT * FROM plg_qbo_customers ORDER BY display_name");
    if($result->count() > 0){
      return $result->results();
    }
    return [];
  }
}

if(!function_exists('qbo_get_local_customer')) {
  function qbo_get_local_customer($qbo_id) {
    global $db;
    $result = $db->query("SELECT * FROM plg_qbo_customers WHERE qbo_id = ? LIMIT 1", [$qbo_id]);
    if($result->count() > 0){
      return $result->first();
    }
    return null;
  }
}

// =============================================================================
// Invoice Functions
// =============================================================================

if(!function_exists('qbo_sync_invoices')) {
  function qbo_sync_invoices() {
    global $db;
    qbo_log("Starting invoice sync");

    $where = '';
    $lastSync = qbo_get_last_sync('Invoice');
    if($lastSync && $lastSync->status === 'success'){
      $where = "WHERE MetaData.LastUpdatedTime > '" . $lastSync->last_sync_at . "'";
    }

    $records = qbo_query_all('Invoice', $where);
    if(isset($records['error'])){
      qbo_log_sync('Invoice', 0, 'error', $records['error']);
      return ['error' => $records['error']];
    }

    $count = 0;
    $now = date('Y-m-d H:i:s');
    foreach($records as $inv){
      $qbo_id = $inv['Id'];
      $doc_number = isset($inv['DocNumber']) ? $inv['DocNumber'] : '';
      $customer_id = isset($inv['CustomerRef']['value']) ? $inv['CustomerRef']['value'] : '';
      $customer_name = isset($inv['CustomerRef']['name']) ? $inv['CustomerRef']['name'] : '';
      $txn_date = isset($inv['TxnDate']) ? $inv['TxnDate'] : null;
      $due_date = isset($inv['DueDate']) ? $inv['DueDate'] : null;
      $total_amt = isset($inv['TotalAmt']) ? $inv['TotalAmt'] : 0;
      $balance = isset($inv['Balance']) ? $inv['Balance'] : 0;
      $email_status = isset($inv['EmailStatus']) ? $inv['EmailStatus'] : '';
      $last_updated = isset($inv['MetaData']['LastUpdatedTime']) ? date('Y-m-d H:i:s', strtotime($inv['MetaData']['LastUpdatedTime'])) : null;

      // Derive status
      $status = 'Open';
      if($balance == 0 && $total_amt > 0) $status = 'Paid';
      elseif($due_date && strtotime($due_date) < time() && $balance > 0) $status = 'Overdue';

      $db->query("INSERT INTO plg_qbo_invoices (qbo_id, doc_number, customer_id, customer_name, txn_date, due_date, total_amt, balance, status, email_status, qbo_last_updated, synced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE doc_number=VALUES(doc_number), customer_id=VALUES(customer_id), customer_name=VALUES(customer_name), txn_date=VALUES(txn_date), due_date=VALUES(due_date), total_amt=VALUES(total_amt), balance=VALUES(balance), status=VALUES(status), email_status=VALUES(email_status), qbo_last_updated=VALUES(qbo_last_updated), synced_at=VALUES(synced_at)",
        [$qbo_id, $doc_number, $customer_id, $customer_name, $txn_date, $due_date, $total_amt, $balance, $status, $email_status, $last_updated, $now]);
      $count++;
    }

    qbo_log("Invoice sync complete: $count records");
    qbo_log_sync('Invoice', $count);
    return ['success' => true, 'count' => $count];
  }
}

if(!function_exists('qbo_get_local_invoices')) {
  function qbo_get_local_invoices() {
    global $db;
    $result = $db->query("SELECT * FROM plg_qbo_invoices ORDER BY txn_date DESC");
    if($result->count() > 0){
      return $result->results();
    }
    return [];
  }
}

if(!function_exists('qbo_get_local_invoice')) {
  function qbo_get_local_invoice($qbo_id) {
    global $db;
    $result = $db->query("SELECT * FROM plg_qbo_invoices WHERE qbo_id = ? LIMIT 1", [$qbo_id]);
    if($result->count() > 0){
      return $result->first();
    }
    return null;
  }
}

// =============================================================================
// Estimate Functions
// =============================================================================

if(!function_exists('qbo_sync_estimates')) {
  function qbo_sync_estimates() {
    global $db;
    qbo_log("Starting estimate sync");

    $where = '';
    $lastSync = qbo_get_last_sync('Estimate');
    if($lastSync && $lastSync->status === 'success'){
      $where = "WHERE MetaData.LastUpdatedTime > '" . $lastSync->last_sync_at . "'";
    }

    $records = qbo_query_all('Estimate', $where);
    if(isset($records['error'])){
      qbo_log_sync('Estimate', 0, 'error', $records['error']);
      return ['error' => $records['error']];
    }

    $count = 0;
    $now = date('Y-m-d H:i:s');
    foreach($records as $est){
      $qbo_id = $est['Id'];
      $doc_number = isset($est['DocNumber']) ? $est['DocNumber'] : '';
      $customer_id = isset($est['CustomerRef']['value']) ? $est['CustomerRef']['value'] : '';
      $customer_name = isset($est['CustomerRef']['name']) ? $est['CustomerRef']['name'] : '';
      $txn_date = isset($est['TxnDate']) ? $est['TxnDate'] : null;
      $expiration_date = isset($est['ExpirationDate']) ? $est['ExpirationDate'] : null;
      $total_amt = isset($est['TotalAmt']) ? $est['TotalAmt'] : 0;
      $status = isset($est['TxnStatus']) ? $est['TxnStatus'] : '';
      $accepted_date = isset($est['AcceptedDate']) ? $est['AcceptedDate'] : null;
      $last_updated = isset($est['MetaData']['LastUpdatedTime']) ? date('Y-m-d H:i:s', strtotime($est['MetaData']['LastUpdatedTime'])) : null;

      $db->query("INSERT INTO plg_qbo_estimates (qbo_id, doc_number, customer_id, customer_name, txn_date, expiration_date, total_amt, status, accepted_date, qbo_last_updated, synced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE doc_number=VALUES(doc_number), customer_id=VALUES(customer_id), customer_name=VALUES(customer_name), txn_date=VALUES(txn_date), expiration_date=VALUES(expiration_date), total_amt=VALUES(total_amt), status=VALUES(status), accepted_date=VALUES(accepted_date), qbo_last_updated=VALUES(qbo_last_updated), synced_at=VALUES(synced_at)",
        [$qbo_id, $doc_number, $customer_id, $customer_name, $txn_date, $expiration_date, $total_amt, $status, $accepted_date, $last_updated, $now]);
      $count++;
    }

    qbo_log("Estimate sync complete: $count records");
    qbo_log_sync('Estimate', $count);
    return ['success' => true, 'count' => $count];
  }
}

if(!function_exists('qbo_get_local_estimates')) {
  function qbo_get_local_estimates() {
    global $db;
    $result = $db->query("SELECT * FROM plg_qbo_estimates ORDER BY txn_date DESC");
    if($result->count() > 0){
      return $result->results();
    }
    return [];
  }
}

if(!function_exists('qbo_get_local_estimate')) {
  function qbo_get_local_estimate($qbo_id) {
    global $db;
    $result = $db->query("SELECT * FROM plg_qbo_estimates WHERE qbo_id = ? LIMIT 1", [$qbo_id]);
    if($result->count() > 0){
      return $result->first();
    }
    return null;
  }
}

// =============================================================================
// Project Functions
// =============================================================================

if(!function_exists('qbo_sync_projects')) {
  function qbo_sync_projects() {
    global $db;
    qbo_log("Starting project sync");

    $where = '';
    $lastSync = qbo_get_last_sync('Project');
    if($lastSync && $lastSync->status === 'success'){
      $where = "WHERE MetaData.LastUpdatedTime > '" . $lastSync->last_sync_at . "'";
    }

    $records = qbo_query_all('Project', $where);
    if(isset($records['error'])){
      qbo_log_sync('Project', 0, 'error', $records['error']);
      return ['error' => $records['error']];
    }

    $count = 0;
    $now = date('Y-m-d H:i:s');
    foreach($records as $proj){
      $qbo_id = $proj['Id'];
      $name = isset($proj['ProjectName']) ? $proj['ProjectName'] : (isset($proj['Name']) ? $proj['Name'] : '');
      $customer_id = isset($proj['CustomerRef']['value']) ? $proj['CustomerRef']['value'] : '';
      $customer_name = isset($proj['CustomerRef']['name']) ? $proj['CustomerRef']['name'] : '';
      $description = isset($proj['Description']) ? $proj['Description'] : '';
      $status = isset($proj['ProjectStatus']) ? $proj['ProjectStatus'] : (isset($proj['Status']) ? $proj['Status'] : '');
      $last_updated = isset($proj['MetaData']['LastUpdatedTime']) ? date('Y-m-d H:i:s', strtotime($proj['MetaData']['LastUpdatedTime'])) : null;

      $db->query("INSERT INTO plg_qbo_projects (qbo_id, name, customer_id, customer_name, description, status, qbo_last_updated, synced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name=VALUES(name), customer_id=VALUES(customer_id), customer_name=VALUES(customer_name), description=VALUES(description), status=VALUES(status), qbo_last_updated=VALUES(qbo_last_updated), synced_at=VALUES(synced_at)",
        [$qbo_id, $name, $customer_id, $customer_name, $description, $status, $last_updated, $now]);
      $count++;
    }

    qbo_log("Project sync complete: $count records");
    qbo_log_sync('Project', $count);
    return ['success' => true, 'count' => $count];
  }
}

if(!function_exists('qbo_get_local_projects')) {
  function qbo_get_local_projects() {
    global $db;
    $result = $db->query("SELECT * FROM plg_qbo_projects ORDER BY name");
    if($result->count() > 0){
      return $result->results();
    }
    return [];
  }
}

if(!function_exists('qbo_get_local_project')) {
  function qbo_get_local_project($qbo_id) {
    global $db;
    $result = $db->query("SELECT * FROM plg_qbo_projects WHERE qbo_id = ? LIMIT 1", [$qbo_id]);
    if($result->count() > 0){
      return $result->first();
    }
    return null;
  }
}

// =============================================================================
// Company Info Functions
// =============================================================================

if(!function_exists('qbo_sync_company_info')) {
  function qbo_sync_company_info() {
    global $db;
    qbo_log("Starting company info sync");

    $tokens = qbo_get_tokens();
    if(!$tokens){
      qbo_log_sync('CompanyInfo', 0, 'error', 'No tokens available');
      return ['error' => 'Not connected to QuickBooks'];
    }

    $result = qbo_api_request('/v3/company/{realmId}/companyinfo/' . $tokens->realm_id);
    if(isset($result['error'])){
      qbo_log_sync('CompanyInfo', 0, 'error', $result['error']);
      return ['error' => $result['error']];
    }
    if(isset($result['fault'])){
      $fault_msg = isset($result['fault']['error'][0]['message']) ? $result['fault']['error'][0]['message'] : 'Unknown fault';
      qbo_log_sync('CompanyInfo', 0, 'error', $fault_msg);
      return ['error' => "QBO API fault: $fault_msg"];
    }

    $ci = isset($result['CompanyInfo']) ? $result['CompanyInfo'] : null;
    if(!$ci){
      qbo_log_sync('CompanyInfo', 0, 'error', 'No CompanyInfo in response');
      return ['error' => 'Unexpected API response'];
    }

    $now = date('Y-m-d H:i:s');
    $qbo_id = $ci['Id'];
    $company_name = isset($ci['CompanyName']) ? $ci['CompanyName'] : '';
    $legal_name = isset($ci['LegalName']) ? $ci['LegalName'] : '';
    $email = isset($ci['Email']['Address']) ? $ci['Email']['Address'] : '';
    $phone = isset($ci['PrimaryPhone']['FreeFormNumber']) ? $ci['PrimaryPhone']['FreeFormNumber'] : '';
    $addr = isset($ci['CompanyAddr']) ? $ci['CompanyAddr'] : [];
    $address_line1 = isset($addr['Line1']) ? $addr['Line1'] : '';
    $address_city = isset($addr['City']) ? $addr['City'] : '';
    $address_state = isset($addr['CountrySubDivisionCode']) ? $addr['CountrySubDivisionCode'] : '';
    $address_postal = isset($addr['PostalCode']) ? $addr['PostalCode'] : '';
    $address_country = isset($addr['Country']) ? $addr['Country'] : '';
    $industry = isset($ci['IndustryType']) ? $ci['IndustryType'] : '';
    $fiscal_year_start = isset($ci['FiscalYearStartMonth']) ? $ci['FiscalYearStartMonth'] : '';

    // Clear and re-insert (single record)
    $db->query("DELETE FROM plg_qbo_company_info");
    $db->insert('plg_qbo_company_info', [
      'qbo_id' => $qbo_id,
      'company_name' => $company_name,
      'legal_name' => $legal_name,
      'email' => $email,
      'phone' => $phone,
      'address_line1' => $address_line1,
      'address_city' => $address_city,
      'address_state' => $address_state,
      'address_postal' => $address_postal,
      'address_country' => $address_country,
      'industry' => $industry,
      'fiscal_year_start' => $fiscal_year_start,
      'synced_at' => $now,
    ]);

    qbo_log("Company info sync complete");
    qbo_log_sync('CompanyInfo', 1);
    return ['success' => true, 'count' => 1];
  }
}

if(!function_exists('qbo_get_company_info')) {
  function qbo_get_company_info() {
    global $db;
    $result = $db->query("SELECT * FROM plg_qbo_company_info LIMIT 1");
    if($result->count() > 0){
      return $result->first();
    }
    return null;
  }
}

// =============================================================================
// Sync All
// =============================================================================

if(!function_exists('qbo_sync_all')) {
  function qbo_sync_all() {
    $results = [];
    $results['CompanyInfo'] = qbo_sync_company_info();
    $results['Customer'] = qbo_sync_customers();
    $results['Invoice'] = qbo_sync_invoices();
    $results['Estimate'] = qbo_sync_estimates();
    $results['Project'] = qbo_sync_projects();
    return $results;
  }
}
