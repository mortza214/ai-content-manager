<?php
/** Regression fixtures only: no WordPress, MySQL, WPML installation or network. */
ob_start();
define( 'ACM_CATEGORY_BOOTSTRAP_ONLY', true );
require __DIR__ . '/workflows.php';

class CategoryDatabase extends WorkflowDatabase {
    public $fail_category_snapshot = false;
    public function update( $table, $data, $where, ...$formats ) {
        if ( $this->fail_category_snapshot && isset( $data['payload'] ) ) { return false; }
        return parent::update( $table, $data, $where, ...$formats );
    }
}
$wpdb = new CategoryDatabase();
$repo = new AIContentManager\Services\QueueRepository();
$wpml = new AIContentManager\Integrations\Wpml();
$categories = new AIContentManager\Services\QueueCategory( $repo, $wpml );
$seo_spy = new class {
    public $calls = 0;
    public function enabled() { $this->calls++; return false; }
    public function apply() { throw new RuntimeException( 'Unexpected SEO call' ); }
};
$drafts = new AIContentManager\Services\DraftCreator( $repo, $wpml, null, $seo_spy );
$queue = new AIContentManager\Services\ContentQueue( $repo, new AIContentManager\Services\ContentTypeRegistry() );
$content = new AIContentManager\Admin\ContentPage( $queue, new AIContentManager\Services\ContentTypeRegistry(), $wpml );
$page = new AIContentManager\Admin\QueueManagerPage( $repo, new AIContentManager\Services\ContentTypeRegistry(), $wpml );
$controller = new AIContentManager\Admin\QueueCategoryPage( $repo, $page, $wpml );
$article = array( 'title' => 'مقاله آزمایشی', 'slug' => 'category-test', 'excerpt' => 'خلاصه', 'content' => '<h2>تیتر</h2><p>متن ثابت مقاله.</p>',
    'primary_keyword' => 'عبارت ثابت', 'secondary_keywords' => array(), 'suggested_meta_description' => 'متای ثابت',
    'headings' => array( array( 'level' => 2, 'text' => 'تیتر' ) ), 'faq' => array(), 'image_prompt' => 'unused' );
foreach ( array( 1 => 'پیش‌فرض', 4 => 'دسته انگلیسی', 5 => 'انتخاب دستی', 7 => 'بدون ترجمه' ) as $id => $name ) {
    $fixture_terms[$id] = (object) array( 'term_id' => $id, 'term_taxonomy_id' => $id * 10, 'taxonomy' => 'category', 'name' => $name );
}
$during_insert = static function () { $GLOBALS['assigned_terms'][$GLOBALS['insert_calls'] + 100] = array( 1 ); };
function category_item( $category = null, $language = 'fa_IR' ) {
    global $repo, $wpdb, $article;
    $repo->insert_batch( wp_generate_uuid4(), array( array( 'content_type' => 'post', 'language' => $language, 'payload' => array( 'topic' => 'آزمایش دسته‌بندی', 'category' => $category ) ) ), 7 );
    $id = count( $wpdb->queue ); $wpdb->queue[$id]['status'] = 'generated'; $wpdb->queue[$id]['generated_output'] = wp_json_encode( $article );
    return $id;
}
function category_post( $choice, $language = 'fa_IR' ) {
    $submission = wp_generate_uuid4(); $_GET = $_REQUEST = array(); $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array( '_wpnonce' => wp_create_nonce( 'acm_enqueue_content' ), 'submission_id' => $submission, 'submission_nonce' => wp_create_nonce( 'acm_submission_' . $submission ),
        'content_type' => 'post', 'language' => $language, 'topic' => 'موضوع دسته‌بندی', 'topic_input_mode' => 'single', 'primary_keyword' => 'یک عبارت', 'category_choice' => $choice );
}
function category_submit() {
    global $content;
    try { $content->handle_submission(); throw new RuntimeException( 'Missing redirect' ); } catch ( RedirectResult $redirect ) { return query( $redirect->getMessage() ); }
}
function category_denied( $callback, $label ) {
    try { $callback(); throw new RuntimeException( 'Expected denial' ); } catch ( AccessDenied $error ) { verify( true, $label ); }
}
function category_command( $id, $signature, $confirm = '1' ) {
    $_GET = $_REQUEST = array(); $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array( 'item_id' => $id, '_wpnonce' => wp_create_nonce( 'acm_category_apply_' . $id ), 'signature' => $signature, 'confirm_replace' => $confirm );
}

