<?php

if (!defined('WPINC')) {
  die;
}

/**
 * BetterStack Logger
 *
 * Lightweight application-level observability for WordPress and WooCommerce.
 */
class BetterStackLogger {

  private const SOURCE = 'betterstack-logger';
  private const ENDPOINT = 'https://in.logs.betterstack.com';
  private const MAX_MESSAGE_LENGTH = 2000;
  private const MAX_TRACE_LENGTH = 8000;

  private const LEVEL_RANK = [
    'debug' => 10,
    'info' => 20,
    'warning' => 30,
    'error' => 40,
    'critical' => 50,
  ];

  private bool $is_transporting = false;
  private array $recent_hashes = [];
  private $previous_exception_handler = null;

  /**
   * Constructor.
   */
  public function __construct() {
    add_action('admin_menu', [$this, 'betterstack_logger_menu']);
    add_action('admin_init', [$this, 'settings_init']);
    add_filter('wp_die_handler', [$this, 'betterstack_wp_die_handler']);

    $this->register_error_handlers();
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
   * Custom wp_die handler filter callback.
   *
   * @return callable
   */
  public function betterstack_wp_die_handler($handler = null) {
    $default_handler = is_callable($handler) ? $handler : '_default_wp_die_handler';

    return function ($message, $title = '', $args = []) use ($default_handler) {
      if ($this->is_category_enabled('php_errors')) {
        $formatted_message = $this->extract_message($message);
        $this->log(
          'critical',
          'wp_die triggered: ' . $formatted_message,
          [
            'event' => 'wp_die',
            'title' => is_string($title) ? $title : '',
            'wp_die_args' => is_array($args) ? $this->safe_wp_die_args($args) : [],
          ],
          'php_errors'
        );
      }

      call_user_func($default_handler, $message, $title, $args);
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

    add_settings_section(
      'betterstack_logger_settings_section',
      'Better Stack Configuration',
      function () {
        echo '<p class="description">Use <code>BETTERSTACK_API_KEY</code> in wp-config.php for the most secure token handling. Database token is a fallback.</p>';
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
      [
        'event' => 'php_error',
        'error_type' => $this->php_error_label((int) $errno),
        'file' => (string) $errfile,
        'line' => (int) $errline,
      ],
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
      $this->log(
        'critical',
        'Uncaught exception: ' . $exception->getMessage(),
        [
          'event' => 'uncaught_exception',
          'exception_class' => get_class($exception),
          'file' => $exception->getFile(),
          'line' => $exception->getLine(),
          'trace' => $this->truncate($exception->getTraceAsString(), self::MAX_TRACE_LENGTH),
        ],
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

    $this->log(
      'critical',
      'Fatal shutdown error: ' . (string) ($error['message'] ?? ''),
      [
        'event' => 'fatal_shutdown',
        'error_type' => $this->php_error_label((int) $error['type']),
        'file' => (string) ($error['file'] ?? ''),
        'line' => (int) ($error['line'] ?? 0),
      ],
      'php_errors',
      true
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

      case 'custom_debug_logs':
      default:
        return $this->get_yes_no_option('betterstack_custom_logs_enabled', 'yes') === 'yes';
    }
  }

  /**
   * Sends payload using WP HTTP API.
   */
  private function send_payload(array $payload, $blocking = false) {
    $token = $this->get_api_key();
    if ($token === '') {
      return 'Skipped: API key is not set.';
    }

    if ($this->is_transporting) {
      return 'Skipped: recursive transport prevented.';
    }

    $this->is_transporting = true;
    $response = wp_remote_post(
      self::ENDPOINT,
      [
        'body' => wp_json_encode($payload),
        'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer ' . $token,
        ],
        'timeout' => $blocking ? 4 : 2,
        'blocking' => (bool) $blocking,
      ]
    );
    $this->is_transporting = false;

    if (!$blocking) {
      return 'Log queued for delivery.';
    }

    if (is_wp_error($response)) {
      return 'Log send failed (non-blocking behavior preserved).';
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
      $safe_context['trace'] = $this->truncate($safe_context['trace'], self::MAX_TRACE_LENGTH);
    }

    return $safe_context;
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
      if (($now - $timestamp) > 5) {
        unset($this->recent_hashes[$hash]);
      }
    }

    $signature = [
      $payload['level'] ?? '',
      $payload['message'] ?? '',
      $payload['category'] ?? '',
      $payload['hook'] ?? '',
      $payload['request_uri'] ?? '',
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
}
