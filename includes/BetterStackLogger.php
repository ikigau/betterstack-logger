<?php

if (!defined('WPINC')) {
  die;
}

/**
 * BetterStack Logger
 *
 * Lightweight application-level observability for WordPress and WooCommerce.
 *
 * Runtime filters:
 * - betterstack_logger_timeout
 * - betterstack_logger_include_traces
 * - betterstack_logger_max_trace_length
 */
class BetterStackLogger {

  private const SOURCE = 'betterstack-logger';
  private const ENDPOINT = 'https://in.logs.betterstack.com';
  private const MAX_MESSAGE_LENGTH = 2000;
  private const MAX_TRACE_LENGTH = 8000;
  private const DUPLICATE_TTL_SECONDS = 3;
  private const DUPLICATE_CACHE_LIMIT = 200;
  private const SOURCE_PLUGIN = 'plugin';
  private const SOURCE_MU_PLUGIN = 'mu-plugin';
  private const SOURCE_THEME = 'theme';
  private const SOURCE_CORE = 'core';
  private const SOURCE_CUSTOM = 'custom';

  private const LEVEL_RANK = [
    'debug' => 10,
    'info' => 20,
    'warning' => 30,
    'error' => 40,
    'critical' => 50,
  ];

  private bool $is_transporting = false;
  // In-memory request-level duplicate suppression cache.
  private array $recent_hashes = [];
  private $previous_exception_handler = null;
  private ?array $cached_plugin_slugs = null;
  private ?array $cached_mu_plugin_slugs = null;

  /**
   * Constructor.
   */
  public function __construct() {
    add_action('admin_menu', [$this, 'betterstack_logger_menu']);
    add_action('admin_init', [$this, 'settings_init']);
    add_filter('wp_die_handler', [$this, 'betterstack_wp_die_handler']);

    $this->register_error_handlers();
    $this->register_runtime_attribution_hooks();
    $this->register_woocommerce_logger_bridge_hooks();
    $this->register_wordpress_hooks();
    $this->register_woocommerce_hooks();
  }

  /**
   * Backward-compatible custom error helper.
   *
   * @param mixed $message
   * @param array<string, mixed> $context
   * @param string $level
   * @return string
   */
  public function log_error($message, array $context = [], $level = 'error') {
    return $this->log($level, (string) $message, $context, 'custom_debug_logs');
  }

  /**
   * Primary structured logger.
   *
   * @param string $level
   * @param string $message
   * @param array<string, mixed> $context
   * @param string $category
   * @param bool $blocking
   * @return string
   */
  public function log($level, $message, array $context = [], $category = 'custom_debug_logs', $blocking = false) {
    $normalized_level = $this->normalize_level($level);
    if (!$this->is_category_enabled($category)) {
      return 'Skipped: category disabled.';
    }

    if (!$this->passes_minimum_level($normalized_level)) {
      return 'Skipped: below minimum level.';
    }

    $safe_message = $this->truncate((string) $this->redact_data($message), self::MAX_MESSAGE_LENGTH);
    $payload = $this->build_payload($normalized_level, $safe_message, $context, $category);
    if ($this->is_duplicate($payload)) {
      return 'Skipped: duplicate log.';
    }

    return $this->send_payload($payload, (bool) $blocking);
  }

  /**
   * Helper for WooCommerce business/integration logs.
   *
   * @param string $event
   * @param string $message
   * @param array<string, mixed> $context
   * @param string $level
   * @return string
   */
  public function log_woocommerce_event($event, $message, array $context = [], $level = 'error') {
    $context['event'] = sanitize_key((string) $event);
    return $this->log($level, $message, $context, 'woocommerce_events');
  }

  /**
   * Returns a wrapped wp_die handler callable that logs then delegates.
   *
   * WordPress sends the active handler through the `wp_die_handler` filter.
   * This wrapper preserves that handler and adds structured logging first.
   *
   * @param callable|string|null $handler Existing wp_die handler from core/filter chain.
   * @return callable Wrapped handler callable.
   */
  public function betterstack_wp_die_handler($handler = null) {
    $downstream_handler = is_callable($handler) ? $handler : '_default_wp_die_handler';

    return function ($message, $title = '', $args = []) use ($downstream_handler) {
      if ($this->is_category_enabled('php_errors')) {
        $formatted_message = $this->extract_message($message);
        $origin = $this->infer_origin_from_backtrace();
        $this->log(
          'critical',
          'wp_die triggered: ' . $formatted_message,
          $this->enrich_origin_context([
            'event' => 'wp_die',
            'title' => is_string($title) ? $title : '',
            'wp_die_args' => is_array($args) ? $this->safe_wp_die_args($args) : [],
          ], $origin['file'], $origin['line']),
          'php_errors'
        );
      }

      call_user_func($downstream_handler, $message, $title, $args);
    };
  }

  /**
   * Adds admin submenu.
   */
  public function betterstack_logger_menu() {
    add_submenu_page(
      'tools.php',
      'BetterStack Logger Settings',
      'BetterStack Logger',
      'manage_options',
      'betterstack-logger',
      [$this, 'settings_page']
    );
  }