verify( ! $wpml->is_available(), 'category fixture begins with WPML disabled' );
category_post( 'post|category|3' ); $args = category_submit(); $id = count( $wpdb->queue );
verify( 'queued' === $args['acm_notice'] && array( 'taxonomy' => 'category', 'term_id' => 3 ) === json_decode( $wpdb->queue[$id]['payload'], true )['category'], 'actual request POST preserves validated category in queue payload' );
$original_post = $_POST;
$wpdb->queue[$id]['status'] = 'generated'; $wpdb->queue[$id]['generated_output'] = wp_json_encode( $article );
$post = $drafts->create( $id );
if ( is_wp_error( $post ) ) { throw new RuntimeException( $post->get_error_message() ); }
verify( is_int( $post ) && array( 3 ) === $assigned_terms[$post] && 'ready' === $wpdb->queue[$id]['draft_state'], 'draft receives selected category with WPML disabled, verified using term IDs rather than setter taxonomy IDs' );
$attempt = json_decode( $wpdb->queue[$id]['payload'], true )['category_attempt'];
verify( array( 1 ) === $attempt['baseline'] && $attempt['post_id'] === $post && $attempt['draft_token'] === $wpdb->queue[$id]['draft_token'], 'baseline records exact default terms and durable draft binding before category changes' );
$_POST = $original_post; $before = count( $wpdb->queue ); $args = category_submit();
verify( 'queued' === $args['acm_notice'] && $before === count( $wpdb->queue ), 'submission replay after internal category baseline remains idempotent' );
$calls = $insert_calls; $writes = $term_write_calls; $seo_calls = $seo_spy->calls;
verify( $post === $drafts->create( $id ) && $calls === $insert_calls && $writes === $term_write_calls && $seo_calls === $seo_spy->calls, 'equal ready draft recheck performs no insert, category write or SEO call' );
category_post( '' ); $args = category_submit(); $empty = count( $wpdb->queue ); $wpdb->queue[$empty]['status'] = 'generated'; $wpdb->queue[$empty]['generated_output'] = wp_json_encode( $article ); $empty_post = $drafts->create( $empty );
verify( 'queued' === $args['acm_notice'] && null === json_decode( $wpdb->queue[$empty]['payload'], true )['category'] && array( 1 ) === $assigned_terms[$empty_post], 'explicit empty selection is null and preserves WordPress default category' );
foreach ( array( 'garbage', 'post|category|999', 'page|category|3', 'post|category|3junk', 'post|category|-3', 'post|category|0', array( 'bad' ) ) as $choice ) {
    category_post( $choice ); $before = count( $wpdb->queue ); $args = category_submit(); $feedback = get_transient( 'acm_content_feedback_7' );
    verify( 'validation' === $args['acm_notice'] && $before === count( $wpdb->queue ) && $choice === $feedback['values']['category_choice'] && 'موضوع دسته‌بندی' === $feedback['values']['topic'] && '' !== $feedback['message'], 'invalid or deleted selection fails with preserved form values instead of silently becoming null' );
}
category_post( 'post|category|999' ); category_submit(); $html = capture( array( $content, 'render' ) );
verify( false !== strpos( $html, 'value="post|category|999" selected' ) && false !== strpos( $html, 'انتخاب قبلی نامعتبر' ), 'invalid selected category remains visibly selected on returned form' );
$caps['assign_terms'] = false; category_post( 'post|category|3' ); $args = category_submit();
verify( 'validation' === $args['acm_notice'] && false !== strpos( get_transient( 'acm_content_feedback_7' )['message'], 'مجوز' ), 'request POST checks taxonomy assign_terms permission' ); $caps['assign_terms'] = true;
verify( is_wp_error( $queue->enqueue_topics( array( 'content_type' => 'post', 'language' => 'fa_IR', 'category' => array() ), array( 'آزمایش' ) ) ), 'queue service independently rejects malformed non-null category payload' );
verify( is_wp_error( $categories->validate( array( 'taxonomy' => 'category', 'term_id' => true ), 'post', 'fa_IR' ) ), 'boolean term ID cannot silently select an existing numeric term' );
$corrupt = category_item(); $wpdb->queue[$corrupt]['payload'] = '{bad'; $calls = $insert_calls;
verify( is_wp_error( $drafts->create( $corrupt ) ) && $calls === $insert_calls, 'corrupt stored payload is not treated as an empty category choice' );
$removed = category_item( array( 'taxonomy' => 'category', 'term_id' => 3 ) ); $saved_term = $fixture_terms[3]; unset( $fixture_terms[3] ); $calls = $insert_calls;
verify( is_wp_error( $drafts->create( $removed ) ) && $calls === $insert_calls, 'term deleted after enqueue fails before insertion' ); $fixture_terms[3] = $saved_term;
$fixture_taxonomy_objects['category']->hierarchical = false;
verify( is_wp_error( $categories->parse_choice( 'post|category|3', 'post', 'fa_IR' ) ), 'non-hierarchical taxonomy cannot enter category path' ); $fixture_taxonomy_objects['category']->hierarchical = true;

