<?php
/** Real plugin services, mock WordPress/SQL. No network or main-site database. */
define( 'ACM_WORKFLOW_BOOTSTRAP_ONLY', true );
require __DIR__ . '/security-budget.php';

$fixture_posts = array(); $fixture_users = array( 7 => (object) array( 'ID' => 7, 'display_name' => 'Actor' ), 9 => (object) array( 'ID' => 9, 'display_name' => 'Creator' ) );
$caps += array( 'edit_pages' => true, 'edit_others_posts' => true, 'edit_others_pages' => true, 'edit_post' => true, 'assign_terms' => true );
$insert_calls = 0; $assigned_languages = array();
function get_post_type_object( $type ) { return in_array( $type, array( 'post', 'page' ), true ) ? (object) array( 'cap' => (object) array( 'create_posts' => 'page' === $type ? 'edit_pages' : 'edit_posts', 'edit_others_posts' => 'page' === $type ? 'edit_others_pages' : 'edit_others_posts' ) ) : null; }
function user_can( $user, $cap ) { return isset( $user->ID ) && empty( $GLOBALS['denied_creators'][$user->ID] ) && ( ! isset( $GLOBALS['fixture_user_caps'] ) || ! empty( $GLOBALS['fixture_user_caps'][$user->ID][$cap] ) ); }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function get_post( $id ) { return $GLOBALS['fixture_posts'][$id] ?? null; }
function get_edit_post_link( $id, $context = '' ) { return '/wp-admin/post.php?post=' . $id . '&action=edit'; }
function get_taxonomy( $taxonomy ) { return $GLOBALS['fixture_taxonomy_objects'][$taxonomy] ?? false; }
function get_term( $id, $taxonomy ) { $term = $GLOBALS['fixture_terms'][(int) $id] ?? false; return $term && $term->taxonomy === $taxonomy ? $term : false; }
function get_terms( $args ) {
    if ( ! empty( $GLOBALS['term_query_error'] ) ) { return new WP_Error( 'fixture', 'خطای خواندن آزمایشی' ); }
    return array_values( array_filter( $GLOBALS['fixture_terms'], static function ( $term ) use ( $args ) {
        return $term->taxonomy === $args['taxonomy'] && ( empty( $GLOBALS['query_language'] ) || 'all' === $GLOBALS['query_language'] || ( $GLOBALS['term_languages'][$term->term_taxonomy_id] ?? null ) === $GLOBALS['query_language'] );
    } ) );
}
function wp_get_object_terms( $id, $taxonomy, $args ) {
    if ( ! empty( $GLOBALS['term_read_error'] ) ) { return new WP_Error( 'fixture', 'خطای خواندن آزمایشی' ); }
    return $GLOBALS['assigned_terms'][$id] ?? array();
}
function wp_set_object_terms( $id, $terms, $taxonomy, $append ) {
    $GLOBALS['term_write_calls'] = ( $GLOBALS['term_write_calls'] ?? 0 ) + 1;
    if ( ! empty( $GLOBALS['term_write_error'] ) ) { return new WP_Error( 'fixture', 'خطای نوشتن آزمایشی' ); }
    $GLOBALS['assigned_terms'][$id] = $terms;
    if ( isset( $GLOBALS['during_terms'] ) ) { call_user_func( $GLOBALS['during_terms'], $id, $terms ); }
    return array_map( static function ( $term ) { return $GLOBALS['fixture_terms'][$term]->term_taxonomy_id; }, $terms );
}
function wp_cache_delete( $id, $group ) { $GLOBALS['cache_deletes'][] = array( $id, $group ); return true; }
$fixture_taxonomy_objects = array( 'category' => (object) array( 'name' => 'category', 'hierarchical' => true, 'show_ui' => true, 'labels' => (object) array( 'name' => 'دسته‌بندی' ), 'object_type' => array( 'post' ), 'cap' => (object) array( 'assign_terms' => 'assign_terms' ) ) );
$fixture_taxonomies = array( 'post' => $fixture_taxonomy_objects );
$fixture_terms = array( 3 => (object) array( 'term_id' => 3, 'term_taxonomy_id' => 30, 'taxonomy' => 'category', 'name' => 'دسته آزمایشی' ) );
function wp_insert_post( $args, $wp_error ) {
    $GLOBALS['insert_calls']++;
    $original = $args;
    $args = apply_filters( 'wp_insert_post_data', $args, $original );
    $args = array_map( static function ( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }, $args );
    if ( isset( $GLOBALS['during_insert'] ) ) { call_user_func( $GLOBALS['during_insert'] ); }
    $id = $GLOBALS['insert_calls'] + 100;
    $args['ID'] = $id;
    $GLOBALS['fixture_posts'][$id] = (object) $args;
    return $id;
}

