<?php

if (!defined('WPINC')) {
  die;
}

if (class_exists('WC_Log_Handler') && !class_exists('BetterStackWCLogHandler')) {
  /**
   * Additive WooCommerce log handler that forwards native WooCommerce logger entries
   * into Better Stack through BetterStackLogger's existing pipeline.
   */
  class BetterStackWCLogHandler extends WC_Log_Handler {

    /**
     * Handle a WooCommerce log entry.
     *
     * @param int|string $timestamp Log timestamp.
     * @param string $level Log level.
     * @param string $message Log message.
     * @param array<string, mixed> $context Additional context.
     * @return bool
     */
    public function handle($timestamp, $level, $message, $context) {
      global $betterstack_logger;

      if ($betterstack_logger instanceof BetterStackLogger && method_exists($betterstack_logger, 'forward_wc_logger_entry')) {
        $safe_context = is_array($context) ? $context : [];
        $betterstack_logger->forward_wc_logger_entry($timestamp, (string) $level, (string) $message, $safe_context);
      }

      // Always return true so WooCommerce continues processing other handlers.
      return true;
    }
  }
}


