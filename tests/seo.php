<?php
/** Isolated native Yoast API contract fixtures. No real WordPress, Yoast or HTTP. */
// Test progress must not count as application output before the controller's headers.
ob_start();
define( 'ACM_SEO_BOOTSTRAP_ONLY', true );
require __DIR__ . '/workflows.php';

class SeoDatabase extends WorkflowDatabase {
    public $fail_proposal = false;
    public $proposal_writes = 0;
    public $throw_lock = false;
    public function update( $table, $data, $where, ...$formats ) {
        if ( isset( $data['payload'] ) ) {
            if ( $this->fail_proposal ) { return false; }
            $this->proposal_writes++;
        }
        return parent::update( $table, $data, $where, ...$formats );
    }
    public function get_var( $sql ) {
        if ( $this->throw_lock && false !== strpos( $sql, 'GET_LOCK' ) ) { throw new RuntimeException( 'private content nonce secret' ); }
        if ( false !== strpos( $sql, 'SELECT message' ) ) {
            preg_match( '/item_id = (\d+)/', $sql, $match );
            foreach ( array_reverse( $this->events ) as $event ) {
                if ( (int) $match[1] === (int) $event['item_id'] && 0 === strpos( $event['message'], 'نتیجه اطلاعات سئو: ' ) ) { return $event['message']; }
            }
            return null;
        }
        return parent::get_var( $sql );
    }
}

$wpdb = new SeoDatabase();
$repo = new AIContentManager\Services\QueueRepository();
$seo = new AIContentManager\Services\QueueSeo( $repo );
$drafts = new AIContentManager\Services\DraftCreator( $repo );
$adapter = new AIContentManager\Integrations\Yoast();
$article = array( 'title' => 'مقاله آزمایشی', 'slug' => 'test', 'excerpt' => 'خلاصه', 'content' => '<h2>تیتر</h2><p>متن آزمایشی.</p>',
    'primary_keyword' => 'آموزش برنامه‌نویسی فارسی', 'secondary_keywords' => array( 'افزوده نشود' ),
    'suggested_meta_description' => 'توضیح فارسی با نیم‌فاصله، "نقل قول" و \\ و 😀 برای بررسی حفظ دقیق متن.',
    'headings' => array( array( 'level' => 2, 'text' => 'تیتر' ) ), 'faq' => array(), 'image_prompt' => 'unused' );
function seo_item( $output = null ) {
    global $repo, $wpdb, $drafts, $article;
    $repo->insert_batch( wp_generate_uuid4(), array( array( 'content_type' => 'post', 'language' => 'fa', 'payload' => array( 'topic' => 'آزمایش' ) ) ), 7 );
    $id = count( $wpdb->queue );
    $wpdb->queue[$id]['status'] = 'generated'; $wpdb->queue[$id]['generated_output'] = wp_json_encode( $output ?? $article );
    $post = $drafts->create( $id );
    if ( is_wp_error( $post ) ) { throw new RuntimeException( $post->get_error_message() ); }
    return array( $id, $post );
}
function signatures( $comparison ) { return array_map( static function ( $field ) { return $field['signature']; }, $comparison['fields'] ); }
function check_denied( $callback, $label ) {
    try { $callback(); throw new RuntimeException( 'Missing denial: ' . $label ); } catch ( AccessDenied $error ) { verify( true, $label ); }
}

// Absence is tested before loading a runtime-defined Yoast fixture class.
verify( ! $adapter->available() && ! $seo->enabled(), 'Yoast absent; old installation defaults off' );
$options['acm_settings'] = array( 'yoast_on_draft' => true );
list( $id, $post ) = seo_item();
verify( is_wp_error( $drafts->seo_result() ) && 'ready' === $wpdb->queue[$id]['draft_state'] && 'generated' === $wpdb->queue[$id]['status'], 'inactive Yoast leaves completed draft and generation intact' );
verify( 'Yoast SEO فعال نیست' === $seo->compare( $id )->get_error_message() && is_wp_error( $seo->apply( $id, array(), array(), true ) ), 'inactive manual SEO stops clearly' );
$saved = $wpdb->queue[$id];
$options['acm_settings'] = array(); $drafts->create( $id );
$options['acm_settings'] = array( 'yoast_on_draft' => true ); $drafts->create( $id );
verify( null === $drafts->seo_result() && $saved === $wpdb->queue[$id], 'changing setting and retry of ready draft never backfills old posts' );