class WorkflowDatabase extends FixtureDatabase {
    public $term_relationships = 'wp_term_relationships';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $posts = 'wp_posts';
    public $fail_link_once = false;
    public $fail_queue_after = null;
    public $missing_column = false;
    public $missing_index = false;
    private function filtered( $sql ) {
        return array_values( array_filter( $this->queue, static function ( $row ) use ( $sql ) {
            if ( false !== strpos( $sql, "status <> 'trashed'" ) && 'trashed' === $row['status'] ) { return false; }
            foreach ( array( 'status', 'language', 'content_type' ) as $key ) { if ( preg_match( "/\\b{$key} = '([^']+)'/", $sql, $match ) && $row[$key] !== $match[1] ) { return false; } }
            return true;
        } ) );
    }
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'SELECT COUNT(*)' ) && false === strpos( $sql, 'updated_at <' ) ) { return count( $this->filtered( $sql ) ); }
        return parent::get_var( $sql );
    }
    public function insert( $table, $row, ...$formats ) {
        if ( 'wp_acm_queue' === $table ) {
            if ( null !== $this->fail_queue_after && count( $this->queue ) >= $this->fail_queue_after ) { return false; }
            $row += array( 'post_id' => null, 'draft_state' => 'none', 'draft_token' => null, 'previous_status' => null, 'trashed_at' => null, 'trashed_by' => null, 'generated_output' => null, 'api_metadata' => null );
        }
        return parent::insert( $table, $row, ...$formats );
    }
    public function update( $table, $data, $where, ...$formats ) {
        if ( $this->fail_link_once && isset( $data['post_id'] ) ) { $this->fail_link_once = false; return false; }
        return parent::update( $table, $data, $where, ...$formats );
    }
    public function get_results( $sql, $format ) {
        if ( false !== strpos( $sql, 'ORDER BY created_at DESC' ) ) { return $this->filtered( $sql ); }
        if ( false !== strpos( $sql, 'WHERE batch_id =' ) ) {
            preg_match( "/batch_id = '([^']+)'/", $sql, $match );
            return array_values( array_filter( $this->queue, static function ( $row ) use ( $match ) { return $row['batch_id'] === $match[1]; } ) );
        }
        if ( false !== strpos( $sql, 'SHOW INDEX' ) ) {
            preg_match( "/Key_name = '([^']+)'/", $sql, $match );
            $key = $match[1];
            if ( $this->missing_index && 'draft_token' === $key ) { return array(); }
            $indexes = array( 'PRIMARY' => array( false !== strpos( $sql, 'queue_events' ) ? 'event_id' : 'item_id' ), 'draft_token' => array( 'draft_token' ), 'status_created' => array( 'status', 'created_at' ), 'status_updated' => array( 'status', 'updated_at' ), 'language_type' => array( 'language', 'content_type' ), 'batch_id' => array( 'batch_id' ), 'scheduled_at' => array( 'scheduled_at' ), 'item_created' => array( 'item_id', 'created_at' ), 'created_at' => array( 'created_at' ) );
            $indexes += array( 'campaign_slot' => array( 'campaign_id', 'campaign_slot' ), 'topic_signature' => array( 'topic_signature' ), 'submission_id' => array( 'submission_id' ), 'due' => array( 'status', 'next_run_at' ), 'attempt_id' => array( 'attempt_id' ), 'campaign_state' => array( 'campaign_id', 'state' ), 'lease' => array( 'state', 'lease_until' ) );
            if ( false !== strpos( $sql, 'acm_campaign_steps' ) ) { $indexes['PRIMARY'] = array( 'step_id' ); }
            elseif ( false !== strpos( $sql, 'acm_campaigns' ) ) { $indexes['PRIMARY'] = array( 'campaign_id' ); }
            $rows = array(); foreach ( $indexes[$key] as $index => $column ) { $rows[] = array( 'Seq_in_index' => $index + 1, 'Column_name' => $column, 'Non_unique' => in_array( $key, array( 'PRIMARY', 'draft_token', 'campaign_slot', 'topic_signature', 'submission_id', 'attempt_id' ), true ) ? 0 : 1 ); } return $rows;
        }
        return parent::get_results( $sql, $format );
    }
    public function get_col( $sql ) {
        if ( false !== strpos( $sql, 'SELECT tt.term_id' ) ) {
            preg_match( '/tr.object_id = (\d+)/', $sql, $match );
            if ( ! empty( $GLOBALS['term_read_error'] ) ) { $this->last_error = 'fixture read failure'; return null; }
            $this->last_error = ''; return $GLOBALS['assigned_terms'][(int) $match[1]] ?? array();
        }
        if ( false !== strpos( $sql, 'post_content_filtered =' ) ) {
            preg_match( "/post_content_filtered = '([^']+)'/", $sql, $match );
            return array_keys( array_filter( $GLOBALS['fixture_posts'], static function ( $post ) use ( $match ) { return $post->post_content_filtered === $match[1]; } ) );
        }
        if ( false !== strpos( $sql, 'SHOW COLUMNS' ) ) {
            $columns = false !== strpos( $sql, 'queue_events' ) ? array( 'event_id', 'item_id', 'status_from', 'status_to', 'message', 'api_metadata', 'created_by', 'created_at' ) : array( 'item_id', 'batch_id', 'content_type', 'language', 'status', 'payload', 'generated_output', 'api_metadata', 'processing_token', 'draft_token', 'draft_state', 'trashed_at', 'trashed_by', 'previous_status', 'created_by', 'created_at', 'updated_at', 'scheduled_at', 'post_id' );
            if ( false !== strpos( $sql, 'acm_campaign_steps' ) ) { $columns = array( 'step_id', 'campaign_id', 'item_id', 'kind', 'source_index', 'attempt_id', 'state', 'lease_until', 'output', 'api_metadata', 'error_message', 'created_at', 'updated_at' ); }
            elseif ( false !== strpos( $sql, 'acm_campaigns' ) ) { $columns = array( 'campaign_id', 'submission_id', 'owner_id', 'keyword', 'language', 'config', 'status', 'phase', 'target_count', 'accepted_count', 'completed_count', 'next_run_at', 'last_started_day', 'last_error', 'created_at', 'updated_at' ); }
            elseif ( false === strpos( $sql, 'queue_events' ) ) { $columns = array_merge( $columns, array( 'campaign_id', 'campaign_slot', 'campaign_stage', 'campaign_started_at', 'research_output', 'topic_signature' ) ); }
            return $this->missing_column ? array_diff( $columns, array( 'draft_state' ) ) : $columns;
        }
        throw new RuntimeException( 'Unexpected get_col: ' . $sql );
    }
}

