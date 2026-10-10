<?php
/*
Plugin Name: BetterStack Logger
Plugin URI:  https://github.com/ikigau/betterstack-logger
Description: Seamlessly integrate with BetterStack to log messages directly from your WordPress site. Enhance your logging capabilities with ease and precision.
Short Description: Seamlessly integrate with BetterStack to log messages directly from your WordPress site.
Tags: betterstack, logger, logging, error, errors, debug, debugging, log, logs, monitoring, monitoring, betterstack logger, betterstack logging, betterstack error logging, betterstack error logger, betterstack debug logging, betterstack debug logger
Version: 1.2.0
Requires at least: 6.0
Requires PHP: 8.0
Tested up to: 6.9.4
Author: Pedro Gaudêncio
Author URI: https://ikigau.com
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html
Text Domain: betterstack-logger

*/

if (!defined('WPINC')) {
  die;
}

require_once plugin_dir_path(__FILE__) . 'includes/BetterStackLogger.php';

// Load the WooCommerce bridge handler after WooCommerce has initialized.
// BetterStackLogger still lazy-loads this file as a fallback when registering handlers.
add_action('woocommerce_loaded', function () {
  if (class_exists('WC_Log_Handler') && interface_exists('WC_Log_Handler_Interface')) {
    require_once plugin_dir_path(__FILE__) . 'includes/WCLogHandlerBetterStack.php';
  }
});

// Create a global instance of the BetterStackLogger class
global $betterstack_logger;
$betterstack_logger = new BetterStackLogger();

/**
 * Backward-compatible helper for custom application logs.
 *
 * Existing calls like better_error_log('message') continue to work.
 *
 * @param string $message Log message.
 * @param array<string, mixed> $context Optional structured context.
 * @param string $level Log level: debug|info|warning|error|critical.
 * @return void
 */
if (!function_exists('better_error_log')) {
  function better_error_log($message, $context = [], $level = 'error') {
    global $betterstack_logger;

    if ($betterstack_logger instanceof BetterStackLogger) {
      $safe_context = is_array($context) ? $context : [];
      $betterstack_logger->log_error($message, $safe_context, (string) $level);
    }
  }
}

/**
 * Shorthand backward-compatible helper for custom application logs.
 *
 * @param string $message Log message.
 * @param array<string, mixed> $context Optional structured context.
 * @param string $level Log level: debug|info|warning|error|critical.
 * @return void
 */
if (!function_exists('b_log')) {
  function b_log($message, $context = [], $level = 'error') {
    global $betterstack_logger;

    if ($betterstack_logger instanceof BetterStackLogger) {
      $safe_context = is_array($context) ? $context : [];
      $betterstack_logger->log_error($message, $safe_context, (string) $level);
    }
  }
}

/**
 * Log a WooCommerce-specific structured event.
 *
 * @param string $event
 * @param string $message
 * @param array<string, mixed> $context
 * @param string $level
 * @return void
 */
if (!function_exists('better_wc_log')) {
  function better_wc_log($event, $message, $context = [], $level = 'error') {
    global $betterstack_logger;

    if ($betterstack_logger instanceof BetterStackLogger) {
      $safe_context = is_array($context) ? $context : [];
      $betterstack_logger->log_woocommerce_event((string) $event, (string) $message, $safe_context, (string) $level);
    }
  }
}