define( 'WPSEO_VERSION', '28.8' );
// Conditional declaration makes the inactive case above a genuine missing-class case.
if ( ! class_exists( 'WPSEO_Meta' ) ) {
    class WPSEO_Meta {
        public static $values = array(); public static $writes = array(); public static $fail = null; public static $throw = null; public static $read_throw = null; public static $during_write;
        public static function get_value( $key, $post_id ) {
            if ( self::$read_throw === $key ) { throw new RuntimeException( 'sensitive read failure' ); }
            return self::$values[$post_id][$key] ?? '';
        }
        public static function set_value( $key, $value, $post_id ) {
            self::$writes[] = array( $post_id, $key, $value );
            if ( self::$throw === $key ) { throw new RuntimeException( 'sensitive write failure' ); }
            if ( self::$fail === $key ) { return false; }
            // Mirrors set_value's slash/WordPress-unslash contract, without network/database.
            self::$values[$post_id][$key] = stripslashes( wp_slash( $value ) );
            do_action( 'updated_post_meta', 1, $post_id, '_yoast_wpseo_' . $key );
            if ( self::$during_write ) { call_user_func( self::$during_write, $post_id, $key ); }
            return true;
        }
    }
}
verify( $adapter->available(), 'Yoast native API detected without Premium' );
$settings = new AIContentManager\Admin\Settings(); $settings->register();
verify( isset( $fields['acm_yoast_on_draft'] ) && false === $registered_setting[2]['default']['yoast_on_draft'], 'translated setting registered with false upgrade default' );
verify( true === $settings->sanitize( array( 'yoast_on_draft' => '1' ) )['yoast_on_draft'] && false === $settings->sanitize( array() )['yoast_on_draft'], 'setting sanitizes explicitly and performs no metadata writes' );
verify( true === $settings->sanitize( $settings->sanitize( array( 'yoast_on_draft' => '1' ) ) )['yoast_on_draft'], 'setting sanitizer is idempotent for Settings API double sanitization' );
$options['acm_settings'] = array();
list( $id, $post ) = seo_item();
$events = count( $wpdb->events ); $writes = count( WPSEO_Meta::$writes );
$comparison = $seo->compare( $id );
verify( 'pending' === $comparison['fields']['primary_keyword']['status'] && 'pending' === $comparison['fields']['suggested_meta_description']['status'], 'both empty fields independently proposed' );
verify( $events === count( $wpdb->events ) && $writes === count( WPSEO_Meta::$writes ), 'comparison performs no writes or audit events' );
$native_hooks = array(); $action_handlers['updated_post_meta'] = static function ( $meta_id, $post_id, $key ) use ( &$native_hooks ) { $native_hooks[] = array( $post_id, $key ); };
$result = $seo->apply( $id, array(), array(), true );
verify( array( 'primary_keyword' => 'applied', 'suggested_meta_description' => 'applied' ) === $result['fields'] && ! $result['review'], 'both empty fields applied successfully' );
verify( $article['primary_keyword'] === WPSEO_Meta::$values[$post]['focuskw'] && $article['suggested_meta_description'] === WPSEO_Meta::$values[$post]['metadesc'], 'Persian multiword Unicode quotes slashes emoji preserved exactly' );
verify( 2 === count( $native_hooks ) && array( 'focuskw', 'metadesc' ) === array_keys( WPSEO_Meta::$values[$post] ), 'only two native keys written and native metadata hooks used' );
$events = count( $wpdb->events ); $writes = count( WPSEO_Meta::$writes ); $inserts = $insert_calls;
$result = $seo->apply( $id, array(), array(), true );
verify( array( 'equal', 'equal' ) === array_values( $result['fields'] ) && $writes === count( WPSEO_Meta::$writes ) && $events === count( $wpdb->events ) && $inserts === $insert_calls, 'equal replay has no writes events drafts or generation' );
$event = end( $wpdb->events );
verify( $event['item_id'] === $id && 7 === $event['created_by'] && isset( $event['created_at'] ) && false !== strpos( $event['message'], '"post_id":' . $post ) && false !== strpos( $event['message'], '"primary_keyword":"applied"' ) && ! isset( $event['api_metadata'] ), 'audit contains item post actor UTC time and per-field codes only' );

foreach ( array( array( 'manual', '' ), array( '', 'manual' ), array( 'manual', 'manual' ) ) as $values ) {
    list( $id, $post ) = seo_item(); WPSEO_Meta::$values[$post] = array( 'focuskw' => $values[0], 'metadesc' => $values[1] );
    $result = $seo->apply( $id, array(), array(), true );
    verify( ( $values[0] ? 'preserved' : 'applied' ) === $result['fields']['primary_keyword'] && ( $values[1] ? 'preserved' : 'applied' ) === $result['fields']['suggested_meta_description'], 'existing and empty fields handled independently: ' . implode( '/', $values ) );
    $events = count( $wpdb->events ); $seo->apply( $id, array(), array(), true ); $seo->apply( $id, array(), array(), true );
    verify( $events === count( $wpdb->events ), 'unchanged preserved result deduplicated' );
}