if ( defined( 'ACM_SEO_BOOTSTRAP_ONLY' ) || defined( 'ACM_CATEGORY_BOOTSTRAP_ONLY' ) ) { return; }

$wpdb = new WorkflowDatabase();
$repo = new AIContentManager\Services\QueueRepository();
$queue = new AIContentManager\Services\ContentQueue( $repo, new AIContentManager\Services\ContentTypeRegistry() );
$shared = array( 'content_type' => 'post', 'language' => 'en', 'primary_keyword' => 'یک عبارت چند کلمه‌ای', 'secondary_keywords' => "اول، دوم, اول\nسوم\n", 'tone' => 'formal', 'word_count' => 1000 );
$submission = wp_generate_uuid4();
$one = $queue->enqueue_topics( $shared, array( 'First' ), $submission );
verify( 1 === count( $one['item_ids'] ) && 1 === count( $wpdb->queue ), 'single topic produces exactly one item' );
$again = $queue->enqueue_topics( $shared, array( 'First' ), $submission );
verify( $one === $again && 1 === count( $wpdb->queue ), 'repeated successful form submission reuses existing items' );
verify( is_wp_error( $queue->enqueue_topics( $shared, array( 'Changed' ), $submission ) ), 'reuse of submission id with changed input rejected' );
$five = $queue->enqueue_topics( $shared, array( 'A', '', 'B', 'C', 'D', 'E', ' ' ), wp_generate_uuid4() );
verify( 5 === count( $five['item_ids'] ) && 6 === count( $wpdb->queue ), 'five independent topics create exactly five items ignoring blank lines' );
$stored = json_decode( $wpdb->queue[2]['payload'], true );
verify( array( 'اول', 'دوم', 'سوم' ) === $stored['secondary_keywords'] && 'formal' === $stored['tone'] && 'A' === $stored['topic'], 'Persian comma, deduplicated secondary keywords and shared settings' );
$before = count( $wpdb->queue );
verify( is_wp_error( $queue->enqueue_topics( $shared, array( 'یادگیری کار', 'يادگيري  كار' ) ) ) && $before === count( $wpdb->queue ), 'equivalent Persian and Arabic letters and extra spaces reject entire submission' );
verify( is_wp_error( $queue->enqueue( array_merge( $shared, array( 'topic' => 'Same' ) ), 5, 7 ) ) && $before === count( $wpdb->queue ), 'legacy count five does not silently repeat topic' );
$options['acm_settings'] = array( 'max_generation_items' => 2 );
verify( is_wp_error( $queue->enqueue_topics( $shared, array( 'A', 'B', 'C' ) ) ), 'GenerationPolicy limit applies to independent topics' );
$options['acm_settings'] = array( 'max_generation_items' => 0 );
verify( is_wp_error( $queue->enqueue_topics( $shared, array_map( 'strval', range( 1, 101 ) ) ) ), 'technical 100 item limit remains when policy disabled' );
foreach ( array( 'دو، عبارت', 'two, phrases', "two\nphrases" ) as $bad ) { verify( is_wp_error( $queue->enqueue_topics( array_merge( $shared, array( 'primary_keyword' => $bad ) ), array( 'Valid' ) ) ), 'multiple primary phrases rejected' ); }
$options['acm_settings'] = array();
$wpdb->fail_queue_after = $before + 1;
verify( is_wp_error( $queue->enqueue_topics( $shared, array( 'Atomic A', 'Atomic B' ), wp_generate_uuid4() ) ) && $before === count( $wpdb->queue ), 'database insert failure rolls back entire topic submission' );
$wpdb->fail_queue_after = null;

