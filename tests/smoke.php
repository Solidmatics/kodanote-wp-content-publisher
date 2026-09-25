<?php
/**
 * Standalone regression smoke harness with deliberately small WordPress stubs.
 * Run: php -n tests/smoke.php
 * This checks plugin wiring and selected boundary behavior, not WordPress integration.
 * HTTP is intercepted; no requests, database writes, or WordPress install are needed.
 */

error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$mode = $argv[1] ?? 'default';
$hooks = $routes = $events = $transients = $http_calls = array();
$options = array('kodanote_api_key' => 'smoke-test-key', 'kodanote_debug_mode' => '0');
$status = null;
$no_cache = false;
$checks = 0;

define('ABSPATH', __DIR__ . '/stub-wordpress/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
if ($mode === 'configured') {
    define('KODANOTE_API_BASE_URL', '  https://publishing.example/api///  ');
}

function check($condition, $message) {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function __($value, $domain = '') { return $value; }
function esc_html__($value, $domain = '') { return $value; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value) { return $value; }
function __return_true() { return true; }
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'https://wordpress.example/wp-content/plugins/kodanote-content-publisher/'; }
function plugin_basename($file) { return 'kodanote-content-publisher/' . basename($file); }
function add_action($name, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$name][] = $callback; }
function add_filter($name, $callback, $priority = 10, $args = 1) { add_action($name, $callback, $priority, $args); }
function register_activation_hook($file, $callback) { add_action('smoke_activation', $callback); }
function register_deactivation_hook($file, $callback) { add_action('smoke_deactivation', $callback); }
function add_shortcode($name, $callback) { add_action('smoke_shortcode_' . $name, $callback); }
function register_rest_route($namespace, $route, $args) { $GLOBALS['routes']['/' . $namespace . $route] = $args; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $expiration) { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); return true; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['events'][$hook]); return true; }
function wp_schedule_event($time, $schedule, $hook) { $GLOBALS['events'][$hook] = (object) compact('time', 'schedule'); return true; }
function wp_get_scheduled_event($hook) { return $GLOBALS['events'][$hook] ?? false; }
function wp_next_scheduled($hook) { return isset($GLOBALS['events'][$hook]) ? $GLOBALS['events'][$hook]->time : false; }
function apply_filters($name, $value) { return $value; }
function home_url($path = '') { return 'https://wordpress.example' . ($path === '' ? '' : '/' . ltrim($path, '/')); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function rest_sanitize_boolean($value) { return (bool) $value; }
function current_time($format) { return $format === 'mysql' ? gmdate('Y-m-d H:i:s') : gmdate($format); }
function current_user_can($capability) { return true; }
function check_ajax_referer($action, $field) { check($action === 'kodanote_ajax_nonce' && $field === 'nonce', 'AJAX nonce wiring changed'); }
function wp_json_encode($value) { return json_encode($value); }
function wp_generate_password($length, $special = true, $extra = false) { return str_repeat('x', $length); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_get($url, $args) { return smoke_http('GET', $url, $args); }
function wp_remote_post($url, $args) { return smoke_http('POST', $url, $args); }
function smoke_http($method, $url, $args) {
    check($GLOBALS['mode'] === 'configured', 'Unconfigured plugin attempted an HTTP request: ' . $url);
    $GLOBALS['http_calls'][] = compact('method', 'url', 'args');
    return array('response' => array('code' => 200), 'body' => '{"success":false,"articles":[],"message":"Mock response"}');
}
function wp_send_json_error($data) { throw new SmokeJsonResponse(false, $data); }
function wp_send_json_success($data) { throw new SmokeJsonResponse(true, $data); }
function status_header($code) { $GLOBALS['status'] = $code; }
function nocache_headers() { $GLOBALS['no_cache'] = true; }
function url_to_postid($url) { return 42; }
function get_post($id) {
    return (object) array(
        'ID' => 42, 'post_title' => 'Smoke article',
        'post_status' => $GLOBALS['mode'] === 'markdown-private' ? 'private' : 'publish',
        'post_password' => $GLOBALS['mode'] === 'markdown-password' ? 'protected' : '',
        'post_type' => $GLOBALS['mode'] === 'markdown-hidden' ? 'internal' : 'post',
    );
}
function is_post_type_viewable($type) { return $type === 'post'; }
function get_post_meta($id, $key, $single = false) {
    if ($key === '_kodanote_article_id') { return 'article-42'; }
    if ($key === '_kodanote_content_markdown') { return 'UNIQUE_SMOKE_MARKDOWN_CONTENT'; }
    return '';
}
function get_the_date($format, $id) { return '2026-09-25'; }
function get_permalink($id) { return home_url('/smoke-article/'); }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }

class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct($code, $message, $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
    private $data;
    private $status;
    public function __construct($data, $status = 200) { $this->data = $data; $this->status = $status; }
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
}
// WordPress JSON responses terminate execution; Error avoids production catch(Exception).
class SmokeJsonResponse extends Error {
    public $success;
    public $data;
    public function __construct($success, $data) { parent::__construct('JSON response'); $this->success = $success; $this->data = $data; }
}
class SmokeRequest {
    private $headers;
    private $body;
    private $params;
    public function __construct($headers = array(), $body = '', $params = array()) {
        $this->headers = array_change_key_case($headers, CASE_LOWER); $this->body = $body; $this->params = $params;
    }
    public function get_header($name) { return $this->headers[strtolower($name)] ?? ''; }
    public function get_body() { return $this->body; }
    public function get_param($name) { return $this->params[$name] ?? null; }
    public function get_params() { return $this->params; }
    public function get_query_params() { return array(); }
    public function get_route() { return '/kodanote/v1/trigger-sync'; }
}

require dirname(__DIR__) . '/kodanote-content-publisher.php';
$plugin = call_user_func($hooks['plugins_loaded'][0]);
$plugin->register_rest_routes();

if (strpos($mode, 'markdown-') === 0) {
    $_SERVER['REQUEST_URI'] = '/smoke-article.md';
    ob_start();
    register_shutdown_function(function () use ($mode) {
        $output = ob_get_clean();
        $public = $mode === 'markdown-public';
        try {
            check($GLOBALS['status'] === ($public ? 200 : 404), $mode . ': wrong response status');
            check((strpos($output, 'UNIQUE_SMOKE_MARKDOWN_CONTENT') !== false) === $public, $mode . ': unexpected content disclosure or missing public content');
            check($public || $GLOBALS['no_cache'], $mode . ': protected response must not be cached');
            fwrite(STDOUT, 'PASS ' . $mode . "\n");
        } catch (Throwable $error) {
            fwrite(STDERR, 'FAIL ' . $error->getMessage() . "\n");
            exit(1);
        }
    });
    $plugin->handle_md_url_request();
    throw new RuntimeException('Markdown handler did not terminate its response');
}

foreach ($hooks as $name => $callbacks) {
    foreach ($callbacks as $callback) {
        check(is_callable($callback), 'Uncallable hook: ' . $name);
    }
}
foreach (array('handshake', 'force-republish', 'trigger-sync', 'push-image', 'conversion-event', 'article-pageview') as $route) {
    check(isset($routes['/kodanote/v1/' . $route]), 'Missing Kodanote REST route: ' . $route);
}
foreach ($routes as $route => $definition) {
    check(is_callable($definition['callback']) && is_callable($definition['permission_callback']), 'Uncallable REST route: ' . $route);
}

$api = new Kodanote_API();
if ($mode === 'configured') {
    check($api->test_connection()['success'] === true, 'Configured connection test failed');
    check($http_calls[0]['url'] === 'https://publishing.example/api/articles/sync?limit=1', 'Connection URL was not normalized');
    check($http_calls[0]['args']['headers']['Authorization'] === 'Bearer smoke-test-key', 'Connection credential missing');
    check($api->send_webhook('sync_completed', array('articles_count' => 1)) === true, 'Configured webhook failed');
    $webhook = $http_calls[1];
    check($webhook['url'] === 'https://publishing.example/api/webhooks/wordpress', 'Webhook URL was not normalized');
    check(hash_equals(hash_hmac('sha256', $webhook['args']['body'], 'smoke-test-key'), $webhook['args']['headers']['X-Kodanote-Signature']), 'Webhook HMAC does not sign actual payload');
    check(is_wp_error($plugin->attempt_auto_verification()), 'Mock verification response should fail');
    check($http_calls[2]['url'] === 'https://publishing.example/api/plugin/initiate-handshake', 'Handshake URL was not normalized');
    check(empty(array_filter(array_keys($transients), function ($key) { return strpos($key, 'kodanote_handshake_installation_token_') === 0; })), 'Handshake token was not cleaned up');
    fwrite(STDOUT, 'PASS configured API (' . $checks . " checks)\n");
    exit;
}

check(KODANOTE_API_BASE_URL === '', 'Default API URL must be explicitly unconfigured');
foreach (array($api->test_connection(), $api->sync_articles(), $api->send_webhook('sync_completed'), $plugin->attempt_auto_verification()) as $result) {
    check(is_wp_error($result) && $result->get_error_code() === 'no_api_url', 'Missing API URL was not reported');
}
foreach (array(array($plugin, 'ajax_test_connection'), array($plugin, 'ajax_sync_articles'), array($plugin, 'ajax_attempt_auto_verification'), array(new Kodanote_Notifications(), 'ajax_manual_sync')) as $callback) {
    try {
        call_user_func($callback);
        throw new RuntimeException('Manual action did not return JSON');
    } catch (SmokeJsonResponse $response) {
        check(!$response->success && strpos($response->data['message'], 'API URL') !== false, 'Manual action should explain missing API URL');
    }
}
(new Kodanote_Scheduler())->run_auto_sync();
foreach (array($plugin->rest_conversion_event_proxy(new SmokeRequest()), $plugin->rest_article_pageview_proxy(new SmokeRequest())) as $response) {
    check($response->get_status() === 503 && $response->get_data()['error'] === 'not_configured', 'Unconfigured tracking proxy must stop before forwarding');
}
check(empty($http_calls), 'Unconfigured actions must not attempt HTTP');
check(!isset($events['kodanote_auto_sync']), 'Unconfigured plugin must not schedule outbound polling');

$body = '{"articles":[{"id":"article-42","title":"Smoke article"}]}';
$signature = hash_hmac('sha256', $body, 'smoke-test-key');
foreach (array(
    array(),
    array('Authorization' => 'Bearer wrong-key', 'X-Kodanote-Signature' => $signature),
    array('Authorization' => 'Bearer smoke-test-key'),
    array('Authorization' => 'Bearer smoke-test-key', 'X-Kodanote-Signature' => 'invalid'),
) as $headers) {
    $result = $plugin->rest_api_permission_check(new SmokeRequest($headers, $body));
    check(is_wp_error($result) && $result->get_error_data()['status'] === 401, 'Unsigned or invalid publishing request must be rejected');
}
check($plugin->rest_api_permission_check(new SmokeRequest(array('Authorization' => 'Bearer smoke-test-key', 'X-Kodanote-Signature' => $signature), $body)) === true, 'Valid signed Bearer request rejected');
check($plugin->rest_api_permission_check(new SmokeRequest(array('X-Kodanote-API-Key' => 'smoke-test-key', 'X-Kodanote-Signature' => $signature), $body)) === true, 'Renamed fallback API-key header rejected');
$tampered = $plugin->rest_api_permission_check(new SmokeRequest(array('Authorization' => 'Bearer smoke-test-key', 'X-Kodanote-Signature' => $signature), $body . ' '));
check(is_wp_error($tampered), 'Changed request body must invalidate signature');

$sitemap = new Kodanote_Sitemap();
$sitemap->register_robots_filter();
$sitemap->schedule_daily_check();
check(isset($hooks['robots_txt']) && is_callable($hooks['robots_txt'][0]), 'Sitemap robots filter not callable');
check(isset($events['kodanote_sitemap_check']) && $events['kodanote_sitemap_check']->schedule === 'daily', 'Sitemap check not scheduled');
$robots = "User-agent: *\nSitemap: https://wordpress.example/wp-sitemap.xml\n";
check($sitemap->filter_robots_txt($robots, true) === $robots, 'Existing sitemap entry should be preserved');
check($sitemap->filter_robots_txt('User-agent: *', false) === 'User-agent: *', 'Private site robots should be preserved');

foreach (array('configured', 'markdown-password', 'markdown-private', 'markdown-hidden', 'markdown-public') as $scenario) {
    $process = proc_open(array(PHP_BINARY, '-n', __FILE__, $scenario), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    check(is_resource($process), 'Could not start scenario: ' . $scenario);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, $scenario . " failed:\n" . $output . $errors);
    fwrite(STDOUT, $output);
}
fwrite(STDOUT, 'PASS smoke harness (' . $checks . " parent checks; WordPress stubs, no live integration)\n");
