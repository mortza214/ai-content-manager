<?php
/** Isolated WordPress contract tests. Network calls are forbidden here. */
define( 'ABSPATH', __DIR__ );
define( 'ACM_PLUGIN_URL', 'https://example.test/plugin/' );
define( 'ACM_VERSION', '0.6.2' );
set_error_handler( function ( $level, $message, $file, $line ) { throw new ErrorException( $message, 0, $level, $file, $line ); } );
$caps = array( 'edit_posts' => true, 'manage_options' => true, 'manage_ai_content' => true );
$roles = array( 'administrator' );
$options = array();
$scripts = $localized = $actions = $fields = $sections = $settings_errors = $menus = array();
$network_calls = 0;
class WP_Error {
    private $message;
    private $code;
    public function __construct( $code, $message ) { $this->message = $message; $this->code = $code; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
class RedirectResult extends RuntimeException {}
class AccessDenied extends RuntimeException {}
class JsonResult extends RuntimeException { public $data; public function __construct( $data ) { $this->data = $data; } }
function __( $value, $domain = '' ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
function esc_attr__( $value, $domain = '' ) { return esc_attr( $value ); }
function esc_html_e( $value, $domain = '' ) { echo esc_html( $value ); }
function current_user_can( $cap, ...$args ) {
    $GLOBALS['cap_checks'][] = array( $cap, $args );
    if ( 'edit_post' === $cap && isset( $GLOBALS['post_capabilities'] ) ) { return ! empty( $GLOBALS['post_capabilities'][$args[0] ?? 0] ); }
    return ! empty( $GLOBALS['caps'][$cap] );
}
function wp_get_current_user() { return (object) array( 'roles' => $GLOBALS['roles'] ); }
function get_current_user_id() { return 7; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][$name] = $value; return true; }
function wp_salt( $context ) { return 'isolated-test-salt'; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function post_type_exists( $type ) { return in_array( $type, array( 'post', 'page', 'product' ), true ); }
function apply_filters( $name, $value, ...$args ) {
    if ( isset( $GLOBALS['filter_handlers'][$name] ) ) { $value = call_user_func( $GLOBALS['filter_handlers'][$name], $value, ...$args ); }
    if ( isset( $GLOBALS['fixture_filters'][$name] ) ) { ksort( $GLOBALS['fixture_filters'][$name] ); foreach ( $GLOBALS['fixture_filters'][$name] as $callbacks ) { foreach ( $callbacks as $callback ) { $value = $callback( $value, ...$args ); } } }
    return $value;
}
function add_filter( $name, $callback, $priority = 10, $accepted = 1 ) { $GLOBALS['fixture_filters'][$name][$priority][] = $callback; }
function remove_filter( $name, $callback, $priority = 10 ) { foreach ( $GLOBALS['fixture_filters'][$name][$priority] ?? array() as $index => $registered ) { if ( $registered === $callback ) { unset( $GLOBALS['fixture_filters'][$name][$priority][$index] ); } } }
function do_action( $name, ...$args ) { if ( isset( $GLOBALS['action_handlers'][$name] ) ) { call_user_func( $GLOBALS['action_handlers'][$name], ...$args ); } }
function get_locale() { return 'fa_IR'; }
function wp_timezone() { return new DateTimeZone( 'Asia/Tehran' ); }
function get_userdata( $id ) { return isset( $GLOBALS['fixture_users'] ) ? ( $GLOBALS['fixture_users'][$id] ?? false ) : (object) array( 'ID' => $id, 'display_name' => 'Test creator' ); }
function get_object_taxonomies( $type, $format ) { return $GLOBALS['fixture_taxonomies'][$type] ?? array(); }
function selected( $a, $b ) { if ( $a == $b ) { echo ' selected'; } }
function checked( $a, $b = true ) { if ( $a == $b ) { echo ' checked'; } }
function add_action( $name, $callback ) { $GLOBALS['actions'][$name][] = $callback; }
function add_menu_page( ...$args ) { $GLOBALS['menus'][] = $args; return 'actual-returned-hook-123'; }
function wp_enqueue_script( $handle, ...$args ) { $GLOBALS['scripts'][] = $handle; }
function wp_enqueue_style( ...$args ) {}
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['localized'][$name] = $data; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . str_replace( '%25%23%25', '%#%', http_build_query( $args ) ); }
function wp_create_nonce( $action ) { return 'nonce-' . $action; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === wp_create_nonce( $action ); }
function get_transient( $key ) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient( $key, $value, $ttl ) {
    if ( ( $GLOBALS['transient_fail_key'] ?? null ) === $key || ( $GLOBALS['transients'][$key] ?? null ) === $value ) { return false; }
    $GLOBALS['transients'][$key] = $value; return true;
}
function delete_transient( $key ) { unset( $GLOBALS['transients'][$key] ); return true; }
function wp_generate_uuid4() { static $n = 0; return sprintf( '00000000-0000-4000-8000-%012d', ++$n ); }
function wp_nonce_url( $url, $action ) { return esc_html( str_replace( '&amp;', '&', $url ) . '&_wpnonce=' . wp_create_nonce( $action ) ); }
function check_admin_referer( $action ) { if ( ( $_REQUEST['_wpnonce'] ?? $_GET['_wpnonce'] ?? $_POST['_wpnonce'] ?? '' ) !== wp_create_nonce( $action ) ) { throw new AccessDenied( 'nonce' ); } }
function check_ajax_referer( $action, $key ) { if ( ( $_POST[$key] ?? '' ) !== wp_create_nonce( $action ) ) { throw new AccessDenied( 'nonce' ); } }
function wp_nonce_field( $action ) { echo '<input name="_wpnonce" value="' . wp_create_nonce( $action ) . '">'; }
function wp_die( ...$args ) { $GLOBALS['last_die'] = $args; throw new AccessDenied( $args[0] ?? '' ); }
function wp_safe_redirect( $url, $status = 302 ) { $GLOBALS['redirect_status'] = $status; if ( ! empty( $GLOBALS['redirect_fail'] ) ) { return false; } throw new RedirectResult( $url ); }
function register_setting( ...$args ) { $GLOBALS['registered_setting'] = $args; }
function add_settings_section( $id, $label, $callback, $page ) { $GLOBALS['sections'][$id] = $label; }
function add_settings_field( $id, $label, $callback, ...$args ) { $GLOBALS['fields'][$id] = array( $label, $callback ); }
function settings_fields( $group ) { echo '<input name="option_page" value="' . $group . '">'; wp_nonce_field( $group . '-options' ); }
function settings_errors() { echo '<div id="settings-errors">'; foreach ( $GLOBALS['settings_errors'] as $error ) { echo esc_html( $error[2] ); } echo '</div>'; }
function add_settings_error( ...$args ) { $GLOBALS['settings_errors'][] = $args; }
function do_settings_sections( $page ) { foreach ( $GLOBALS['fields'] as $field ) { echo '<label>' . esc_html( $field[0] ) . '</label>'; call_user_func( $field[1] ); } }
function submit_button( $label, ...$args ) { echo '<button>' . esc_html( $label ) . '</button>'; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_get( ...$args ) { if ( isset( $GLOBALS['fixture_http'] ) ) { return call_user_func( $GLOBALS['fixture_http'], ...$args ); } $GLOBALS['network_calls']++; throw new RuntimeException( 'network forbidden' ); }
function wp_remote_post( ...$args ) { if ( isset( $GLOBALS['fixture_http'] ) ) { return call_user_func( $GLOBALS['fixture_http'], ...$args ); } $GLOBALS['network_calls']++; throw new RuntimeException( 'network forbidden' ); }
function wp_send_json_success( $data ) { throw new JsonResult( $data ); }
function wp_send_json_error( $data, $status = null ) { throw new JsonResult( $data ); }
function wp_kses( $text, $allowed ) { return strip_tags( $text, '<p><h2><h3>' ); }
function wp_kses_post( $text ) { return $text; }
function get_date_from_gmt( $time, $format ) { return $time; }
function paginate_links( $args ) { return '<a href="' . esc_attr( str_replace( '%#%', '2', $args['base'] ) ) . '">2</a>'; }
spl_autoload_register( function ( $name ) {
    $prefix = 'AIContentManager\\';
    if ( 0 !== strpos( $name, $prefix ) ) { return; }
    $parts = explode( '\\', substr( $name, strlen( $prefix ) ) );
    $class = array_pop( $parts );
    $path = __DIR__ . '/../includes/' . strtolower( implode( '/', $parts ) ) . '/class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $class ) ) . '.php';
    if ( file_exists( $path ) ) { require $path; }
} );
function verify( $condition, $label ) { if ( ! $condition ) { throw new RuntimeException( $label ); } echo "PASS: $label\n"; }
function capture( $callback ) { ob_start(); try { $callback(); return ob_get_contents(); } finally { ob_end_clean(); } }
function query( $url ) { parse_str( parse_url( html_entity_decode( $url ), PHP_URL_QUERY ) ?? '', $args ); return $args; }
class FakeRepository {
    public $preview_reads = 0;
    public function usage_metadata() { return array(); }
    public function count_stale( $cutoff ) { return 0; }
    public function recover_stale( $cutoff, $mode ) { return 0; }
    public function count( $filters ) { return 21; }
    public function find( ...$args ) { return array( array( 'item_id' => 4, 'status' => 'generated', 'content_type' => 'post', 'language' => 'en', 'payload' => '{"topic":"Example","mode":"manual"}', 'batch_id' => 'test-batch', 'created_at' => '2026-11-09 12:00' ) ); }
    public function get( $id ) {
        $this->preview_reads++;
        return array( 'status' => 'generated', 'generated_output' => json_encode( array( 'title' => 'Title', 'excerpt' => 'Summary', 'content' => '<p>Body</p>', 'slug' => 'title', 'primary_keyword' => 'test', 'secondary_keywords' => array(), 'suggested_meta_description' => 'Meta', 'headings' => array(), 'faq' => array(), 'image_prompt' => 'Illustration' ) ), 'api_metadata' => '{"model":"gpt-4o-mini","total_tokens":30}' );
    }
}
class FakeQueue { public function enqueue_topics( $request, $topics, $submission = '' ) { return array( 'item_ids' => array( 4 ) ); } }
class FakeGenerator { public $calls = 0; public function generate( $id, $user ) { $this->calls++; return true; } }
if ( defined( 'ACM_TEST_BOOTSTRAP_ONLY' ) ) { return; }
$settings = new AIContentManager\Admin\Settings();
$settings->register();
verify( is_callable( $registered_setting[2]['sanitize_callback'] ), 'Settings API callback' );
$valid = $settings->sanitize( array( 'openai_model' => 'gpt-4.1', 'openai_api_key' => 'fixture-credential' ) );
verify( 'gpt-4.1' === $valid['openai_model'] && ! isset( $valid['openai_api_key'] ), 'model and write-only credential' );
verify( 'gpt-4o-mini' === $settings->sanitize( array( 'openai_model' => 'unknown' ) )['openai_model'], 'invalid model fallback' );
verify( isset( $options['acm_openai_api_key'] ) && false === strpos( $options['acm_openai_api_key'], 'fixture-credential' ), 'encrypted credential storage' );
$registry = new AIContentManager\Services\ContentTypeRegistry();
$wpml = new AIContentManager\Integrations\Wpml();
verify( array( 'fa_IR', 'en', 'ar' ) === array_keys( $wpml->active_languages() ), 'article language choices' );
$repository = new FakeRepository();
$content = new AIContentManager\Admin\ContentPage( new FakeQueue(), $registry, $wpml );
$queue = new AIContentManager\Admin\QueueManagerPage( $repository, $registry, $wpml );
$generator = new FakeGenerator();
$generation = new AIContentManager\Admin\QueueGeneration( $generator );
$page = new AIContentManager\Admin\AdminPage( $settings, $content, $queue, $generation );
$menu = new AIContentManager\Admin\Menu( $page );
$menu->register();
verify( 1 === count( $menus ) && 'acm-content' === $menus[0][3], 'single menu entry' );
foreach ( array( 'content' => 'acm-content-page', 'queue' => 'acm-queue-generation', 'settings' => 'acm-admin-settings' ) as $tab => $handle ) {
    $_GET = array( 'tab' => $tab ); $scripts = array();
    $page->enqueue( 'unrelated-hook' ); verify( array() === $scripts, 'no unrelated assets ' . $tab );
    $page->enqueue( 'actual-returned-hook-123' ); verify( array( $handle ) === $scripts, 'tab assets ' . $tab );
    $html = capture( array( $page, 'render' ) );
    verify( false !== strpos( $html, 'aria-current="page"' ) && false !== strpos( $html, 'dir="rtl"' ), 'navigation render ' . $tab );
    verify( substr_count( $html, '<form' ) === substr_count( $html, '</form>' ) && substr_count( $html, '<form' ) >= 1, 'balanced section forms ' . $tab );
    preg_match_all( '/<\/?form\b[^>]*>/i', $html, $form_tags ); $depth = 0; $nested = false;
    foreach ( $form_tags[0] as $tag ) { $depth += 0 === strpos( $tag, '</' ) ? -1 : 1; if ( $depth > 1 ) { $nested = true; } }
    verify( ! $nested && 0 === $depth, 'no nested forms ' . $tab );
    verify( false === strpos( $html, 'fixture-credential' ) && false === strpos( json_encode( $localized ), 'fixture-credential' ), 'credential never rendered ' . $tab );
    if ( 'settings' === $tab ) {
        verify( false !== strpos( $html, 'options.php' ) && false !== strpos( $html, 'acm_settings_group-options' ) && false !== strpos( $html, 'tab=settings' ) && false !== strpos( $html, 'settings-errors' ), 'settings nonce and return path' );
    }
    if ( 'queue' === $tab ) {
        verify( false !== strpos( $html, 'name="tab" value="queue"' ) && false !== strpos( $html, 'tab=queue' ) && false !== strpos( $html, 'paged=2' ), 'queue filter pagination preview paths' );
        verify( false !== strpos( $html, 'سازنده' ) && false !== strpos( $html, 'Test creator' ), 'creator displayed in queue' );
    }
}
foreach ( array( null, 'bad', array( 'settings' ) ) as $tab ) {
    $_GET = array( 'tab' => $tab ); verify( 'content' === AIContentManager\Admin\AdminPage::active_tab(), 'tab allowlist' );
}
$caps['manage_options'] = false; $roles = array( 'editor' ); $_GET = array( 'tab' => 'content' );
$html = capture( array( $page, 'render' ) ); verify( false === strpos( $html, 'tab=settings' ), 'hide unauthorized settings tab' );
$_GET = array( 'tab' => 'settings' );
try { capture( array( $page, 'render' ) ); throw new RuntimeException( 'missing denial' ); } catch ( AccessDenied $error ) { verify( true, 'direct settings denied' ); }
$caps['manage_options'] = true;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array( 'page' => 'acm-queue', 'tab' => 'settings', 'status' => 'generated', 'language' => 'en', 'content_type' => 'post', 'paged' => '2', 'preview_item' => '4', '_wpnonce' => wp_create_nonce( 'acm_preview_4' ), 'evil' => 'https://evil.test' );
try { $menu->redirect_legacy(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) {
    $args = query( $redirect->getMessage() );
    verify( 'queue' === $args['tab'] && 'acm-content' === $args['page'] && '2' === $args['paged'] && 'generated' === $args['status'] && 'en' === $args['language'] && 'post' === $args['content_type'] && '4' === $args['preview_item'] && isset( $args['_wpnonce'] ) && ! isset( $args['evil'] ), 'safe legacy queue redirect preserves navigation' );
}
$_GET = $args; $_REQUEST = array();
$html = capture( array( $page, 'render' ) ); verify( false === strpos( $html, 'JSON خام' ), 'editor raw JSON denied' );
$roles = array( 'administrator' );
$html = capture( array( $page, 'render' ) ); verify( false !== strpos( $html, 'JSON خام' ), 'administrator raw JSON allowed' );
$_GET['_wpnonce'] = 'invalid'; $reads = $repository->preview_reads;
try { capture( array( $page, 'render' ) ); throw new RuntimeException( 'missing nonce denial' ); } catch ( AccessDenied $error ) { verify( $reads === $repository->preview_reads, 'preview nonce enforced after redirect' ); }
$_GET = array( 'page' => 'ai-content-manager', 'settings-updated' => 'true' );
try { $menu->redirect_legacy(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) { $args = query( $redirect->getMessage() ); verify( 'settings' === $args['tab'] && 'true' === $args['settings-updated'], 'legacy settings redirect' ); }
$caps['manage_options'] = false;
try { $menu->redirect_legacy(); throw new RuntimeException( 'missing denial' ); } catch ( AccessDenied $error ) { verify( true, 'legacy settings permission' ); }
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = array(); $_POST = array( '_wpnonce' => wp_create_nonce( 'acm_enqueue_content' ), 'content_type' => 'post', 'language' => 'en', 'topic' => 'Example', 'batch_preset' => '1' );
$_POST['submission_id'] = '00000000-0000-4000-8000-000000000001'; $_POST['submission_nonce'] = wp_create_nonce( 'acm_submission_' . $_POST['submission_id'] );
try { $content->handle_submission(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) { $args = query( $redirect->getMessage() ); verify( 'content' === $args['tab'] && 'queued' === $args['acm_notice'], 'enqueue returns to content tab' ); }
$_POST = array( 'item_id' => 4, 'nonce' => wp_create_nonce( 'acm_generate_item' ) );
try { $generation->generate(); } catch ( JsonResult $result ) { verify( 1 === $generator->calls, 'manual generate with fake service' ); }
$_POST['nonce'] = 'invalid';
try { $generation->generate(); throw new RuntimeException( 'missing denial' ); } catch ( AccessDenied $error ) { verify( 1 === $generator->calls, 'AJAX nonce enforced' ); }
verify( 0 === $network_calls, 'zero external API calls' );
$_SERVER['REQUEST_METHOD'] = 'GET';
try { $queue->handle_recovery(); throw new RuntimeException( 'missing denial' ); } catch ( AccessDenied $error ) { verify( true, 'GET recovery denied' ); }
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array( '_wpnonce' => wp_create_nonce( 'acm_recover_queue' ) ); $_GET = $_REQUEST = array();
try { $queue->handle_recovery(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) { verify( '0' === query( $redirect->getMessage() )['acm_recovered'], 'manual recovery nonce and result' ); }
$_POST['_wpnonce'] = 'invalid';
try { $queue->handle_recovery(); throw new RuntimeException( 'missing denial' ); } catch ( AccessDenied $error ) { verify( true, 'recovery nonce enforced' ); }
$caps['manage_ai_content'] = false; $caps['edit_posts'] = true; $_GET = array( 'tab' => 'queue' );
try { capture( array( $page, 'render' ) ); throw new RuntimeException( 'missing denial' ); } catch ( AccessDenied $error ) { verify( true, 'edit_posts alone cannot access AI management' ); }