// Draft validation and durable linking.
$article = array( 'title' => 'Article', 'slug' => 'article', 'excerpt' => 'Summary', 'content' => '<h2>Section</h2><p>Article with \\ and "quotes".</p>', 'primary_keyword' => 'key', 'secondary_keywords' => array(), 'suggested_meta_description' => 'Meta', 'headings' => array( array( 'level' => 2, 'text' => 'Section' ) ), 'faq' => array( array( 'question' => 'FAQ?', 'answer' => 'Answer' ) ), 'image_prompt' => 'Image' );
$wpdb->queue[1]['status'] = 'generated'; $wpdb->queue[1]['generated_output'] = json_encode( $article ); $wpdb->queue[1]['created_by'] = 9;
$drafts = new AIContentManager\Services\DraftCreator( $repo );
$post_id = $drafts->create( 1 );
verify( is_int( $post_id ) && 'draft' === get_post( $post_id )->post_status && 'post' === get_post( $post_id )->post_type && 'generated' === $wpdb->queue[1]['status'], 'valid generated result creates only draft and preserves generation state' );
verify( get_post( $post_id )->post_title === $article['title'] && get_post( $post_id )->post_content === $article['content'] && get_post( $post_id )->post_excerpt === $article['excerpt'] && get_post( $post_id )->post_name === $article['slug'], 'title content excerpt slug mapping preserves slashes without appending FAQ' );
verify( 9 === get_post( $post_id )->post_author && $wpdb->queue[1]['post_id'] === $post_id && 'ready' === $wpdb->queue[1]['draft_state'], 'valid creator and persistent post_id link' );
verify( $post_id === $drafts->create( 1 ) && 1 === $insert_calls, 'double click or replay never inserts second post' );
verify( ! $assigned_languages, 'WPML disabled creates draft without language action or translation' );
get_post( $post_id )->post_status = 'trash'; verify( is_wp_error( $drafts->create( 1 ) ) && 1 === $insert_calls, 'existing WordPress trash prevents replacement' );
unset( $fixture_posts[$post_id] ); verify( is_wp_error( $drafts->create( 1 ) ) && 1 === $insert_calls, 'deleted linked post prevents replacement' );
foreach ( array( 'pending', 'failed', 'trashed' ) as $status ) { $wpdb->queue[2]['status'] = $status; verify( is_wp_error( $drafts->create( 2 ) ), 'draft denied for ' . $status ); }
$wpdb->queue[2]['status'] = 'generated'; $wpdb->queue[2]['generated_output'] = '{}';
verify( is_wp_error( $drafts->create( 2 ) ), 'invalid generated JSON cannot create draft' );
$wpdb->queue[2]['generated_output'] = json_encode( $article ); $wpdb->queue[2]['content_type'] = 'product';
verify( is_wp_error( $drafts->create( 2 ) ), 'product draft rejected clearly' );
$wpdb->queue[2]['content_type'] = 'page'; $caps['edit_pages'] = false;
verify( is_wp_error( $drafts->create( 2 ) ), 'post type create capability required' );
$caps['edit_pages'] = true; $wpdb->queue[2]['created_by'] = 999;
$page_id = $drafts->create( 2 );
verify( is_int( $page_id ) && 'page' === get_post( $page_id )->post_type && 7 === get_post( $page_id )->post_author, 'page draft and safe fallback author for deleted creator' );
get_post( $page_id )->post_status = 'publish'; $calls_before = $insert_calls;
verify( is_wp_error( $drafts->create( 2 ) ) && 'publish' === get_post( $page_id )->post_status && $calls_before === $insert_calls, 'manually published linked post never changed or replaced' );
get_post( $page_id )->post_status = 'draft';

