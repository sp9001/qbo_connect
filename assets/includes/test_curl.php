<?php
// Temporary diagnostic script - DELETE after testing
require_once '../../../../../users/init.php';

if (!$user->isLoggedIn() || !in_array($user->data()->id, $master_account)) {
  die('Not authorized');
}

echo "<h3>cURL Diagnostics - Extended</h3>";
echo "<pre>";

// Test 1: DNS resolution
echo "--- Test 1: DNS Resolution ---\n";
$hosts = ['oauth.platform.intuit.com', 'appcenter.intuit.com', 'httpbin.org'];
foreach ($hosts as $host) {
  $ips = gethostbynamel($host);
  if ($ips) {
    echo "$host => " . implode(', ', $ips) . "\n";
  } else {
    echo "$host => FAILED TO RESOLVE\n";
  }
}

// Test 2: Try different Intuit endpoints
echo "\n--- Test 2: Connection tests (5s timeout each) ---\n";
$urls = [
  'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
  'https://appcenter.intuit.com/',
  'https://developer.intuit.com/',
  'https://sandbox-quickbooks.api.intuit.com/',
];
foreach ($urls as $url) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_NOBODY, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 5);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
  curl_exec($ch);
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  $time = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
  $ip = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
  curl_close($ch);
  echo "$url\n  HTTP: $http_code | IP: $ip | Time: {$time}s | Error: $error\n\n";
}

// Test 3: Try file_get_contents as alternative
echo "--- Test 3: file_get_contents fallback ---\n";
$ctx = stream_context_create([
  'http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
    'content' => 'grant_type=authorization_code&code=test',
    'timeout' => 5,
  ],
  'ssl' => [
    'verify_peer' => false,
    'verify_peer_name' => false,
  ],
]);
$result = @file_get_contents('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', false, $ctx);
if ($result === false) {
  $err = error_get_last();
  echo "file_get_contents FAILED: " . ($err['message'] ?? 'unknown error') . "\n";
} else {
  echo "file_get_contents OK: " . substr($result, 0, 300) . "\n";
}

// Test 4: Try with IPv4 forced
echo "\n--- Test 4: cURL with IPRESOLVE_V4 ---\n";
$ch = curl_init('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=authorization_code&code=test');
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Content-Type: application/x-www-form-urlencoded',
  'Accept: application/json',
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$ip = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
$time = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
curl_close($ch);
echo "HTTP: $http_code | IP: $ip | Time: {$time}s | Error: $error\n";
if ($response) echo "Response: " . substr($response, 0, 300) . "\n";

echo "</pre>";
