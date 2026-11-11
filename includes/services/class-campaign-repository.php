<?php
namespace AIContentManager\Services;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Durable campaigns and attempt ledger. Queue content remains in QueueRepository. */
class CampaignRepository {
    private $clock;
    public function __construct( $clock = null ) { $this->clock = $clock ?: 'time'; }
    public function now() { return (int) call_user_func( $this->clock ); }
    public function date( $time = null ) { return gmdate( 'Y-m-d H:i:s', null === $time ? $this->now() : $time ); }
    public static function available() { return '1.4.0' === get_option( 'acm_db_version' ); }
    private function table( $suffix ) { global $wpdb; return $wpdb->prefix . 'acm_campaign' . $suffix; }
    private function error() { return new \WP_Error( 'acm_campaign_storage', __( 'ثبت امن کمپین یا مرحله انجام نشد؛ ارسال تازه متوقف است و سوابق حفظ می‌شوند.', 'ai-content-manager' ) ); }
    public function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table( 's' ) . ' WHERE campaign_id = %d', $id ), ARRAY_A );
    }
    public function list_campaigns( $owner = 0 ) {
        global $wpdb;
        $sql = 'SELECT * FROM ' . $this->table( 's' ) . ( $owner ? $wpdb->prepare( ' WHERE owner_id = %d', $owner ) : '' ) . ' ORDER BY campaign_id DESC LIMIT 100';
        return $wpdb->get_results( $sql, ARRAY_A );
    }
    public function create( $keyword, $language, $config, $submission ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->error(); }
        $owner = get_current_user_id(); $hash = hash( 'sha256', $owner . ':' . $submission );
        $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table( 's' ) . ' WHERE submission_id = %s', $hash ), ARRAY_A );
        $json = wp_json_encode( $config );
        if ( $existing ) {
            return $existing['keyword'] === $keyword && $existing['language'] === $language && json_decode( $existing['config'], true ) === $config ? (int) $existing['campaign_id'] : $this->error();
        }
        $row = array( 'submission_id' => $hash, 'owner_id' => $owner, 'keyword' => $keyword, 'language' => $language, 'config' => $json,
            'status' => 'draft', 'phase' => 'discover', 'target_count' => $config['target'], 'accepted_count' => 0, 'completed_count' => 0,
            'next_run_at' => null, 'last_started_day' => null, 'last_error' => '', 'created_at' => $this->date(), 'updated_at' => $this->date() );
        if ( false === $wpdb->insert( $this->table( 's' ), $row ) ) { return $this->error(); }
        return (int) $wpdb->insert_id;
    }
    public function control( $id, $state, $message = '' ) {
        global $wpdb;
        $campaign = $this->get( $id );
        if ( ! $campaign || ! current_user_can( 'manage_ai_content' ) ||
            ( (int) $campaign['owner_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ||
            ! in_array( $state, array( 'active', 'paused', 'ended' ), true ) || in_array( $campaign['status'], array( 'ended', 'completed' ), true ) ) { return $this->error(); }
        return $this->stop( $id, $state, $message );
    }
    public function save_config( $id, $config ) {
        global $wpdb; $row = $this->get( $id );
        if ( ! $row || ! current_user_can( 'manage_ai_content' ) || ( (int) $row['owner_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) || in_array( $row['status'], array( 'active', 'ended', 'completed' ), true ) ) { return $this->error(); }
        $json = wp_json_encode( $config ); if ( $json === $row['config'] ) { return true; }
        return 1 === $wpdb->update( $this->table( 's' ), array( 'config' => $json, 'updated_at' => $this->date() ), array( 'campaign_id' => (int) $id, 'config' => $row['config'], 'status' => $row['status'] ) ) ? true : $this->error();
    }
    public function pending_result( $id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT step_id FROM ' . $this->table( '_steps' ) . " WHERE campaign_id = %d AND state = 'result' ORDER BY step_id LIMIT 1", $id ) );
    }
    /** Internal worker entry points validate their own route and claim, not browser user IDs. */
    public function stop( $id, $state, $message ) {
        global $wpdb;
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $this->error(); }
        try {
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table( 's' ) . ' WHERE campaign_id = %d FOR UPDATE', $id ), ARRAY_A );
        if ( ! $row ) { $wpdb->query( 'ROLLBACK' ); return $this->error(); }
        if ( in_array( $row['status'], array( 'ended', 'completed' ), true ) && $row['status'] !== $state ) { $wpdb->query( 'ROLLBACK' ); return true; }
        if ( 'paused' === $row['status'] && in_array( $state, array( 'review', 'budget' ), true ) ) { $wpdb->query( 'ROLLBACK' ); return true; }
        $changed = $wpdb->update( $this->table( 's' ), array( 'status' => $state, 'next_run_at' => 'active' === $state ? $this->date() : null,
            'last_error' => sanitize_text_field( $message ), 'updated_at' => $this->date() ), array( 'campaign_id' => (int) $id, 'status' => $row['status'] ) );
        if ( false === $changed ) { $wpdb->query( 'ROLLBACK' ); return $this->error(); }
        $event = array( 'campaign_id' => (int) $id, 'item_id' => 0, 'kind' => 'control', 'source_index' => 0, 'attempt_id' => wp_generate_uuid4(),
            'state' => 'complete', 'lease_until' => $this->date(), 'output' => wp_json_encode( array( 'status' => $state, 'actor' => get_current_user_id(), 'owner' => (int) $row['owner_id'] ) ),
            'api_metadata' => null, 'error_message' => sanitize_text_field( $message ), 'created_at' => $this->date(), 'updated_at' => $this->date() );
        if ( false === $wpdb->insert( $this->table( '_steps' ), $event ) || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return $this->error(); }
        return true;
        } catch ( \Throwable $error ) { $wpdb->query( 'ROLLBACK' ); return $this->error(); }
    }
    public function step( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table( '_steps' ) . ' WHERE step_id = %d', $id ), ARRAY_A );
    }
    public function steps( $id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table( '_steps' ) . ' WHERE campaign_id = %d ORDER BY step_id DESC LIMIT 150', $id ), ARRAY_A );
    }
    public function valid_claim( $claim ) {
        $step = is_array( $claim ) ? $this->step( $claim['step_id'] ?? 0 ) : null;
        return $step && is_string( $claim['attempt_id'] ?? null ) && hash_equals( $step['attempt_id'], $claim['attempt_id'] ) &&
            (int) $step['campaign_id'] === (int) ( $claim['campaign_id'] ?? 0 ) && (int) $step['item_id'] === (int) ( $claim['item_id'] ?? 0 ) &&
            in_array( $step['state'], array( 'claimed', 'reserved', 'result' ), true ) && $step['lease_until'] > $this->date();
    }
    public function has_active() {
        global $wpdb;
        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table( 's' ) . " WHERE status = 'active'" ) > 0;
    }
    public function busy() {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->table( '_steps' ) . " WHERE state IN ('claimed','reserved') AND lease_until > %s", $this->date() ) ) > 0;
    }
    public function inflight( $id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->table( '_steps' ) . " WHERE campaign_id = %d AND state IN ('claimed','reserved') AND lease_until > %s", $id, $this->date() ) ) > 0;
    }
    public function retry_not_before( $id ) {
        foreach ( $this->steps( $id ) as $step ) {
            if ( ! in_array( $step['state'], array( 'unknown', 'failed' ), true ) || ! in_array( $step['kind'], array( 'discover', 'research', 'generate' ), true ) ) { continue; }
            $metadata = json_decode( $step['api_metadata'] ?? '', true );
            if ( empty( $metadata['attempted'] ) ) { continue; }
            return strtotime( $step['updated_at'] . ' UTC' ) + min( 3600, max( 'unknown' === $step['state'] ? 300 : 60, 60 * ( 2 ** min( 5, $this->call_count( $id, $step['item_id'] ) ) ) ) );
        }
        return 0;
    }
    public function pause_all() {
        global $wpdb;
        return $wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->table( 's' ) . " SET status = 'paused', next_run_at = NULL, last_error = %s, updated_at = %s WHERE status = 'active'",
            __( 'افزونه غیرفعال شد؛ ادامه فقط با اقدام صریح کاربر انجام می‌شود.', 'ai-content-manager' ), $this->date() ) );
    }
    public function next_day() {
        return ( new \DateTimeImmutable( '@' . $this->now() ) )->setTimezone( wp_timezone() )->modify( '+1 day' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
    }
    /** Called under the global worker lock. Expired network attempts never become free retries. */
    public function claim() {
        global $wpdb;
        $steps = $this->table( '_steps' ); $campaigns = $this->table( 's' ); $queue = $wpdb->prefix . 'acm_queue'; $now = $this->date();
        $expired = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$steps} WHERE state IN ('claimed','reserved') AND lease_until <= %s ORDER BY step_id LIMIT 1", $now ), ARRAY_A );
        if ( $expired ) {
            $metadata = json_decode( $expired['api_metadata'] ?? '', true );
            $uncertain = ! empty( $metadata['attempted'] );
            $wpdb->update( $steps, array( 'state' => $uncertain ? 'unknown' : 'failed', 'error_message' => __( 'مهلت worker گذشته است؛ پاسخ دیررس مجوز تغییر صف ندارد.', 'ai-content-manager' ), 'updated_at' => $now ), array( 'step_id' => $expired['step_id'], 'attempt_id' => $expired['attempt_id'] ) );
            $this->stop( $expired['campaign_id'], 'review', $uncertain ? __( 'پاسخ یا مصرف درخواست قبلی نامشخص است؛ رزرو حفظ شد. ادامه نیازمند تأیید احتمال هزینه دوباره است.', 'ai-content-manager' ) : __( 'worker پیش از ارسال قطع شد؛ همان مرحله قابل بررسی و ادامه است.', 'ai-content-manager' ) );
            return false;
        }
        if ( $this->busy() || (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE status = 'generating' AND campaign_id IS NULL" ) > 0 ) { return false; }
        $campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$campaigns} WHERE status = 'active' AND next_run_at <= %s ORDER BY next_run_at, campaign_id LIMIT 1", $now ), ARRAY_A );
        if ( ! $campaign ) { return false; }
        $owner = get_userdata( (int) $campaign['owner_id'] );
        if ( ! $owner || ! user_can( $owner, 'manage_ai_content' ) || ! user_can( $owner, 'edit_posts' ) ) { $this->stop( $campaign['campaign_id'], 'paused', __( 'مالک کمپین حذف شده یا مجوز او سلب شده است.', 'ai-content-manager' ) ); return false; }
        $result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$steps} WHERE campaign_id = %d AND state = 'result' ORDER BY step_id LIMIT 1", $campaign['campaign_id'] ), ARRAY_A );
        if ( $result ) {
            $result['lease_until'] = $this->date( $this->now() + 300 );
            if ( 1 !== $wpdb->update( $steps, array( 'lease_until' => $result['lease_until'] ), array( 'step_id' => $result['step_id'], 'state' => 'result' ) ) ) { return $this->error(); }
            $result['resume_result'] = true; return $result;
        }
        $kind = $campaign['phase']; $item = null; $source_index = 0;
        if ( 'articles' === $kind ) {
            $item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue} WHERE campaign_id = %d AND campaign_stage <> 'done' ORDER BY campaign_slot LIMIT 1", $campaign['campaign_id'] ), ARRAY_A );
            if ( ! $item ) { $this->stop( $campaign['campaign_id'], 'completed', $campaign['accepted_count'] < $campaign['target_count'] ? __( 'کمپین با تعداد موضوع مستقل کمتر از هدف پایان یافت؛ موضوع تکراری اضافه نشد.', 'ai-content-manager' ) : '' ); return false; }
            if ( 'trashed' === $item['status'] ) { $this->stop( $campaign['campaign_id'], 'review', __( 'آیتم کمپین در زباله‌دان است؛ ابتدا آن را بررسی کنید.', 'ai-content-manager' ) ); return false; }
            $kind = $item['campaign_stage'];
            if ( empty( $item['campaign_started_at'] ) ) {
                $today = ( new \DateTimeImmutable( '@' . $this->now() ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
                if ( $campaign['last_started_day'] === $today ) { $wpdb->update( $campaigns, array( 'next_run_at' => $this->next_day() ), array( 'campaign_id' => $campaign['campaign_id'] ) ); return false; }
                if ( 1 !== $wpdb->update( $queue, array( 'campaign_started_at' => $now ), array( 'item_id' => $item['item_id'], 'campaign_started_at' => null ) ) || false === $wpdb->update( $campaigns, array( 'last_started_day' => $today ), array( 'campaign_id' => $campaign['campaign_id'] ) ) ) { return $this->error(); }
            }
            if ( 'fetch' === $kind ) {
                $research = json_decode( $item['research_output'] ?? '', true );
                foreach ( $research['sources'] ?? array() as $index => $source ) { if ( empty( $source['verified'] ) ) { $source_index = $index; break; } }
                $fetches = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$steps} WHERE item_id = %d AND kind = 'fetch' AND source_index = %d", $item['item_id'], $source_index ) );
                if ( $fetches >= 3 ) { $this->stop( $campaign['campaign_id'], 'review', __( 'سقف تلاش دریافت این منبع رسیده است؛ پژوهش باید دستی بررسی شود.', 'ai-content-manager' ) ); return false; }
            }
        }
        if ( ! in_array( $kind, array( 'discover', 'admit', 'research', 'fetch', 'generate', 'draft', 'seo' ), true ) ) { $this->stop( $campaign['campaign_id'], 'review', __( 'مرحله کمپین معتبر نیست.', 'ai-content-manager' ) ); return false; }
        $row = array( 'campaign_id' => (int) $campaign['campaign_id'], 'item_id' => $item ? (int) $item['item_id'] : 0, 'kind' => $kind, 'source_index' => $source_index,
            'attempt_id' => wp_generate_uuid4(), 'state' => 'claimed', 'lease_until' => $this->date( $this->now() + 300 ), 'output' => null, 'api_metadata' => null,
            'error_message' => '', 'created_at' => $now, 'updated_at' => $now );
        if ( false === $wpdb->insert( $steps, $row ) ) { return $this->error(); }
        $row['step_id'] = (int) $wpdb->insert_id; return $row;
    }
    public function latest_output( $campaign_id, $kind ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table( '_steps' ) . " WHERE campaign_id = %d AND kind = %s AND state = 'complete' ORDER BY step_id DESC LIMIT 1", $campaign_id, $kind ), ARRAY_A );
        return $row ? json_decode( $row['output'], true ) : null;
    }
    /** Store validated result durably before trying to mutate the queue. */
    public function save_result( $claim, $output ) {
        global $wpdb;
        $json = wp_json_encode( $output );
        if ( ! is_string( $json ) || strlen( $json ) > 1500000 ) { return $this->error(); }
        if ( ! $this->valid_claim( $claim ) ) {
            $step = $this->step( $claim['step_id'] );
            if ( $step && $step['attempt_id'] === $claim['attempt_id'] && $step['lease_until'] <= $this->date() && in_array( $step['state'], array( 'claimed', 'reserved', 'unknown', 'failed' ), true ) ) {
                $wpdb->update( $this->table( '_steps' ), array( 'output' => $json, 'state' => 'late', 'updated_at' => $this->date() ), array( 'step_id' => $claim['step_id'], 'attempt_id' => $claim['attempt_id'], 'state' => $step['state'] ) );
            }
            $this->stop( $claim['campaign_id'], 'review', __( 'پاسخ دیررس برای سابقه حفظ شد اما روی صف اعمال نشد؛ مصرف همچنان ثبت است.', 'ai-content-manager' ) );
            return new \WP_Error( 'acm_late_response', __( 'پاسخ پس از پایان lease دریافت شد و فقط برای بررسی حفظ شد.', 'ai-content-manager' ) );
        }
        return 1 === $wpdb->update( $this->table( '_steps' ), array( 'output' => $json, 'state' => 'result', 'updated_at' => $this->date() ), array( 'step_id' => $claim['step_id'], 'attempt_id' => $claim['attempt_id'] ) ) ? true : $this->error();
    }
    /** The queue mutator shares this transaction and cannot be applied twice by another worker. */
    public function finish( $claim, $mutator ) {
        global $wpdb;
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $this->error(); }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table( '_steps' ) . ' WHERE step_id = %d FOR UPDATE', $claim['step_id'] ), ARRAY_A );
        $campaign = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table( 's' ) . ' WHERE campaign_id = %d FOR UPDATE', $claim['campaign_id'] ), ARRAY_A );
        if ( ! $row || 'result' !== $row['state'] || $row['attempt_id'] !== $claim['attempt_id'] || $row['lease_until'] <= $this->date() || ! $campaign || 'active' !== $campaign['status'] ) {
            $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'acm_claim_stopped', __( 'کمپین یا claim متوقف است؛ نتیجه برای ادامه بعدی حفظ شد.', 'ai-content-manager' ) );
        }
        try {
            $result = call_user_func( $mutator, json_decode( $row['output'], true ), $campaign );
            if ( is_wp_error( $result ) || 1 !== $wpdb->update( $this->table( '_steps' ), array( 'state' => 'complete', 'updated_at' => $this->date() ), array( 'step_id' => $claim['step_id'], 'state' => 'result', 'attempt_id' => $claim['attempt_id'] ) ) || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return is_wp_error( $result ) ? $result : $this->error(); }
            return true;
        } catch ( \Throwable $error ) { $wpdb->query( 'ROLLBACK' ); return $this->error(); }
    }
    public function update_campaign( $id, $fields ) {
        global $wpdb;
        $fields = array_intersect_key( $fields, array_flip( array( 'phase', 'status', 'accepted_count', 'completed_count', 'last_error', 'next_run_at', 'updated_at' ) ) );
        return false === $wpdb->update( $this->table( 's' ), $fields, array( 'campaign_id' => (int) $id ) ) ? $this->error() : true;
    }
    public function fail( $claim, $message, $metadata = array() ) {
        global $wpdb;
        $stored = $this->step( $claim['step_id'] );
        if ( ! $stored || $stored['attempt_id'] !== $claim['attempt_id'] || in_array( $stored['state'], array( 'complete', 'late' ), true ) ) { return; }
        if ( 'result' === $stored['state'] ) { $this->stop( $claim['campaign_id'], 'review', $message ); return; }
        $metadata = $metadata ?: json_decode( $stored['api_metadata'] ?? '', true );
        $unknown = ! empty( $metadata['attempted'] ) && ( ! isset( $metadata['total_tokens'] ) || ! isset( $metadata['actual_cost_micros'] ) );
        $state = $unknown ? 'unknown' : 'failed';
        $wpdb->update( $this->table( '_steps' ), array( 'state' => $state, 'error_message' => sanitize_text_field( $message ), 'updated_at' => $this->date() ), array( 'step_id' => $claim['step_id'], 'attempt_id' => $claim['attempt_id'] ) );
        $campaign = $this->get( $claim['campaign_id'] );
        if ( ! $campaign || in_array( $campaign['status'], array( 'paused', 'ended' ), true ) ) { return; }
        $config = json_decode( $campaign['config'], true );
        $calls = $this->call_count( $claim['campaign_id'], $claim['item_id'] );
        if ( ! $unknown && ! empty( $metadata['attempted'] ) && in_array( (int) ( $metadata['http_status'] ?? 0 ), array( 429, 500, 502, 503 ), true ) && $calls < ( $claim['item_id'] ? $config['max_calls'] : 2 ) ) {
            $this->reset_failed_generation( $claim['item_id'] );
            $this->update_campaign( $claim['campaign_id'], array( 'next_run_at' => $this->date( $this->now() + min( 3600, 60 * ( 2 ** $calls ) ) ), 'last_error' => sanitize_text_field( $message ) ) );
        } else { $this->stop( $claim['campaign_id'], ! empty( $metadata['budget_denied'] ) ? 'budget' : 'review', $message ); }
    }
    public function reset_failed_generation( $item_id ) {
        global $wpdb;
        if ( ! $item_id ) { return true; }
        $item = ( new QueueRepository() )->get( $item_id );
        if ( $item && $item['campaign_id'] && 'generate' === $item['campaign_stage'] && empty( $item['generated_output'] ) && in_array( $item['status'], array( 'generating', 'failed' ), true ) ) {
            return false === $wpdb->update( $wpdb->prefix . 'acm_queue', array( 'status' => 'pending', 'processing_token' => null, 'api_metadata' => null ), array( 'item_id' => $item_id, 'campaign_stage' => 'generate' ) ) ? $this->error() : true;
        }
        return true;
    }
    public function item_to_resume( $id ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( "SELECT item_id FROM {$wpdb->prefix}acm_queue WHERE campaign_id = %d AND campaign_stage <> 'done' ORDER BY campaign_slot LIMIT 1", $id ) );
    }
    public function usage( $id ) {
        global $wpdb; $result = array();
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT api_metadata FROM ' . $this->table( '_steps' ) . ' WHERE campaign_id = %d AND api_metadata IS NOT NULL ORDER BY step_id', $id ), ARRAY_A );
        if ( $wpdb->last_error ) { return $this->error(); }
        foreach ( $rows as $row ) { $metadata = json_decode( $row['api_metadata'], true ); if ( is_array( $metadata ) && ! empty( $metadata['attempted'] ) ) { $result[] = $metadata; } }
        return $result;
    }
    public function call_count( $id, $item_id ) {
        $rows = $this->usage( $id ); if ( is_wp_error( $rows ) ) { return PHP_INT_MAX; }
        return count( array_filter( $rows, static function ( $row ) use ( $item_id ) { return (int) ( $row['item_id'] ?? 0 ) === (int) $item_id; } ) );
    }
    public function money_summary( $id ) {
        $rows = $this->usage( $id ); if ( is_wp_error( $rows ) ) { return $rows; }
        $sum = array( 'requests' => count( $rows ), 'actual' => 0, 'reserved' => 0, 'search' => 0, 'unknown' => 0 );
        foreach ( $rows as $row ) {
            if ( isset( $row['actual_cost_micros'] ) ) { $sum['actual'] += $row['actual_cost_micros']; }
            else { $sum['reserved'] += $row['reserved_cost_micros'] ?? 0; $sum['unknown']++; }
            $sum['search'] += $row['search_calls'] ?? 0;
        }
        return $sum;
    }
    public function reserve( $metadata, $context ) {
        global $wpdb;
        $claim = ExecutionContext::claim();
        if ( ! ExecutionContext::may_request( $context ) || ! $claim ) { return $this->error(); }
        $campaign = $this->get( $claim['campaign_id'] ); $config = json_decode( $campaign['config'], true );
        $sum = $this->money_summary( $campaign['campaign_id'] );
        if ( is_wp_error( $sum ) || ! CampaignPricing::valid( $config['rates'] ?? null, $this->now() ) ||
            $this->call_count( $campaign['campaign_id'], $claim['item_id'] ) >= ( $claim['item_id'] ? $config['max_calls'] : 2 ) ||
            $sum['actual'] + $sum['reserved'] + $metadata['reserved_cost_micros'] > $config['budget_micros'] ) {
            return new \WP_Error( 'acm_campaign_budget', __( 'سقف هزینه/فراخوانی کمپین رسیده یا قیمت تأییدشده منقضی است؛ درخواست تازه ارسال نشد.', 'ai-content-manager' ) );
        }
        $changed = $wpdb->update( $this->table( '_steps' ), array( 'api_metadata' => wp_json_encode( $metadata ), 'state' => 'reserved', 'updated_at' => $this->date() ),
            array( 'step_id' => $claim['step_id'], 'attempt_id' => $claim['attempt_id'], 'state' => 'claimed' ) );
        return 1 === $changed ? array( 'campaign_step_id' => $claim['step_id'], 'campaign_attempt_id' => $claim['attempt_id'] ) : $this->error();
    }
    public function finish_usage( $metadata, $ticket ) {
        global $wpdb;
        $row = $this->step( $ticket['campaign_step_id'] );
        $prior = $row ? json_decode( $row['api_metadata'] ?? '', true ) : null;
        if ( ! $row || $row['attempt_id'] !== $ticket['campaign_attempt_id'] || ( $prior['attempt_id'] ?? null ) !== $metadata['attempt_id'] ) { return $this->error(); }
        return false === $wpdb->update( $this->table( '_steps' ), array( 'api_metadata' => wp_json_encode( $metadata ), 'updated_at' => $this->date() ), array( 'step_id' => $row['step_id'], 'attempt_id' => $row['attempt_id'] ) ) ? $this->error() : true;
    }
}