$wpdb->queue[3]['status'] = 'generated'; $wpdb->queue[3]['generated_output'] = json_encode( $article ); $wpdb->fail_link_once = true;
$count_before = $insert_calls;
verify( is_wp_error( $drafts->create( 3 ) ) && $count_before + 1 === $insert_calls && empty( $wpdb->queue[3]['post_id'] ), 'post survives failed queue post_id write with durable marker' );
$recovered_id = $drafts->create( 3 );
verify( is_int( $recovered_id ) && $count_before + 1 === $insert_calls && $wpdb->queue[3]['post_id'] === $recovered_id, 'retry recovers initial post instead of inserting duplicate' );
$wpdb->queue[4]['status'] = 'generated'; $wpdb->queue[4]['generated_output'] = json_encode( $article );
$during_insert = function () use ( $wpdb, $drafts ) { $wpdb->lock_available = false; $competing = $drafts->create( 4 ); $wpdb->lock_available = true; verify( is_wp_error( $competing ), 'simulated concurrent request cannot obtain draft claim' ); };
$count_before = $insert_calls; $concurrent_id = $drafts->create( 4 ); unset( $during_insert );
verify( is_int( $concurrent_id ) && $count_before + 1 === $insert_calls, 'simulated concurrency inserts only once' );
$wpdb->queue[5]['status'] = 'generated'; $wpdb->queue[5]['generated_output'] = json_encode( $article );
$payload = json_decode( $wpdb->queue[5]['payload'], true ); $payload['category'] = array( 'taxonomy' => 'category', 'term_id' => 999 ); $wpdb->queue[5]['payload'] = json_encode( $payload );
verify( is_wp_error( $drafts->create( 5 ) ), 'invalid taxonomy term rejected before insertion' );
$payload['category']['term_id'] = 3; $wpdb->queue[5]['payload'] = json_encode( $payload ); $category_id = $drafts->create( 5 );
verify( is_int( $category_id ) && array( 3 ) === $assigned_terms[$category_id], 'validated taxonomy relationship applied' );

