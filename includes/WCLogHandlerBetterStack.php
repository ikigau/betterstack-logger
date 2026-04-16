<?php

if (!defined('WPINC')) {
  die;
}

if (
  class_exists('WC_Log_Handler')
  && interface_exists('WC_Log_Handler_Interface')
  && !class_exists('BetterStackWCLogHandlerV2')
) {
  /**
   * Additive WooCommerce log handler that forwards native WooCommerce logger entries
   * into Better Stack through BetterStackLogger's existing pipeline.
   */
  // WooCommerce validates handlers against WC_Log_Handler_Interface and rejects non-conforming classes.
  class BetterStackWCLogHandlerV2 extends WC_Log_Handler implements WC_Log_Handler_Interface {

    /**
     * Handle a WooCommerce log entry.
     *
     * @param int|string $timestamp Log timestamp.
     * @param string $level Log level.
     * @param string $message Log message.
     * @param array<string, mixed>|mixed $context Additional context.
     * @return bool
     */
    public function handle($timestamp, $level, $message, $context) {
      global $betterstack_logger;

      if (
        class_exists('BetterStackLogger')
        && $betterstack_logger instanceof BetterStackLogger
        && method_exists($betterstack_logger, 'forward_wc_logger_entry')
      ) {
        $safe_context = is_array($context) ? $context : [];

        try {
          $betterstack_logger->forward_wc_logger_entry($timestamp, (string) $level, (string) $message, $safe_context);
        } catch (Throwable $e) {
          // Fail quietly to avoid interrupting WooCommerce's native logger pipeline.
        }
      }

      // Always return true so WooCommerce continues processing other handlers.
      return true;
    }
  }
}



