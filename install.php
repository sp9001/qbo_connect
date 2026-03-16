<?php
require_once("init.php");
//For security purposes, it is MANDATORY that this page be wrapped in the following
//if statement. This prevents remote execution of this code.
if (in_array($user->data()->id, $master_account)){
include "plugin_info.php";

//all actions should be performed here.
$pluginCheck = $db->query("SELECT * FROM us_plugins WHERE plugin = ?",array($plugin_name))->count();
if($pluginCheck > 0){
	err($plugin_name.' has already been installed!');
}else{
 $fields = array(
	 'plugin'=>$plugin_name,
	 'status'=>'installed',
 );
 $db->insert('us_plugins',$fields);
 if(!$db->error()) {
 	 	err($plugin_name.' installed');
		logger($user->data()->id,"USPlugins",$plugin_name." installed");
 } else {
 	 	err($plugin_name.' was not installed');
		logger($user->data()->id,"USPlugins","Failed to to install plugin, Error: ".$db->errorString());
 }
}

// Create QBO settings table
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

if($db->error()){
  logger($user->data()->id,"USPlugins","Failed to create plg_qbo_settings table, Error: ".$db->errorString());
}

// Create QBO tokens table
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

if($db->error()){
  logger($user->data()->id,"USPlugins","Failed to create plg_qbo_tokens table, Error: ".$db->errorString());
}

// Insert default settings row
$db->query("SELECT * FROM plg_qbo_settings");
if($db->count() == 0){
  $db->insert('plg_qbo_settings', [
    'environment' => 'sandbox',
  ]);
}

//Plugin hooks
$hooks = [];
registerHooks($hooks,$plugin_name);

} //do not perform actions outside of this statement
