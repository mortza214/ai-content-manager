<?php
namespace AIContentManager\Services;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class QueueTrash {
    private $repository;
    public function __construct( $repository ) { $this->repository = $repository; }
    public function apply( $ids, $restore = false ) {
        if ( ! current_user_can( 'manage_ai_content' ) || ! is_array( $ids ) || ! $ids || count( $ids ) > 100 ) {
            return new \WP_Error( 'acm_forbidden', __( 'مجوز یا تعداد شناسه‌های عملیات معتبر نیست؛ حداکثر ۱۰۰ آیتم انتخاب کنید.', 'ai-content-manager' ) );
        }
        $result = array( 'success' => 0, 'rejected' => 0, 'failed' => 0, 'details' => array() );
        $seen = array();
        foreach ( $ids as $raw_id ) {
            $id = is_scalar( $raw_id ) ? filter_var( $raw_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
            if ( false === $id ) { $result['rejected']++; $result['details'][] = __( 'شناسه نامعتبر رد شد.', 'ai-content-manager' ); continue; }
            if ( isset( $seen[ $id ] ) ) { continue; }
            $seen[ $id ] = true;
            try { $changed = $this->repository->change_trash( $id, $restore ); }
            catch ( \Throwable $error ) { $changed = new \WP_Error( 'acm_storage_failed', __( 'خطای ذخیره‌سازی؛ آیتم نیازمند بررسی است.', 'ai-content-manager' ) ); }
            $key = true === $changed ? 'success' : ( 'acm_action_rejected' === $changed->get_error_code() ? 'rejected' : 'failed' );
            $result[ $key ]++;
            $result['details'][] = sprintf( __( 'آیتم #%d: %s', 'ai-content-manager' ), $id, true === $changed ? __( 'انجام شد', 'ai-content-manager' ) : $changed->get_error_message() );
        }
        return $result;
    }
}