// Soft trash retains post_id, content and budget including unknown reservations.
$trash = new AIContentManager\Services\QueueTrash( $repo );
$metadata = array( 'attempted' => true, 'requested_at' => gmdate( 'c' ), 'reserved_tokens' => 777, 'total_tokens' => null );
$wpdb->queue[3]['api_metadata'] = json_encode( $metadata ); $original = $wpdb->queue[3]; $linked_status = get_post( $recovered_id )->post_status;
$guard = new AIContentManager\Services\BudgetGuard( $repo ); $usage_before = $guard->summary();
$result = $trash->apply( array( 3 ) );
verify( 1 === $result['success'] && 'trashed' === $wpdb->queue[3]['status'] && 'generated' === $wpdb->queue[3]['previous_status'] && 7 === $wpdb->queue[3]['trashed_by'] && ! empty( $wpdb->queue[3]['trashed_at'] ), 'single soft trash records actor time and previous status' );
verify( $wpdb->queue[3]['generated_output'] === $original['generated_output'] && $wpdb->queue[3]['api_metadata'] === $original['api_metadata'] && $wpdb->queue[3]['post_id'] === $recovered_id && get_post( $recovered_id )->post_status === $linked_status && $guard->summary() === $usage_before, 'trash preserves output usage reservation post link and WordPress post' );
verify( is_wp_error( $drafts->create( 3 ) ), 'trashed generated item cannot create draft' );
$result = $trash->apply( array( 3 ), true ); verify( 1 === $result['success'] && 'generated' === $wpdb->queue[3]['status'] && $guard->summary() === $usage_before, 'single restore recovers original valid status without freeing quota' );
$wpdb->queue[6]['status'] = 'generating';
$result = $trash->apply( array( 1, 2, 6, 999, 'invalid' ) );
verify( 2 === $result['success'] && 3 === $result['rejected'] && 0 === $result['failed'] && 'generating' === $wpdb->queue[6]['status'], 'bulk trash counts each success and rejection independently' );
$result = $trash->apply( array( 1, 2 ), true ); verify( 2 === $result['success'], 'bulk restore' );
$wpdb->queue[6]['status'] = 'pending'; $repo->transition_generation( 6, 'pending', 'generating', 7 );
verify( 1 === $trash->apply( array( 6 ) )['rejected'], 'Generate wins interleaving then Trash rejects' );
$wpdb->queue[6]['status'] = 'pending'; $trash->apply( array( 6 ) );
verify( is_wp_error( $repo->transition_generation( 6, 'pending', 'generating', 7 ) ), 'Trash wins interleaving then Generate claim rejects' );
$wpdb->fail_event = true; $result = $trash->apply( array( 1 ) ); $wpdb->fail_event = false;
verify( 1 === $result['failed'] && 'generated' === $wpdb->queue[1]['status'], 'trash rollback when event cannot persist' );

