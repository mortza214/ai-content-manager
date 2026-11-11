<?php
namespace AIContentManager\Services;

use AIContentManager\Integrations\Yoast;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** SEO never transitions generation, drafts, language, usage or reservations. */
final class QueueSeo {
    private $repository;
    private $yoast;
    public function __construct( $repository, $yoast = null ) {
        $this->repository = $repository;
        $this->yoast = $yoast ?: new Yoast();
    }

    public function enabled() {
        $settings = get_option( 'acm_settings', array() );
        return is_array( $settings ) && ! empty( $settings['yoast_on_draft'] );
    }

    /** Resolve the server-side relationship, never a browser-supplied post_id. */
    private function context( $item_id ) {
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->error( __( 'مجوز مدیریت محتوای هوشمند لازم است.', 'ai-content-manager' ) ); }
        $item = $this->repository->get( $item_id );
        if ( ! $item || 'trashed' === $item['status'] ) { return $this->error( __( 'آیتم پیدا نشد یا در زباله‌دان صف است؛ عملیات سئو مجاز نیست.', 'ai-content-manager' ) ); }
        $id = (int) ( $item['post_id'] ?? 0 );
        if ( $id ) { wp_cache_delete( $id, 'posts' ); }
        $post = $id ? get_post( $id ) : null;
        if ( ! $post ) { return $this->error( __( 'نوشته مرتبط وجود ندارد یا حذف شده است.', 'ai-content-manager' ) ); }
        if ( ! current_user_can( 'edit_post', $id ) ) { return $this->error( __( 'مجوز ویرایش نوشته مرتبط را ندارید.', 'ai-content-manager' ) ); }
        if ( $post->post_type !== $item['content_type'] || empty( $item['draft_token'] ) ||
            ( $post->post_content_filtered ?? '' ) !== 'acm-draft:' . (int) $item_id . ':' . $item['draft_token'] ) {
            return $this->error( __( 'ارتباط یا نوع نوشته با آیتم صف معتبر نیست؛ هیچ تغییری انجام نشد.', 'ai-content-manager' ) );
        }
        if ( 'draft' !== $post->post_status ) { return $this->error( __( 'عملیات سئو فقط برای پیش‌نویس مجاز است؛ نوشته منتشرشده، زمان‌بندی‌شده، خصوصی یا داخل زباله‌دان تغییر نمی‌کند.', 'ai-content-manager' ) ); }
        if ( ! $this->yoast->available() ) { return new \WP_Error( 'acm_yoast_inactive', __( 'Yoast SEO فعال نیست', 'ai-content-manager' ) ); }

