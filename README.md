# QBO Connect - QuickBooks Online Plugin for UserSpice

A UserSpice 6 plugin that integrates with QuickBooks Online via OAuth 2.0. Syncs customers, invoices, estimates, projects, and company info from QBO into local cache tables for display and reference.

## Requirements

- UserSpice 6.0.5+
- PHP 7.1+ with cURL extension
- MySQL 5.7+ (InnoDB)
- HTTPS (required for OAuth and webhooks)
- Intuit Developer account with an OAuth 2.0 app

## Installation

1. Copy the `qbo_connect` folder into `usersc/plugins/`
2. Activate the plugin from the UserSpice admin panel
3. Navigate to **Plugin Settings > QBO Connect** to configure

## Setup

### Step 1: Create an Intuit Developer App

1. Go to [developer.intuit.com](https://developer.intuit.com) and create an app
2. Under **Keys & credentials**, copy your **Client ID** and **Client Secret**
3. Add your Redirect URI (shown on the configure page) to the app's allowed redirect URIs
4. Select **Sandbox** or **Production** environment

### Step 2: Connect to QuickBooks

1. Enter your Client ID, Client Secret, and select your environment on the configure page
2. Click **Save Settings**, then click **Connect to QuickBooks**
3. Authorize the app through Intuit's OAuth flow

### Step 3: Webhooks (Optional)

Webhooks allow QBO to notify your app when data changes, so your local cache stays up to date automatically.

1. In the Intuit Developer Portal, go to **Webhooks** and add your webhook endpoint URL (shown on the configure page)
2. Subscribe to the entity types you want to track (Customer, Invoice, Estimate)
3. Copy the **Verifier Token** from the portal into the plugin settings
4. Set up a cron job to process the webhook queue:

```
*/5 * * * * php /path/to/usersc/plugins/qbo_connect/assets/includes/webhook_cron.php
```

## Features

### Data Sync

- **Customers** - Syncs all active customers (excludes projects)
- **Invoices** - Syncs invoices with derived status (Open, Paid, Overdue)
- **Estimates** - Syncs estimates with acceptance and expiration tracking
- **Projects** - Syncs QBO projects (represented internally as customers with `IsProject=true`)
- **Company Info** - Syncs company metadata
- **Sync All** - Batch sync of all entity types at once
- **Incremental Sync** - Only fetches records updated since last sync

### Webhook Queue

- Receives and validates QBO webhook notifications (HMAC-SHA256)
- Queues events for asynchronous processing via cron
- Deduplicates pending events by entity and operation
- Automatic retry on failure (configurable max attempts)
- Manual pull and retry from the queue management page
- Status tracking: pending, processing, complete, failed, skipped

### Browse Pages

- DataTables-powered sortable and searchable tables for each entity type
- Customer detail page with raw API response data
- Webhook queue page with status cards and action buttons
- Color-coded badges for operations and statuses

## Database Tables

| Table | Description |
|---|---|
| `plg_qbo_settings` | OAuth credentials and environment config |
| `plg_qbo_tokens` | Access and refresh tokens with expiry |
| `plg_qbo_customers` | Customer cache |
| `plg_qbo_invoices` | Invoice cache |
| `plg_qbo_estimates` | Estimate cache |
| `plg_qbo_projects` | Project cache |
| `plg_qbo_company_info` | Company metadata |
| `plg_qbo_sync_log` | Sync history and status |
| `plg_qbo_webhook_queue` | Webhook event queue |

## File Structure

```
qbo_connect/
  plugin_info.php         - Plugin identifier
  install.php             - Initial installation
  activate.php            - Activation handler
  migrate.php             - Database migrations
  delete.php              - Uninstall cleanup
  configure.php           - Admin settings and UI
  functions.php           - Core API and sync functions
  assets/
    includes/
      callback.php              - OAuth callback handler
      webhook.php               - Webhook receiver endpoint
      webhook_cron.php          - Cron job for queue processing
      webhook_cron_functions.php - Shared webhook handler functions
      webhook_queue.php         - Webhook queue browse page
      customers.php             - Customer browse page
      customer_detail.php       - Customer detail/diagnostic page
      invoices.php              - Invoice browse page
      estimates.php             - Estimate browse page
      projects.php              - Project browse page
      company_info.php          - Company info browse page
```

## Important Notes

- **Token Expiry** - Refresh tokens expire after 100 days of inactivity. If the connection breaks, reconnect through the configure page.
- **Webhooks are metadata-only** - QBO sends entity type, ID, and operation. The actual data is fetched from the API during cron processing.
- **Projects** - QBO does not fire dedicated project webhook events. Projects sync via Customer Update events when invoices or estimates are created against them.
- **HTTPS Required** - Both OAuth redirects and webhook endpoints require HTTPS.
- **Admin Only** - Plugin configuration requires UserSpice master account (permission level 2).

## License

This plugin is designed for use with UserSpice 6. See UserSpice license for terms.
