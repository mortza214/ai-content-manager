<?php
/** Actual plugin entry point/autoloader with isolated WordPress hooks. No WordPress or SQL. */
define( 'ABSPATH', __DIR__ . '/' );
set_error_handler( static function ( $level, $message, $file, $line ) { throw new ErrorException( $message, 0, $level, $file, $line ); } );
$hooks = array(); $fired = array(); $allowed = true;
function add_action( $name, $callback ) { $GLOBALS['hooks'][$name][] = $callback; }
function do_action( $name ) { $GLOBALS['fired'][] = $name; foreach ( $GLOBALS['hooks'][$name] ?? array() as $callback ) { call_user_func( $callback ); } }
function register_activation_hook( $file, $callback ) {}
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/plugin/'; }
function plugin_basename( $file ) { return 'ai-content-manager/' . basename( $file ); }
function load_plugin_textdomain( ...$args ) {}
function get_option( $key, $default = false ) { return array( 'acm_db_version' => '1.3.0', 'acm_capability_version' => '1' )[$key] ?? $default; }
function is_admin() { return true; }
function current_user_can( $cap ) { return $GLOBALS['allowed']; }
function __( $value, $domain = '' ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_unslash( $value ) { return $value; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'fixture-' . $action; }
class BootstrapDenied extends RuntimeException { public $status; public function __construct( $html, $status ) { parent::__construct( $html ); $this->status = $status; } }
function wp_die( $html, $title, $args ) { throw new BootstrapDenied( $html, $args['response'] ); }
function verify_bootstrap( $condition, $label ) { if ( ! $condition ) { throw new RuntimeException( $label ); } echo "PASS: $label\n"; }
function invoke_denied( $expected, $status ) {
    try { do_action( 'admin_post_acm_queue_seo_apply' ); throw new RuntimeException( 'Missing denial' ); }
    catch ( BootstrapDenied $error ) {
        verify_bootstrap( false !== strpos( $error->getMessage(), $expected ) && $error->status === $status &&
            false !== strpos( $error->getMessage(), 'https://example.test/wp-admin/admin.php?page=acm-content&amp;tab=queue' ), 'bootstrapped admin-post denial has Persian explanation, HTTP status and safe return link' );
    }
}

// Simulate admin-post.php: plugins load before admin_init; no admin_menu or tab render occurs.
$_GET = $_POST = $_REQUEST = array(); $_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
require __DIR__ . '/../ai-content-manager.php';
do_action( 'plugins_loaded' );
verify_bootstrap( '' === ob_get_contents(), 'real plugin bootstrap emits no output before headers' );
$registered = $hooks['admin_post_acm_queue_seo_apply'] ?? array();
verify_bootstrap( 1 === count( $registered ) && is_callable( $registered[0] ) &&
    $registered[0][0] instanceof AIContentManager\Admin\QueueSeoPage, 'real autoloader resolves namespace and registers SEO handler on plugins_loaded without rendering queue' );
verify_bootstrap( 1 === count( $hooks['admin_post_acm_queue_category_apply'] ?? array() ) && is_callable( $hooks['admin_post_acm_queue_category_apply'][0] ), 'category repair POST handler registered at bootstrap without queue render' );
verify_bootstrap( ! in_array( 'admin_menu', $fired, true ) && ! in_array( 'admin_init', $fired, true ), 'admin-post handler exists before admin_init and without executing admin_menu' );
AIContentManager\Plugin::instance()->init();
verify_bootstrap( 1 === count( $hooks['admin_post_acm_queue_seo_apply'] ), 'repeated plugin initialization does not duplicate the SEO handler' );
$allowed = false; invoke_denied( 'مجوز مدیریت محتوا', 403 ); $allowed = true;
$_POST = array( 'item_id' => 1, '_wpnonce' => 'invalid' ); invoke_denied( 'اعتبار فرم', 403 );
$_POST['_wpnonce'] = 'fixture-acm_seo_apply_1';
$_SERVER['REQUEST_METHOD'] = 'GET'; invoke_denied( 'ارسال فرم', 403 ); $_SERVER['REQUEST_METHOD'] = 'POST';
// Flush actual CLI output to exercise native headers_sent(), not a mocked condition.
ob_end_flush();
verify_bootstrap( headers_sent(), 'native PHP headers_sent detects early output in isolated process' );
invoke_denied( 'ارسال زودهنگام خروجی، عملیات آغاز نشد', 500 );