// Enable WPML after testing its genuine absence. Both restore contracts can run separately.
define( 'ICL_SITEPRESS_VERSION', in_array( '--wpml4', $argv, true ) ? '4.7' : '5.0' );
$term_languages = array( 10 => 'fa', 30 => 'fa', 40 => 'en', 50 => 'en', 70 => 'fa' );
$translations = array( 1 => array( 'fa' => 1 ), 3 => array( 'fa' => 3, 'en' => 4 ), 4 => array( 'fa' => 3, 'en' => 4 ), 5 => array( 'en' => 5 ), 7 => array( 'fa' => 7 ) );
$query_language = 'en'; $language_stack = array(); $language_calls = 0; $translation_calls = array();
$filter_handlers['wpml_active_languages'] = static function () { return array( 'fa' => array( 'language_code' => 'fa', 'native_name' => 'فارسی' ), 'en' => array( 'language_code' => 'en', 'native_name' => 'انگلیسی' ) ); };
$filter_handlers['wpml_is_translated_taxonomy'] = static function ( $translated, $taxonomy ) { return 'category' === $taxonomy; };
$filter_handlers['wpml_current_language'] = static function () { return $GLOBALS['query_language']; };
$action_handlers['wpml_switch_language'] = static function ( $language ) {
    if ( version_compare( ICL_SITEPRESS_VERSION, '5.0', '<' ) ) { $GLOBALS['query_language'] = $language; return; }
    if ( null === $language ) { $GLOBALS['query_language'] = array_pop( $GLOBALS['language_stack'] ); }
    else { $GLOBALS['language_stack'][] = $GLOBALS['query_language']; $GLOBALS['query_language'] = $language; }
};
$filter_handlers['wpml_object_id'] = static function ( $id, $taxonomy, $fallback, $language ) {
    $GLOBALS['translation_calls'][] = array( $id, $taxonomy, $fallback, $language ); return $GLOBALS['translations'][$id][$language] ?? null;
};
$filter_handlers['wpml_element_type'] = static function ( $type ) { return 'post_' . $type; };
$filter_handlers['wpml_element_language_code'] = static function ( $value, $args ) { return 'category' === $args['element_type'] ? ( $GLOBALS['term_languages'][$args['element_id']] ?? null ) : ( $GLOBALS['assigned_languages'][$args['element_id']] ?? null ); };
$action_handlers['wpml_set_element_language_details'] = static function ( $args ) {
    $GLOBALS['language_calls']++; $GLOBALS['assigned_languages'][$args['element_id']] = $args['language_code'];
    if ( isset( $GLOBALS['during_language'] ) ) { call_user_func( $GLOBALS['during_language'], $args['element_id'] ); }
};
verify( $wpml->is_available(), 'WPML enabled contract detected' );
$options = $categories->options( array( 'post' => 'نوشته' ), $wpml->active_languages() );
verify( array( 'fa' ) === $options['post|category|3']['languages'] && array( 'en' ) === $options['post|category|4']['languages'] && 'en' === $query_language && ! $language_stack, 'category options are language-specific and query language scope is fully restored' );
verify( array( 'taxonomy' => 'category', 'term_id' => 4 ) === $categories->parse_choice( 'post|category|4', 'post', 'en' ) && is_wp_error( $categories->parse_choice( 'post|category|3', 'post', 'en' ) ), 'matching-language choice accepted while source-language choice requires explicit correction' );
verify( array( 'taxonomy' => 'category', 'term_id' => 4 ) === $categories->validate( array( 'taxonomy' => 'category', 'term_id' => 3 ), 'post', 'en', true ), 'legacy queue choice resolves only its existing translation in content language' );
$missing = category_item( array( 'taxonomy' => 'category', 'term_id' => 7 ), 'en' ); $calls = $insert_calls;
verify( is_wp_error( $drafts->create( $missing ) ) && $calls === $insert_calls && null === $wpdb->queue[$missing]['post_id'], 'missing category translation never falls back, creates a category or creates a draft' );
verify( false === end( $translation_calls )[2], 'WPML object_id always uses return_original_if_missing=false' );
$term_languages[40] = 'fa';
verify( is_wp_error( $categories->parse_choice( 'post|category|4', 'post', 'en' ) ), 'translated term language is independently checked with term_taxonomy_id' ); $term_languages[40] = 'en';
$missing_translation = $categories->compare( $missing );
verify( is_wp_error( $missing_translation ), 'unlinked item with missing translation cannot expose an actionable repair form' );
$term_query_error = true;
verify( is_wp_error( $categories->options( array( 'post' => 'نوشته' ), $wpml->active_languages() ) ) && 'en' === $query_language && ! $language_stack, 'failed term query reports error and restores WPML language scope' ); $term_query_error = false;
category_post( 'post|category|4', 'en' ); $args = category_submit(); $id = count( $wpdb->queue );
$wpdb->queue[$id]['status'] = 'generated'; $wpdb->queue[$id]['generated_output'] = wp_json_encode( $article );
$during_language = static function ( $post_id ) { $GLOBALS['terms_at_language_hook'] = $GLOBALS['assigned_terms'][$post_id]; $GLOBALS['assigned_terms'][$post_id] = array( 5 ); };
$post = $drafts->create( $id );
verify( is_int( $post ) && array( 1 ) === $terms_at_language_hook && array( 4 ) === $assigned_terms[$post] && 'en' === $assigned_languages[$post] && 'ready' === $wpdb->queue[$id]['draft_state'], 'actual draft path assigns language before category and repairs category changes made by language hook before final verification' ); unset( $during_language );
$legacy = category_item( array( 'taxonomy' => 'category', 'term_id' => 3 ), 'en' ); $legacy_post = $drafts->create( $legacy );
verify( is_int( $legacy_post ) && array( 4 ) === $assigned_terms[$legacy_post] && 3 === json_decode( $wpdb->queue[$legacy]['payload'], true )['category']['term_id'], 'legacy translated selection applies correct target while preserving original queue selection' );

