<?php
require_once("init.php");
//For security purposes, it is MANDATORY that this page be wrapped in the following
//if statement. This prevents remote execution of this code.
if (in_array($user->data()->id, $master_account)){
$db = DB::getInstance();
include "plugin_info.php";

//all actions should be performed here.
// Drop plugin tables on full delete
$db->query("DROP TABLE IF EXISTS plg_qbo_tokens");
$db->query("DROP TABLE IF EXISTS plg_qbo_settings");
$db->query("DROP TABLE IF EXISTS plg_qbo_customers");
$db->query("DROP TABLE IF EXISTS plg_qbo_invoices");
$db->query("DROP TABLE IF EXISTS plg_qbo_estimates");
$db->query("DROP TABLE IF EXISTS plg_qbo_projects");
$db->query("DROP TABLE IF EXISTS plg_qbo_company_info");
$db->query("DROP TABLE IF EXISTS plg_qbo_sync_log");
$db->query("DROP TABLE IF EXISTS plg_qbo_webhook_queue");

logger($user->data()->id,"USPlugins",$plugin_name." plugin data deleted");

} //do not perform actions outside of this statement
