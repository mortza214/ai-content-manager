<?php
/** OpenAI connection test AJAX handler.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Admin;

use AIContentManager\Integrations\OpenAi;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Handles authorized admin-only connection checks. */
final class OpenAiConnection {
    /** Register the authenticated AJAX action. */
    public function __construct() {
        add_action( 'wp_ajax_acm_test_openai_connection', array( $this, 'handle' ) );
    }

    /** Validate the request and return a safe status message. */
    public function handle() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_send_json_error( array( 'message' => __( 'این عملیات فقط با درخواست POST مجاز است.', 'ai-content-manager' ) ), 405 ); }
        check_ajax_referer( 'acm_test_openai_connection', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'manage_ai_content' ) ) {
            wp_send_json_error( array( 'message' => __( 'اجازه بررسی این اتصال را ندارید.', 'ai-content-manager' ) ), 403 );
        }

        $model = isset( $_POST['model'] ) && is_string( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
        $result = ( new OpenAi() )->test_connection( $model );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        wp_send_json_success( array( 'message' => __( 'اتصال با موفقیت برقرار شد.', 'ai-content-manager' ) ) );
    }
}
