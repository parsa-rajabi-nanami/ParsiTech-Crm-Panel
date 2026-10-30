=== ParsiReach – WooCommerce Marketing Automation ===
Contributors: parsishop
Tags: woocommerce, sms, marketing automation, ippanel
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Consent-aware, event-driven SMS marketing automation for WooCommerce with IPPanel Edge.

== Description ==

ParsiReach automates post-order SMS messages based on WooCommerce order status changes. It supports delays, repeated messages, order-total rules, frequency caps, quiet hours, a queued dispatcher, sandbox testing, consent checks, unsubscribe handling and send logs.

The current release is SMS-focused. It does not provide email campaign sending, bulk newsletters, abandoned-cart recovery or a full contact CRM.

== Requirements ==

* WordPress 6.4+
* PHP 8.1+
* WooCommerce 7.9+
* An IPPanel Edge API key and an authorized sender line in E.164 format (for example, `+983000505`)

== Installation ==

1. Back up your WordPress files and database.
2. Upload and activate the plugin ZIP. For an existing ParsiShop CRM installation, replace/update the existing `parsishop-crm` plugin; do not run two copies.
3. Enter your IPPanel Edge API key and sender line in E.164 format (for example, `+983000505`) in ParsiReach > SMS Settings.
4. Keep Sandbox enabled while checking the rules and schedule.
5. Enable the optional, unchecked-by-default marketing consent checkbox in classic WooCommerce checkout.
6. Create an automation and review the logs before disabling Sandbox.

== Privacy ==

Marketing consent is required by default. Opt-out records are checked before sending. The classic checkout consent field is optional and unchecked by default. Pattern messages must include their opt-out wording/link in the approved pattern itself.

This plugin provides technical controls, not legal advice or a guarantee of regulatory compliance. The site owner is responsible for lawful consent and message content.

== Delivery status ==

A successful `sent` log means IPPanel accepted the API request. Delivery receipts are not integrated in this version, so the plugin does not confirm handset delivery.

== Upgrade and uninstall ==

Legacy option names, database tables, plugin folder and scheduled hook names are retained for in-place upgrades. Uninstall removes connection settings and scheduled tasks but intentionally retains database tables, logs, consent records and suppression hashes to avoid accidental data loss.

== Changelog ==

= 2.0.0 =
* Renamed the product to ParsiReach while preserving legacy identifiers for upgrade compatibility.
* Migrated SMS requests and credit lookup to IPPanel Edge API.
* Added stable recipient hashes, marketing-consent records and signed unsubscribe links.
* Enforced global enable state, opt-outs, consent, quiet hours and frequency caps before dispatch.
* Added safer queue pause/resume behavior, retry scheduling and sandbox isolation.
* Removed the misleading email-drafts-only screen from the active product UI.