// Successful setter results do not prove final terms; retries preserve later manual changes.
$failed = category_item( array( 'taxonomy' => 'category', 'term_id' => 4 ), 'en' );
$during_terms = static function ( $post_id ) { $GLOBALS['assigned_terms'][$post_id] = array( 5 ); };
$result = $drafts->create( $failed ); $failed_post = $wpdb->queue[$failed]['post_id']; $calls = $insert_calls;
verify( is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'تکمیل پیش‌نویس ناقص' ) && 'linked' === $wpdb->queue[$failed]['draft_state'] && array( 5 ) === $assigned_terms[$failed_post], 'setter success followed by hook mismatch is detected; same linked draft survives as incomplete' ); unset( $during_terms );
verify( is_wp_error( $drafts->create( $failed ) ) && $calls === $insert_calls && array( 5 ) === $assigned_terms[$failed_post], 'retry cannot overwrite an unexplained manual category change or insert a second post' );
$comparison = $categories->compare( $failed );
verify( false !== strpos( $comparison['selected'], '#4' ) && false !== strpos( implode( ' ', $comparison['current'] ), '#5' ) && $comparison['signature'], 'comparison displays exact queue selection, actual manual category and a bound signature' );
verify( is_wp_error( $categories->apply( $failed, $comparison['signature'], false ) ) && array( 5 ) === $assigned_terms[$failed_post], 'repair never overwrites manual category without explicit confirmation' );
verify( true === $categories->apply( $failed, $comparison['signature'], true ) && array( 4 ) === $assigned_terms[$failed_post], 'explicit manual repair applies and verifies category on the same linked draft' );
verify( $failed_post === $drafts->create( $failed ) && $calls === $insert_calls && 'ready' === $wpdb->queue[$failed]['draft_state'], 'completion after explicit repair reuses the same post and reaches ready' );
$failed_write = category_item( array( 'taxonomy' => 'category', 'term_id' => 4 ), 'en' ); $term_write_error = true;
verify( is_wp_error( $drafts->create( $failed_write ) ), 'category setter failure reports incomplete completion' ); $term_write_error = false;
$same_post = $wpdb->queue[$failed_write]['post_id']; $calls = $insert_calls;
verify( $same_post === $drafts->create( $failed_write ) && $calls === $insert_calls, 'transient setter failure with unchanged baseline retries on same draft without duplication' );
$snapshot_fail = category_item( array( 'taxonomy' => 'category', 'term_id' => 4 ), 'en' ); $wpdb->fail_category_snapshot = true; $language_before = $language_calls; $writes = $term_write_calls;
verify( is_wp_error( $drafts->create( $snapshot_fail ) ) && $language_before === $language_calls && $writes === $term_write_calls && 'linked' === $wpdb->queue[$snapshot_fail]['draft_state'], 'baseline storage failure stops language/category mutations and preserves link' ); $wpdb->fail_category_snapshot = false;