  /**
   * Renders settings page.
   */
  public function settings_page() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have permission to access this page.', 'betterstack-logger'));
    }

    if (
      isset($_POST['betterstack_test_log_action'])
      && check_admin_referer('betterstack_test_log_action', 'betterstack_test_log_nonce')
    ) {
      $test_message = isset($_POST['betterstack_test_message'])
        ? sanitize_text_field(wp_unslash($_POST['betterstack_test_message']))
        : 'BetterStack test log';

      $result = $this->log(
        'info',
        $test_message,
        ['event' => 'manual_test_log', 'is_test' => true],
        'custom_debug_logs',
        true
      );

      echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($result) . '</p></div>';
    }

    ?>
    <div class="wrap">
      <h1><?php echo esc_html__('BetterStack Logger Settings', 'betterstack-logger'); ?></h1>
      <p class="description">
        <?php echo esc_html__('This plugin captures application logs from WordPress, PHP runtime, and WooCommerce. It does not capture server-level logs (web server, database, OS).', 'betterstack-logger'); ?>
      </p>
      <form method="post" action="options.php">
        <?php
        settings_fields('betterstack_logger_options_group');
        do_settings_sections('betterstack-logger');
        submit_button();
        ?>
      </form>

      <h2><?php echo esc_html__('Send Test Log', 'betterstack-logger'); ?></h2>
      <form method="post" action="">
        <?php wp_nonce_field('betterstack_test_log_action', 'betterstack_test_log_nonce'); ?>
        <input
          type="text"
          name="betterstack_test_message"
          value=""
          placeholder="<?php echo esc_attr__('Enter test message', 'betterstack-logger'); ?>"
          size="50"
        />
        <input type="hidden" name="betterstack_test_log_action" value="1" />
        <?php submit_button(__('Send Test Log', 'betterstack-logger'), 'secondary', 'submit', false); ?>
      </form>
    </div>
    <?php
  }

  /**
   * Registers plugin settings and fields.
   */
  public function settings_init() {
    register_setting(
      'betterstack_logger_options_group',
      'betterstack_api_key',
      ['sanitize_callback' => [$this, 'sanitize_api_key']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_error_logging_enabled',
      ['sanitize_callback' => [$this, 'sanitize_yes_no']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_event_logging_enabled',
      ['sanitize_callback' => [$this, 'sanitize_yes_no']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_php_errors_enabled',
      ['sanitize_callback' => [$this, 'sanitize_yes_no']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_wp_events_enabled',
      ['sanitize_callback' => [$this, 'sanitize_yes_no']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_wc_events_enabled',
      ['sanitize_callback' => [$this, 'sanitize_yes_no']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_custom_logs_enabled',
      ['sanitize_callback' => [$this, 'sanitize_yes_no']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_min_log_level',
      ['sanitize_callback' => [$this, 'sanitize_min_level']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_environment',
      ['sanitize_callback' => [$this, 'sanitize_environment']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_event_post_types',
      ['sanitize_callback' => [$this, 'sanitize_post_types_csv']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_include_stack_traces',
      ['sanitize_callback' => [$this, 'sanitize_yes_no']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_max_trace_length',
      ['sanitize_callback' => [$this, 'sanitize_trace_length']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_runtime_attribution_enabled',
      ['sanitize_callback' => [$this, 'sanitize_yes_no']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_wc_logger_bridge_enabled',
      ['sanitize_callback' => [$this, 'sanitize_yes_no']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_wc_logger_bridge_min_level',
      ['sanitize_callback' => [$this, 'sanitize_min_level']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_wc_logger_bridge_source_allowlist',
      ['sanitize_callback' => [$this, 'sanitize_source_list_csv']]
    );

    register_setting(
      'betterstack_logger_options_group',
      'betterstack_wc_logger_bridge_source_blocklist',
      ['sanitize_callback' => [$this, 'sanitize_source_list_csv']]
    );

    add_settings_section(
      'betterstack_logger_settings_section',
      'Better Stack Configuration',
      function () {
        echo '<p class="description">Use <code>BETTERSTACK_API_KEY</code> in wp-config.php for the most secure token handling. Database token is a fallback.</p>';
        echo '<p class="description">Logs are sent with WordPress HTTP API using non-blocking mode by default to minimize request impact if Better Stack is slow or unavailable.</p>';
      },
      'betterstack-logger'
    );

    add_settings_field('betterstack_api_key', 'Source Token', [$this, 'render_api_key_field'], 'betterstack-logger', 'betterstack_logger_settings_section');
    add_settings_field('betterstack_environment', 'Environment', [$this, 'render_environment_field'], 'betterstack-logger', 'betterstack_logger_settings_section');
    add_settings_field('betterstack_min_log_level', 'Minimum Log Level', [$this, 'render_min_level_field'], 'betterstack-logger', 'betterstack_logger_settings_section');

    add_settings_field('betterstack_php_errors_enabled', 'PHP Errors', function () {
      $this->render_checkbox_field('betterstack_php_errors_enabled', 'Enable PHP errors, uncaught exceptions, shutdown fatals, and wp_die logging', 'yes');
    }, 'betterstack-logger', 'betterstack_logger_settings_section');

    add_settings_field('betterstack_wp_events_enabled', 'WordPress Events', function () {
      $this->render_checkbox_field('betterstack_wp_events_enabled', 'Enable high-value WordPress events', 'no');
    }, 'betterstack-logger', 'betterstack_logger_settings_section');

    add_settings_field('betterstack_wc_events_enabled', 'WooCommerce Events', function () {
      $this->render_checkbox_field('betterstack_wc_events_enabled', 'Enable checkout/payment/webhook failure logs', 'no');
    }, 'betterstack-logger', 'betterstack_logger_settings_section');

    add_settings_field('betterstack_include_stack_traces', 'Include Stack Traces', function () {
      $this->render_checkbox_field('betterstack_include_stack_traces', 'Include stack traces for exceptions/errors (recommended in staging)', 'yes');
    }, 'betterstack-logger', 'betterstack_logger_settings_section');

    add_settings_field('betterstack_runtime_attribution_enabled', 'Plugin/Runtime Attribution', function () {
      $this->render_checkbox_field('betterstack_runtime_attribution_enabled', 'Attempt plugin/theme/core origin attribution and enable high-value runtime attribution hooks', 'no');
    }, 'betterstack-logger', 'betterstack_logger_settings_section');

    add_settings_field('betterstack_wc_logger_bridge_enabled', 'WooCommerce Logger Bridge', function () {
      $this->render_checkbox_field('betterstack_wc_logger_bridge_enabled', 'Forward WooCommerce native logs (Status > Logs) into Better Stack while keeping WooCommerce logging active', 'no');
    }, 'betterstack-logger', 'betterstack_logger_settings_section');

    add_settings_field('betterstack_wc_logger_bridge_min_level', 'WooCommerce Bridge Min Level', [$this, 'render_wc_bridge_min_level_field'], 'betterstack-logger', 'betterstack_logger_settings_section');
    add_settings_field('betterstack_wc_logger_bridge_source_allowlist', 'WooCommerce Bridge Source Allowlist', [$this, 'render_wc_bridge_allowlist_field'], 'betterstack-logger', 'betterstack_logger_settings_section');
    add_settings_field('betterstack_wc_logger_bridge_source_blocklist', 'WooCommerce Bridge Source Blocklist', [$this, 'render_wc_bridge_blocklist_field'], 'betterstack-logger', 'betterstack_logger_settings_section');

    add_settings_field('betterstack_max_trace_length', 'Max Trace Length', [$this, 'render_trace_length_field'], 'betterstack-logger', 'betterstack_logger_settings_section');

    add_settings_field('betterstack_custom_logs_enabled', 'Custom Debug Logs', function () {
      $this->render_checkbox_field('betterstack_custom_logs_enabled', 'Enable logs from better_error_log() and b_log()', 'yes');
    }, 'betterstack-logger', 'betterstack_logger_settings_section');

    add_settings_field('betterstack_event_post_types', 'WP Event Post Types', [$this, 'render_post_types_field'], 'betterstack-logger', 'betterstack_logger_settings_section');

    // Legacy toggles remain visible for compatibility with old installs.
    add_settings_field('betterstack_error_logging_enabled', 'Legacy Error Toggle', function () {
      $this->render_checkbox_field('betterstack_error_logging_enabled', 'Legacy compatibility toggle (maps to PHP Errors)', 'yes');
    }, 'betterstack-logger', 'betterstack_logger_settings_section');

    add_settings_field('betterstack_event_logging_enabled', 'Legacy Event Toggle', function () {
      $this->render_checkbox_field('betterstack_event_logging_enabled', 'Legacy compatibility toggle (maps to WordPress Events)', 'no');
    }, 'betterstack-logger', 'betterstack_logger_settings_section');
  }

  /**
   * PHP runtime error handler.
   *
   * @return bool false lets normal PHP error handling continue.
   */
  public function handle_php_error($errno, $errstr, $errfile, $errline) {
    if (!$this->is_category_enabled('php_errors')) {
      return false;
    }

    if (!(error_reporting() & $errno)) {
      return false;
    }

    $this->log(
      $this->map_php_error_to_level((int) $errno),
      'PHP runtime error: ' . (string) $errstr,
      $this->enrich_origin_context([
        'event' => 'php_error',
        'error_type' => $this->php_error_label((int) $errno),
      ], (string) $errfile, (int) $errline),
      'php_errors'
    );

    return false;
  }

  /**
   * Uncaught exception handler.
   */
  public function handle_uncaught_exception($exception) {
    if (!$exception instanceof Throwable) {
      return;
    }

    if ($this->is_category_enabled('php_errors')) {
      $trace = $this->should_include_traces() ? $this->truncate($exception->getTraceAsString(), $this->get_max_trace_length()) : '';

      $this->log(
        'critical',
        'Uncaught exception: ' . $exception->getMessage(),
        $this->enrich_origin_context([
          'event' => 'uncaught_exception',
          'exception_class' => get_class($exception),
          'file' => (string) $exception->getFile(),
          'line' => (int) $exception->getLine(),
          'trace' => $trace,
        ], (string) $exception->getFile(), (int) $exception->getLine()),
        'php_errors'
      );
    }

    if (is_callable($this->previous_exception_handler)) {
      call_user_func($this->previous_exception_handler, $exception);
    }
  }

  /**
   * Shutdown hook to capture fatal errors.
   */
  public function handle_shutdown() {
    if (!$this->is_category_enabled('php_errors')) {
      return;
    }

    $error = error_get_last();
    if (!is_array($error)) {
      return;
    }

    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!isset($error['type']) || !in_array((int) $error['type'], $fatal_types, true)) {
      return;
    }

    $fatal_file = (string) ($error['file'] ?? '');
    $fatal_line = (int) ($error['line'] ?? 0);

    $this->log(
      'critical',
      'Fatal shutdown error: ' . (string) ($error['message'] ?? ''),
      $this->enrich_origin_context([
        'event' => 'fatal_shutdown',
        'error_type' => $this->php_error_label((int) $error['type']),
      ], $fatal_file, $fatal_line),
      'php_errors',
      true
    );
  }

  /**
   * Runtime attribution hooks for high-value plugin-related failures.
   */
  public function on_http_api_debug($response, $context, $class, $parsed_args, $url) {
    if (!$this->is_runtime_attribution_enabled() || !$this->is_category_enabled('php_errors')) {
      return;
    }

    if ($context !== 'response') {
      return;
    }

    $http_code = 0;
    $is_failure = false;

    if (is_wp_error($response)) {
      $is_failure = true;
    } elseif (is_array($response)) {
      $http_code = (int) wp_remote_retrieve_response_code($response);
      $is_failure = $http_code >= 400;
    }

    if (!$is_failure) {
      return;
    }

    $origin = $this->infer_origin_from_backtrace();
    $context_payload = [
      'event' => 'http_api_failure',
      'hook' => 'http_api_debug',
      'http_code' => $http_code,
      'transport_class' => is_string($class) ? $class : '',
      'request_method' => is_array($parsed_args) && isset($parsed_args['method']) ? sanitize_text_field((string) $parsed_args['method']) : '',
      'target_host' => wp_parse_url((string) $url, PHP_URL_HOST),
      'target_path' => wp_parse_url((string) $url, PHP_URL_PATH),
    ];

    if (is_wp_error($response)) {
      $context_payload['wp_error_codes'] = $response->get_error_codes();
      $context_payload['wp_error_messages'] = $response->get_error_messages();
    }

    $this->log(
      is_wp_error($response) ? 'error' : 'warning',
      'WordPress HTTP API request failed.',
      $this->enrich_origin_context($context_payload, $origin['file'], $origin['line']),
      'php_errors'
    );
  }

  public function on_doing_it_wrong_run($function_name, $message, $version) {
    if (!$this->is_runtime_attribution_enabled() || !$this->is_category_enabled('php_errors')) {
      return;
    }

    $origin = $this->infer_origin_from_backtrace();
    $this->log(
      'warning',
      'WordPress doing_it_wrong notice: ' . sanitize_text_field((string) $function_name),
      $this->enrich_origin_context([
        'event' => 'doing_it_wrong_run',
        'hook' => 'doing_it_wrong_run',
        'function_name' => sanitize_text_field((string) $function_name),
        'message' => $this->truncate((string) $message, self::MAX_MESSAGE_LENGTH),
        'version' => sanitize_text_field((string) $version),
      ], $origin['file'], $origin['line']),
      'php_errors'
    );
  }

  public function on_deprecated_function_run($function, $replacement, $version) {
    if (!$this->is_runtime_attribution_enabled() || !$this->is_category_enabled('php_errors')) {
      return;
    }

    $origin = $this->infer_origin_from_backtrace();
    $this->log(
      'warning',
      'Deprecated function used: ' . sanitize_text_field((string) $function),
      $this->enrich_origin_context([
        'event' => 'deprecated_function_run',
        'hook' => 'deprecated_function_run',
        'function_name' => sanitize_text_field((string) $function),
        'replacement' => sanitize_text_field((string) $replacement),
        'version' => sanitize_text_field((string) $version),
      ], $origin['file'], $origin['line']),
      'php_errors'
    );
  }

  /**
   * Forwards entries emitted via WooCommerce's native logger (wc_get_logger).
   *
   * This is additive: WooCommerce keeps its own handlers (file/DB/etc), and this method
   * only bridges those same entries into Better Stack.
   *
   * @param int|string $timestamp
   * @param string $level
   * @param string $message
   * @param array<string, mixed> $context
   */
  public function forward_wc_logger_entry($timestamp, $level, $message, $context = []) {
    if (!$this->is_wc_logger_bridge_enabled()) {
      return;
    }

    $context = is_array($context) ? $context : [];
    $message = is_string($message) ? $message : (string) $message;
    $wc_level = sanitize_key((string) $level);
    $mapped_level = $this->map_wc_level_to_logger_level($wc_level);

    if (!$this->passes_wc_bridge_min_level($mapped_level)) {
      return;
    }

    $source = isset($context['source']) ? sanitize_key((string) $context['source']) : 'woocommerce';
    if (!$this->is_wc_bridge_source_allowed($source)) {
      return;
    }

    $source_classification = $this->classify_source_from_wc_log_source($source);

    $wc_context = $context;
    unset($wc_context['backtrace']);

    $bridge_context = [
      'event' => 'woocommerce_logger_entry',
      'log_origin' => 'woocommerce_logger_bridge',
      'wc_log_level' => $wc_level,
      'wc_log_source' => $source,
      'wc_log_timestamp' => is_scalar($timestamp) ? (string) $timestamp : '',
      'wc_log_context' => $wc_context,
      'betterstack_category' => 'woocommerce_logger_bridge',
      'code_source_type' => $source_classification['code_source_type'],
      'plugin_slug' => $source_classification['plugin_slug'],
    ];

    $this->log(
      $mapped_level,
      $message,
      $bridge_context,
      'woocommerce_logger_bridge'
    );
  }

  /**
   * WP event handlers.
   */
  public function on_wp_login($user_login, $user) {
    $this->log('info', 'User logged in.', [
      'event' => 'wp_login',
      'user_id' => $user instanceof WP_User ? $user->ID : 0,
      'username' => is_string($user_login) ? $user_login : '',
    ], 'wordpress_events');
  }

  public function on_user_register($user_id) {
    $this->log('info', 'User registered.', [
      'event' => 'user_register',
      'user_id' => (int) $user_id,
    ], 'wordpress_events');
  }

  public function on_profile_update($user_id) {
    $this->log('info', 'User profile updated.', [
      'event' => 'profile_update',
      'user_id' => (int) $user_id,
    ], 'wordpress_events');
  }

  public function on_delete_user($user_id) {
    $this->log('warning', 'User deleted.', [
      'event' => 'delete_user',
      'user_id' => (int) $user_id,
    ], 'wordpress_events');
  }

  public function on_save_post($post_id, $post, $update) {
    if (!($post instanceof WP_Post)) {
      return;
    }

    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
      return;
    }

    $allowed_post_types = $this->get_allowed_post_types();
    if (!empty($allowed_post_types) && !in_array($post->post_type, $allowed_post_types, true)) {
      return;
    }

    $this->log('info', $update ? 'Post updated.' : 'Post created.', [
      'event' => 'save_post',
      'post_id' => (int) $post_id,
      'post_type' => $post->post_type,
      'post_status' => $post->post_status,
    ], 'wordpress_events');
  }

  public function on_delete_post($post_id) {
    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
      return;
    }

    $this->log('warning', 'Post deleted.', [
      'event' => 'delete_post',
      'post_id' => (int) $post_id,
      'post_type' => $post->post_type,
    ], 'wordpress_events');
  }

  public function on_plugin_activated($plugin) {
    $this->log('info', 'Plugin activated.', [
      'event' => 'activated_plugin',
      'plugin' => sanitize_text_field((string) $plugin),
    ], 'wordpress_events');
  }

  public function on_plugin_deactivated($plugin) {
    $this->log('warning', 'Plugin deactivated.', [
      'event' => 'deactivated_plugin',
      'plugin' => sanitize_text_field((string) $plugin),
    ], 'wordpress_events');
  }

  public function on_switch_theme($new_name, $new_theme) {
    $stylesheet = '';
    if ($new_theme instanceof WP_Theme) {
      $stylesheet = $new_theme->get_stylesheet();
    }

    $this->log('warning', 'Theme switched.', [
      'event' => 'switch_theme',
      'theme_name' => sanitize_text_field((string) $new_name),
      'theme_stylesheet' => sanitize_text_field($stylesheet),
    ], 'wordpress_events');
  }

  /**
   * WooCommerce observability hooks.
   */
  public function on_wc_checkout_exception($exception, $data = []) {
    $message = is_string($exception) ? $exception : 'Checkout exception encountered.';
    $this->log_woocommerce_event('checkout_exception', $message, [
      'checkout_keys' => is_array($data) ? array_slice(array_keys($data), 0, 30) : [],
    ], 'error');
  }

  public function on_wc_payment_failed($order_id) {
    $this->log_woocommerce_event('payment_failed', 'WooCommerce payment failed.', [
      'order_id' => (int) $order_id,
    ], 'error');
  }

  public function on_wc_order_failed($order_id) {
    $this->log_woocommerce_event('order_status_failed', 'WooCommerce order moved to failed status.', [
      'order_id' => (int) $order_id,
    ], 'warning');
  }

  public function on_wc_webhook_delivery_failed($webhook = null, $arg2 = null) {
    $webhook_id = 0;
    if (is_object($webhook) && method_exists($webhook, 'get_id')) {
      $webhook_id = (int) $webhook->get_id();
    }

    $context = [
      'webhook_id' => $webhook_id,
      'event' => 'webhook_delivery_failed',
    ];

    if (is_scalar($arg2)) {
      $context['details'] = (string) $arg2;
    }

    $this->log_woocommerce_event('webhook_delivery_failed', 'WooCommerce webhook delivery failed.', $context, 'error');
  }

  /**
   * Determines if category is enabled with legacy fallbacks.
   */
  private function is_category_enabled($category) {
    switch ($category) {
      case 'php_errors':
        return $this->get_yes_no_option(
          'betterstack_php_errors_enabled',
          get_option('betterstack_error_logging_enabled', 'yes')
        ) === 'yes';

      case 'wordpress_events':
        return $this->get_yes_no_option(
          'betterstack_wp_events_enabled',
          get_option('betterstack_event_logging_enabled', 'no')
        ) === 'yes';

      case 'woocommerce_events':
        return $this->get_yes_no_option('betterstack_wc_events_enabled', 'no') === 'yes';

      case 'woocommerce_logger_bridge':
        return $this->is_wc_logger_bridge_enabled();

      case 'custom_debug_logs':
      default:
        return $this->get_yes_no_option('betterstack_custom_logs_enabled', 'yes') === 'yes';
    }
  }

  /**
   * Sends a structured payload to Better Stack using WordPress HTTP API.
   *
   * Non-blocking mode is the default to avoid adding request latency to frontend/admin traffic.
   * Blocking mode is reserved for flows that need immediate feedback (for example, test logs).
   *
   * Applies `betterstack_logger_timeout` with signature:
   * apply_filters('betterstack_logger_timeout', $timeout, $blocking)
   */
  private function send_payload(array $payload, $blocking = false) {
    $token = $this->get_api_key();
    if ($token === '') {
      return 'Skipped: API key is not set.';
    }

    if ($this->is_transporting) {
      return 'Skipped: recursive transport prevented.';
    }

    $json_body = wp_json_encode($payload);
    if (!is_string($json_body) || $json_body === '') {
      return 'Skipped: unable to encode payload.';
    }

    // Keep transport non-blocking by default to protect frontend/admin request latency.
    $request_timeout = max(0.1, (float) apply_filters('betterstack_logger_timeout', $blocking ? 4 : 2, (bool) $blocking));

    $this->is_transporting = true;
    $response = wp_remote_post(
      self::ENDPOINT,
      [
        'body' => $json_body,
        'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer ' . $token,
        ],
        'timeout' => $request_timeout,
        'blocking' => (bool) $blocking,
        'data_format' => 'body',
      ]
    );
    $this->is_transporting = false;

    if (is_wp_error($response)) {
      return 'Log send failed (non-blocking behavior preserved).';
    }

    if (!$blocking) {
      return 'Log queued for delivery.';
    }

    $http_code = (int) wp_remote_retrieve_response_code($response);
    if ($http_code === 202 || $http_code === 200) {
      return 'Log delivered successfully.';
    }

    return 'Log send returned HTTP ' . $http_code . '.';
  }

  /**
   * Creates final structured payload for Better Stack.
   */
  private function build_payload($level, $message, array $context, $category) {
    global $wp_version;

    $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
    $request_uri = isset($_SERVER['REQUEST_URI']) ? $this->sanitize_request_uri(wp_unslash($_SERVER['REQUEST_URI'])) : '';

    $user_id = get_current_user_id();
    $current_user = $user_id ? get_userdata($user_id) : false;
    $safe_username = '';
    if ($current_user instanceof WP_User && is_admin()) {
      $safe_username = $current_user->user_login;
    }

    $safe_context = $this->sanitize_context($context);

    return [
      'timestamp' => gmdate(DATE_ATOM),
      'dt' => gmdate('Y-m-d H:i:s') . ' UTC',
      'level' => $level,
      'message' => $message,
      'environment' => get_option('betterstack_environment', 'production'),
      'source' => self::SOURCE,
      'category' => $category,
      'site_url' => home_url('/'),
      'host' => wp_parse_url(home_url('/'), PHP_URL_HOST),
      'wp_version' => is_string($wp_version) ? $wp_version : '',
      'php_version' => PHP_VERSION,
      'request_method' => $request_method,
      'request_uri' => $request_uri,
      'request_has_post_data' => !empty($_POST),
      'request_post_keys' => !empty($_POST) ? array_slice(array_map('sanitize_key', array_keys($_POST)), 0, 25) : [],
      'user_id' => $user_id,
      'username' => $safe_username,
      'hook' => isset($safe_context['event']) ? $safe_context['event'] : '',
      'code_source_type' => isset($safe_context['code_source_type']) ? $safe_context['code_source_type'] : '',
      'plugin_slug' => isset($safe_context['plugin_slug']) ? $safe_context['plugin_slug'] : '',
      'origin_file' => isset($safe_context['origin_file']) ? $safe_context['origin_file'] : '',
      'origin_line' => isset($safe_context['origin_line']) ? (int) $safe_context['origin_line'] : 0,
      'context' => $safe_context,
    ];
  }

  /**
   * Registers PHP-level handlers.
   */
  private function register_error_handlers() {
    set_error_handler([$this, 'handle_php_error']);
    $this->previous_exception_handler = set_exception_handler([$this, 'handle_uncaught_exception']);
    register_shutdown_function([$this, 'handle_shutdown']);
  }

  /**
   * Registers additional high-value runtime attribution hooks.
   */
  private function register_runtime_attribution_hooks() {
    if (!$this->is_runtime_attribution_enabled()) {
      return;
    }

    add_action('http_api_debug', [$this, 'on_http_api_debug'], 10, 5);
    add_action('doing_it_wrong_run', [$this, 'on_doing_it_wrong_run'], 10, 3);
    add_action('deprecated_function_run', [$this, 'on_deprecated_function_run'], 10, 3);
  }

  /**
   * Registers additive WooCommerce logger bridge hooks.
   */
  private function register_woocommerce_logger_bridge_hooks() {
    // Register regardless of plugin load order; WooCommerce will apply this filter when available.
    add_filter('woocommerce_register_log_handlers', [$this, 'register_wc_bridge_handler'], 20, 1);
  }

  /**
   * Adds BetterStack handler to WooCommerce logger handlers without replacing existing handlers.
   *
   * @param array<int, string> $handlers
   * @return array<int, string>
   */
  public function register_wc_bridge_handler($handlers) {
    if (!$this->is_wc_logger_bridge_enabled()) {
      return $handlers;
    }

    if (!is_array($handlers)) {
      $handlers = [];
    }

    if (!class_exists('WC_Log_Handler')) {
      return $handlers;
    }

    require_once __DIR__ . '/WCLogHandlerBetterStack.php';
    if (!class_exists('BetterStackWCLogHandlerV2')) {
      return $handlers;
    }

    $interfaces = class_implements('BetterStackWCLogHandlerV2');
    if (!is_array($interfaces) || !in_array('WC_Log_Handler_Interface', $interfaces, true)) {
      return $handlers;
    }

    if (!in_array('BetterStackWCLogHandlerV2', $handlers, true)) {
      $handlers[] = 'BetterStackWCLogHandlerV2';
    }

    return $handlers;
  }

  /**
   * Registers high-value WordPress events.
   */
  private function register_wordpress_hooks() {
    if (!$this->is_category_enabled('wordpress_events')) {
      return;
    }

    add_action('wp_login', [$this, 'on_wp_login'], 10, 2);
    add_action('user_register', [$this, 'on_user_register'], 10, 1);
    add_action('profile_update', [$this, 'on_profile_update'], 10, 1);
    add_action('delete_user', [$this, 'on_delete_user'], 10, 1);
    add_action('save_post', [$this, 'on_save_post'], 10, 3);
    add_action('delete_post', [$this, 'on_delete_post'], 10, 1);
    add_action('activated_plugin', [$this, 'on_plugin_activated'], 10, 1);
    add_action('deactivated_plugin', [$this, 'on_plugin_deactivated'], 10, 1);
    add_action('switch_theme', [$this, 'on_switch_theme'], 10, 2);
  }

  /**
   * Registers WooCommerce failure-focused hooks.
   */
  private function register_woocommerce_hooks() {
    if (!$this->is_category_enabled('woocommerce_events') || !class_exists('WooCommerce')) {
      return;
    }

    add_action('woocommerce_checkout_order_exception', [$this, 'on_wc_checkout_exception'], 10, 2);
    add_action('woocommerce_payment_failed', [$this, 'on_wc_payment_failed'], 10, 1);
    add_action('woocommerce_order_status_failed', [$this, 'on_wc_order_failed'], 10, 1);
    add_action('woocommerce_webhook_delivery_failed', [$this, 'on_wc_webhook_delivery_failed'], 10, 2);
  }

  private function get_api_key() {
    if (defined('BETTERSTACK_API_KEY') && is_string(BETTERSTACK_API_KEY) && BETTERSTACK_API_KEY !== '') {
      return trim(BETTERSTACK_API_KEY);
    }

    $stored = get_option('betterstack_api_key', '');
    return is_string($stored) ? trim($stored) : '';
  }

  private function normalize_level($level) {
    $level = strtolower((string) $level);
    return isset(self::LEVEL_RANK[$level]) ? $level : 'error';
  }

  private function passes_minimum_level($level) {
    $minimum = $this->normalize_level((string) get_option('betterstack_min_log_level', 'warning'));
    return self::LEVEL_RANK[$level] >= self::LEVEL_RANK[$minimum];
  }

  private function sanitize_context(array $context) {
    $safe_context = $this->redact_data($context);

    if (isset($safe_context['trace']) && is_string($safe_context['trace'])) {
      if (!$this->should_include_traces()) {
        unset($safe_context['trace']);
      } else {
        $safe_context['trace'] = $this->truncate($safe_context['trace'], $this->get_max_trace_length());
      }
    }

    return $safe_context;
  }

  private function enrich_origin_context(array $context, $file = '', $line = 0) {
    $origin_file = is_string($file) ? wp_normalize_path($file) : '';
    $origin_line = (int) $line;

    if ($origin_file !== '') {
      $context['file'] = $origin_file;
      $context['line'] = $origin_line;
      $context['origin_file'] = $origin_file;
      $context['origin_line'] = $origin_line;
    }

    if (!$this->is_runtime_attribution_enabled() || $origin_file === '') {
      return $context;
    }

    $classification = $this->classify_source_from_file($origin_file);
    $context['code_source_type'] = $classification['code_source_type'];
    if ($classification['plugin_slug'] !== '') {
      $context['plugin_slug'] = $classification['plugin_slug'];
    }

    return $context;
  }

  private function classify_source_from_file($file_path) {
    $normalized = wp_normalize_path((string) $file_path);
    if ($normalized === '') {
      return ['code_source_type' => self::SOURCE_CUSTOM, 'plugin_slug' => ''];
    }

    $plugin_base = wp_normalize_path(WP_PLUGIN_DIR) . '/';
    if (strpos($normalized, $plugin_base) === 0) {
      $relative = substr($normalized, strlen($plugin_base));
      $parts = explode('/', $relative);
      $slug = isset($parts[0]) ? sanitize_key($parts[0]) : '';
      return ['code_source_type' => self::SOURCE_PLUGIN, 'plugin_slug' => $slug];
    }

    if (defined('WPMU_PLUGIN_DIR')) {
      $mu_base = wp_normalize_path(WPMU_PLUGIN_DIR) . '/';
      if (strpos($normalized, $mu_base) === 0) {
        $relative = substr($normalized, strlen($mu_base));
        $parts = explode('/', $relative);
        $first = isset($parts[0]) ? $parts[0] : '';
        $slug = strpos($relative, '/') === false ? sanitize_key(pathinfo($first, PATHINFO_FILENAME)) : sanitize_key($first);
        return ['code_source_type' => self::SOURCE_MU_PLUGIN, 'plugin_slug' => $slug];
      }
    }

    if (function_exists('get_theme_roots')) {
      $roots = get_theme_roots();
      if (is_array($roots)) {
        foreach (array_keys($roots) as $root) {
          $theme_base = wp_normalize_path(WP_CONTENT_DIR . '/' . ltrim((string) $root, '/')) . '/';
          if (strpos($normalized, $theme_base) === 0) {
            return ['code_source_type' => self::SOURCE_THEME, 'plugin_slug' => ''];
          }
        }
      }
    }

    $theme_root = wp_normalize_path(get_theme_root()) . '/';
    if (strpos($normalized, $theme_root) === 0) {
      return ['code_source_type' => self::SOURCE_THEME, 'plugin_slug' => ''];
    }

    $core_base = wp_normalize_path(ABSPATH);
    if ($core_base !== '' && strpos($normalized, $core_base) === 0) {
      return ['code_source_type' => self::SOURCE_CORE, 'plugin_slug' => ''];
    }

    return ['code_source_type' => self::SOURCE_CUSTOM, 'plugin_slug' => ''];
  }

  private function infer_origin_from_backtrace() {
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);
    $self_file = wp_normalize_path(__FILE__);

    foreach ($trace as $frame) {
      $file = isset($frame['file']) ? wp_normalize_path((string) $frame['file']) : '';
      if ($file === '' || $file === $self_file) {
        continue;
      }

      return [
        'file' => $file,
        'line' => isset($frame['line']) ? (int) $frame['line'] : 0,
      ];
    }

    return ['file' => '', 'line' => 0];
  }

  private function extract_message($message) {
    if (is_wp_error($message)) {
      return $this->truncate(implode('; ', $message->get_error_messages()), self::MAX_MESSAGE_LENGTH);
    }

    if (is_scalar($message)) {
      return $this->truncate(wp_strip_all_tags((string) $message), self::MAX_MESSAGE_LENGTH);
    }

    return $this->truncate(wp_strip_all_tags(wp_json_encode($this->redact_data($message))), self::MAX_MESSAGE_LENGTH);
  }

  private function safe_wp_die_args(array $args) {
    $allowed_keys = ['response', 'back_link', 'text_direction'];
    $filtered = [];

    foreach ($allowed_keys as $key) {
      if (isset($args[$key])) {
        $filtered[$key] = $args[$key];
      }
    }

    return $this->redact_data($filtered);
  }

  private function sanitize_request_uri($request_uri) {
    $request_uri = (string) $request_uri;
    $parts = wp_parse_url($request_uri);

    if (!is_array($parts)) {
      return $this->truncate($this->redact_data($request_uri), 400);
    }

    $path = isset($parts['path']) ? $parts['path'] : '';
    return $this->truncate($path, 400);
  }

  private function redact_data($data, $key_name = '') {
    $key_name = strtolower((string) $key_name);
    $sensitive_key_match = preg_match('/(authorization|cookie|pass(word)?|pwd|token|api[_-]?key|secret|nonce|card|cvv|cvc|expiry|exp_month|exp_year|billing|payment)/i', $key_name) === 1;
    if ($sensitive_key_match) {
      return '[REDACTED]';
    }

    if (is_array($data)) {
      $sanitized = [];
      foreach ($data as $k => $value) {
        $safe_key = is_string($k) ? sanitize_key($k) : $k;
        $sanitized[$safe_key] = $this->redact_data($value, (string) $k);
      }
      return $sanitized;
    }

    if (is_object($data)) {
      return $this->redact_data(get_object_vars($data), $key_name);
    }

    if (is_string($data)) {
      $value = $data;
      $value = preg_replace('/(bearer\s+)[a-z0-9\-\._~\+\/]+=*/i', '$1[REDACTED]', $value);
      $value = preg_replace('/([?&](?:_wpnonce|nonce|token|api_key|key)=)[^&]+/i', '$1[REDACTED]', $value);
      $value = preg_replace('/(authorization:\s*)(.+)/i', '$1[REDACTED]', $value);
      $value = preg_replace('/(cookie:\s*)(.+)/i', '$1[REDACTED]', $value);
      return $value;
    }

    return $data;
  }

  private function is_duplicate(array $payload) {
    $now = time();

    foreach ($this->recent_hashes as $hash => $timestamp) {
      if (($now - $timestamp) > self::DUPLICATE_TTL_SECONDS) {
        unset($this->recent_hashes[$hash]);
      }
    }

    if (count($this->recent_hashes) > self::DUPLICATE_CACHE_LIMIT) {
      $this->recent_hashes = array_slice($this->recent_hashes, -1 * self::DUPLICATE_CACHE_LIMIT, null, true);
    }

    $context = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : [];
    $signature = [
      $payload['level'] ?? '',
      $payload['message'] ?? '',
      $payload['category'] ?? '',
      $payload['hook'] ?? '',
      $payload['request_uri'] ?? '',
      $context['event'] ?? '',
      $context['file'] ?? '',
      $context['line'] ?? '',
      $context['post_id'] ?? '',
      $context['order_id'] ?? '',
      $context['user_id'] ?? '',
    ];
    $hash = md5(wp_json_encode($signature));

    if (isset($this->recent_hashes[$hash])) {
      return true;
    }

    $this->recent_hashes[$hash] = $now;
    return false;
  }

  private function get_allowed_post_types() {
    $csv = get_option('betterstack_event_post_types', 'post,page,product,shop_order');
    if (!is_string($csv) || trim($csv) === '') {
      return [];
    }

    $types = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $csv))));
    return array_values(array_unique($types));
  }

  private function map_php_error_to_level($errno) {
    if (in_array($errno, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true)) {
      return 'warning';
    }

    if (in_array($errno, [E_WARNING, E_USER_WARNING, E_RECOVERABLE_ERROR], true)) {
      return 'error';
    }

    if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
      return 'critical';
    }

    return 'error';
  }

  private function php_error_label($errno) {
    $map = [
      E_ERROR => 'E_ERROR',
      E_WARNING => 'E_WARNING',
      E_PARSE => 'E_PARSE',
      E_NOTICE => 'E_NOTICE',
      E_CORE_ERROR => 'E_CORE_ERROR',
      E_CORE_WARNING => 'E_CORE_WARNING',
      E_COMPILE_ERROR => 'E_COMPILE_ERROR',
      E_COMPILE_WARNING => 'E_COMPILE_WARNING',
      E_USER_ERROR => 'E_USER_ERROR',
      E_USER_WARNING => 'E_USER_WARNING',
      E_USER_NOTICE => 'E_USER_NOTICE',
      E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
      E_DEPRECATED => 'E_DEPRECATED',
      E_USER_DEPRECATED => 'E_USER_DEPRECATED',
    ];

    return isset($map[$errno]) ? $map[$errno] : 'UNKNOWN_ERROR';
  }

  private function truncate($text, $max_length) {
    $text = (string) $text;
    return strlen($text) > $max_length ? substr($text, 0, $max_length) . '... [truncated]' : $text;
  }

  private function get_yes_no_option($name, $default = 'no') {
    $value = get_option($name, $default);
    return $value === 'yes' ? 'yes' : 'no';
  }

  private function is_runtime_attribution_enabled() {
    return $this->get_yes_no_option('betterstack_runtime_attribution_enabled', 'no') === 'yes';
  }

  public function sanitize_yes_no($value) {
    return $value === 'yes' ? 'yes' : 'no';
  }

  public function sanitize_api_key($value) {
    if (defined('BETTERSTACK_API_KEY')) {
      return get_option('betterstack_api_key', '');
    }

    $value = is_string($value) ? trim($value) : '';
    return sanitize_text_field($value);
  }

  public function sanitize_min_level($value) {
    $normalized = $this->normalize_level($value);
    return $normalized;
  }

  public function sanitize_environment($value) {
    $allowed = ['production', 'staging', 'local'];
    $value = sanitize_key((string) $value);
    return in_array($value, $allowed, true) ? $value : 'production';
  }

  public function sanitize_post_types_csv($value) {
    $value = is_string($value) ? $value : '';
    $types = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $value))));
    return implode(',', array_unique($types));
  }

  public function sanitize_trace_length($value) {
    $length = absint($value);

    if ($length < 500) {
      return 500;
    }

    if ($length > 20000) {
      return 20000;
    }

    return $length;
  }

  public function sanitize_source_list_csv($value) {
    $value = is_string($value) ? $value : '';
    $sources = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $value))));
    return implode(',', array_unique($sources));
  }

  private function render_checkbox_field($name, $label, $default = 'no') {
    $value = $this->get_yes_no_option($name, $default);
    echo '<label><input type="hidden" name="' . esc_attr($name) . '" value="no" />';
    echo '<input type="checkbox" name="' . esc_attr($name) . '" value="yes" ' . checked($value, 'yes', false) . ' /> ';
    echo esc_html($label) . '</label>';
  }

  public function render_api_key_field() {
    if (defined('BETTERSTACK_API_KEY')) {
      echo '<input type="password" value="********" size="50" readonly autocomplete="off" />';
      echo '<p class="description">Token source: <code>wp-config.php</code> (read-only).</p>';
      return;
    }

    $api_key = get_option('betterstack_api_key', '');
    echo '<input type="password" name="betterstack_api_key" value="' . esc_attr((string) $api_key) . '" size="50" autocomplete="off" />';
    echo '<p class="description">Stored in WordPress options only when no wp-config token is defined.</p>';
  }

  public function render_environment_field() {
    $value = get_option('betterstack_environment', 'production');
    $allowed = ['production', 'staging', 'local'];

    echo '<select name="betterstack_environment">';
    foreach ($allowed as $option) {
      echo '<option value="' . esc_attr($option) . '" ' . selected($value, $option, false) . '>' . esc_html(ucfirst($option)) . '</option>';
    }
    echo '</select>';
  }

  public function render_min_level_field() {
    $value = $this->normalize_level((string) get_option('betterstack_min_log_level', 'warning'));

    echo '<select name="betterstack_min_log_level">';
    foreach (array_keys(self::LEVEL_RANK) as $level) {
      echo '<option value="' . esc_attr($level) . '" ' . selected($value, $level, false) . '>' . esc_html(ucfirst($level)) . '</option>';
    }
    echo '</select>';
  }

  public function render_post_types_field() {
    $value = get_option('betterstack_event_post_types', 'post,page,product,shop_order');
    echo '<input type="text" name="betterstack_event_post_types" value="' . esc_attr((string) $value) . '" size="50" />';
    echo '<p class="description">Comma-separated post types for save_post logs. Leave empty to allow all post types.</p>';
  }

  public function render_trace_length_field() {
    $value = (int) get_option('betterstack_max_trace_length', self::MAX_TRACE_LENGTH);
    echo '<input type="number" name="betterstack_max_trace_length" value="' . esc_attr((string) $value) . '" min="500" max="20000" step="100" />';
    echo '<p class="description">Maximum number of characters kept for stack traces.</p>';
  }

  public function render_wc_bridge_min_level_field() {
    $value = $this->normalize_level((string) get_option('betterstack_wc_logger_bridge_min_level', 'error'));

    echo '<select name="betterstack_wc_logger_bridge_min_level">';
    foreach (array_keys(self::LEVEL_RANK) as $level) {
      echo '<option value="' . esc_attr($level) . '" ' . selected($value, $level, false) . '>' . esc_html(ucfirst($level)) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">Only WooCommerce logger entries at or above this mapped level are forwarded.</p>';
  }

  public function render_wc_bridge_allowlist_field() {
    $value = get_option('betterstack_wc_logger_bridge_source_allowlist', '');
    echo '<input type="text" name="betterstack_wc_logger_bridge_source_allowlist" value="' . esc_attr((string) $value) . '" size="50" />';
    echo '<p class="description">Optional comma-separated WooCommerce log sources to allow (example: mailchimp_woocommerce,shopify_woocommerce_bridge).</p>';
  }

  public function render_wc_bridge_blocklist_field() {
    $value = get_option('betterstack_wc_logger_bridge_source_blocklist', '');
    echo '<input type="text" name="betterstack_wc_logger_bridge_source_blocklist" value="' . esc_attr((string) $value) . '" size="50" />';
    echo '<p class="description">Optional comma-separated WooCommerce log sources to suppress.</p>';
  }

  /**
   * Determines whether traces are included after applying settings + runtime filter.
   *
   * Applies `betterstack_logger_include_traces` with signature:
   * apply_filters('betterstack_logger_include_traces', $enabled)
   */
  private function should_include_traces() {
    $enabled = $this->get_yes_no_option('betterstack_include_stack_traces', 'yes') === 'yes';
    return (bool) apply_filters('betterstack_logger_include_traces', $enabled);
  }

  /**
   * Returns the trace length cap after applying settings + runtime filter.
   *
   * Applies `betterstack_logger_max_trace_length` with signature:
   * apply_filters('betterstack_logger_max_trace_length', $max_length)
   */
  private function get_max_trace_length() {
    $configured = (int) get_option('betterstack_max_trace_length', self::MAX_TRACE_LENGTH);
    $clamped = $this->sanitize_trace_length($configured);
    return (int) apply_filters('betterstack_logger_max_trace_length', $clamped);
  }

  private function is_wc_logger_bridge_enabled() {
    return $this->get_yes_no_option('betterstack_wc_logger_bridge_enabled', 'no') === 'yes';
  }

  private function map_wc_level_to_logger_level($wc_level) {
    $wc_level = sanitize_key((string) $wc_level);

    switch ($wc_level) {
      case 'emergency':
      case 'alert':
      case 'critical':
        return 'critical';
      case 'error':
        return 'error';
      case 'warning':
        return 'warning';
      case 'notice':
      case 'info':
        return 'info';
      case 'debug':
      default:
        return 'debug';
    }
  }

  private function passes_wc_bridge_min_level($mapped_level) {
    $minimum = $this->normalize_level((string) get_option('betterstack_wc_logger_bridge_min_level', 'error'));
    return self::LEVEL_RANK[$mapped_level] >= self::LEVEL_RANK[$minimum];
  }

  private function parse_source_list_option($option_name) {
    $raw = get_option($option_name, '');
    if (!is_string($raw) || trim($raw) === '') {
      return [];
    }

    return array_values(array_unique(array_filter(array_map('sanitize_key', array_map('trim', explode(',', $raw))))));
  }

  private function is_wc_bridge_source_allowed($source) {
    $source = sanitize_key((string) $source);
    $allowlist = $this->parse_source_list_option('betterstack_wc_logger_bridge_source_allowlist');
    $blocklist = $this->parse_source_list_option('betterstack_wc_logger_bridge_source_blocklist');

    if (!empty($allowlist) && !in_array($source, $allowlist, true)) {
      return false;
    }

    if (!empty($blocklist) && in_array($source, $blocklist, true)) {
      return false;
    }

    return true;
  }

  private function classify_source_from_wc_log_source($source) {
    $source = sanitize_key((string) $source);
    if ($source === '') {
      return ['code_source_type' => self::SOURCE_CUSTOM, 'plugin_slug' => ''];
    }

    if ($source === 'woocommerce' || strpos($source, 'wc_') === 0) {
      return ['code_source_type' => self::SOURCE_PLUGIN, 'plugin_slug' => 'woocommerce'];
    }

    $plugin_slugs = $this->get_plugin_slugs();
    foreach ($plugin_slugs as $slug) {
      if ($source === $slug || strpos($source, $slug) !== false || strpos($slug, $source) !== false) {
        return ['code_source_type' => self::SOURCE_PLUGIN, 'plugin_slug' => $slug];
      }
    }

    $mu_plugin_slugs = $this->get_mu_plugin_slugs();
    foreach ($mu_plugin_slugs as $slug) {
      if ($source === $slug || strpos($source, $slug) !== false || strpos($slug, $source) !== false) {
        return ['code_source_type' => self::SOURCE_MU_PLUGIN, 'plugin_slug' => $slug];
      }
    }

    if (strpos($source, 'theme') !== false) {
      return ['code_source_type' => self::SOURCE_THEME, 'plugin_slug' => ''];
    }

    return ['code_source_type' => self::SOURCE_CUSTOM, 'plugin_slug' => ''];
  }

  private function get_plugin_slugs() {
    if (is_array($this->cached_plugin_slugs)) {
      return $this->cached_plugin_slugs;
    }

    $slugs = [];
    $plugin_dir = wp_normalize_path(WP_PLUGIN_DIR);

    if (is_dir($plugin_dir)) {
      $entries = @scandir($plugin_dir);
      if (is_array($entries)) {
        foreach ($entries as $entry) {
          if ($entry === '.' || $entry === '..') {
            continue;
          }

          $slug = sanitize_key(pathinfo($entry, PATHINFO_FILENAME));
          if ($slug !== '') {
            $slugs[] = $slug;
          }
        }
      }
    }

    $this->cached_plugin_slugs = array_values(array_unique($slugs));
    return $this->cached_plugin_slugs;
  }

  private function get_mu_plugin_slugs() {
    if (is_array($this->cached_mu_plugin_slugs)) {
      return $this->cached_mu_plugin_slugs;
    }

    $slugs = [];
    if (defined('WPMU_PLUGIN_DIR')) {
      $mu_dir = wp_normalize_path(WPMU_PLUGIN_DIR);
      if (is_dir($mu_dir)) {
        $entries = @scandir($mu_dir);
        if (is_array($entries)) {
          foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
              continue;
            }

            $slug = sanitize_key(pathinfo($entry, PATHINFO_FILENAME));
            if ($slug !== '') {
              $slugs[] = $slug;
            }
          }
        }
      }
    }

    $this->cached_mu_plugin_slugs = array_values(array_unique($slugs));
    return $this->cached_mu_plugin_slugs;
  }
}