// Manual explicit overwrite, signatures, tampering and independent conflicts.
list( $id, $post ) = seo_item(); WPSEO_Meta::$values[$post] = array( 'focuskw' => 'عبارت دستی', 'metadesc' => 'متای دستی' );
$comparison = $seo->compare( $id );
$result = $seo->apply( $id, array( 'primary_keyword' => 'overwrite' ), signatures( $comparison ) );
verify( 'applied' === $result['fields']['primary_keyword'] && 'skipped' === $result['fields']['suggested_meta_description'] && 'متای دستی' === WPSEO_Meta::$values[$post]['metadesc'], 'explicit overwrite of one field preserves unselected manual field' );
$writes = count( WPSEO_Meta::$writes ); $events = count( $wpdb->events );
$result = $seo->apply( $id, array( 'primary_keyword' => 'overwrite' ), signatures( $comparison ) );
verify( 'equal' === $result['fields']['primary_keyword'] && $writes === count( WPSEO_Meta::$writes ) && $events === count( $wpdb->events ), 'identical successful manual POST replay is equal without writes or duplicate events' );
$comparison = $seo->compare( $id );
WPSEO_Meta::$values[$post]['metadesc'] = 'تغییر جدید کاربر';
$result = $seo->apply( $id, array( 'primary_keyword' => 'fill', 'suggested_meta_description' => 'overwrite' ), signatures( $comparison ) );
verify( 'equal' === $result['fields']['primary_keyword'] && 'conflict' === $result['fields']['suggested_meta_description'] && 'تغییر جدید کاربر' === WPSEO_Meta::$values[$post]['metadesc'], 'changed value since comparison conflicts only its own field' );
$comparison = $seo->compare( $id ); $sigs = signatures( $comparison ); $sigs['suggested_meta_description'] = 'forged';
$result = $seo->apply( $id, array( 'suggested_meta_description' => 'overwrite' ), $sigs );
verify( 'conflict' === $result['fields']['suggested_meta_description'], 'forged signature cannot authorize replacement' );
$comparison = $seo->compare( $id ); $changed = $article; $changed['suggested_meta_description'] = 'پیشنهاد تغییر کرده'; $wpdb->queue[$id]['generated_output'] = wp_json_encode( $changed );
$result = $seo->apply( $id, array( 'suggested_meta_description' => 'overwrite' ), signatures( $comparison ) );
verify( 'conflict' === $result['fields']['suggested_meta_description'], 'changed stored suggestion invalidates comparison' );

foreach ( array( '', '<script>alert(1)</script>', '&lt;b&gt;markup&lt;/b&gt;', array( 'bad' ), "bad\nphrase", 'اول، دوم', str_repeat( 'ی', 192 ) ) as $bad ) {
    $bad_article = $article; $bad_article['primary_keyword'] = $bad;
    list( $id, $post ) = seo_item(); $wpdb->queue[$id]['generated_output'] = wp_json_encode( $bad_article );
    $comparison = $seo->compare( $id ); $result = $seo->apply( $id, array(), array(), true );
    verify( 'invalid' === $comparison['fields']['primary_keyword']['status'] && 'invalid' === $result['fields']['primary_keyword'] && 'applied' === $result['fields']['suggested_meta_description'] && empty( WPSEO_Meta::$values[$post]['focuskw'] ), 'invalid or empty primary never clears or prevents other field' );
}
list( $id, $post ) = seo_item(); $bad_article = $article; $bad_article['suggested_meta_description'] = str_repeat( 'ف', 1001 ); $wpdb->queue[$id]['generated_output'] = wp_json_encode( $bad_article ); WPSEO_Meta::$values[$post]['metadesc'] = 'متای دستی';
$result = $seo->apply( $id, array(), array(), true );
verify( 'invalid' === $result['fields']['suggested_meta_description'] && 'متای دستی' === WPSEO_Meta::$values[$post]['metadesc'], 'overlong Unicode meta rejected without truncating or erasing manual text' );
verify( ! is_wp_error( $adapter->validate( 'suggested_meta_description', str_repeat( 'ف', 160 ) ) ) && '' !== $adapter->guidance( 'suggested_meta_description', str_repeat( 'ف', 161 ) ) && '' === $adapter->guidance( 'suggested_meta_description', str_repeat( 'ف', 160 ) ), 'Unicode lengths count codepoints and guidance never truncates' );
verify( is_wp_error( $adapter->validate( 'primary_keyword', "bad\xFF" ) ), 'malformed UTF-8 rejected' );
$wpdb->queue[$id]['generated_output'] = '{}'; verify( is_wp_error( $seo->apply( $id, array(), array(), true ) ), 'stored article schema revalidated' );