// Existing ready drafts are inspected, with no automatic rewriting or SEO/language side effects.
$id = $legacy; $post = $legacy_post; $assigned_terms[$post] = array( 5 );
$old_payload = json_decode( $wpdb->queue[$id]['payload'], true ); unset( $old_payload['category_attempt'] ); $wpdb->queue[$id]['payload'] = wp_json_encode( $old_payload );
$saved_row = $wpdb->queue[$id]; $saved_post = clone get_post( $post ); $saved_languages = $assigned_languages; $seo_calls = $seo_spy->calls;
$budget = ( new AIContentManager\Services\BudgetGuard( $repo ) )->summary(); $calls = $insert_calls; $writes = $term_write_calls; $language_before = $language_calls;
verify( is_wp_error( $drafts->create( $id ) ) && array( 5 ) === $assigned_terms[$post] && $writes === $term_write_calls && $language_before === $language_calls && $seo_calls === $seo_spy->calls, 'ready draft mismatch is detected instead of early success; manual category, language and SEO are preserved' );
$comparison = $categories->compare( $id );
verify( $comparison['signature'] && ! isset( json_decode( $wpdb->queue[$id]['payload'], true )['category_attempt'] ), 'old ready draft without new baseline can be reviewed and repaired only by explicit signed command' );
$caps['assign_terms'] = false;
verify( is_wp_error( $categories->apply( $id, $comparison['signature'], true ) ), 'manual repair checks taxonomy assign_terms capability' ); $caps['assign_terms'] = true;
$caps['edit_post'] = false;
verify( is_wp_error( $categories->apply( $id, $comparison['signature'], true ) ), 'manual repair checks edit_post for related draft' ); $caps['edit_post'] = true;
$caps['manage_ai_content'] = false;
verify( is_wp_error( $categories->apply( $id, $comparison['signature'], true ) ), 'manual repair checks manage_ai_content' ); $caps['manage_ai_content'] = true;
$assigned_terms[$post] = array( 3 );
verify( is_wp_error( $categories->apply( $id, $comparison['signature'], true ) ) && array( 3 ) === $assigned_terms[$post], 'stale comparison detects later manual edit across language scopes without replacement' );
$comparison = $categories->compare( $id );
verify( is_wp_error( $categories->apply( $id, 'forged', true ) ), 'forged comparison cannot authorize repair' );
$before = $wpdb->queue[$id]['payload']; $modified = json_decode( $before, true ); $modified['category']['term_id'] = 5; $wpdb->queue[$id]['payload'] = wp_json_encode( $modified );
verify( is_wp_error( $categories->apply( $id, $comparison['signature'], true ) ), 'changed stored selection invalidates repair signature' ); $wpdb->queue[$id]['payload'] = $before;
$term_read_error = true;
verify( is_wp_error( $categories->compare( $id ) ) && is_wp_error( $categories->apply( $id, $comparison['signature'], true ) ), 'term read failure prevents success or unverified mutation' ); $term_read_error = false;
$term = $fixture_terms[4]; unset( $fixture_terms[4] );
verify( is_wp_error( $drafts->create( $id ) ) && $wpdb->queue[$id]['post_id'] === $post && $calls === $insert_calls, 'deletion of selected translation on an old linked draft preserves relationship and refuses replacement' ); $fixture_terms[4] = $term;
$assigned_languages[$post] = 'fa';
verify( is_wp_error( $categories->apply( $id, $comparison['signature'], true ) ) && 'fa' === $assigned_languages[$post], 'manual category repair refuses language mismatch without changing WPML language' ); $assigned_languages[$post] = 'en';

