# BetterStack Logger

BetterStack Logger is a lightweight, WordPress-native application observability plugin for WordPress and WooCommerce.

It sends structured JSON logs to Better Stack so you can monitor PHP/WordPress/WooCommerce runtime behavior even when infrastructure logs are not easily accessible.

Current version: `1.1.2`

## Scope

This plugin captures **application logs only**:

- WordPress/PHP runtime and hook-level events
- WooCommerce checkout/payment/webhook failure paths
- Custom application logs from helper functions

It does **not** capture:

- Nginx/Apache access or error logs
- MySQL logs
- OS/container/infrastructure logs

## Features

- Structured JSON payloads with level, message, environment, request metadata, and context
- PHP runtime coverage: notices, warnings, deprecated notices, uncaught exceptions, and fatal shutdown errors
- `wp_die` capture through a wrapped handler
- Optional WordPress event logging (login, user/profile changes, plugin/theme changes, selected `save_post`/`delete_post`)
- Optional WooCommerce event logging (checkout exception, payment failure, failed order status, webhook delivery failure)
- Category controls:
  - PHP errors
  - WordPress events
  - WooCommerce events
  - custom debug logs
- Minimum log level control (`debug`, `info`, `warning`, `error`, `critical`)
- Environment label (`production`, `staging`, `local`)
- Test log action in admin settings
- Built-in secret redaction (tokens, authorization headers, cookies, passwords, API keys, nonces, payment-related fields)
- Duplicate suppression for noisy repeated events within a short window
- Optional plugin/runtime attribution for runtime failures and high-value runtime hooks
  - `code_source_type` classification: `plugin`, `mu-plugin`, `theme`, `core`, `custom`
  - `plugin_slug` when plugin source can be resolved
  - `origin_file` and `origin_line` when available
- Optional WooCommerce native logger bridge for entries written through `wc_get_logger()`
  - mirrors entries that normally appear in `WooCommerce -> Status -> Logs`
  - preserves existing WooCommerce logging handlers (file/DB) and forwards additively

## Transport behavior

- Uses WordPress HTTP API (`wp_remote_post`) over HTTPS
- Non-blocking transport by default to reduce request latency impact
- Short timeouts and fail-quiet behavior to protect frontend/admin UX when Better Stack is unavailable
- Blocking mode is used only for specific paths (for example, manual test action and shutdown fatal attempts)

## Installation

1. Upload `betterstack-logger` to `/wp-content/plugins/`
2. Activate the plugin
3. Open `Tools -> BetterStack Logger`
4. Configure token and categories

## Configuration

### Better Stack token

Recommended secure source:

```php
define('BETTERSTACK_API_KEY', 'your-better-stack-source-token');
```

If `BETTERSTACK_API_KEY` is not set, the plugin falls back to the token stored in WordPress options.

### Settings

- Source Token (read-only in UI when defined via `wp-config.php`)
- Environment label
- Minimum log level
- Category toggles
- WP event post types for `save_post`
- Include stack traces toggle
- Max trace length
- Plugin/runtime attribution toggle
- WooCommerce logger bridge toggle
- WooCommerce logger bridge minimum level
- WooCommerce logger bridge source allowlist/blocklist
- Send test log action

Trace controls support both admin settings and runtime filters.

## Helper functions (backward compatible)

```php
better_error_log($message, $context = [], $level = 'error');
b_log($message, $context = [], $level = 'error');
better_wc_log($event, $message, $context = [], $level = 'error');
```

Examples:

```php
better_error_log('Stock sync failed', ['event' => 'stock_sync', 'sku' => 'ABC-123'], 'error');
b_log('Starting import', ['event' => 'import_start'], 'info');
better_wc_log('payment_failed', 'Payment gateway returned an error', ['order_id' => 1234], 'error');
```

## Redaction and privacy

- Sensitive keys and token-like values are redacted before sending
- Request body content is not logged by default
- For POST requests, only POST keys are included for observability
- Keep custom context minimal and avoid unnecessary customer-identifiable data

## WooCommerce notes

WooCommerce logging is intentionally failure-focused and opt-in, so it avoids logging every lifecycle event.

## WooCommerce logger bridge

When enabled, BetterStack Logger registers an additional WooCommerce log handler and forwards entries written through WooCommerce's native logger (`wc_get_logger()`) to Better Stack.

Technical registration:

- the plugin hooks `woocommerce_register_log_handlers` to append `BetterStackWCLogHandler`
- `BetterStackWCLogHandler` calls back into `BetterStackLogger::forward_wc_logger_entry(...)`
- WooCommerce default handlers remain registered and continue writing Status logs

This is additive behavior:

- WooCommerce native logging stays active
- existing WooCommerce log handlers are not replaced
- logs still appear in `WooCommerce -> Status -> Logs` as usual

Forwarded bridge payloads include fields such as:

- `event: woocommerce_logger_entry`
- `log_origin: woocommerce_logger_bridge`
- `wc_log_source`
- `wc_log_level`
- `wc_log_context` (sanitized/redacted by existing BetterStack pipeline)
- source attribution fields when inferable (`code_source_type`, `plugin_slug`)

Noise controls:

- bridge enable/disable toggle
- minimum level threshold for forwarded WooCommerce entries
- source allowlist and source blocklist

## Plugin/runtime attribution

When enabled, BetterStack Logger enriches runtime failure events with source attribution metadata so plugin-caused issues can be triaged centrally without editing each plugin.

Attribution is applied to:

- PHP runtime errors/warnings/notices/deprecations
- uncaught exceptions
- fatal shutdown errors
- `wp_die` events (best-effort via call stack)
- selected high-value runtime hooks (`http_api_debug` failure paths, `doing_it_wrong_run`, `deprecated_function_run`)

What it can detect well:

- failures that include a concrete file path from plugin/theme/core code

What it cannot guarantee:

- exact origin for every event if stack/file context is incomplete
- arbitrary private debug/info logs emitted internally by third-party plugins unless surfaced through observable WordPress/PHP events

## Filters

- `betterstack_logger_timeout` controls transport timeout
  - Signature: `apply_filters('betterstack_logger_timeout', $timeout, $blocking)`
- `betterstack_logger_include_traces` enables/disables traces at runtime
  - Signature: `apply_filters('betterstack_logger_include_traces', $enabled)`
- `betterstack_logger_max_trace_length` controls trace truncation length
  - Signature: `apply_filters('betterstack_logger_max_trace_length', $max_length)`

Example:

```php
add_filter('betterstack_logger_timeout', function ($timeout, $blocking) {
  return $blocking ? 5 : 2;
}, 10, 2);

add_filter('betterstack_logger_include_traces', '__return_false');

add_filter('betterstack_logger_max_trace_length', function ($max_length) {
  return 1200;
});
```

## Changelog

### 1.1.2

- Final docs/code consistency cleanup
- Canonical documentation consolidated in `README.md`
- Transport and `wp_die` docblock/comment clarity improvements
- Runtime trace controls and documented filters verified/aligned
- Metadata normalization check (version/license)
- Added optional plugin/runtime attribution for runtime failures and selected high-value runtime hooks
- Added optional WooCommerce native logger bridge to forward `wc_get_logger()` entries to Better Stack

## License

This plugin is licensed under **GPL-2.0-or-later**. See `LICENSE`.
