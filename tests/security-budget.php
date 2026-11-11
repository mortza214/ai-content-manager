<?php
/** Actual services/repository with in-memory SQL and HTTP fixtures; no external access. */
define( 'ACM_TEST_BOOTSTRAP_ONLY', true );
require __DIR__ . '/admin.php';
define( 'ARRAY_A', 'ARRAY_A' );
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function sanitize_title( $value ) { return trim( $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function current_time( $format, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function wp_remote_retrieve_header( $response, $header ) { return $response['headers'][ $header ] ?? 'fixture-request'; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
class FixtureRole { public $caps = array(); public function add_cap( $cap ) { $this->caps[$cap] = true; } }
$fixture_roles = array( 'administrator' => new FixtureRole(), 'editor' => new FixtureRole() );
function get_role( $name ) { return $GLOBALS['fixture_roles'][$name] ?? null; }

/** Small SQL contract adapter: deliberately rejects unknown SQL instead of silently passing. */
class FixtureDatabase {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $queue = array();
    public $events = array();
    public $lock_available = true;
    public $fail_event = false;
    private $snapshot;
    public function prepare( $sql, ...$args ) {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; }
        $index = 0;
        return preg_replace_callback( '/%[ds]/', function ( $match ) use ( &$index, $args ) { $value = $args[$index++]; return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes( $value ) . "'"; }, $sql );
    }
    public function esc_like( $value ) { return $value; }
    public function query( $sql ) {
        if ( 'START TRANSACTION' === $sql ) { $this->snapshot = array( $this->queue, $this->events ); }
        elseif ( 'ROLLBACK' === $sql ) { list( $this->queue, $this->events ) = $this->snapshot; }
        elseif ( 'COMMIT' !== $sql ) { throw new RuntimeException( 'Unexpected query: ' . $sql ); }
        return 1;
    }
    public function insert( $table, $row, ...$formats ) {
        if ( 'wp_acm_queue_events' === $table ) {
            if ( $this->fail_event ) { return false; }
            $id = count( $this->events ) + 1; $row['event_id'] = $id; $this->events[$id] = $row;
        } else { $id = count( $this->queue ) + 1; $row['item_id'] = $id; $this->queue[$id] = $row; }
        $this->insert_id = $id;
        return 1;
    }
    public function update( $table, $data, $where, ...$formats ) {
        $rows =& $this->queue;
        if ( 'wp_acm_queue_events' === $table ) { $rows =& $this->events; }
        foreach ( $rows as &$row ) {
            $matches = true;
            foreach ( $where as $key => $value ) { if ( ( $row[$key] ?? null ) != $value ) { $matches = false; } }
            if ( $matches ) { $row = array_merge( $row, $data ); return 1; }
        }
        return 0;
    }
    private function stale( $sql ) {
        preg_match( "/updated_at < '([^']+)'/", $sql, $match );
        return array_values( array_filter( $this->queue, function ( $row ) use ( $match ) { return 'generating' === $row['status'] && $row['updated_at'] < $match[1]; } ) );
    }
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'acm_campaign_steps' ) ) { return 0; }
        if ( preg_match( "/SELECT COUNT\(\*\) FROM wp_acm_queue WHERE status = 'generating'$/", $sql ) ) { return count( array_filter( $this->queue, static function ( $row ) { return 'generating' === $row['status']; } ) ); }
        if ( false !== strpos( $sql, 'GET_LOCK' ) ) { return $this->lock_available ? '1' : '0'; }
        if ( false !== strpos( $sql, 'RELEASE_LOCK' ) ) { return '1'; }
        if ( false !== strpos( $sql, 'COUNT(*)' ) && false !== strpos( $sql, 'updated_at <' ) ) { return count( $this->stale( $sql ) ); }
        if ( false !== strpos( $sql, 'SELECT event_id' ) ) {
            preg_match( '/item_id = (\d+)/', $sql, $id ); preg_match( "/LIKE '%([^']+)%'/", $sql, $attempt );
            foreach ( array_reverse( $this->events, true ) as $key => $event ) { if ( $event['item_id'] == $id[1] && false !== strpos( $event['api_metadata'] ?? '', $attempt[1] ) ) { return $key; } }
            return null;
        }
        throw new RuntimeException( 'Unexpected get_var: ' . $sql );
    }
    public function get_row( $sql, $format ) {
        preg_match( '/item_id = (\d+)/', $sql, $match );
        return $this->queue[(int) $match[1]] ?? null;
    }
    public function get_results( $sql, $format ) {
        if ( false !== strpos( $sql, 'acm_campaign_steps' ) ) { return array(); }
        if ( false !== strpos( $sql, 'updated_at <' ) ) { return array_slice( $this->stale( $sql ), 0, 100 ); }
        if ( false !== strpos( $sql, 'AS record_id' ) ) {
            $is_event = false !== strpos( $sql, 'wp_acm_queue_events' );
            $rows = $is_event ? $this->events : $this->queue;
            $key = $is_event ? 'event_id' : 'item_id';
            preg_match( '/WHERE \w+ > (\d+)/', $sql, $match );
            $result = array();
            foreach ( $rows as $row ) { if ( $row[$key] > $match[1] && isset( $row['api_metadata'] ) ) { $result[] = array( 'record_id' => $row[$key], 'api_metadata' => $row['api_metadata'] ); } }
            return array_slice( $result, 0, 500 );
        }
        throw new RuntimeException( 'Unexpected get_results: ' . $sql );
    }
}
if ( defined( 'ACM_WORKFLOW_BOOTSTRAP_ONLY' ) ) { return; }
$wpdb = new FixtureDatabase();
$repository = new AIContentManager\Services\QueueRepository();
$options['acm_db_version'] = AIContentManager\Infrastructure\Installer::DB_VERSION;
AIContentManager\Infrastructure\Installer::activate();
verify( isset( $fixture_roles['administrator']->caps['manage_ai_content'] ) && ! isset( $fixture_roles['editor']->caps['manage_ai_content'] ), 'activation grants only administrator' );
AIContentManager\Infrastructure\Installer::maybe_upgrade();
verify( '1' === $options['acm_capability_version'], 'capability upgrade idempotent' );

