<?php
/**
 * QBO Connect - Webhook Receiver
 *
 * This is the public endpoint that QBO posts webhook notifications to.
 * It validates the HMAC-SHA256 signature, queues events for processing,
 * and returns HTTP 200 as fast as possible (QBO requires response within 3 seconds).
 *
 * URL: https://yoursite.com/usersc/plugins/qbo_connect/assets/includes/webhook.php
 *
 * No user session or login required - this is called by QBO servers.
 */

// Load UserSpice core (no session/login needed, just DB access)
require_once '../../../../../users/init.php';
include_once $abs_us_root . $us_url_root . 'usersc/plugins/qbo_connect/functions.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

// Read the raw payload before anything else
$rawPayload = file_get_contents('php://input');

// Get the signature header from QBO
$signature = isset($_SERVER['HTTP_INTUIT_SIGNATURE']) ? $_SERVER['HTTP_INTUIT_SIGNATURE'] : '';

// Load the verifier token from settings
$qboSettings = qbo_get_settings();
$verifierToken = ($qboSettings && !empty($qboSettings->webhook_verifier_token))
  ? $qboSettings->webhook_verifier_token
  : null;

// Validate signature
if (!$verifierToken) {
  qbo_log("Webhook received but no verifier token configured - rejecting");
  http_response_code(403);
  exit;
}

if (empty($signature)) {
  qbo_log("Webhook received with no intuit-signature header - rejecting");
  http_response_code(401);
  exit;
}

// Compute expected HMAC-SHA256 signature
$expectedSignature = base64_encode(hash_hmac('sha256', $rawPayload, $verifierToken, true));

if (!hash_equals($expectedSignature, $signature)) {
  qbo_log("Webhook signature mismatch - rejecting", [
    'expected' => substr($expectedSignature, 0, 10) . '...',
    'received' => substr($signature, 0, 10) . '...',
  ]);
  http_response_code(401);
  exit;
}

// Signature valid - respond 200 immediately before processing
http_response_code(200);

// Flush the response to QBO so it doesn't wait
if (function_exists('fastcgi_finish_request')) {
  fastcgi_finish_request();
} else {
  if (!headers_sent()) {
    header('Connection: close');
    header('Content-Length: 0');
  }
  ob_end_flush();
  flush();
}

// --- From here on, QBO has already received our 200 response ---

// Parse the payload
$payload = json_decode($rawPayload, true);

if (!$payload || !isset($payload['eventNotifications'])) {
  qbo_log("Webhook payload missing eventNotifications", ['raw' => substr($rawPayload, 0, 500)]);
  exit;
}

global $db;
$now = date('Y-m-d H:i:s');
$queued = 0;

foreach ($payload['eventNotifications'] as $notification) {
  $realmId = isset($notification['realmId']) ? $notification['realmId'] : '';

  if (!isset($notification['dataChangeEvent']['entities'])) {
    continue;
  }

  foreach ($notification['dataChangeEvent']['entities'] as $entity) {
    $entityType = isset($entity['name']) ? $entity['name'] : '';
    $entityId = isset($entity['id']) ? $entity['id'] : '';
    $operation = isset($entity['operation']) ? $entity['operation'] : '';
    $lastUpdated = isset($entity['lastUpdated']) ? date('Y-m-d H:i:s', strtotime($entity['lastUpdated'])) : null;

    if (empty($entityType) || empty($entityId) || empty($operation)) {
      continue;
    }

    // Check for duplicate: same entity + operation still pending
    $existing = $db->query(
      "SELECT id FROM plg_qbo_webhook_queue WHERE realm_id = ? AND entity_type = ? AND entity_id = ? AND operation = ? AND status = 'pending' LIMIT 1",
      [$realmId, $entityType, $entityId, $operation]
    );

    if ($existing->count() > 0) {
      // Update the existing pending row with latest timestamp
      $db->query(
        "UPDATE plg_qbo_webhook_queue SET last_updated_qbo = ?, raw_payload = ?, updated_at = ? WHERE id = ?",
        [$lastUpdated, $rawPayload, $now, $existing->first()->id]
      );
    } else {
      $db->insert('plg_qbo_webhook_queue', [
        'realm_id' => $realmId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'operation' => $operation,
        'last_updated_qbo' => $lastUpdated,
        'status' => 'pending',
        'attempts' => 0,
        'raw_payload' => $rawPayload,
        'received_at' => $now,
      ]);
    }
    $queued++;
  }
}

qbo_log("Webhook processed: $queued event(s) queued");
