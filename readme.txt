=== ParsiReach – WooCommerce Order SMS Automation ===
Tags: woocommerce, sms, automation, ippanel
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Schedule IPPanel pattern messages when WooCommerce orders change status.

== Description ==

Create automations with an order status trigger, pattern code and variables, an initial delay, a total send count and an interval between sends. The plugin uses WooCommerce Action Scheduler, with WP-Cron as a fallback.

== Requirements ==

* WordPress 6.4+
* PHP 8.1+
* WooCommerce 7.9+
* IPPanel Edge API key and an authorized sender line

== Installation ==

1. Install and activate the plugin.
2. In ParsiReach > SMS Settings, enter the raw IPPanel API key without a Bearer prefix and the authorized sender number.
3. Keep Sandbox enabled while checking the connection and automation setup. Enable live sending only when ready.
4. Create an automation and set its trigger status, delay, pattern code, JSON variables, total send count and interval.

== Automation flow ==

When an order status changes, the plugin finds active automations matching the new status and creates a unique queued job. The job runs after the configured delay. Before sending, the plugin checks the automation, current order status and recipient number, fills pattern tokens from order data, and submits the request to IPPanel. After an accepted request, the next send is scheduled until the configured total count is reached. Temporary errors are retried up to three times. A successful report means IPPanel accepted the request; delivery receipts are not supported.

Sandbox jobs are recorded without contacting IPPanel. In low-traffic stores, configure a real server cron to run wp-cron.php reliably.

== Pattern variables ==

Enter a JSON object with keys matching the approved IPPanel pattern variables. Supported value tokens: {customer_name}, {customer_last_name}, {order_number}, {order_date}, {order_total}, {product_name}, {shop_name}.

== Reports ==

Filter send records, delete selected records or clear all reports. Failed jobs can be queued again from the report table.

== Uninstall ==

Uninstall deletes the plugin settings, queues, reports, tables and plugin-specific database data.