        $output = ( new ArticleOutputValidator() )->validate_for_draft( $item['generated_output'] ?? '' );
        if ( is_wp_error( $output ) ) { return $output; }
        $original_keyword = $output['primary_keyword'] ?? null;
        $payload = json_decode( $item['payload'], true );
        $review = is_array( $payload ) ? ( $payload['seo_review'] ?? null ) : null;
        if ( is_array( $review ) && ( $review['post_id'] ?? null ) === $id &&
            ( $review['draft_token'] ?? null ) === $item['draft_token'] &&
            ( $review['output_hash'] ?? null ) === hash( 'sha256', $item['generated_output'] ) &&
            is_string( $review['primary_keyword'] ?? null ) ) {
            $output['primary_keyword'] = $review['primary_keyword'];
        }
        return array( 'item' => $item, 'post_id' => $id, 'output' => $output, 'original_keyword' => $original_keyword );
    }

    /** Bind each displayed pair to its item, post, user and proposal. */
    private function signature( $context, $field, $current, $proposed ) {
        return hash_hmac( 'sha256', wp_json_encode( array( (int) $context['item']['item_id'], $context['post_id'],
            $context['item']['draft_token'], hash( 'sha256', $context['item']['generated_output'] ), get_current_user_id(), $field, $current, $proposed ) ), wp_salt( 'auth' ) );
    }

    public function compare( $item_id ) {
        try {
            $context = $this->context( $item_id );
            if ( is_wp_error( $context ) ) { return $context; }
            $fields = array();
            foreach ( Yoast::FIELDS as $field => $key ) {
                $raw = $context['output'][ $field ] ?? null;
                $proposed = $this->yoast->validate( $field, $raw );
                try {
                    $current = $this->yoast->read( $context['post_id'], $field );
                    if ( ! is_string( $current ) ) { throw new \RuntimeException(); }
                    $status = is_wp_error( $proposed ) ? 'invalid' : ( $current === $proposed ? 'equal' : ( '' !== $current ? 'preserved' : 'pending' ) );
                    $fields[ $field ] = array( 'current' => $current, 'proposed' => is_string( $raw ) ? $raw : '', 'status' => $status,
                        'signature' => $this->signature( $context, $field, $current, $raw ),
                        'message' => is_wp_error( $proposed ) ? $proposed->get_error_message() : $this->yoast->guidance( $field, $proposed ) );
                } catch ( \Throwable $error ) {
                    $fields[ $field ] = array( 'current' => '', 'proposed' => is_string( $raw ) ? $raw : '', 'status' => 'error', 'signature' => '',
                        'message' => __( 'خواندن این فیلد از Yoast انجام نشد.', 'ai-content-manager' ) );
                }
            }
            return array( 'post_id' => $context['post_id'], 'fields' => $fields, 'original_keyword' => $context['original_keyword'] );
        } catch ( \Throwable $error ) { return $this->error( __( 'مقایسه اطلاعات سئو انجام نشد؛ دوباره تلاش کنید.', 'ai-content-manager' ) ); }
    }

    /** Save a proposal only. A new signed comparison is required before applying it to Yoast. */
    public function save_primary_keyword( $item_id, $value, $signature ) {
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->error( __( 'مجوز مدیریت محتوای هوشمند لازم است.', 'ai-content-manager' ) ); }
        if ( ! $this->repository->lock_item( $item_id ) ) { return $this->error( __( 'این آیتم در حال پردازش است؛ دوباره تلاش کنید.', 'ai-content-manager' ) ); }
        try {
            $context = $this->context( $item_id );
            if ( is_wp_error( $context ) ) { return $context; }
            $value = $this->yoast->validate( 'primary_keyword', $value );
            if ( is_wp_error( $value ) ) { return $value; }
            $current = $this->yoast->read( $context['post_id'], 'primary_keyword' );
            if ( ! is_string( $current ) || ! is_string( $signature ) ||
                ! hash_equals( $this->signature( $context, 'primary_keyword', $current, $context['output']['primary_keyword'] ?? null ), $signature ) ) {
                return $this->error( __( 'مقدار Yoast یا پیشنهاد پس از نمایش مقایسه تغییر کرده است؛ مقایسه تازه را بررسی و دوباره اصلاح کنید.', 'ai-content-manager' ) );
            }
            return $this->repository->save_seo_keyword( $context['item'], $value );
        } catch ( \Throwable $error ) {
            return $this->error( __( 'ذخیره پیشنهاد اصلاح‌شده انجام نشد؛ مقایسه را دوباره بررسی کنید.', 'ai-content-manager' ) );
        } finally { $this->repository->unlock_item( $item_id ); }
    }

    /** Shares the draft/trash item lock. Ordinary WordPress editors do not share it. */
    public function apply( $item_id, $choices = array(), $signatures = array(), $automatic = false ) {
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->error( __( 'مجوز مدیریت محتوای هوشمند لازم است.', 'ai-content-manager' ) ); }
        if ( ! $this->repository->lock_item( $item_id ) ) { return $this->error( __( 'این آیتم در حال پردازش است؛ دوباره تلاش کنید.', 'ai-content-manager' ) ); }
        try {
            $context = $this->context( $item_id );
            if ( is_wp_error( $context ) ) {
                // This error is reached only after validating the linked draft and its permissions.
                if ( 'acm_yoast_inactive' === $context->get_error_code() ) {
                    $item = $this->repository->get( $item_id );
                    $this->repository->record_seo_result( $item_id, (int) $item['post_id'], array_fill_keys( array_keys( Yoast::FIELDS ), 'error' ), $automatic );
                }
                return $context;
            }
            $fields = array();
            foreach ( Yoast::FIELDS as $field => $key ) {
                $choice = $automatic ? 'fill' : ( $choices[ $field ] ?? 'skip' );
                if ( ! in_array( $choice, array( 'fill', 'overwrite' ), true ) ) { $fields[ $field ] = 'skipped'; continue; }
                $value = $this->yoast->validate( $field, $context['output'][ $field ] ?? null );
                if ( is_wp_error( $value ) ) { $fields[ $field ] = 'invalid'; continue; }
                try {
                    $fresh = $this->context( $item_id );
                    if ( is_wp_error( $fresh ) || $fresh['post_id'] !== $context['post_id'] || $fresh['output'] !== $context['output'] ) { $fields[ $field ] = 'conflict'; continue; }
                    $current = $this->yoast->read( $context['post_id'], $field );
                    if ( ! is_string( $current ) ) { $fields[ $field ] = 'error'; continue; }
                    $signature = $signatures[ $field ] ?? null;
                    $receipt = is_string( $signature ) ? 'acm_seo_applied_' . hash( 'sha256', $signature ) : '';
                    if ( ! $automatic && ( ! is_string( $signatures[ $field ] ?? null ) ||
                        ! hash_equals( $this->signature( $context, $field, $current, $context['output'][ $field ] ), $signatures[ $field ] ) ) ) {
                        // Recognize this user's identical successful POST without masking new edits.
                        if ( $receipt && $current === $value && get_transient( $receipt ) === hash( 'sha256', $value ) ) { $fields[ $field ] = 'equal'; continue; }
                        $fields[ $field ] = 'conflict'; continue;
                    }
                    if ( $current === $value ) { $fields[ $field ] = 'equal'; continue; }
                    if ( '' !== $current && 'overwrite' !== $choice ) { $fields[ $field ] = 'preserved'; continue; }
                    $written = $this->yoast->write( $context['post_id'], $field, $value );
                    $after = $this->yoast->read( $context['post_id'], $field );
                    $fields[ $field ] = ( true === $written || ( is_int( $written ) && $written > 0 ) ) && $after === $value ? 'applied' : 'error';
                    if ( ! $automatic && 'applied' === $fields[ $field ] ) { set_transient( $receipt, hash( 'sha256', $value ), 86400 ); }
                } catch ( \Throwable $error ) { $fields[ $field ] = 'error'; }
            }
            $result = array( 'post_id' => $context['post_id'], 'fields' => $fields, 'review' => (bool) array_intersect( $fields, array( 'invalid', 'error', 'conflict' ) ) );
            $logged = $this->repository->record_seo_result( $item_id, $context['post_id'], $fields, $automatic );
            if ( is_wp_error( $logged ) ) { $result['review'] = true; $result['audit_error'] = __( 'ثبت رویداد سئو انجام نشد؛ مقادیر نوشته را پیش از تلاش دوباره بررسی کنید.', 'ai-content-manager' ); }
            return $result;
        } catch ( \Throwable $error ) { return $this->error( __( 'تکمیل اطلاعات سئو نیازمند بررسی است؛ پیش‌نویس و وضعیت تولید حفظ شدند.', 'ai-content-manager' ) ); }
        finally { $this->repository->unlock_item( $item_id ); }
    }

    private function error( $message ) { return new \WP_Error( 'acm_seo_error', $message ); }
}