// WPML disabled path above succeeded; enable mock hooks and verify/retry on the same draft.
if ( ! class_exists( 'SitePress' ) ) { class SitePress {} }
$filter_handlers['wpml_active_languages'] = static function () { return array( 'en' => array( 'language_code' => 'en', 'native_name' => 'English' ) ); };
$filter_handlers['wpml_element_type'] = static function ( $type ) { return 'post_' . $type; };
$filter_handlers['wpml_element_language_code'] = static function ( $value, $args ) { return $GLOBALS['assigned_languages'][$args['element_id']] ?? null; };
$action_handlers['wpml_set_element_language_details'] = static function ( $args ) { if ( empty( $GLOBALS['fail_language'] ) ) { $GLOBALS['assigned_languages'][$args['element_id']] = $args['language_code']; } };
$repo->insert_batch( 'language-batch', array( array( 'content_type' => 'post', 'language' => 'en', 'payload' => array( 'topic' => 'Language' ) ) ), 7 );
$language_item = count( $wpdb->queue ); $wpdb->queue[$language_item]['status'] = 'generated'; $wpdb->queue[$language_item]['generated_output'] = json_encode( $article );
$fail_language = true; $count_before = $insert_calls;
verify( is_wp_error( $drafts->create( $language_item ) ) && ! empty( $wpdb->queue[$language_item]['post_id'] ) && 'linked' === $wpdb->queue[$language_item]['draft_state'], 'failed WPML verification never claims full success and retains link' );
$fail_language = false; $language_id = $drafts->create( $language_item );
verify( is_int( $language_id ) && 'en' === $assigned_languages[$language_id] && $count_before + 1 === $insert_calls, 'WPML retry assigns language on same draft without translation or duplicate' );

// Required schema readiness checks reject partial upgrades.
verify( AIContentManager\Infrastructure\Installer::schema_is_ready(), 'all required columns and index definitions verified' );
$wpdb->missing_column = true; verify( ! AIContentManager\Infrastructure\Installer::schema_is_ready(), 'missing column prevents migration success' ); $wpdb->missing_column = false;
$wpdb->missing_index = true; verify( ! AIContentManager\Infrastructure\Installer::schema_is_ready(), 'missing unique index prevents migration success' ); $wpdb->missing_index = false;
verify( 0 === $network_calls, 'all draft trash restore preview data paths used zero external API calls' );

