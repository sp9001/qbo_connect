<?php
// For security purposes, it is MANDATORY that this page be wrapped in the following
// if statement. This prevents remote execution of this code.

include "plugin_info.php";
if (in_array($user->data()->id, $master_account) && pluginActive($plugin_name,true)){
//all actions should be performed here.

//check which updates have been installed
$count = 0;
$db = DB::getInstance();

//Make sure the plugin is installed and get the existing updates
$checkQ = $db->query("SELECT id,updates FROM us_plugins WHERE plugin = ?",array($plugin_name));
$checkC = $checkQ->count();
if($checkC > 0){
  $checkR = $checkQ->first();
  if($checkR->updates == ''){
  $existing = []; //deal with not finding any updates
  }else{
  $existing = json_decode($checkR->updates);
  }

  // Migration 00001 - create plugin tables
  $update = '00001';
  if(!in_array($update,$existing)){
  logger($user->data()->id,"Migrations","$update migration triggered for $plugin_name");

  $db->query("CREATE TABLE IF NOT EXISTS plg_qbo_settings (
    id INT(11) NOT NULL AUTO_INCREMENT,
    client_id VARCHAR(255) DEFAULT NULL,
    client_secret VARCHAR(255) DEFAULT NULL,
    redirect_uri VARCHAR(500) DEFAULT NULL,
    environment VARCHAR(20) DEFAULT 'sandbox',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $db->query("CREATE TABLE IF NOT EXISTS plg_qbo_tokens (
    id INT(11) NOT NULL AUTO_INCREMENT,
    realm_id VARCHAR(255) DEFAULT NULL,
    access_token TEXT DEFAULT NULL,
    refresh_token TEXT DEFAULT NULL,
    token_type VARCHAR(50) DEFAULT 'bearer',
    access_token_expires_at DATETIME DEFAULT NULL,
    refresh_token_expires_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Insert default settings row if empty
  $db->query("SELECT * FROM plg_qbo_settings");
  if($db->count() == 0){
    $db->insert('plg_qbo_settings', [
      'environment' => 'sandbox',
    ]);
  }

  $existing[] = $update;
  $count++;
  }

  // Migration 00002 - customers + sync log tables
  $update = '00002';
  if(!in_array($update,$existing)){
  logger($user->data()->id,"Migrations","$update migration triggered for $plugin_name");

  $db->query("CREATE TABLE IF NOT EXISTS plg_qbo_customers (
    id INT(11) NOT NULL AUTO_INCREMENT,
    qbo_id VARCHAR(50) NOT NULL,
    display_name VARCHAR(500) DEFAULT NULL,
    company_name VARCHAR(500) DEFAULT NULL,
    given_name VARCHAR(255) DEFAULT NULL,
    family_name VARCHAR(255) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    balance DECIMAL(15,2) DEFAULT 0.00,
    active TINYINT(1) DEFAULT 1,
    qbo_last_updated DATETIME DEFAULT NULL,
    synced_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_qbo_id (qbo_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $db->query("CREATE TABLE IF NOT EXISTS plg_qbo_sync_log (
    id INT(11) NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(50) NOT NULL,
    last_sync_at DATETIME DEFAULT NULL,
    records_synced INT(11) DEFAULT 0,
    status VARCHAR(20) DEFAULT 'success',
    error_message TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_entity (entity_type)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $existing[] = $update;
  $count++;
  }

  // Migration 00003 - invoices, estimates, projects, company_info tables
  $update = '00003';
  if(!in_array($update,$existing)){
  logger($user->data()->id,"Migrations","$update migration triggered for $plugin_name");

  $db->query("CREATE TABLE IF NOT EXISTS plg_qbo_invoices (
    id INT(11) NOT NULL AUTO_INCREMENT,
    qbo_id VARCHAR(50) NOT NULL,
    doc_number VARCHAR(50) DEFAULT NULL,
    customer_id VARCHAR(50) DEFAULT NULL,
    customer_name VARCHAR(500) DEFAULT NULL,
    txn_date DATE DEFAULT NULL,
    due_date DATE DEFAULT NULL,
    total_amt DECIMAL(15,2) DEFAULT 0.00,
    balance DECIMAL(15,2) DEFAULT 0.00,
    status VARCHAR(50) DEFAULT NULL,
    email_status VARCHAR(50) DEFAULT NULL,
    qbo_last_updated DATETIME DEFAULT NULL,
    synced_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_qbo_id (qbo_id),
    KEY idx_customer (customer_id),
    KEY idx_txn_date (txn_date)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $db->query("CREATE TABLE IF NOT EXISTS plg_qbo_estimates (
    id INT(11) NOT NULL AUTO_INCREMENT,
    qbo_id VARCHAR(50) NOT NULL,
    doc_number VARCHAR(50) DEFAULT NULL,
    customer_id VARCHAR(50) DEFAULT NULL,
    customer_name VARCHAR(500) DEFAULT NULL,
    txn_date DATE DEFAULT NULL,
    expiration_date DATE DEFAULT NULL,
    total_amt DECIMAL(15,2) DEFAULT 0.00,
    status VARCHAR(50) DEFAULT NULL,
    accepted_date DATE DEFAULT NULL,
    qbo_last_updated DATETIME DEFAULT NULL,
    synced_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_qbo_id (qbo_id),
    KEY idx_customer (customer_id),
    KEY idx_txn_date (txn_date)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $db->query("CREATE TABLE IF NOT EXISTS plg_qbo_projects (
    id INT(11) NOT NULL AUTO_INCREMENT,
    qbo_id VARCHAR(50) NOT NULL,
    name VARCHAR(500) DEFAULT NULL,
    customer_id VARCHAR(50) DEFAULT NULL,
    customer_name VARCHAR(500) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    status VARCHAR(50) DEFAULT NULL,
    qbo_last_updated DATETIME DEFAULT NULL,
    synced_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_qbo_id (qbo_id),
    KEY idx_customer (customer_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $db->query("CREATE TABLE IF NOT EXISTS plg_qbo_company_info (
    id INT(11) NOT NULL AUTO_INCREMENT,
    qbo_id VARCHAR(50) DEFAULT NULL,
    company_name VARCHAR(500) DEFAULT NULL,
    legal_name VARCHAR(500) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    address_line1 VARCHAR(500) DEFAULT NULL,
    address_city VARCHAR(255) DEFAULT NULL,
    address_state VARCHAR(100) DEFAULT NULL,
    address_postal VARCHAR(20) DEFAULT NULL,
    address_country VARCHAR(100) DEFAULT NULL,
    industry VARCHAR(255) DEFAULT NULL,
    fiscal_year_start VARCHAR(20) DEFAULT NULL,
    synced_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $existing[] = $update;
  $count++;
  }

  // Migration 00004 - webhook queue table + verifier token setting
  $update = '00004';
  if(!in_array($update,$existing)){
  logger($user->data()->id,"Migrations","$update migration triggered for $plugin_name");

  $db->query("CREATE TABLE IF NOT EXISTS plg_qbo_webhook_queue (
    id INT(11) NOT NULL AUTO_INCREMENT,
    realm_id VARCHAR(255) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(50) NOT NULL,
    operation VARCHAR(20) NOT NULL,
    last_updated_qbo DATETIME DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    attempts INT(11) DEFAULT 0,
    max_attempts INT(11) DEFAULT 3,
    last_attempt_at DATETIME DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    raw_payload TEXT DEFAULT NULL,
    received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status (status),
    KEY idx_entity (entity_type, entity_id),
    KEY idx_realm (realm_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Add webhook verifier token column to settings
  $db->query("ALTER TABLE plg_qbo_settings ADD COLUMN webhook_verifier_token VARCHAR(255) DEFAULT NULL AFTER environment");

  $existing[] = $update;
  $count++;
  }

  // Migration 00005 - cron security settings (allowed IPs + access code)
  $update = '00005';
  if(!in_array($update,$existing)){
  logger($user->data()->id,"Migrations","$update migration triggered for $plugin_name");

  $db->query("ALTER TABLE plg_qbo_settings ADD COLUMN cron_allowed_ips VARCHAR(500) DEFAULT '::1,127.0.0.1' AFTER webhook_verifier_token");
  $db->query("ALTER TABLE plg_qbo_settings ADD COLUMN cron_access_code VARCHAR(255) DEFAULT NULL AFTER cron_allowed_ips");

  // Generate a random access code on first setup
  $randomCode = bin2hex(random_bytes(16));
  $db->query("UPDATE plg_qbo_settings SET cron_access_code = ?", [$randomCode]);

  $existing[] = $update;
  $count++;
  }

  //after all updates are done. Keep this at the bottom.
  $new = json_encode($existing);
  $db->update('us_plugins',$checkR->id,['updates'=>$new,'last_check'=>date("Y-m-d H:i:s")]);
  if(!$db->error()) {
    logger($user->data()->id,"Migrations","$count migration(s) successfully triggered for $plugin_name");
  } else {
   	logger($user->data()->id,"USPlugins","Failed to save updates, Error: ".$db->errorString());
  }
}//do not perform actions outside of this statement
}
