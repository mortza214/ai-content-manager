<?php
/** Content request queue service.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Validates and expands one content request into queue items. */
final class ContentQueue {
    /** @var QueueRepository */
    private $repository;

    /** @var ContentTypeRegistry */
    private $content_types;

    /** @param QueueRepository|null $repository Queue persistence layer. */
    public function __construct( $repository = null, $content_types = null ) {
        $this->repository   = $repository ? $repository : new QueueRepository();
        $this->content_types = $content_types ? $content_types : new ContentTypeRegistry();
    }

    /** Create queue rows only; this method never calls AI or creates WordPress posts. */
    public function enqueue( $request, $count, $user_id ) {
        if ( (int) $count !== 1 ) { return new \WP_Error( 'acm_legacy_repeat', __( 'تکثیر یک موضوع با تعداد بیشتر از یک دیگر مجاز نیست؛ حالت چند موضوع مستقل را انتخاب و هر موضوع را در یک خط وارد کنید.', 'ai-content-manager' ) ); }
        return $this->enqueue_topics( $request, array( $request['topic'] ?? '' ) );
    }

    /** Validate all topics and keywords before one atomic insert; no topic suggestion API. */
    public function enqueue_topics( $request, $topics, $submission_id = '' ) {
        if ( ! current_user_can( 'manage_ai_content' ) ) { return new \WP_Error( 'acm_forbidden', __( 'مجوز ثبت درخواست محتوا را ندارید.', 'ai-content-manager' ) ); }
        $user_id = get_current_user_id();
        $topics = $this->validate_topics( $topics );
        if ( is_wp_error( $topics ) ) { return $topics; }
        $count = count( $topics );
        $limits = GenerationPolicy::values();
        if ( $limits['max_generation_items'] && $count > $limits['max_generation_items'] ) {
            return new \WP_Error( 'acm_items_limit', __( 'تعداد آیتم‌ها از سقف مجاز هر درخواست بیشتر است.', 'ai-content-manager' ) );
        }
        if ( $count < 1 || $count > 100 ) {
            return new \WP_Error( 'acm_invalid_batch_size', __( 'تعداد مقالات باید بین ۱ تا ۱۰۰ باشد.', 'ai-content-manager' ) );
        }

        if ( empty( $request['content_type'] ) || ! $this->content_types->supports( $request['content_type'] ) ) {
            return new \WP_Error( 'acm_invalid_content_type', __( 'نوع محتوا معتبر نیست.', 'ai-content-manager' ) );
        }

        if ( empty( $request['language'] ) ) {
            return new \WP_Error( 'acm_missing_request_fields', __( 'موضوع و زبان اصلی الزامی هستند.', 'ai-content-manager' ) );
        }

        $category = ( new QueueCategory( $this->repository ) )->validate( $request['category'] ?? null, $request['content_type'], $request['language'] );
        if ( is_wp_error( $category ) ) { return $category; }
        if ( array_key_exists( 'category', $request ) ) { $request['category'] = $category; }
        unset( $request['seo_review'], $request['category_attempt'] );

        $primary = $request['primary_keyword'] ?? '';
        if ( ! is_string( $primary ) || preg_match( '/[,،]|\R/u', $primary ) ) {
            return new \WP_Error( 'acm_primary_keyword', __( 'کلمه کلیدی اصلی باید فقط یک عبارت باشد؛ عبارت چندکلمه‌ای مجاز است. عبارت‌های دیگر را در کلمات کلیدی کمکی وارد کنید.', 'ai-content-manager' ) );
        }
        $request['primary_keyword'] = sanitize_text_field( $primary );
        $request['secondary_keywords'] = $this->secondary_keywords( $request['secondary_keywords'] ?? array() );
        if ( $submission_id && ! preg_match( '/^[a-f0-9-]{36}$/D', $submission_id ) ) { return new \WP_Error( 'acm_submission_invalid', __( 'شناسه ارسال معتبر نیست؛ فرم را تازه‌سازی کنید.', 'ai-content-manager' ) ); }
        $hash = $submission_id ? hash( 'sha256', $user_id . ':' . $submission_id ) : '';
        $batch_id = $hash ? substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-' . substr( $hash, 12, 4 ) . '-' . substr( $hash, 16, 4 ) . '-' . substr( $hash, 20, 12 ) : wp_generate_uuid4();
        $items    = array();
        for ( $index = 1; $index <= $count; $index++ ) {
            $request['topic'] = $topics[ $index - 1 ];
            $items[] = array(
                'content_type' => $request['content_type'],
                'language'     => $request['language'],
                'payload'      => array_merge( $request, array( 'batch_index' => $index, 'batch_total' => $count ) ),
            );
        }

        $item_ids = $submission_id ? $this->repository->insert_submission( $batch_id, $items ) : $this->repository->insert_batch( $batch_id, $items, absint( $user_id ) );
        if ( is_wp_error( $item_ids ) ) {
            return $item_ids;
        }

        return array( 'batch_id' => $batch_id, 'item_ids' => $item_ids );
    }