$caps['manage_ai_content'] = false;
verify( is_wp_error( $repository->insert_batch( 'b', array(), 99 ) ), 'repository authorization' );
$denied_api = new AIContentManager\Integrations\OpenAi();
verify( is_wp_error( $denied_api->test_connection( 'gpt-4o-mini' ) ), 'API authorization before transport' );
$denied_generator = new AIContentManager\Services\ContentGeneratorService( $repository );
verify( is_wp_error( $denied_generator->generate( 1, 99 ) ), 'service authorization' );
$denied_recovery = new AIContentManager\Services\QueueRecovery( $repository );
verify( is_wp_error( $denied_recovery->recover() ), 'recovery authorization' );
$guard = new AIContentManager\Services\BudgetGuard( $repository );
verify( is_wp_error( $guard->summary() ), 'usage authorization' );
$caps['manage_ai_content'] = true;
$request = array( 'content_type' => 'post', 'language' => 'en', 'payload' => array( 'topic' => 'Example' ) );
$repository->insert_batch( 'b', array( $request ), 999 );
verify( 7 === $wpdb->queue[1]['created_by'], 'existing created_by is authenticated creator_user_id' );
$queue_service = new AIContentManager\Services\ContentQueue( $repository, new AIContentManager\Services\ContentTypeRegistry() );
verify( is_wp_error( $queue_service->enqueue( array( 'content_type' => 'post', 'topic' => 'Test', 'language' => 'en' ), 21, 999 ) ), 'max items enforced without batch generation' );
$policy = AIContentManager\Services\GenerationPolicy::defaults();
$clean = AIContentManager\Services\GenerationPolicy::sanitize( array( 'daily_request_limit' => 0, 'max_input_tokens' => -3, 'recovery_mode' => 'wrong', 'generating_timeout_minutes' => 0 ) );
verify( 0 === $clean['daily_request_limit'] && 30000 === $clean['max_input_tokens'] && 'failed' === $clean['recovery_mode'] && 2 === $clean['generating_timeout_minutes'], 'sanitize settings and disabled limits' );

