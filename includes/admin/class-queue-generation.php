<?php
namespace AIContentManager\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class QueueGeneration {
    private $generator;
    public function __construct( $generator ) {
        $this->generator = $generator;
        add_action( 'wp_ajax_acm_generate_item', array( $this, 'generate' ) );
    }
    public function enqueue( $hook ) {
        wp_enqueue_script( 'acm-queue-generation', ACM_PLUGIN_URL . 'assets/js/queue-generation.js', array(), ACM_VERSION, true );
        wp_localize_script( 'acm-queue-generation', 'acmQueueGeneration', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'acm_generate_item' ),
            'strings' => array(
                'generating' => __( 'در حال تولید؛ لطفاً منتظر بمانید…', 'ai-content-manager' ),
                'failure' => __( 'درخواست ناموفق بود؛ صفحه را تازه‌سازی کنید.', 'ai-content-manager' ),
                'connectionError' => __( 'ارتباط قطع شد؛ برای مشاهده وضعیت، صفحه را تازه‌سازی کنید.', 'ai-content-manager' ),
            ),
        ) );
    }
    public function generate() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_ai_content' ) ) {
            wp_send_json_error( array( 'message' => __( 'اجازه تولید محتوا ندارید.', 'ai-content-manager' ) ), 403 );
        }
        check_ajax_referer( 'acm_generate_item', 'nonce' );
        $item_id = isset( $_POST['item_id'] ) && is_scalar( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
        if ( ! $item_id ) { wp_send_json_error( array( 'message' => __( 'شناسه آیتم معتبر نیست.', 'ai-content-manager' ) ), 400 ); }
        $result = $this->generator->generate( $item_id, get_current_user_id() );
        if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
        wp_send_json_success( array( 'message' => __( 'محتوا تولید و در صف ذخیره شد.', 'ai-content-manager' ) ) );
    }
}