// Actual controller contract: nonce, POST, explicit confirmation, ignored client post_id and raw redirect.
category_command( $id, $comparison['signature'] ); $_POST['_wpnonce'] = 'bad';
category_denied( array( $controller, 'handle' ), 'manual category controller rejects invalid nonce' );
verify( false !== strpos( $last_die[0], 'بازگشت به مدیریت صف' ) && 403 === $last_die[2]['response'], 'category nonce denial includes Persian recovery link and HTTP status' );
category_command( $id, $comparison['signature'] ); $_SERVER['REQUEST_METHOD'] = 'GET';
category_denied( array( $controller, 'handle' ), 'manual category GET cannot mutate' ); $_SERVER['REQUEST_METHOD'] = 'POST';
$caps['manage_ai_content'] = false; category_denied( array( $controller, 'handle' ), 'category controller rejects missing management capability' ); $caps['manage_ai_content'] = true;
category_command( $id, $comparison['signature'], '0' );
try { $controller->handle(); throw new RuntimeException( 'Missing redirect' ); } catch ( RedirectResult $redirect ) { verify( get_transient( 'acm_queue_notice_7' )['error'] && array( 3 ) === $assigned_terms[$post], 'actual POST without confirmation returns error and preserves manual selection' ); }
category_command( $id, $comparison['signature'] ); $_POST['post_id'] = 999999;
try { $controller->handle(); throw new RuntimeException( 'Missing redirect' ); } catch ( RedirectResult $redirect ) {
    parse_str( parse_url( $redirect->getMessage(), PHP_URL_QUERY ), $args );
    verify( ! get_transient( 'acm_queue_notice_7' )['error'] && array( 4 ) === $assigned_terms[$post] && ! isset( $assigned_terms[999999] ) && (string) $id === $args['category_item'] && 'queue' === $args['tab'] && 303 === $redirect_status && false === strpos( $redirect->getMessage(), '&amp;' ), 'actual signed repair POST uses repository relationship and safe raw 303 redirect' );
}
$comparison = $categories->compare( $id ); $events = count( $wpdb->events ); $writes = $term_write_calls;
verify( true === $categories->apply( $id, $comparison['signature'], true ) && $writes === $term_write_calls && $events === count( $wpdb->events ), 'equal category repair performs no redundant term write or audit event' );
$_GET = array( 'category_item' => $id, '_wpnonce' => wp_create_nonce( 'acm_category_compare_' . $id ) ); $_POST = array();
$html = capture( array( $controller, 'render' ) );
verify( false !== strpos( $html, 'دسته‌بندی انتخاب‌شده در صف' ) && false !== strpos( $html, 'دسته‌بندی فعلی نوشته' ) && false !== strpos( $html, 'name="confirm_replace"' ) && false !== strpos( $html, 'value="1" required' ) && 1 === substr_count( $html, '<form ' ), 'manual comparison shows stored source and translated target with explicit replacement form' );
verify( $writes === $term_write_calls && $events === count( $wpdb->events ), 'manual category comparison is read-only' );
$_GET = array( 'preview_item' => $id, '_wpnonce' => wp_create_nonce( 'acm_preview_' . $id ) );
$html = capture( array( $page, 'render' ) );
verify( false !== strpos( $html, 'دسته‌بندی انتخاب‌شده در صف' ) && false !== strpos( $html, 'category #3' ) && false !== strpos( $html, 'بررسی و اصلاح دسته‌بندی' ), 'queue details retain original selected category and expose independent repair action' ); $_GET = array();
foreach ( array( 'publish', 'future', 'private', 'trash' ) as $status ) {
    get_post( $post )->post_status = $status;
    verify( is_wp_error( $categories->apply( $id, $comparison['signature'], true ) ) && $writes === $term_write_calls, 'category repair refuses non-draft status: ' . $status );
}
get_post( $post )->post_status = 'draft'; $marker = get_post( $post )->post_content_filtered; get_post( $post )->post_content_filtered = 'unrelated';
verify( is_wp_error( $categories->apply( $id, $comparison['signature'], true ) ), 'unrelated post marker cannot be repaired' ); get_post( $post )->post_content_filtered = $marker;
$wpdb->queue[$id]['status'] = 'trashed'; verify( is_wp_error( $categories->apply( $id, $comparison['signature'], true ) ), 'queue trash blocks category repair' ); $wpdb->queue[$id]['status'] = 'generated';
$comparison = $categories->compare( $id ); $assigned_terms[$post] = array( 5 ); $comparison = $categories->compare( $id ); $wpdb->fail_event = true;
verify( is_wp_error( $categories->apply( $id, $comparison['signature'], true ) ) && array( 4 ) === $assigned_terms[$post], 'audit failure reports review after verified category without undoing post or claiming success' ); $wpdb->fail_event = false;
verify( $saved_row === $wpdb->queue[$id] && $saved_post == get_post( $post ) && $saved_languages === $assigned_languages && $calls === $insert_calls && $seo_calls === $seo_spy->calls && $budget === ( new AIContentManager\Services\BudgetGuard( $repo ) )->summary(), 'manual repair and ready rechecks preserve content, original payload, draft state, WPML, SEO, usage, budget and post count' );
verify( 0 === $network_calls && 'en' === $query_language && ! $language_stack, 'entire category suite makes zero HTTP/OpenAI requests and leaves WPML query scope unchanged' );
ob_end_flush();
