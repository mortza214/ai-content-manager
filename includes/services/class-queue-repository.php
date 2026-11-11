<?php
/** Queue database repository.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Reads and writes queue records and their status history. */
final class QueueRepository {
    /** Fetch one item, including its generated result. */
    public function get( $item_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}acm_queue WHERE item_id = %d", $item_id ), ARRAY_A );
    }

    /** Atomically claim a pending item or finish a claimed generation with its event. */
    public function transition_generation( $item_id, $from, $to, $user_id, $metadata = null, $output = null, $processing_token = null ) {
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->permission_error(); }
        if ( ! $this->lock_usage() ) { return $this->storage_error(); }
        try {
            return $this->transition_locked( $item_id, $from, $to, $user_id, $metadata, $output, $processing_token );
        } finally { $this->unlock_usage(); }
    }

    private function transition_locked( $item_id, $from, $to, $user_id, $metadata, $output, $processing_token ) {
        global $wpdb;
        if ( ! ( 'pending' === $from && 'generating' === $to ) && ! ( 'generating' === $from && in_array( $to, array( 'failed', 'generated' ), true ) && $processing_token ) ) {
            return $this->storage_error();
        }
        $table = $wpdb->prefix . 'acm_queue';
        $now = current_time( 'mysql', true );
        $data = array( 'status' => $to, 'updated_at' => $now );
        $where = array( 'item_id' => absint( $item_id ), 'status' => $from );
        if ( 'generating' === $to ) {
            $data['processing_token'] = wp_generate_uuid4();
            $data['api_metadata'] = null;
        } else {
            $where['processing_token'] = $processing_token;
            $data['processing_token'] = null;
        }
        if ( null !== $metadata ) {
            $data['api_metadata'] = wp_json_encode( $metadata );
        }
        if ( null !== $output ) {
            $data['generated_output'] = wp_json_encode( $output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        }
        $wpdb->query( 'START TRANSACTION' );
        $previous = $wpdb->get_row( $wpdb->prepare( "SELECT api_metadata FROM {$table} WHERE item_id = %d FOR UPDATE", $item_id ), ARRAY_A );
        $updated = $wpdb->update( $table, $data, $where );
        if ( 1 !== $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'acm_transition_failed', __( 'آیتم در وضعیت مورد انتظار نیست یا ذخیره‌سازی انجام نشد.', 'ai-content-manager' ) );
        }
        $messages = array( 'generating' => __( 'تولید دستی آغاز شد.', 'ai-content-manager' ), 'generated' => __( 'خروجی معتبر تولید و ذخیره شد.', 'ai-content-manager' ), 'failed' => __( 'تولید ناموفق بود؛ جزئیات در اطلاعات درخواست ثبت شد.', 'ai-content-manager' ) );
        $event = $wpdb->insert( $wpdb->prefix . 'acm_queue_events', array(
            'item_id' => absint( $item_id ), 'status_from' => $from, 'status_to' => $to,
            'message' => 'failed' === $to && ! empty( $metadata['error_message'] ) ? sanitize_text_field( $metadata['error_message'] ) : $messages[ $to ],
            'api_metadata' => 'generating' === $to ? ( $previous['api_metadata'] ?? null ) : null,
            'created_by' => get_current_user_id(), 'created_at' => $now,
        ) );
        if ( false === $event || false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'acm_generation_save_failed', __( 'ذخیره نتیجه و رویداد تولید انجام نشد.', 'ai-content-manager' ) );
        }
        return true;
    }

    /** Insert a batch atomically, starting each item in pending. */
    public function insert_batch( $batch_id, $items, $user_id ) {
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->permission_error(); }
        global $wpdb;

        $queue_table  = $wpdb->prefix . 'acm_queue';
        $events_table = $wpdb->prefix . 'acm_queue_events';
        $now          = current_time( 'mysql', true );
        $item_ids     = array();

        $wpdb->query( 'START TRANSACTION' );
        foreach ( $items as $item ) {
            $inserted = $wpdb->insert(
                $queue_table,
                array(
                    'batch_id'     => $batch_id,
                    'content_type' => $item['content_type'],
                    'language'     => $item['language'],
                    'status'       => 'pending',
                    'payload'      => wp_json_encode( $item['payload'] ),
                    'created_by'   => get_current_user_id(),
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
            );

            if ( false === $inserted ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'acm_queue_insert_failed', __( 'ذخیره درخواست در صف انجام نشد.', 'ai-content-manager' ) );
            }

            $item_id = (int) $wpdb->insert_id;
            $history = $wpdb->insert(
                $events_table,
                array(
                    'item_id'    => $item_id,
                    'status_from' => null,
                    'status_to'  => 'pending',
                    'message'    => __( 'درخواست به صف اضافه شد.', 'ai-content-manager' ),
                    'created_by' => $user_id,
                    'created_at' => $now,
                ),
                array( '%d', '%s', '%s', '%s', '%d', '%s' )
            );

            if ( false === $history ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'acm_queue_history_failed', __( 'ثبت تاریخچه صف انجام نشد.', 'ai-content-manager' ) );
            }

            $item_ids[] = $item_id;
        }

        if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return $this->storage_error(); }
        return $item_ids;
    }

    /** Update one item's status and append a history event. */
    public function update_status( $item_id, $status, $message = '', $user_id = 0 ) {
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->permission_error(); }
        global $wpdb;

        if ( ! QueueStatus::is_valid( $status ) ) {
            return new \WP_Error( 'acm_invalid_status', __( 'وضعیت صف معتبر نیست.', 'ai-content-manager' ) );
        }

        $queue_table  = $wpdb->prefix . 'acm_queue';
        $events_table = $wpdb->prefix . 'acm_queue_events';
        $wpdb->query( 'START TRANSACTION' );
        $previous = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$queue_table} WHERE item_id = %d FOR UPDATE", $item_id ) );
        if ( null === $previous ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'acm_queue_item_missing', __( 'درخواست صف پیدا نشد.', 'ai-content-manager' ) );
        }

        if ( in_array( $previous, array( 'generating', 'trashed' ), true ) || in_array( $status, array( 'generating', 'trashed' ), true ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'acm_processing_transition', __( 'تغییر وضعیت تولید فقط از مسیر تولید یا بازیابی مجاز است.', 'ai-content-manager' ) );
        }

        $now    = current_time( 'mysql', true );
        $updated = $wpdb->update( $queue_table, array( 'status' => $status, 'updated_at' => $now ), array( 'item_id' => absint( $item_id ) ), array( '%s', '%s' ), array( '%d' ) );
        $history = $wpdb->insert(
            $events_table,
            array(
                'item_id'     => absint( $item_id ),
                'status_from' => $previous,
                'status_to'   => $status,
                'message'     => sanitize_text_field( $message ),
                'created_by'  => absint( $user_id ),
                'created_at'  => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%d', '%s' )
        );

        if ( false === $updated || false === $history ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'acm_queue_update_failed', __( 'به‌روزرسانی وضعیت صف انجام نشد.', 'ai-content-manager' ) );
        }

        $wpdb->query( 'COMMIT' );
        return true;
    }

    /** Fetch paginated queue entries matching filters. */
    public function find( $filters, $limit, $offset ) {
        global $wpdb;

        $table      = $wpdb->prefix . 'acm_queue';
        $conditions = array( isset( $filters['status'] ) && 'trashed' === $filters['status'] ? '1 = 1' : "status <> 'trashed'" );
        $arguments  = array();
        foreach ( array( 'status', 'language', 'content_type' ) as $field ) {
            if ( ! empty( $filters[ $field ] ) ) {
                $conditions[] = "{$field} = %s";
                $arguments[]  = $filters[ $field ];
            }
        }

        $sql        = "SELECT item_id, batch_id, content_type, language, status, previous_status, payload, created_by, created_at, updated_at, scheduled_at, post_id, draft_state, trashed_at, trashed_by FROM {$table} WHERE " . implode( ' AND ', $conditions ) . ' ORDER BY created_at DESC, item_id DESC LIMIT %d OFFSET %d';
        $arguments[] = absint( $limit );
        $arguments[] = absint( $offset );

        return $wpdb->get_results( $wpdb->prepare( $sql, $arguments ), ARRAY_A );
    }

    /** Count queue entries matching filters. */
    public function count( $filters ) {
        global $wpdb;

        $table      = $wpdb->prefix . 'acm_queue';
        $conditions = array( isset( $filters['status'] ) && 'trashed' === $filters['status'] ? '1 = 1' : "status <> 'trashed'" );
        $arguments  = array();
        foreach ( array( 'status', 'language', 'content_type' ) as $field ) {
            if ( ! empty( $filters[ $field ] ) ) {
                $conditions[] = "{$field} = %s";
                $arguments[]  = $filters[ $field ];
            }
        }

        $sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $conditions );
        return $arguments ? (int) $wpdb->get_var( $wpdb->prepare( $sql, $arguments ) ) : (int) $wpdb->get_var( $sql );
    }

    /** Site-scoped advisory lock: reservations, archival and recovery share this lock. */
    public function lock_usage() {
        global $wpdb;
        $key = 'acm_' . md5( $wpdb->prefix . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) );
        return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) );
    }

    public function unlock_usage() {
        global $wpdb;
        $key = 'acm_' . md5( $wpdb->prefix . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) );
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
    }

    /** Current metadata is moved, never copied, to history before retry or recovery. */
    public function usage_metadata() {
        if ( ! current_user_can( 'manage_ai_content' ) ) { throw new \RuntimeException( 'forbidden' ); }
        global $wpdb;
        foreach ( array( 'acm_queue', 'acm_queue_events' ) as $suffix ) {
            $table = $wpdb->prefix . $suffix;
            $key = 'acm_queue' === $suffix ? 'item_id' : 'event_id';
            $after = 0;
            do {
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$key} AS record_id, api_metadata FROM {$table} WHERE {$key} > %d AND api_metadata IS NOT NULL ORDER BY {$key} LIMIT 500", $after ), ARRAY_A );
                if ( $wpdb->last_error ) { throw new \RuntimeException( 'usage_read_failed' ); }
                foreach ( $rows as $row ) {
                    $after = (int) $row['record_id'];
                    $data = json_decode( $row['api_metadata'], true );
                    if ( is_array( $data ) ) { yield $data; }
                }
            } while ( count( $rows ) === 500 );
        }
    }

    /** Called with the usage lock held. Persist before making any external request. */
    public function reserve_usage( $metadata, $context ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->permission_error(); }
        if ( ! empty( $context['item_id'] ) ) {
            $changed = $wpdb->update( $wpdb->prefix . 'acm_queue', array( 'api_metadata' => wp_json_encode( $metadata ) ), array(
                'item_id' => absint( $context['item_id'] ), 'status' => 'generating', 'processing_token' => $context['processing_token'] ?? '', 'api_metadata' => null,
            ) );
            return 1 === $changed ? $context : $this->storage_error();
        }
        // item_id=0 is a standalone connection audit, not a queue item or queue status change.
        $inserted = $wpdb->insert( $wpdb->prefix . 'acm_queue_events', array( 'item_id' => 0, 'status_from' => null,
            'status_to' => 'api_request', 'message' => __( 'درخواست بررسی اتصال OpenAI ثبت شد.', 'ai-content-manager' ),
            'api_metadata' => wp_json_encode( $metadata ), 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) );
        return false === $inserted ? $this->storage_error() : array( 'event_id' => (int) $wpdb->insert_id );
    }

    /** Find an attempt even if recovery moved it to history while the API was running. */
    public function finish_usage( $metadata, $context ) {
        global $wpdb;
        if ( ! empty( $context['item_id'] ) ) {
            $changed = $wpdb->update( $wpdb->prefix . 'acm_queue', array( 'api_metadata' => wp_json_encode( $metadata ) ), array(
                'item_id' => absint( $context['item_id'] ), 'status' => 'generating', 'processing_token' => $context['processing_token'],
            ) );
            if ( false === $changed ) { return $this->storage_error(); }
            if ( 1 === $changed ) { return true; }
            $event_id = $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}acm_queue_events WHERE item_id = %d AND api_metadata LIKE %s ORDER BY event_id DESC LIMIT 1", $context['item_id'], '%' . $wpdb->esc_like( $metadata['attempt_id'] ) . '%' ) );
        } else { $event_id = $context['event_id'] ?? 0; }
        if ( ! $event_id ) { return $this->storage_error(); }
        $changed = $wpdb->update( $wpdb->prefix . 'acm_queue_events', array( 'api_metadata' => wp_json_encode( $metadata ) ), array( 'event_id' => (int) $event_id ) );
        return false === $changed ? $this->storage_error() : true;
    }

    public function count_stale( $cutoff ) {
        global $wpdb;
        $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acm_queue WHERE status = 'generating' AND updated_at < %s", $cutoff ) );
        return $wpdb->last_error ? $this->storage_error() : (int) $count;
    }

    /** Recheck cutoff under row lock. Bounded batches avoid long admin requests. */
    public function recover_stale( $cutoff, $mode ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->permission_error(); }
        if ( ! in_array( $mode, array( 'failed', 'pending' ), true ) || ! $this->lock_usage() ) { return $this->storage_error(); }
        $table = $wpdb->prefix . 'acm_queue';
        try {
            $wpdb->query( 'START TRANSACTION' );
            $items = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, api_metadata FROM {$table} WHERE status = 'generating' AND updated_at < %s ORDER BY item_id LIMIT 100 FOR UPDATE", $cutoff ), ARRAY_A );
            if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return $this->storage_error(); }
            $count = 0;
            foreach ( $items as $item ) {
                $message = __( 'بازیابی دستی: مهلت وضعیت تولید گذشته است؛ وضعیت به ', 'ai-content-manager' ) . ( 'failed' === $mode ? __( 'ناموفق', 'ai-content-manager' ) : __( 'در انتظار', 'ai-content-manager' ) );
                $changed = $wpdb->update( $table, array( 'status' => $mode, 'processing_token' => null,
                    'api_metadata' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'item_id' => $item['item_id'], 'status' => 'generating' ) );
                $event = $wpdb->insert( $wpdb->prefix . 'acm_queue_events', array( 'item_id' => $item['item_id'],
                    'status_from' => 'generating', 'status_to' => $mode, 'message' => $message, 'api_metadata' => $item['api_metadata'],
                    'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) );
                if ( 1 !== $changed || false === $event ) { $wpdb->query( 'ROLLBACK' ); return $this->storage_error(); }
                $count++;
            }
            if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return $this->storage_error(); }
            return $count;
        } finally { $this->unlock_usage(); }
    }

    private function storage_error() { return new \WP_Error( 'acm_storage_failed', __( 'ثبت امن مصرف یا وضعیت صف انجام نشد؛ درخواست ارسال نشد یا نتیجه نیازمند بررسی است.', 'ai-content-manager' ) ); }
    private function permission_error() { return new \WP_Error( 'acm_forbidden', __( 'مجوز مدیریت محتوای هوشمند لازم است.', 'ai-content-manager' ) ); }

    public function lock_item( $item_id ) { return $this->named_lock( 'item:' . (int) $item_id, true ); }
    public function unlock_item( $item_id ) { $this->named_lock( 'item:' . (int) $item_id, false ); }
    private function named_lock( $scope, $acquire ) {
        global $wpdb;
        $key = 'acm_' . md5( $wpdb->prefix . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $scope );
        $sql = $acquire ? 'SELECT GET_LOCK(%s, 5)' : 'SELECT RELEASE_LOCK(%s)';
        return '1' === (string) $wpdb->get_var( $wpdb->prepare( $sql, $key ) );
    }

    /** Stable batch id and payload comparison make a successful submission replay read-only. */
    public function insert_submission( $batch_id, $items ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->permission_error(); }
        if ( ! $this->named_lock( 'submission:' . $batch_id, true ) ) { return $this->storage_error(); }
        try {
            $existing = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, payload, content_type, language, created_by FROM {$wpdb->prefix}acm_queue WHERE batch_id = %s ORDER BY item_id", $batch_id ), ARRAY_A );
            if ( $wpdb->last_error ) { return $this->storage_error(); }
            if ( $existing ) {
                $matches = count( $existing ) === count( $items );
                foreach ( $existing as $index => $row ) {
                    if ( ! isset( $items[ $index ] ) || (int) $row['created_by'] !== get_current_user_id() ||
                        $row['content_type'] !== $items[ $index ]['content_type'] || $row['language'] !== $items[ $index ]['language'] ||
                        json_decode( $row['payload'], true ) !== $items[ $index ]['payload'] ) { $matches = false; break; }
                }
                return $matches ? array_map( 'intval', array_column( $existing, 'item_id' ) ) : new \WP_Error( 'acm_submission_used', __( 'این شناسه ارسال قبلاً با داده دیگری ثبت شده است؛ برای درخواست تازه، فرم را دوباره باز کنید.', 'ai-content-manager' ) );
            }
            return $this->insert_batch( $batch_id, $items, get_current_user_id() );
        } finally { $this->named_lock( 'submission:' . $batch_id, false ); }
    }

    /** Only status and trash audit fields change: usage, generated output and post_id survive. */
    public function change_trash( $item_id, $restore ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->permission_error(); }
        if ( ! $this->lock_item( $item_id ) ) { return $this->storage_error(); }
        try {
            if ( ! $this->lock_usage() ) { return $this->storage_error(); }
            try {
                $wpdb->query( 'START TRANSACTION' );
                $item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}acm_queue WHERE item_id = %d FOR UPDATE", $item_id ), ARRAY_A );
                $allowed = array( 'pending', 'failed', 'generated' );
                if ( ! $item || ( $restore ? ( 'trashed' !== $item['status'] || ! in_array( $item['previous_status'], $allowed, true ) ) : ! in_array( $item['status'], $allowed, true ) ) ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new \WP_Error( 'acm_action_rejected', __( 'وضعیت آیتم برای این عملیات مجاز نیست؛ آیتم در حال تولید قابل انتقال نیست.', 'ai-content-manager' ) );
                }
                $to = $restore ? $item['previous_status'] : 'trashed';
                $data = array( 'status' => $to, 'updated_at' => current_time( 'mysql', true ) );
                if ( ! $restore ) { $data += array( 'previous_status' => $item['status'], 'trashed_at' => current_time( 'mysql', true ), 'trashed_by' => get_current_user_id() ); }
                // Keep the latest removal audit after restore; the event records every operation.
                $changed = $wpdb->update( $wpdb->prefix . 'acm_queue', $data, array( 'item_id' => (int) $item_id, 'status' => $item['status'] ) );
                $event = $this->append_event( $item_id, $item['status'], $to, $restore ? __( 'آیتم صف بازگردانی شد؛ نوشته مرتبط و مصرف تغییری نکرد.', 'ai-content-manager' ) : __( 'آیتم صف به زباله‌دان منتقل شد؛ نوشته مرتبط و مصرف تغییری نکرد.', 'ai-content-manager' ) );
                if ( 1 !== $changed || false === $event || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return $this->storage_error(); }
                return true;
            } finally { $this->unlock_usage(); }
        } finally { $this->unlock_item( $item_id ); }
    }

    /** Caller holds item lock. Persist claim/link/event together, independently of WordPress hooks. */
    public function save_draft_fields( $item_id, $fields, $message ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->permission_error(); }
        $fields = array_intersect_key( $fields, array_flip( array( 'draft_token', 'draft_state', 'post_id' ) ) );
        $wpdb->query( 'START TRANSACTION' );
        $changed = $wpdb->update( $wpdb->prefix . 'acm_queue', $fields, array( 'item_id' => (int) $item_id, 'status' => 'generated' ) );
        if ( 1 !== $changed || false === $this->append_event( $item_id, 'generated', 'generated', $message ) || false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' ); return $this->storage_error();
        }
        return true;
    }

    public function find_draft_marker( $marker ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content_filtered = %s ORDER BY ID LIMIT 2", $marker ) );
        if ( $wpdb->last_error || count( $ids ) > 1 ) { return $this->storage_error(); }
        return $ids ? (int) $ids[0] : 0;
    }

    /** Caller holds item lock and authorizes the linked draft. Preserve the generated history. */
    public function save_seo_keyword( $item, $value ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->permission_error(); }
        $payload = json_decode( $item['payload'], true );
        if ( ! is_array( $payload ) ) { return $this->storage_error(); }
        $payload['seo_review'] = array( 'primary_keyword' => $value, 'post_id' => (int) $item['post_id'],
            'draft_token' => $item['draft_token'], 'output_hash' => hash( 'sha256', $item['generated_output'] ) );
        $encoded = wp_json_encode( $payload );
        if ( ! is_string( $encoded ) ) { return $this->storage_error(); }
        if ( $encoded === $item['payload'] ) { return true; }
        // Compare-and-swap also protects against writers which do not share the item lock.
        $changed = $wpdb->update( $wpdb->prefix . 'acm_queue', array( 'payload' => $encoded ),
            array( 'item_id' => (int) $item['item_id'], 'payload' => $item['payload'], 'status' => $item['status'],
                'post_id' => (int) $item['post_id'], 'draft_token' => $item['draft_token'], 'generated_output' => $item['generated_output'] ) );
        return 1 === $changed ? true : $this->storage_error();
    }

    /** Caller holds item lock. Store only outcome codes, never output or API metadata. */
    public function record_seo_result( $item_id, $post_id, $fields, $automatic ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->permission_error(); }
        if ( ! array_diff( $fields, array( 'skipped' ) ) ) { return true; }
        $prefix = __( 'نتیجه اطلاعات سئو: ', 'ai-content-manager' );
        $message = $prefix . wp_json_encode( array( 'post_id' => (int) $post_id, 'mode' => $automatic ? 'automatic' : 'manual', 'fields' => $fields ) );
        $previous = $wpdb->get_var( $wpdb->prepare( "SELECT message FROM {$wpdb->prefix}acm_queue_events WHERE item_id = %d AND message LIKE %s ORDER BY event_id DESC LIMIT 1", $item_id, $wpdb->esc_like( $prefix ) . '%' ) );
        if ( $wpdb->last_error ) { return $this->storage_error(); }
        if ( is_string( $previous ) && ! in_array( 'applied', $fields, true ) ) {
            $prior = json_decode( substr( $previous, strlen( $prefix ) ), true );
            $normalize = static function ( $results ) { return array_map( static function ( $status ) { return 'applied' === $status ? 'equal' : $status; }, $results ); };
            if ( is_array( $prior ) && (int) ( $prior['post_id'] ?? 0 ) === (int) $post_id &&
                ( $prior['mode'] ?? '' ) === ( $automatic ? 'automatic' : 'manual' ) &&
                $normalize( $prior['fields'] ?? array() ) === $normalize( $fields ) ) { return true; }
        }
        $item = $this->get( $item_id );
        if ( ! $item || false === $this->append_event( $item_id, $item['status'], $item['status'], $message ) ) { return $this->storage_error(); }
        return true;
    }

    private function append_event( $item_id, $from, $to, $message ) {
        global $wpdb;
        return $wpdb->insert( $wpdb->prefix . 'acm_queue_events', array( 'item_id' => (int) $item_id, 'status_from' => $from, 'status_to' => $to,
            'message' => sanitize_text_field( $message ), 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) );
    }
}
