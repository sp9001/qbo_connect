<?php
/**
 * QBO Connect - Webhook Queue Processor (Cron Job)
 *
 * Run this via cron to process pending webhook events from the queue.
 * It picks up pending items, calls the QBO API to fetch the updated entity,
 * and upserts the local cache.
 *
 * Can also be called via URL if protected, but CLI is preferred.
 */

// Detect if running from CLI
$isCli = (php_sapi_name() === 'cli');

// Load UserSpice core
require_once __DIR__ . '/../../../../../users/init.php';
include_once $abs_us_root . $us_url_root . 'usersc/plugins/qbo_connect/functions.php';
require_once __DIR__ . '/webhook_cron_functions.php';

// Security: if called via web, check permissions
if (!$isCli) {
  if (!hasPerm(2)) { // allow admin to call directly if logged in
    $cronSettings = qbo_get_settings();
    $allowedIps = array_map('trim', explode(',', ($cronSettings->cron_allowed_ips ?? '::1,127.0.0.1')));
    $ip = ipCheck();
    if (!in_array($ip, $allowedIps)) {
      logger(0, "QBO Webhook Cron", "Blocked access attempt from IP: $ip");
      dnd($ip);
      die("not allowed");
    }
    $code = Input::get('code');
    $validCode = $cronSettings->cron_access_code ?? '';
    if (empty($validCode) || $code !== $validCode) {
      logger(0, "QBO Webhook Cron", "Invalid code from IP: $ip");
      die("imposter!");
    }
  }
}

global $db;

// Entity type -> sync function mapping for single-record fetches
// We map QBO entity names to our local table and sync approach
$entityMap = [
  'Customer'    => 'qbo_process_webhook_customer',
  'Invoice'     => 'qbo_process_webhook_invoice',
  'Estimate'    => 'qbo_process_webhook_estimate',
  'CompanyInfo' => 'qbo_process_webhook_company_info',
];

// Check connection before processing
if (!qbo_is_connected()) {
  $msg = "Webhook cron: not connected to QBO, skipping processing";
  logger(0, "QBO Webhook Cron", $msg);
  if ($isCli) echo $msg . "\n";
  exit;
}

// Grab pending items, oldest first, limited batch to avoid timeout
$result = $db->query(
  "SELECT * FROM plg_qbo_webhook_queue WHERE status IN ('pending','failed') AND attempts < max_attempts ORDER BY received_at ASC LIMIT 50"
);

$items = ($result->count() > 0) ? $result->results() : [];
$processed = 0;
$failed = 0;
$skipped = 0;
$now = date('Y-m-d H:i:s');

if ($isCli) echo "Webhook cron: " . count($items) . " item(s) to process\n";
logger(0, "QBO Webhook Cron", "Cron started: " . count($items) . " item(s) to process");

foreach ($items as $item) {
  // Mark as processing
  $db->query(
    "UPDATE plg_qbo_webhook_queue SET status = 'processing', attempts = attempts + 1, last_attempt_at = ? WHERE id = ?",
    [$now, $item->id]
  );

  $entityType = $item->entity_type;
  $entityId = $item->entity_id;
  $operation = $item->operation;

  // Handle Delete/Void operations - remove from local cache
  if (in_array($operation, ['Delete', 'Void'])) {
    $deleteResult = qbo_webhook_delete_entity($entityType, $entityId);
    if ($deleteResult) {
      $db->query(
        "UPDATE plg_qbo_webhook_queue SET status = 'complete', processed_at = ?, error_message = NULL WHERE id = ?",
        [$now, $item->id]
      );
      $processed++;
      logger(0, "QBO Webhook Cron", "Deleted $entityType #$entityId locally ($operation)");
      if ($isCli) echo "  OK: $entityType #$entityId ($operation) - deleted locally\n";
    } else {
      $db->query(
        "UPDATE plg_qbo_webhook_queue SET status = 'skipped', processed_at = ?, error_message = ? WHERE id = ?",
        [$now, "Entity type '$entityType' not tracked locally or delete not applicable", $item->id]
      );
      $skipped++;
      logger(0, "QBO Webhook Cron", "Skipped $operation for $entityType #$entityId - not tracked locally");
      if ($isCli) echo "  Skipped: $entityType #$entityId ($operation) - not tracked\n";
    }
    continue;
  }

  // Handle Create/Update/Merge - fetch from QBO and upsert
  if (!isset($entityMap[$entityType])) {
    $db->query(
      "UPDATE plg_qbo_webhook_queue SET status = 'skipped', processed_at = ?, error_message = ? WHERE id = ?",
      [$now, "Entity type '$entityType' not supported for sync", $item->id]
    );
    $skipped++;
    logger(0, "QBO Webhook Cron", "Skipped $entityType #$entityId - entity type not supported");
    if ($isCli) echo "  Skipped: $entityType #$entityId (not supported)\n";
    continue;
  }

  $handler = $entityMap[$entityType];
  $handlerResult = $handler($entityId, $item->realm_id);

  if (isset($handlerResult['error'])) {
    $errMsg = $handlerResult['error'];
    $attemptNum = $item->attempts + 1;
    $newStatus = ($attemptNum >= $item->max_attempts) ? 'failed' : 'pending';
    $db->query(
      "UPDATE plg_qbo_webhook_queue SET status = ?, error_message = ? WHERE id = ?",
      [$newStatus, $errMsg, $item->id]
    );
    $failed++;
    logger(0, "QBO Webhook Cron", "Failed $entityType #$entityId ($operation) attempt $attemptNum/{$item->max_attempts}: $errMsg");
    if ($isCli) echo "  Failed: $entityType #$entityId - $errMsg\n";
  } else {
    $db->query(
      "UPDATE plg_qbo_webhook_queue SET status = 'complete', processed_at = ?, error_message = NULL WHERE id = ?",
      [$now, $item->id]
    );
    $processed++;
    logger(0, "QBO Webhook Cron", "Processed $entityType #$entityId ($operation) successfully");
    if ($isCli) echo "  OK: $entityType #$entityId ($operation)\n";
  }
}

$summary = "Webhook cron complete: $processed processed, $failed failed, $skipped skipped";
logger(0, "QBO Webhook Cron", $summary);
if ($isCli) echo "$summary\n";