$now = new DateTimeImmutable( '2026-11-10 00:30:00', wp_timezone() );
$wpdb->queue[1]['api_metadata'] = json_encode( array( 'requested_at' => $now->modify( '-10 minutes' )->format( 'c' ), 'model' => 'gpt-4o-mini', 'input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30 ) );
$wpdb->events[1] = array( 'event_id' => 1, 'item_id' => 0, 'api_metadata' => json_encode( array( 'requested_at' => $now->modify( '-1 hour' )->format( 'c' ), 'input_tokens' => 5, 'output_tokens' => 5, 'total_tokens' => 10 ) ) );
$wpdb->events[2] = array( 'event_id' => 2, 'item_id' => 0, 'api_metadata' => json_encode( array( 'requested_at' => $now->format( 'c' ), 'attempted' => false, 'total_tokens' => 999 ) ) );
$wpdb->events[3] = array( 'event_id' => 3, 'item_id' => 0, 'api_metadata' => json_encode( array( 'requested_at' => '2026-10-31T19:00:00+00:00', 'total_tokens' => 100 ) ) );
$usage = $guard->summary( $now );
verify( 1 === $usage['day']['requests'] && 30 === $usage['day']['total_tokens'] && 2 === $usage['month']['requests'] && 40 === $usage['month']['total_tokens'], 'usage aggregation site timezone, legacy metadata, excluded denials and previous month' );
verify( 10 === $usage['day']['input_tokens'] && 20 === $usage['day']['output_tokens'], 'actual input and output usage' );
$policy['daily_request_limit'] = 1;
verify( is_wp_error( $guard->check_limits( $usage, 1, 1, $policy ) ), 'daily request limit' );
$policy = AIContentManager\Services\GenerationPolicy::defaults(); $policy['monthly_request_limit'] = 2;
verify( is_wp_error( $guard->check_limits( $usage, 1, 1, $policy ) ), 'monthly request limit' );
foreach ( array( 'daily_token_limit' => 31, 'monthly_token_limit' => 41, 'max_input_tokens' => 1, 'max_output_tokens' => 1 ) as $key => $limit ) {
    $policy = AIContentManager\Services\GenerationPolicy::defaults(); $policy[$key] = $limit;
    verify( is_wp_error( $guard->check_limits( $usage, 2, 2, $policy ) ), $key );
}
$policy = array_fill_keys( array_keys( AIContentManager\Services\GenerationPolicy::defaults() ), 0 );
verify( true === $guard->check_limits( $usage, 100000, 100000, $policy ), 'zero disables each budget limit' );
verify( $guard->estimate_input_tokens( array( 'text' => 'فارسی' ) ) >= strlen( 'فارسی' ) + 256, 'conservative byte estimate' );

// Stale boundary, row fencing, transactional events and archival of unknown consumption.
$wpdb->events = array();
$wpdb->queue[1]['status'] = 'generating';
$wpdb->queue[1]['processing_token'] = 'old-token';
$wpdb->queue[1]['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - 901 );
$reservation = array( 'attempt_id' => 'old-attempt', 'attempted' => true, 'requested_at' => gmdate( 'c' ), 'reserved_tokens' => 1000, 'total_tokens' => null );
$wpdb->queue[1]['api_metadata'] = json_encode( $reservation );
$wpdb->queue[2] = array_merge( $wpdb->queue[1], array( 'item_id' => 2, 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 899 ), 'api_metadata' => null ) );
$recovery = new AIContentManager\Services\QueueRecovery( $repository );
verify( 1 === $recovery->count(), 'stale detection uses generating and UTC cutoff' );
$wpdb->fail_event = true;
verify( is_wp_error( $recovery->recover() ) && 'generating' === $wpdb->queue[1]['status'], 'recovery rolls back if event insertion fails' );
$wpdb->fail_event = false;
verify( 1 === $recovery->recover() && 'failed' === $wpdb->queue[1]['status'] && 'generating' === $wpdb->queue[2]['status'], 'default recovery marks only stale failed' );
verify( null === $wpdb->queue[1]['api_metadata'] && 'old-attempt' === json_decode( $wpdb->events[1]['api_metadata'], true )['attempt_id'], 'usage moved to recovery event without duplicate' );
verify( 0 === $recovery->recover(), 'recovery idempotence' );
$options['acm_settings'] = array( 'recovery_mode' => 'pending' );
$wpdb->queue[2]['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - 901 );
verify( 1 === $recovery->recover() && 'pending' === $wpdb->queue[2]['status'], 'return stale item to pending' );
$repository->transition_generation( 2, 'pending', 'generating', 7 );
$new_token = $wpdb->queue[2]['processing_token'];
verify( is_wp_error( $repository->transition_generation( 2, 'generating', 'generated', 7, array(), array(), 'old-token' ) ) && $new_token === $wpdb->queue[2]['processing_token'], 'late old worker cannot overwrite reclaimed item' );
$updated_reservation = array_merge( $reservation, array( 'total_tokens' => 12, 'input_tokens' => 5, 'output_tokens' => 7 ) );
verify( true === $repository->finish_usage( $updated_reservation, array( 'item_id' => 1, 'processing_token' => 'old-token' ) ) && 12 === json_decode( $wpdb->events[1]['api_metadata'], true )['total_tokens'], 'late usage updates archived attempt' );

// Real guard with mock transport: count reservations before callbacks and replace with actual usage.
$wpdb = new FixtureDatabase(); $options['acm_settings'] = array( 'daily_request_limit' => 1 );
$calls = 0;
$transport = function () use ( &$calls ) { $calls++; return array( 'status' => 200, 'body' => '{"usage":{"prompt_tokens":10,"completion_tokens":20,"total_tokens":30}}' ); };
$first = $guard->execute( 'gpt-4o-mini', array( 'max_completion_tokens' => 80, 'messages' => array() ), array(), $transport );
$second = $guard->execute( 'gpt-4o-mini', array( 'max_completion_tokens' => 80 ), array(), $transport );
verify( 1 === $calls && ! $first['metadata']['error_message'] && $second['metadata']['error_message'], 'guard blocks second request before mock API' );
verify( 30 === $guard->summary()['day']['total_tokens'] && 0 === $guard->summary()['day']['reserved_tokens'], 'actual usage replaces reservation' );
$options['acm_settings'] = array( 'daily_request_limit' => 0, 'monthly_request_limit' => 0, 'daily_token_limit' => 0, 'monthly_token_limit' => 0 );
$guard->execute( 'gpt-4o-mini', null, array(), $transport );
verify( 2 === $calls, 'disabled request limits permit transport' );
$wpdb->lock_available = false;
$blocked = $guard->execute( 'gpt-4o-mini', null, array(), $transport );
verify( 2 === $calls && ! empty( $blocked['metadata']['error_message'] ), 'fail closed without advisory lock' );
$wpdb->lock_available = true;
$unknown = $guard->execute( 'gpt-4o-mini', array( 'max_completion_tokens' => 100 ), array(), function () { return new WP_Error( 'timeout', 'fixture timeout' ); } );
verify( $guard->summary()['day']['reserved_tokens'] > 100, 'timeout keeps conservative unknown reservation' );
$options['acm_settings'] = array( 'daily_token_limit' => 1 );
$blocked = $guard->execute( 'gpt-4o-mini', array( 'max_completion_tokens' => 100 ), array(), $transport );
verify( 2 === $calls && ! empty( $blocked['metadata']['error_message'] ), 'token budget blocks before transport including unknown reserve' );

// Full generation integration through existing OpenAi with the guarded HTTP fixture.
$options['acm_settings'] = array( 'daily_request_limit' => 1 );
$api = new AIContentManager\Integrations\OpenAi( $guard );
$api->store_api_key( 'fixture-credential' );
$wpdb = new FixtureDatabase();
$repository->insert_batch( 'b', array( $request ), 7 );
$wpdb->events[2] = array( 'event_id' => 2, 'item_id' => 0, 'api_metadata' => json_encode( array( 'requested_at' => gmdate( 'c' ), 'attempted' => true, 'total_tokens' => 0 ) ) );
$service = new AIContentManager\Services\ContentGeneratorService( $repository, $api );
verify( is_wp_error( $service->generate( 1, 7 ) ) && 'failed' === $wpdb->queue[1]['status'], 'blocked generation transitions queue to failed' );
verify( false !== strpos( end( $wpdb->events )['message'], 'روزانه' ), 'queue event contains understandable budget reason' );
verify( 0 === $network_calls, 'no real HTTP or OpenAI call in entire suite' );

// Successful existing OpenAi path uses a local fake HTTP function, not a network client.
$wpdb = new FixtureDatabase(); $options['acm_settings'] = array();
$repository->insert_batch( 'b', array( $request ), 7 );
$article = array( 'title' => 'Example', 'slug' => 'example', 'excerpt' => 'Summary', 'content' => '<h2>Section</h2><p>Text</p>',
    'primary_keyword' => 'example', 'secondary_keywords' => array(), 'suggested_meta_description' => 'Meta', 'headings' => array( array( 'level' => 2, 'text' => 'Section' ) ), 'faq' => array(), 'image_prompt' => 'Illustration' );
$fake_http_calls = 0;
$fixture_http = function ( $url, $args ) use ( &$fake_http_calls, $article, $guard, $wpdb ) {
    $fake_http_calls++;
    $sent = json_decode( $args['body'], true );
    verify( 8000 === $sent['max_completion_tokens'], 'configured output cap sent to existing OpenAi transport' );
    verify( 1 === $guard->summary()['day']['requests'] && $guard->summary()['day']['reserved_tokens'] > 8000, 'reservation visible before HTTP callback' );
    verify( ! isset( json_decode( $wpdb->queue[1]['api_metadata'], true )['Authorization'] ), 'credential excluded from reservation' );
    return array( 'status' => 200, 'body' => json_encode( array( 'usage' => array( 'prompt_tokens' => 100, 'completion_tokens' => 200, 'total_tokens' => 300 ),
        'choices' => array( array( 'finish_reason' => 'stop', 'message' => array( 'content' => json_encode( $article ) ) ) ) ) ) );
};
verify( true === $service->generate( 1, 7 ) && 'generated' === $wpdb->queue[1]['status'], 'successful generation through guard and fake existing OpenAi transport' );
verify( 1 === $fake_http_calls && 300 === $guard->summary()['day']['total_tokens'], 'real response usage becomes canonical exactly once' );
verify( false === strpos( json_encode( array( $wpdb->queue, $wpdb->events ) ), 'fixture-credential' ), 'no credential in queue or events' );
unset( $fixture_http );

$wpdb = new FixtureDatabase(); $options['acm_settings'] = array( 'daily_request_limit' => 1 );
$overlap_calls = 0;
$guard->execute( 'gpt-4o-mini', array( 'max_completion_tokens' => 100 ), array(), function () use ( $guard, &$overlap_calls ) {
    $blocked = $guard->execute( 'gpt-4o-mini', null, array(), function () use ( &$overlap_calls ) { $overlap_calls++; } );
    verify( 0 === $overlap_calls && ! empty( $blocked['metadata']['error_message'] ), 'overlapping request sees reservation and cannot exceed limit' );
    return array( 'status' => 200, 'body' => '{"usage":{"prompt_tokens":1,"completion_tokens":1,"total_tokens":2}}' );
} );
verify( 0 === $network_calls, 'all integration responses were local fixtures' );