foreach ( array( 'trash', 'publish', 'future', 'private', 'pending', 'auto-draft' ) as $status ) {
    list( $id, $post ) = seo_item(); get_post( $post )->post_status = $status; $writes = count( WPSEO_Meta::$writes );
    verify( is_wp_error( $seo->compare( $id ) ) && is_wp_error( $seo->apply( $id, array(), array(), true ) ) && $writes === count( WPSEO_Meta::$writes ), 'non-draft post refused: ' . $status );
}
list( $id, $post ) = seo_item(); unset( $fixture_posts[$post] ); verify( is_wp_error( $seo->apply( $id, array(), array(), true ) ), 'deleted post refused' );
list( $id, $post ) = seo_item(); $wpdb->queue[$id]['status'] = 'trashed'; verify( is_wp_error( $seo->apply( $id, array(), array(), true ) ), 'queue trash refused' );
list( $id, $post ) = seo_item(); get_post( $post )->post_type = 'page'; verify( is_wp_error( $seo->apply( $id, array(), array(), true ) ), 'mismatched post type refused' );
list( $id, $post ) = seo_item(); get_post( $post )->post_content_filtered = 'unrelated'; verify( is_wp_error( $seo->apply( $id, array(), array(), true ) ), 'unrelated linked post refused by durable marker' );
list( $id, $post ) = seo_item(); $caps['edit_post'] = false; verify( is_wp_error( $seo->compare( $id ) ) && is_wp_error( $seo->apply( $id, array(), array(), true ) ), 'edit_post on resolved post required' ); $caps['edit_post'] = true;
$post_capabilities = array( 999999 => true );
verify( is_wp_error( $seo->apply( $id, array(), array(), true ) ), 'permission on a different post cannot authorize the linked post' ); unset( $post_capabilities );
$caps['manage_ai_content'] = false; verify( is_wp_error( $seo->apply( $id, array(), array(), true ) ), 'dedicated AI management capability required' ); $caps['manage_ai_content'] = true;
$wpdb->lock_available = false; verify( is_wp_error( $seo->apply( $id, array(), array(), true ) ), 'shared item lock fails closed' ); $wpdb->lock_available = true;

list( $id, $post ) = seo_item(); WPSEO_Meta::$fail = 'focuskw';
$result = $seo->apply( $id, array(), array(), true );
verify( 'error' === $result['fields']['primary_keyword'] && 'applied' === $result['fields']['suggested_meta_description'] && $result['review'], 'one update failure does not prevent successful other field or become equal' );
WPSEO_Meta::$fail = null; WPSEO_Meta::$throw = 'focuskw';
$result = $seo->apply( $id, array(), array(), true );
verify( 'error' === $result['fields']['primary_keyword'] && 'equal' === $result['fields']['suggested_meta_description'] && false === strpos( wp_json_encode( $result ), 'sensitive' ), 'throwing write isolated per field and secrets never disclosed' ); WPSEO_Meta::$throw = null;
WPSEO_Meta::$read_throw = 'focuskw';
$comparison = $seo->compare( $id ); $result = $seo->apply( $id, array(), array(), true );
verify( 'error' === $comparison['fields']['primary_keyword']['status'] && 'error' === $result['fields']['primary_keyword'] && 'equal' === $result['fields']['suggested_meta_description'], 'read failure isolated per field' ); WPSEO_Meta::$read_throw = null;
$wpdb->fail_event = true; $result = $seo->apply( $id, array(), array(), true );
verify( $result['review'] && isset( $result['audit_error'] ) && 'applied' === $result['fields']['primary_keyword'], 'audit failure reports review without undoing successful metadata or draft' ); $wpdb->fail_event = false;
$events = count( $wpdb->events ); $result = $seo->apply( $id, array(), array(), true );
verify( ! $result['review'] && $events + 1 === count( $wpdb->events ) && array( 'equal', 'equal' ) === array_values( $result['fields'] ), 'retry after audit failure records verified equality without another metadata write' );

// Automatic completion is separate from DraftCreator success and ready state.
$options['acm_settings'] = array( 'yoast_on_draft' => true );
WPSEO_Meta::$fail = null; $invalid_seo = $article; $invalid_seo['primary_keyword'] = ''; $invalid_seo['suggested_meta_description'] = '<script>bad</script>';
list( $id, $post ) = seo_item( $invalid_seo );
verify( 'ready' === $wpdb->queue[$id]['draft_state'] && array( 'invalid', 'invalid' ) === array_values( $drafts->seo_result()['fields'] ), 'invalid SEO cannot block otherwise valid draft content completion' );
WPSEO_Meta::$fail = 'focuskw'; list( $id, $post ) = seo_item();
verify( 'ready' === $wpdb->queue[$id]['draft_state'] && $drafts->seo_result()['review'] && 'applied' === $drafts->seo_result()['fields']['suggested_meta_description'], 'automatic partial failure preserves completed draft and per-field result' );
$inserts = $insert_calls; $events = count( $wpdb->events );
$drafts->create( $id ); verify( $inserts === $insert_calls && $events === count( $wpdb->events ) && null === $drafts->seo_result(), 'draft replay does not retry SEO or create second draft' );
WPSEO_Meta::$fail = null; $options['acm_settings'] = array();
list( $id, $post ) = seo_item();
WPSEO_Meta::$during_write = static function ( $post_id, $key ) { get_post( $post_id )->post_status = 'publish'; };
$result = $seo->apply( $id, array(), array(), true );
verify( 'applied' === $result['fields']['primary_keyword'] && 'conflict' === $result['fields']['suggested_meta_description'] && empty( WPSEO_Meta::$values[$post]['metadesc'] ), 'post state rechecked before each field after external status change' );
WPSEO_Meta::$during_write = null;

