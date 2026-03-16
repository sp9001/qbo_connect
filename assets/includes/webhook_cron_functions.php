<?php
/**
 * QBO Connect - Webhook Handler Functions
 *
 * Shared by webhook_cron.php and webhook_queue.php for processing
 * individual webhook queue items (fetching from QBO API and upserting locally).
 */

if (!function_exists('qbo_process_webhook_customer')) {
  function qbo_process_webhook_customer($entityId, $realmId) {
    global $db;
    $result = qbo_api_request("/v3/company/{realmId}/customer/$entityId");

    if (isset($result['error'])) return $result;
    if (isset($result['fault'])) {
      return ['error' => isset($result['fault']['error'][0]['message']) ? $result['fault']['error'][0]['message'] : 'QBO fault'];
    }

    $c = isset($result['Customer']) ? $result['Customer'] : null;
    if (!$c) return ['error' => 'No Customer in API response'];

    // If this "Customer" is actually a project, route to the projects table
    if (isset($c['IsProject']) && $c['IsProject']) {
      return qbo_process_webhook_project_from_customer($c);
    }

    $now = date('Y-m-d H:i:s');
    $db->query("INSERT INTO plg_qbo_customers (qbo_id, display_name, company_name, given_name, family_name, email, phone, balance, active, qbo_last_updated, synced_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), company_name=VALUES(company_name), given_name=VALUES(given_name), family_name=VALUES(family_name), email=VALUES(email), phone=VALUES(phone), balance=VALUES(balance), active=VALUES(active), qbo_last_updated=VALUES(qbo_last_updated), synced_at=VALUES(synced_at)",
      [
        $c['Id'],
        isset($c['DisplayName']) ? $c['DisplayName'] : '',
        isset($c['CompanyName']) ? $c['CompanyName'] : '',
        isset($c['GivenName']) ? $c['GivenName'] : '',
        isset($c['FamilyName']) ? $c['FamilyName'] : '',
        isset($c['PrimaryEmailAddr']['Address']) ? $c['PrimaryEmailAddr']['Address'] : '',
        isset($c['PrimaryPhone']['FreeFormNumber']) ? $c['PrimaryPhone']['FreeFormNumber'] : '',
        isset($c['Balance']) ? $c['Balance'] : 0,
        isset($c['Active']) ? ($c['Active'] ? 1 : 0) : 1,
        isset($c['MetaData']['LastUpdatedTime']) ? date('Y-m-d H:i:s', strtotime($c['MetaData']['LastUpdatedTime'])) : null,
        $now,
      ]);

    return ['success' => true];
  }
}

if (!function_exists('qbo_process_webhook_invoice')) {
  function qbo_process_webhook_invoice($entityId, $realmId) {
    global $db;
    $result = qbo_api_request("/v3/company/{realmId}/invoice/$entityId");

    if (isset($result['error'])) return $result;
    if (isset($result['fault'])) {
      return ['error' => isset($result['fault']['error'][0]['message']) ? $result['fault']['error'][0]['message'] : 'QBO fault'];
    }

    $inv = isset($result['Invoice']) ? $result['Invoice'] : null;
    if (!$inv) return ['error' => 'No Invoice in API response'];

    $now = date('Y-m-d H:i:s');
    $total_amt = isset($inv['TotalAmt']) ? $inv['TotalAmt'] : 0;
    $balance = isset($inv['Balance']) ? $inv['Balance'] : 0;
    $due_date = isset($inv['DueDate']) ? $inv['DueDate'] : null;

    $status = 'Open';
    if ($balance == 0 && $total_amt > 0) $status = 'Paid';
    elseif ($due_date && strtotime($due_date) < time() && $balance > 0) $status = 'Overdue';

    $db->query("INSERT INTO plg_qbo_invoices (qbo_id, doc_number, customer_id, customer_name, txn_date, due_date, total_amt, balance, status, email_status, qbo_last_updated, synced_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE doc_number=VALUES(doc_number), customer_id=VALUES(customer_id), customer_name=VALUES(customer_name), txn_date=VALUES(txn_date), due_date=VALUES(due_date), total_amt=VALUES(total_amt), balance=VALUES(balance), status=VALUES(status), email_status=VALUES(email_status), qbo_last_updated=VALUES(qbo_last_updated), synced_at=VALUES(synced_at)",
      [
        $inv['Id'],
        isset($inv['DocNumber']) ? $inv['DocNumber'] : '',
        isset($inv['CustomerRef']['value']) ? $inv['CustomerRef']['value'] : '',
        isset($inv['CustomerRef']['name']) ? $inv['CustomerRef']['name'] : '',
        isset($inv['TxnDate']) ? $inv['TxnDate'] : null,
        $due_date,
        $total_amt,
        $balance,
        $status,
        isset($inv['EmailStatus']) ? $inv['EmailStatus'] : '',
        isset($inv['MetaData']['LastUpdatedTime']) ? date('Y-m-d H:i:s', strtotime($inv['MetaData']['LastUpdatedTime'])) : null,
        $now,
      ]);

    return ['success' => true];
  }
}

