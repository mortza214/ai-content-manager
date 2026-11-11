<?php
namespace AIContentManager\Admin;

use AIContentManager\Services\QueueCategory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Registered at admin bootstrap, independently of rendering the queue tab. */
final class QueueCategoryPage {
    private $categories;
    private $page;
    public function __construct( $repository, $page, $wpml ) {
        $this->categories = new QueueCategory( $repository, $wpml );
        $this->page = $page;
        add_action( 'admin_post_acm_queue_category_apply', array( $this, 'handle' ) );
    }

    private function back_link() {
        return '<p><a href="' . esc_url( AdminPage::url( 'queue' ) ) . '">' . esc_html__( 'بازگشت به مدیریت صف', 'ai-content-manager' ) . '</a></p>';
    }

    private function fail( $message, $status ) {
        wp_die( '<p>' . esc_html( $message ) . '</p>' . $this->back_link(), esc_html__( 'بررسی دسته‌بندی', 'ai-content-manager' ), array( 'response' => $status ) );
    }

    private function valid_nonce( $source, $action ) {
        return is_string( $source['_wpnonce'] ?? null ) && wp_verify_nonce( wp_unslash( $source['_wpnonce'] ), $action );
    }

    private function id( $source, $key ) {
        return isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? filter_var( $source[ $key ], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
    }

    public function render() {
        if ( ! isset( $_GET['category_item'] ) ) { return; }
        $id = $this->id( $_GET, 'category_item' );
        if ( ! current_user_can( 'manage_ai_content' ) ) { $this->fail( __( 'مجوز بررسی دسته‌بندی را ندارید.', 'ai-content-manager' ), 403 ); }
        if ( ! $id || ! $this->valid_nonce( $_GET, 'acm_category_compare_' . $id ) ) { $this->fail( __( 'شناسه یا اعتبار پیوند دسته‌بندی معتبر نیست؛ از صف دوباره وارد شوید.', 'ai-content-manager' ), 403 ); }
        $comparison = $this->categories->compare( $id );
        echo '<div class="wrap"><h2>' . esc_html__( 'بررسی و اصلاح دسته‌بندی', 'ai-content-manager' ) . '</h2>';
        if ( is_wp_error( $comparison ) ) { echo '<p role="alert">' . esc_html( $comparison->get_error_message() ) . '</p>' . $this->back_link() . '</div>'; return; }
        echo '<p>' . esc_html( sprintf( __( 'آیتم #%1$d؛ پیش‌نویس مرتبط #%2$d', 'ai-content-manager' ), $id, $comparison['post_id'] ) ) . '</p>';
        echo '<p><strong>' . esc_html__( 'دسته‌بندی انتخاب‌شده در صف: ', 'ai-content-manager' ) . '</strong>' . esc_html( $comparison['selected'] ) . '</p>';
        echo '<p><strong>' . esc_html__( 'دسته‌بندی فعلی نوشته: ', 'ai-content-manager' ) . '</strong>' . esc_html( $comparison['current'] ? implode( '، ', $comparison['current'] ) : __( 'بدون دسته‌بندی ثبت‌شده', 'ai-content-manager' ) ) . '</p>';
        if ( $comparison['target'] ) { echo '<p><strong>' . esc_html__( 'انتخاب متناظر با زبان محتوا برای اعمال: ', 'ai-content-manager' ) . '</strong>' . esc_html( $comparison['target'] ) . '</p>'; }
        if ( $comparison['message'] ) { echo '<p role="alert">' . esc_html( $comparison['message'] ) . '</p>'; }
        elseif ( $comparison['equal'] ) { echo '<p>' . esc_html__( 'دسته‌بندی فعلی از قبل با انتخاب صف برابر است؛ ذخیره اضافی انجام نمی‌شود.', 'ai-content-manager' ) . '</p>'; }
        if ( $comparison['signature'] ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="acm_queue_category_apply"><input type="hidden" name="item_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="signature" value="' . esc_attr( $comparison['signature'] ) . '">';
            wp_nonce_field( 'acm_category_apply_' . $id );
            echo '<p><label><input type="checkbox" name="confirm_replace" value="1" required> ' . esc_html__( 'صریحاً تأیید می‌کنم دسته‌بندی‌های فعلی این رده‌بندی با انتخاب صف جایگزین شوند؛ تغییر دستی آن‌ها نیز جایگزین می‌شود.', 'ai-content-manager' ) . '</label></p>';
            echo '<p>' . esc_html__( 'این عملیات فقط دسته‌بندی را تغییر می‌دهد. تغییر پس از نمایش مقایسه، عملیات را متوقف می‌کند. ویرایشگر عادی وردپرس قفل نمی‌شود و فاصله کوتاه خواندن تا نوشتن همچنان امکان رقابت دارد.', 'ai-content-manager' ) . '</p>';
            submit_button( __( 'اعمال دسته‌بندی با تأیید صریح', 'ai-content-manager' ), 'secondary', 'apply_category', false );
            echo '</form>';
        }
        echo $this->back_link() . '</div>';
    }

    public function handle() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_ai_content' ) ) { $this->fail( __( 'اصلاح دسته‌بندی فقط با ارسال فرم و مجوز مدیریت محتوا مجاز است.', 'ai-content-manager' ), 403 ); }
        $id = $this->id( $_POST, 'item_id' );
        if ( ! $id || ! $this->valid_nonce( $_POST, 'acm_category_apply_' . $id ) ) { $this->fail( __( 'شناسه یا اعتبار فرم دسته‌بندی معتبر نیست؛ مقایسه را از صف دوباره باز کنید.', 'ai-content-manager' ), 403 ); }
        if ( headers_sent() ) { $this->fail( __( 'خروجی پیش از header ارسال شده است؛ اصلاح دسته‌بندی آغاز نشد.', 'ai-content-manager' ), 500 ); }
        try {
            $result = $this->categories->apply( $id, $_POST['signature'] ?? null, '1' === ( $_POST['confirm_replace'] ?? null ) );
            $notice = array( 'error' => is_wp_error( $result ), 'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'دسته‌بندی پیش‌نویس بررسی و تأیید شد؛ متن، زبان و اطلاعات سئو تغییر نکردند.', 'ai-content-manager' ) );
            $key = 'acm_queue_notice_' . get_current_user_id();
            $stored = set_transient( $key, $notice, 600 ) || get_transient( $key ) === $notice;
            $url = AdminPage::url( 'queue', array( 'category_item' => $id, '_wpnonce' => wp_create_nonce( 'acm_category_compare_' . $id ) ) );
        } catch ( \Throwable $error ) { $this->fail( __( 'پردازش نتیجه دسته‌بندی کامل نشد؛ پیش از تلاش دوباره همان پیش‌نویس را بررسی کنید.', 'ai-content-manager' ), 500 ); }
        if ( ! $stored || headers_sent() || ! wp_safe_redirect( $url, 303 ) ) { $this->fail( __( 'بازگشت یا ثبت پیام نتیجه انجام نشد؛ پیش‌نویس را پیش از ارسال دوباره بررسی کنید. ', 'ai-content-manager' ) . $notice['message'], 500 ); }
        exit;
    }
}
