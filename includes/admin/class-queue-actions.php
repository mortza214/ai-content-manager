<?php
namespace AIContentManager\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** POST-only command controller; no work runs during page or preview rendering. */
final class QueueActions {
    private $drafts;
    private $trash;
    private $page;
    public function __construct( $drafts, $trash, $page ) {
        $this->drafts = $drafts; $this->trash = $trash; $this->page = $page;
        add_action( 'admin_post_acm_queue_action', array( $this, 'handle' ) );
    }
    public function handle() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_ai_content' ) ) {
            wp_die( esc_html__( 'مجوز اجرای عملیات صف را ندارید.', 'ai-content-manager' ), '', array( 'response' => 403 ) );
        }
        $operation = isset( $_POST['queue_action'] ) && is_string( $_POST['queue_action'] ) ? sanitize_key( wp_unslash( $_POST['queue_action'] ) ) : '';
        if ( ! in_array( $operation, array( 'draft', 'trash', 'restore' ), true ) ) { wp_die( esc_html__( 'عملیات معتبر نیست.', 'ai-content-manager' ), '', array( 'response' => 400 ) ); }
        $bulk = ! empty( $_POST['bulk'] );
        if ( $bulk && 'draft' === $operation ) { wp_die( esc_html__( 'ایجاد پیش‌نویس فقط به‌صورت تکی مجاز است.', 'ai-content-manager' ), '', array( 'response' => 400 ) ); }
        check_admin_referer( 'acm_queue_' . ( $bulk ? 'bulk' : $operation ) );
        if ( 'draft' === $operation ) {
            $id = isset( $_POST['item_id'] ) && is_scalar( $_POST['item_id'] ) ? filter_var( $_POST['item_id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
            $result = $id ? $this->drafts->create( $id ) : new \WP_Error( 'acm_invalid_id', __( 'شناسه آیتم معتبر نیست.', 'ai-content-manager' ) );
            $notice = array( 'message' => is_wp_error( $result ) ? $result->get_error_message() : sprintf( __( 'پیش‌نویس #%d آماده است؛ انتشار انجام نشد.', 'ai-content-manager' ), $result ), 'error' => is_wp_error( $result ) );
        } else {
            $ids = $bulk ? ( isset( $_POST['item_ids'] ) && is_array( $_POST['item_ids'] ) ? wp_unslash( $_POST['item_ids'] ) : array() ) : array( $_POST['item_id'] ?? '' );
            $result = $this->trash->apply( $ids, 'restore' === $operation );
            $notice = is_wp_error( $result ) ? array( 'message' => $result->get_error_message(), 'error' => true ) : array( 'message' => sprintf( __( 'موفق: %d؛ ردشده: %d؛ ناموفق: %d', 'ai-content-manager' ), $result['success'], $result['rejected'], $result['failed'] ), 'details' => $result['details'], 'error' => $result['failed'] > 0 );
        }
        set_transient( 'acm_queue_notice_' . get_current_user_id(), $notice, 600 );
        $args = $this->page->return_args( $_POST );
        wp_safe_redirect( AdminPage::url( 'queue', $args ) );
        exit;
    }
}