// Actual POST controllers: action nonce, capability, safe redirects and preserved form inputs.
$queue_page = new AIContentManager\Admin\QueueManagerPage( $repo, new AIContentManager\Services\ContentTypeRegistry(), new AIContentManager\Integrations\Wpml() );
$commands = new AIContentManager\Admin\QueueActions( $drafts, $trash, $queue_page );
$_GET = $_REQUEST = array(); $_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'queue_action' => 'draft', 'item_id' => $language_item, '_wpnonce' => 'invalid' );
try { $commands->handle(); throw new RuntimeException( 'missing nonce check' ); } catch ( AccessDenied $error ) { verify( true, 'draft action invalid nonce rejected' ); }
$caps['manage_ai_content'] = false;
try { $commands->handle(); throw new RuntimeException( 'missing capability check' ); } catch ( AccessDenied $error ) { verify( true, 'draft action insufficient capability rejected' ); }
$caps['manage_ai_content'] = true; $_SERVER['REQUEST_METHOD'] = 'GET';
try { $commands->handle(); throw new RuntimeException( 'GET mutation allowed' ); } catch ( AccessDenied $error ) { verify( true, 'queue command GET never mutates' ); }
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'queue_action' => 'trash', 'bulk' => '1', 'item_ids' => array( 1, 2 ), '_wpnonce' => wp_create_nonce( 'acm_queue_bulk' ), 'return_status' => 'generated', 'return_language' => 'en', 'return_content_type' => 'post', 'return_paged' => 2 );
try { $commands->handle(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) { $args = query( $redirect->getMessage() ); verify( 'queue' === $args['tab'] && 'generated' === $args['status'] && 'en' === $args['language'] && 'post' === $args['content_type'] && '2' === $args['paged'], 'bulk result preserves validated filter pagination and tab' ); }
verify( false !== strpos( get_transient( 'acm_queue_notice_7' )['message'], 'موفق: 2' ), 'bulk counts and per-item results available in UI notice' );
$_GET = array( 'preview_item' => '2', '_wpnonce' => wp_create_nonce( 'acm_preview_2' ) );
$html = capture( array( $queue_page, 'render' ) );
verify( false !== strpos( $html, 'فقط خواندنی' ) && false !== strpos( $html, 'Article' ), 'trashed generated preview remains read-only' );
$_GET = array();
$content_page = new AIContentManager\Admin\ContentPage( $queue, new AIContentManager\Services\ContentTypeRegistry(), new AIContentManager\Integrations\Wpml() );
$form_id = wp_generate_uuid4();
$_POST = array( '_wpnonce' => wp_create_nonce( 'acm_enqueue_content' ), 'submission_id' => $form_id, 'submission_nonce' => wp_create_nonce( 'acm_submission_' . $form_id ),
    'content_type' => 'post', 'language' => 'en', 'topic_input_mode' => 'multiple', 'topics' => "Handler A\nHandler B\nHandler C\nHandler D\nHandler E", 'primary_keyword' => 'one phrase', 'secondary_keywords' => 'اول، دوم', 'tone' => 'formal' );
$count_before = count( $wpdb->queue );
try { $content_page->handle_submission(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) { verify( 'queued' === query( $redirect->getMessage() )['acm_notice'] && $count_before + 5 === count( $wpdb->queue ), 'five topics through actual POST handler' ); }
try { $content_page->handle_submission(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) { verify( $count_before + 5 === count( $wpdb->queue ), 'actual form POST replay is idempotent' ); }
$_POST['submission_id'] = wp_generate_uuid4(); $_POST['submission_nonce'] = wp_create_nonce( 'acm_submission_' . $_POST['submission_id'] );
$_POST['topics'] = "يادگيري كار\nیادگیری  کار";
try { $content_page->handle_submission(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) { verify( 'validation' === query( $redirect->getMessage() )['acm_notice'], 'duplicate topics handler gives validation feedback' ); }
$saved = get_transient( 'acm_content_feedback_7' );
verify( $_POST['topics'] === $saved['values']['topics'] && 'formal' === $saved['values']['tone'], 'invalid submission preserves original inputs' );
$html = capture( array( $content_page, 'render' ) );
verify( false !== strpos( $html, esc_html( $_POST['topics'] ) ) && false !== strpos( $html, 'کلمه کلیدی اصلی مشترک' ), 'server renders preserved topics and shared keyword label without JavaScript' );
$_POST['topic_input_mode'] = 'single'; $_POST['topic'] = 'Legacy'; $_POST['batch_preset'] = '5';
try { $content_page->handle_submission(); throw new RuntimeException( 'missing redirect' ); } catch ( RedirectResult $redirect ) { verify( 'validation' === query( $redirect->getMessage() )['acm_notice'] && $count_before + 5 === count( $wpdb->queue ), 'legacy POST count five rejected with preserved input' ); }
verify( 0 === $network_calls, 'all new controllers and previews make no external API calls' );

// An ambiguous crash/deletion before linking must fail closed rather than replace a post.
$repo->insert_batch( 'ambiguous-batch', array( array( 'content_type' => 'post', 'language' => 'en', 'payload' => array( 'topic' => 'Ambiguous' ) ) ), 7 );
$ambiguous_id = count( $wpdb->queue ); $wpdb->queue[$ambiguous_id]['status'] = 'generated';
$wpdb->queue[$ambiguous_id]['generated_output'] = json_encode( $article );
$wpdb->queue[$ambiguous_id]['draft_token'] = wp_generate_uuid4(); $wpdb->queue[$ambiguous_id]['draft_state'] = 'creating';
$calls_before = $insert_calls;
verify( is_wp_error( $drafts->create( $ambiguous_id ) ) && $calls_before === $insert_calls, 'ambiguous interrupted claim or missing marker cannot auto-create replacement' );