    public function validate_topics( $topics ) {
        if ( ! is_array( $topics ) || count( $topics ) > 1000 ) { return new \WP_Error( 'acm_topics_invalid', __( 'فهرست موضوع‌ها معتبر نیست یا بیش از سقف است.', 'ai-content-manager' ) ); }
        $result = $seen = array();
        foreach ( $topics as $line => $topic ) {
            if ( ! is_string( $topic ) ) { return new \WP_Error( 'acm_topics_invalid', __( 'هر موضوع باید متن باشد.', 'ai-content-manager' ) ); }
            $topic = trim( sanitize_textarea_field( $topic ) );
            if ( '' === $topic ) { continue; }
            if ( preg_match( '/\R/u', $topic ) || ( function_exists( 'mb_strlen' ) ? mb_strlen( $topic ) : strlen( $topic ) ) > 250 ) { return new \WP_Error( 'acm_topic_length', __( 'هر موضوع باید یک خط و حداکثر ۲۵۰ نویسه باشد.', 'ai-content-manager' ) ); }
            $key = $this->normalize_phrase( $topic );
            if ( isset( $seen[ $key ] ) ) { return new \WP_Error( 'acm_duplicate_topic', sprintf( __( 'موضوع خط %d با موضوع قبلی تکراری است؛ ورودی‌ها حفظ شدند. موضوع‌های تکراری را اصلاح کنید.', 'ai-content-manager' ), $line + 1 ) ); }
            $seen[ $key ] = true;
            $result[] = $topic;
        }
        $limit = GenerationPolicy::values()['max_generation_items'];
        if ( ! $result || count( $result ) > 100 || ( $limit && count( $result ) > $limit ) ) { return new \WP_Error( 'acm_items_limit', __( 'تعداد موضوع‌های غیرخالی باید دست‌کم یک و در سقف مجاز کنترل مصرف و سقف فنی ۱۰۰ باشد.', 'ai-content-manager' ) ); }
        return $result;
    }

    public function secondary_keywords( $input ) {
        $parts = is_string( $input ) ? preg_split( '/(?:[,،]|\R)+/u', $input ) : ( is_array( $input ) ? $input : array() );
        $result = $seen = array();
        foreach ( $parts as $part ) {
            if ( ! is_string( $part ) ) { continue; }
            $part = sanitize_text_field( $part );
            $key = $this->normalize_phrase( $part );
            if ( '' !== $key && ! isset( $seen[ $key ] ) ) { $seen[ $key ] = true; $result[] = $part; }
        }
        return $result;
    }

    private function normalize_phrase( $text ) {
        $text = trim( preg_replace( '/[\s\p{Z}]+/u', ' ', str_replace( array( 'ي', 'ك' ), array( 'ی', 'ک' ), $text ) ) );
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
    }
}