if (!function_exists('qbo_process_webhook_estimate')) {
  function qbo_process_webhook_estimate($entityId, $realmId) {
    global $db;
    $result = qbo_api_request("/v3/company/{realmId}/estimate/$entityId");

    if (isset($result['error'])) return $result;
    if (isset($result['fault'])) {
      return ['error' => isset($result['fault']['error'][0]['message']) ? $result['fault']['error'][0]['message'] : 'QBO fault'];
    }

    $est = isset($result['Estimate']) ? $result['Estimate'] : null;
    if (!$est) return ['error' => 'No Estimate in API response'];

    $now = date('Y-m-d H:i:s');
    $db->query("INSERT INTO plg_qbo_estimates (qbo_id, doc_number, customer_id, customer_name, txn_date, expiration_date, total_amt, status, accepted_date, qbo_last_updated, synced_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE doc_number=VALUES(doc_number), customer_id=VALUES(customer_id), customer_name=VALUES(customer_name), txn_date=VALUES(txn_date), expiration_date=VALUES(expiration_date), total_amt=VALUES(total_amt), status=VALUES(status), accepted_date=VALUES(accepted_date), qbo_last_updated=VALUES(qbo_last_updated), synced_at=VALUES(synced_at)",
      [
        $est['Id'],
        isset($est['DocNumber']) ? $est['DocNumber'] : '',
        isset($est['CustomerRef']['value']) ? $est['CustomerRef']['value'] : '',
        isset($est['CustomerRef']['name']) ? $est['CustomerRef']['name'] : '',
        isset($est['TxnDate']) ? $est['TxnDate'] : null,
        isset($est['ExpirationDate']) ? $est['ExpirationDate'] : null,
        isset($est['TotalAmt']) ? $est['TotalAmt'] : 0,
        isset($est['TxnStatus']) ? $est['TxnStatus'] : '',
        isset($est['AcceptedDate']) ? $est['AcceptedDate'] : null,
        isset($est['MetaData']['LastUpdatedTime']) ? date('Y-m-d H:i:s', strtotime($est['MetaData']['LastUpdatedTime'])) : null,
        $now,
      ]);

    return ['success' => true];
  }
}

if (!function_exists('qbo_process_webhook_company_info')) {
  function qbo_process_webhook_company_info($entityId, $realmId) {
    $result = qbo_sync_company_info();
    return $result;
  }
}

if (!function_exists('qbo_process_webhook_project_from_customer')) {
  function qbo_process_webhook_project_from_customer($c) {
    global $db;

    $now = date('Y-m-d H:i:s');
    $qbo_id = $c['Id'];
    $name = isset($c['DisplayName']) ? $c['DisplayName'] : '';
    $customer_id = isset($c['ParentRef']['value']) ? $c['ParentRef']['value'] : '';
    $customer_name = isset($c['ParentRef']['name']) ? $c['ParentRef']['name'] : '';
    $description = isset($c['Notes']) ? $c['Notes'] : '';
    $status = isset($c['Active']) ? ($c['Active'] ? 'In Progress' : 'Closed') : '';
    $last_updated = isset($c['MetaData']['LastUpdatedTime']) ? date('Y-m-d H:i:s', strtotime($c['MetaData']['LastUpdatedTime'])) : null;

    $db->query("INSERT INTO plg_qbo_projects (qbo_id, name, customer_id, customer_name, description, status, qbo_last_updated, synced_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE name=VALUES(name), customer_id=VALUES(customer_id), customer_name=VALUES(customer_name), description=VALUES(description), status=VALUES(status), qbo_last_updated=VALUES(qbo_last_updated), synced_at=VALUES(synced_at)",
      [$qbo_id, $name, $customer_id, $customer_name, $description, $status, $last_updated, $now]);

    return ['success' => true];
  }
}

if (!function_exists('qbo_webhook_delete_entity')) {
  function qbo_webhook_delete_entity($entityType, $entityId) {
    global $db;

    $tableMap = [
      'Customer'  => 'plg_qbo_customers',
      'Invoice'   => 'plg_qbo_invoices',
      'Estimate'  => 'plg_qbo_estimates',
      'Project'   => 'plg_qbo_projects',
    ];

    if (!isset($tableMap[$entityType])) {
      return false;
    }

    $table = $tableMap[$entityType];
    $db->query("DELETE FROM $table WHERE qbo_id = ?", [$entityId]);
    qbo_log("Webhook delete: $entityType #$entityId removed from $table");
    return true;
  }
}