// Real admin controller contract, including ignored client post_id and read-only UI.
$page = new AIContentManager\Admin\QueueManagerPage( $repo, new AIContentManager\Services\ContentTypeRegistry(), new AIContentManager\Integrations\Wpml() );
$controller = new AIContentManager\Admin\QueueSeoPage( $repo, $page );
list( $id, $post ) = seo_item(); $comparison = $seo->compare( $id );
$old_location = wp_nonce_url( AIContentManager\Admin\AdminPage::url( 'queue', array( 'seo_item' => $id ) ), 'acm_seo_compare_' . $id );
parse_str( parse_url( $old_location, PHP_URL_QUERY ), $old_query );
verify( ! isset( $old_query['tab'], $old_query['seo_item'], $old_query['_wpnonce'] ) && isset( $old_query['amp;tab'] ), 'old wp_nonce_url-in-Location pattern deterministically corrupts raw redirect query with WordPress escaping contract' );
$_GET = $_REQUEST = array(); $_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'item_id' => $id, 'post_id' => 999999, '_wpnonce' => 'bad', 'fields' => array( 'primary_keyword' => 'fill' ), 'signatures' => signatures( $comparison ) );
check_denied( array( $controller, 'handle' ), 'manual POST invalid nonce denied' );
$_POST['_wpnonce'] = wp_create_nonce( 'acm_seo_apply_' . $id ); $caps['manage_ai_content'] = false;
check_denied( array( $controller, 'handle' ), 'manual controller capability denied' ); $caps['manage_ai_content'] = true;
$_SERVER['REQUEST_METHOD'] = 'GET'; check_denied( array( $controller, 'handle' ), 'manual GET cannot mutate metadata' ); $_SERVER['REQUEST_METHOD'] = 'POST';
try { $controller->handle(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) {
    parse_str( parse_url( $redirect->getMessage(), PHP_URL_QUERY ), $raw_args );
    verify( false === strpos( $redirect->getMessage(), '&amp;' ) && 'queue' === $raw_args['tab'] && (string) $id === $raw_args['seo_item'] && wp_verify_nonce( $raw_args['_wpnonce'], 'acm_seo_compare_' . $id ) && 303 === $redirect_status, 'Location has raw query separators, correct page, item and comparison nonce; POST uses 303' );
    verify( (string) $id === query( $redirect->getMessage() )['seo_item'] && 'queue' === query( $redirect->getMessage() )['tab'] && $article['primary_keyword'] === WPSEO_Meta::$values[$post]['focuskw'] && ! isset( WPSEO_Meta::$values[999999] ), 'client post_id ignored; signed field applied to Repository relationship with safe redirect' );
}
$_GET = array( 'seo_item' => $id, '_wpnonce' => wp_create_nonce( 'acm_seo_compare_' . $id ) ); $_POST = $_REQUEST = array();
$writes = count( WPSEO_Meta::$writes ); $events = count( $wpdb->events ); $html = capture( array( $controller, 'render' ) );
verify( false !== strpos( $html, 'مقدار فعلی Yoast' ) && false !== strpos( $html, 'مقدار پیشنهادی خروجی' ) && false !== strpos( $html, 'value="fill" selected' ) && false !== strpos( $html, 'value="overwrite"' ) && false !== strpos( $html, 'signatures[primary_keyword]' ), 'manual comparison renders independent default-fill explicit-overwrite and signed fields' );
verify( $writes === count( WPSEO_Meta::$writes ) && $events === count( $wpdb->events ), 'manual comparison UI causes no writes' );
verify( 1 === substr_count( $html, '<form ' ) && 1 === substr_count( $html, '</form>' ) && false === strpos( $html, 'name="post_id"' ), 'comparison has one balanced form and never submits a post_id' );
$_GET['_wpnonce'] = 'invalid'; check_denied( array( $controller, 'render' ), 'comparison nonce checked' );
$_GET = array( 'preview_item' => $id, '_wpnonce' => wp_create_nonce( 'acm_preview_' . $id ) );
$html = capture( array( $page, 'render' ) ); verify( false !== strpos( $html, esc_html( $article['suggested_meta_description'] ) ), 'stored suggested metadata remains visible in article Preview' );
$_GET = array(); $wpdb->queue[$id]['status'] = 'trashed';
$_GET = array( 'status' => 'trashed' );
$html = capture( array( $page, 'render' ) );
verify( false !== strpos( $html, 'اطلاعات سئو در زباله‌دان صف غیرفعال است' ) && false !== strpos( $html, 'disabled' ), 'queue trash disables manual SEO action' );
$_GET = array(); $wpdb->queue[$id]['status'] = 'generated';
$html = capture( array( $page, 'render' ) );
verify( false !== strpos( $html, 'بررسی و اعمال اطلاعات سئو' ), 'linked queue rows expose manual SEO entry' );

// Automatic warning uses the actual draft command, without claiming whole-draft failure.
$options['acm_settings'] = array();
$repo->insert_batch( wp_generate_uuid4(), array( array( 'content_type' => 'post', 'language' => 'fa', 'payload' => array( 'topic' => 'اخطار' ) ) ), 7 );
$warning_id = count( $wpdb->queue ); $wpdb->queue[$warning_id]['status'] = 'generated'; $wpdb->queue[$warning_id]['generated_output'] = wp_json_encode( $article );
$commands = new AIContentManager\Admin\QueueActions( $drafts, new AIContentManager\Services\QueueTrash( $repo ), $page );
$options['acm_settings'] = array( 'yoast_on_draft' => true ); WPSEO_Meta::$fail = 'focuskw';
$_GET = $_REQUEST = array(); $_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'queue_action' => 'draft', 'item_id' => $warning_id, '_wpnonce' => wp_create_nonce( 'acm_queue_draft' ) );
try { $commands->handle(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) {
    $notice = get_transient( 'acm_queue_notice_7' );
    verify( ! $notice['error'] && $notice['warning'] && 'پیش‌نویس ایجاد شد؛ تکمیل اطلاعات سئو نیازمند بررسی است.' === $notice['message'] && 2 === count( $notice['details'] ) && 'ready' === $wpdb->queue[$warning_id]['draft_state'], 'actual draft controller reports SEO warning and both field results without whole-draft failure' );
}
WPSEO_Meta::$fail = null;

// Legacy proposals: edit only the queue proposal, then explicitly apply from a fresh comparison.
$options['acm_settings'] = array();
$legacy = $article; $legacy['primary_keyword'] = 'عبارت اول، عبارت دوم، عبارت سوم';
list( $id, $post ) = seo_item( $legacy );
WPSEO_Meta::$values[$post] = array( 'focuskw' => 'عبارت دستی کاربر', 'metadesc' => $article['suggested_meta_description'] );
$comparison = $seo->compare( $id ); $old_sigs = signatures( $comparison );
verify( 'invalid' === $comparison['fields']['primary_keyword']['status'] && $legacy['primary_keyword'] === $comparison['fields']['primary_keyword']['proposed'] && 'equal' === $comparison['fields']['suggested_meta_description']['status'], 'legacy multi-phrase stays intact and invalid; equal Yoast meta detected independently' );
$_GET = array( 'seo_item' => $id, '_wpnonce' => wp_create_nonce( 'acm_seo_compare_' . $id ) ); $_POST = array();
$html = capture( array( $controller, 'render' ) );
verify( false !== strpos( $html, 'اصلاح پیشنهاد عبارت کلیدی اصلی (فقط یک عبارت)' ) && false !== strpos( $html, esc_html( $legacy['primary_keyword'] ) ) && false !== strpos( $html, 'name="seo_command" value="save_keyword"' ) && false !== strpos( $html, 'signatures[primary_keyword]' ), 'invalid legacy keyword remains editable and signed; explicit two-step save available' );
verify( false !== strpos( $html, 'name="fields[primary_keyword]"' ) && false !== strpos( $html, 'value="overwrite" disabled' ), 'invalid field is submitted for independent validation unless explicitly skipped; overwrite disabled until corrected' );
$_GET = array(); $_POST = array( 'item_id' => $id, '_wpnonce' => wp_create_nonce( 'acm_seo_apply_' . $id ), 'fields' => array( 'primary_keyword' => 'fill', 'suggested_meta_description' => 'fill' ), 'signatures' => $old_sigs );
try { $controller->handle(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) {
    $notice = get_transient( 'acm_queue_notice_7' );
    verify( $notice['warning'] && false !== strpos( $notice['details'][0], 'نامعتبر' ) && false !== strpos( $notice['details'][1], 'از قبل برابر بود' ), 'actual legacy apply POST reports invalid keyword and equal meta independently without a success notice' );
}
$original_row = $wpdb->queue[$id]; $original_post = clone get_post( $post );
$inserts = $insert_calls; $writes = count( WPSEO_Meta::$writes ); $budget = ( new AIContentManager\Services\BudgetGuard( $repo ) )->summary(); $languages = $assigned_languages;
foreach ( array( 'اول، دوم', 'first, second', "first\nsecond", "first\rsecond", "first\u{2028}second", "first\u{2029}second", array( 'bad' ), '', '<b>bad</b>' ) as $bad ) {
    verify( is_wp_error( $seo->save_primary_keyword( $id, $bad, $old_sigs['primary_keyword'] ) ) && $original_row === $wpdb->queue[$id], 'invalid correction rejected server-side without truncation or storage' );
}
$_GET = $_REQUEST = array(); $_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'item_id' => $id, '_wpnonce' => 'bad', 'seo_command' => 'save_keyword', 'primary_keyword_proposal' => 'عبارت اصلاح‌شده', 'signatures' => $old_sigs );
check_denied( array( $controller, 'handle' ), 'correction POST requires valid nonce' );
verify( false !== strpos( $last_die[0], 'اعتبار فرم' ) && false !== strpos( $last_die[0], 'بازگشت به مدیریت صف' ) && 403 === $last_die[2]['response'], 'invalid nonce has Persian message, explicit status and valid queue back link' );
$_POST['_wpnonce'] = wp_create_nonce( 'acm_seo_apply_' . $id ); $caps['manage_ai_content'] = false;
check_denied( array( $controller, 'handle' ), 'correction POST requires AI management capability' ); $caps['manage_ai_content'] = true;
$caps['edit_post'] = false;
verify( is_wp_error( $seo->save_primary_keyword( $id, 'عبارت اصلاح‌شده', $old_sigs['primary_keyword'] ) ), 'correction requires permission on linked draft' ); $caps['edit_post'] = true;
verify( is_wp_error( $seo->save_primary_keyword( $id, 'عبارت اصلاح‌شده', 'forged' ) ), 'forged signature cannot save a correction' );
$wpdb->fail_proposal = true;
verify( is_wp_error( $seo->save_primary_keyword( $id, 'عبارت اصلاح‌شده', $old_sigs['primary_keyword'] ) ) && $original_row === $wpdb->queue[$id], 'proposal database failure cannot claim success or mutate history' ); $wpdb->fail_proposal = false;
try { $controller->handle(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) {
    verify( ! get_transient( 'acm_queue_notice_7' )['error'] && (string) $id === query( $redirect->getMessage() )['seo_item'], 'actual correction POST stores proposal and returns to a fresh comparison' );
}
$payload = json_decode( $wpdb->queue[$id]['payload'], true );
verify( 'عبارت اصلاح‌شده' === $payload['seo_review']['primary_keyword'] && $original_row['generated_output'] === $wpdb->queue[$id]['generated_output'] && $original_row['api_metadata'] === $wpdb->queue[$id]['api_metadata'], 'correction stored in existing payload.seo_review, original output and API history preserved' );
$unchanged = $wpdb->queue[$id]; $unchanged['payload'] = $original_row['payload'];
verify( $unchanged === $original_row && $original_post == get_post( $post ) && $inserts === $insert_calls && $writes === count( WPSEO_Meta::$writes ) && $budget === ( new AIContentManager\Services\BudgetGuard( $repo ) )->summary() && $languages === $assigned_languages, 'save changes only proposal; draft content state language budget usage and draft count unchanged' );
$comparison = $seo->compare( $id );
verify( 'عبارت اصلاح‌شده' === $comparison['fields']['primary_keyword']['proposed'] && 'preserved' === $comparison['fields']['primary_keyword']['status'] && $legacy['primary_keyword'] === $comparison['original_keyword'], 'fresh comparison shows corrected proposal and preserved manual Yoast value with original history' );
$proposal_writes = $wpdb->proposal_writes;
verify( true === $seo->save_primary_keyword( $id, 'عبارت اصلاح‌شده', signatures( $comparison )['primary_keyword'] ) && $proposal_writes === $wpdb->proposal_writes, 'identical correction avoids redundant queue write' );
verify( is_wp_error( $seo->save_primary_keyword( $id, 'اصلاح تب قدیمی', $old_sigs['primary_keyword'] ) ), 'older comparison cannot overwrite a concurrently corrected proposal' );
$result = $seo->apply( $id, array( 'primary_keyword' => 'overwrite', 'suggested_meta_description' => 'fill' ), $old_sigs );
verify( 'conflict' === $result['fields']['primary_keyword'] && 'equal' === $result['fields']['suggested_meta_description'] && 'عبارت دستی کاربر' === WPSEO_Meta::$values[$post]['focuskw'], 'correction invalidates old apply signature only for changed proposal; equal meta still processed' );
$result = $seo->apply( $id, array( 'primary_keyword' => 'fill', 'suggested_meta_description' => 'fill' ), signatures( $comparison ) );
verify( 'preserved' === $result['fields']['primary_keyword'] && 'equal' === $result['fields']['suggested_meta_description'] && $writes === count( WPSEO_Meta::$writes ), 'corrected proposal cannot bypass explicit overwrite and equal meta never writes' );
WPSEO_Meta::$values[$post]['focuskw'] = 'تغییر دستی پس از مقایسه';
$result = $seo->apply( $id, array( 'primary_keyword' => 'overwrite' ), signatures( $comparison ) );
verify( 'conflict' === $result['fields']['primary_keyword'] && 'تغییر دستی پس از مقایسه' === WPSEO_Meta::$values[$post]['focuskw'], 'manual Yoast edit after correction comparison still conflicts' );
verify( is_wp_error( $seo->save_primary_keyword( $id, 'اصلاح از تب قدیمی', signatures( $comparison )['primary_keyword'] ) ), 'correction also detects concurrent manual Yoast change' );
$comparison = $seo->compare( $id );
$_POST = array( 'item_id' => $id, '_wpnonce' => wp_create_nonce( 'acm_seo_apply_' . $id ), 'fields' => array( 'primary_keyword' => 'overwrite', 'suggested_meta_description' => 'fill' ), 'signatures' => signatures( $comparison ), 'primary_keyword_proposal' => 'مقدار دستکاری‌شده مرورگر' );
try { $controller->handle(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) {
    verify( 'عبارت اصلاح‌شده' === WPSEO_Meta::$values[$post]['focuskw'] && ! get_transient( 'acm_queue_notice_7' )['warning'], 'actual apply POST uses stored signed correction; browser text cannot replace it' );
}
$writes = count( WPSEO_Meta::$writes );
try { $controller->handle(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) {
    verify( $writes === count( WPSEO_Meta::$writes ) && false !== strpos( implode( ' ', get_transient( 'acm_queue_notice_7' )['details'] ), 'از قبل برابر بود' ), 'corrected apply POST replay is equal with no redundant Yoast writes' );
}
try { $controller->handle(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) {
    verify( $writes === count( WPSEO_Meta::$writes ), 'unchanged transient false return is recognized as already stored, not a notice failure' );
}
$_GET = array( 'seo_item' => $id, '_wpnonce' => wp_create_nonce( 'acm_seo_compare_' . $id ) );
$html = capture( array( $controller, 'render' ) );
verify( false !== strpos( $html, 'پیشنهاد اصلی تولیدشده (سابقه)' ) && false !== strpos( $html, esc_html( $legacy['primary_keyword'] ) ), 'comparison retains and escapes original generated proposal after correction' ); $_GET = array();
$redirect_fail = true;
check_denied( array( $controller, 'handle' ), 'canceled redirect renders an error instead of exiting with a blank page' );
verify( 500 === $last_die[2]['response'] && false !== strpos( $last_die[0], 'بازگشت خودکار' ) && false !== strpos( $last_die[0], 'بازگشت به مدیریت صف' ) && false !== strpos( $last_die[0], 'از قبل برابر بود' ), 'redirect failure displays Persian recovery link and actual field outcomes' ); $redirect_fail = false;
delete_transient( 'acm_queue_notice_7' ); $transient_fail_key = 'acm_queue_notice_7';
check_denied( array( $controller, 'handle' ), 'notice storage failure returns visible recovery and actual result' );
verify( false !== strpos( $last_die[0], 'ثبت پیام نتیجه' ) && false !== strpos( $last_die[0], 'از قبل برابر بود' ) && ! get_transient( 'acm_queue_notice_7' ), 'failed notice is not hidden behind a successful redirect' ); unset( $transient_fail_key );
$wpdb->throw_lock = true;
check_denied( array( $controller, 'handle' ), 'unexpected storage exception at command boundary becomes a recovery page' );
verify( false !== strpos( $last_die[0], 'پردازش یا ثبت نتیجه' ) && false === strpos( $last_die[0], 'private content nonce secret' ), 'unexpected exception does not disclose raw private details or claim success' ); $wpdb->throw_lock = false;
$caps['edit_post'] = false;
try { $controller->handle(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) { verify( get_transient( 'acm_queue_notice_7' )['error'] && get_transient( 'acm_queue_notice_7' )['warning'], 'linked-post permission failure returns error notice rather than success' ); } $caps['edit_post'] = true;
$_POST['item_id'] = array( $id ); check_denied( array( $controller, 'handle' ), 'malformed item ID has a recoverable error page' );
$_GET = array( 'seo_item' => 'bad' ); check_denied( array( $controller, 'render' ), 'malformed comparison ID cannot silently return a blank view' );
verify( $original_post == get_post( $post ) && $inserts === $insert_calls && $languages === $assigned_languages && $budget === ( new AIContentManager\Services\BudgetGuard( $repo ) )->summary(), 'entire correction and apply path preserves draft content status count WPML budget and usage' );
verify( 0 === $network_calls, 'entire SEO suite makes zero OpenAI or other HTTP requests' );
ob_end_flush();
