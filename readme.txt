=== BetterStack Logger ===
Contributors: millertchris
Tags: betterstack, logging, observability, error, woocommerce
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html

Application-level logs for WordPress and WooCommerce, sent to Better Stack.

== Description ==

BetterStack Logger is a lightweight application observability plugin for WordPress and WooCommerce. It sends structured JSON logs to Better Stack, so you can follow PHP, WordPress and WooCommerce runtime behaviour even when server logs are hard to reach.

It captures application logs only: PHP and WordPress runtime events, WooCommerce failure paths, and your own custom logs. It does not capture web server, database or operating system logs.

= Features =

* Structured JSON payloads with level, message, environment, request metadata and context.
* PHP runtime coverage: notices, warnings, deprecations, uncaught exceptions and fatal shutdown errors.
* `wp_die` capture through a wrapped handler that still calls the original one.
* Optional WordPress events: logins, user registration/profile updates/deletion, plugin activation and deactivation, theme switches, and `save_post`/`delete_post` for selected post types.
* Optional WooCommerce events: checkout exceptions, payment failures, orders moved to failed, and webhook delivery failures.
* Optional WooCommerce logger bridge: forwards entries written with `wc_get_logger()` (WooCommerce > Status > Logs) to Better Stack, while WooCommerce keeps logging as usual. Filter by minimum level and by source allowlist/blocklist.
* Optional plugin/runtime attribution: tags errors with `code_source_type` (plugin, mu-plugin, theme, core, custom), `plugin_slug`, `origin_file` and `origin_line`, and logs failed HTTP API calls, `doing_it_wrong` notices and deprecated function calls.
* Category toggles, a minimum log level (debug, info, warning, error, critical) and an environment label (production, staging, local).
* Optional stack traces with a configurable maximum length.
* Built-in redaction of tokens, authorization headers, cookies, passwords, API keys, nonces and payment-related fields. Request bodies are never sent; only POST field names are.
* Duplicate suppression for repeated entries within a short window.
* Send a test log from the settings page.

= Performance =

* Logs are sent with the WordPress HTTP API in non-blocking mode by default, with short timeouts, so a slow or unavailable Better Stack does not slow down your pages.
* At most 20 entries are sent per request. Critical entries and blocking sends always go through; when entries are dropped, a single summary entry with the count is sent at shutdown.
* Continuously logging WooCommerce sources (`facebook_for_woocommerce`, `swb-status`) are only forwarded at error level and above.

= Helper functions =

`better_error_log( $message, $context = [], $level = 'error' );`
`b_log( $message, $context = [], $level = 'error' );`
`better_wc_log( $event, $message, $context = [], $level = 'error' );`

Example:

`better_wc_log( 'payment_failed', 'Payment gateway returned an error', [ 'order_id' => 1234 ], 'error' );`

= Filters =

* `betterstack_logger_timeout` — transport timeout. Arguments: `$timeout`, `$blocking`.
* `betterstack_logger_include_traces` — enable or disable stack traces at runtime.
* `betterstack_logger_max_trace_length` — maximum stack trace length.
* `betterstack_logger_max_sends_per_request` — per-request send limit (`0` disables it).
* `betterstack_logger_wc_bridge_noisy_sources` — WooCommerce sources forwarded only at error level and above.

This plugin is a fork of [BetterStack Logger by Prolific Digital](https://github.com/prolific-digital/betterstack-logger). The fork is maintained at [ikigau/betterstack-logger](https://github.com/ikigau/betterstack-logger).

== Installation ==

1. Upload the `betterstack-logger` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New > Upload Plugin.
2. Activate the plugin from the Plugins screen.
3. Go to Tools > BetterStack Logger.
4. Add your Better Stack source token and choose which categories to log.

For better security, define the token in `wp-config.php` instead of saving it in the database:

`define( 'BETTERSTACK_API_KEY', 'your-better-stack-source-token' );`

When the constant is set, the token field on the settings page is read-only.

== Frequently Asked Questions ==

= Where do I get a source token? =

Create a source in Better Stack Logs (Telemetry) and copy its source token.

= Does it log server, database or access logs? =

No. It only captures what happens inside WordPress and PHP: runtime errors, WordPress and WooCommerce events, and your custom logs.

= Will it slow down my site? =

Logs are sent without waiting for a response, with short timeouts, and at most 20 per request. The settings page test log is the only send that waits for Better Stack to respond, along with fatal error reports at shutdown.

= Does it send personal data? =

Sensitive fields are redacted and request bodies are never sent. With WordPress events enabled, logins include the username, and admin requests include the current user's login. Keep custom context free of customer data you don't need.

= Does the WooCommerce bridge replace WooCommerce's own logs? =

No. It adds a handler next to the existing ones, so entries still appear in WooCommerce > Status > Logs.

== Changelog ==

= 1.2.0 =
* Limit sends to 20 per request (filter: `betterstack_logger_max_sends_per_request`), always sending critical entries and reporting dropped entries in one summary at shutdown.
* Forward noisy WooCommerce logger sources only at error level and above (filter: `betterstack_logger_wc_bridge_noisy_sources`).

= 1.1.0 =
* Structured JSON payloads with environment, request metadata and context.
* PHP runtime, uncaught exception, fatal shutdown and `wp_die` logging.
* Optional WordPress and WooCommerce event logging, with category toggles and a minimum log level.
* Optional WooCommerce native logger bridge.
* Optional plugin/runtime attribution.
* Secret redaction, duplicate suppression and non-blocking transport.
* Stack trace controls and filters.
* Test log action in settings.
* New optional `$context` and `$level` arguments for the helper functions, plus `better_wc_log()`.

= 1.0.0 =
* Initial release by Prolific Digital.

== Upgrade Notice ==

= 1.2.0 =
Caps Better Stack sends per request and quiets noisy WooCommerce log sources.
