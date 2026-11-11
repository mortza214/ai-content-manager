<?php
namespace AIContentManager\Admin;

use AIContentManager\Integrations\Yoast;
use AIContentManager\Services\QueueSeo;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Comparison and independent, nonce-protected POST commands. */
final class QueueSeoPage {
    private $seo;
    private $page;
    public function __construct( $repository, $page ) {
        $this->seo = new QueueSeo( $repository );
        $this->page = $page;
        add_action( 'admin_post_acm_queue_seo_apply', array( $this, 'handle' ) );
    }

    public function render() {
        if ( ! isset( $_GET['seo_item'] ) ) { return; }
        $id = isset( $_GET['seo_item'] ) && is_scalar( $_GET['seo_item'] ) ? filter_var( $_GET['seo_item'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
        if ( ! current_user_can( 'manage_ai_content' ) ) { $this->fail( __( 'مجوز بررسی اطلاعات سئو را ندارید.', 'ai-content-manager' ), 403 ); }
        if ( ! $id ) { $this->fail( __( 'شناسه آیتم معتبر نیست.', 'ai-content-manager' ), 400 ); }
        if ( ! $this->valid_nonce( $_GET, 'acm_seo_compare_' . $id ) ) { $this->fail( __( 'اعتبار پیوند مقایسه پایان یافته یا نامعتبر است؛ از صف دوباره وارد شوید.', 'ai-content-manager' ), 403 ); }
        $comparison = $this->seo->compare( $id );
        echo '<div class="wrap"><h2>' . esc_html__( 'بررسی و اعمال اطلاعات سئو', 'ai-content-manager' ) . '</h2>';
        if ( is_wp_error( $comparison ) ) { echo '<p role="alert">' . esc_html( $comparison->get_error_message() ) . '</p>' . $this->back_link() . '</div>'; return; }
        echo '<p>' . esc_html( sprintf( __( 'آیتم #%1$d؛ پیش‌نویس مرتبط #%2$d', 'ai-content-manager' ), $id, $comparison['post_id'] ) ) . '</p>';
        echo '<p>' . esc_html__( 'نمایش مقایسه تغییری ذخیره نمی‌کند. پیش‌فرض، تکمیل فیلد خالی است. بازنویسی را فقط برای فیلد موردنظر صریحاً انتخاب کنید؛ مقدار فعلی با پیشنهاد نمایش‌داده‌شده جایگزین می‌شود.', 'ai-content-manager' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="acm_queue_seo_apply"><input type="hidden" name="item_id" value="' . esc_attr( $id ) . '">';
        wp_nonce_field( 'acm_seo_apply_' . $id );
        foreach ( $this->page->return_args( $this->navigation_input() ) as $key => $value ) {
            echo '<input type="hidden" name="return_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
        }
        $statuses = Yoast::statuses();
        foreach ( Yoast::labels() as $field => $label ) {
            $data = $comparison['fields'][ $field ];
            echo '<fieldset class="acm-seo-field"><legend><strong>' . esc_html( $label ) . '</strong></legend>';
            echo '<p><strong>' . esc_html__( 'مقدار فعلی Yoast: ', 'ai-content-manager' ) . '</strong><span class="acm-seo-value">' . esc_html( '' === $data['current'] ? __( 'خالی', 'ai-content-manager' ) : $data['current'] ) . '</span></p>';
            echo '<p><strong>' . esc_html__( 'مقدار پیشنهادی خروجی (جایگزین): ', 'ai-content-manager' ) . '</strong><span class="acm-seo-value">' . esc_html( $data['proposed'] ) . '</span></p>';
            echo '<p>' . esc_html( $statuses[ $data['status'] ] ) . '</p>';
            if ( $data['message'] ) { echo '<p class="description">' . esc_html( $data['message'] ) . '</p>'; }
            if ( 'primary_keyword' === $field && 'error' !== $data['status'] ) {
                $original = $comparison['original_keyword'];
                if ( is_string( $original ) && $original !== $data['proposed'] ) {
                    echo '<p><strong>' . esc_html__( 'پیشنهاد اصلی تولیدشده (سابقه): ', 'ai-content-manager' ) . '</strong>' . esc_html( $original ) . '</p>';
                }
                echo '<label for="acm-seo-keyword-edit">' . esc_html__( 'اصلاح پیشنهاد عبارت کلیدی اصلی (فقط یک عبارت)', 'ai-content-manager' ) . '</label>';
                echo '<textarea id="acm-seo-keyword-edit" name="primary_keyword_proposal" rows="2" class="large-text" aria-describedby="acm-seo-keyword-help">' . esc_html( $data['proposed'] ) . '</textarea>';
                echo '<p id="acm-seo-keyword-help" class="description">' . esc_html__( 'عبارت چندکلمه‌ای مجاز است؛ ویرگول فارسی، انگلیسی و خط جدید مجاز نیست. ابتدا پیشنهاد اصلاح‌شده را ذخیره کنید؛ سپس در مقایسه تازه، اعمال آن را انتخاب کنید. ذخیره پیشنهاد، Yoast یا متن مقاله را تغییر نمی‌دهد.', 'ai-content-manager' ) . '</p>';
                echo '<button type="submit" class="button" name="seo_command" value="save_keyword">' . esc_html__( 'ذخیره پیشنهاد اصلاح‌شده و بررسی دوباره', 'ai-content-manager' ) . '</button>';
            }
            if ( 'error' !== $data['status'] ) {
                echo '<input type="hidden" name="signatures[' . esc_attr( $field ) . ']" value="' . esc_attr( $data['signature'] ) . '">';
            }
            // Invalid/read-failed fields must reach the server unless the user explicitly skips them.
            echo '<label for="acm-seo-' . esc_attr( $field ) . '">' . esc_html__( 'عملیات این فیلد', 'ai-content-manager' ) . '</label> <select id="acm-seo-' . esc_attr( $field ) . '" name="fields[' . esc_attr( $field ) . ']">';
            echo '<option value="fill" selected>' . esc_html__( 'فقط اگر خالی است تکمیل شود', 'ai-content-manager' ) . '</option><option value="skip">' . esc_html__( 'اعمال نشود', 'ai-content-manager' ) . '</option><option value="overwrite"' . ( in_array( $data['status'], array( 'invalid', 'error' ), true ) ? ' disabled' : '' ) . '>' . esc_html__( 'بازنویسی صریح مقدار فعلی با پیشنهاد بالا', 'ai-content-manager' ) . '</option></select>';
            echo '</fieldset>';
        }
        echo '<p class="description">' . esc_html__( 'تغییر هر مقدار پس از نمایش مقایسه، عملیات همان فیلد را متوقف می‌کند. قفل افزونه، ویرایشگر عادی وردپرس را قفل نمی‌کند؛ در فاصله کوتاه بررسی تا ذخیره همچنان امکان رقابت وجود دارد.', 'ai-content-manager' ) . '</p>';
        submit_button( __( 'اعمال فیلدهای انتخاب‌شده', 'ai-content-manager' ), 'secondary', 'apply_seo', false );
        echo '</form>' . $this->back_link() . '</div>';
    }

    private function navigation_input() {
        $input = array();
        foreach ( array( 'status', 'language', 'content_type', 'paged' ) as $key ) { $input[ 'return_' . $key ] = $_GET[ $key ] ?? ''; }
        return $input;
    }

    public static function details( $result ) {
        if ( is_wp_error( $result ) ) { return array( $result->get_error_message() ); }
        $details = array(); $labels = Yoast::labels(); $statuses = Yoast::statuses();
        foreach ( $result['fields'] as $field => $status ) { $details[] = $labels[ $field ] . ': ' . $statuses[ $status ]; }
        if ( ! empty( $result['audit_error'] ) ) { $details[] = $result['audit_error']; }
        return $details;
    }

    public function handle() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_ai_content' ) ) {
            $this->fail( __( 'اجرای عملیات سئو فقط با ارسال فرم و مجوز مدیریت محتوا مجاز است.', 'ai-content-manager' ), 403 );
        }
        $id = isset( $_POST['item_id'] ) && is_scalar( $_POST['item_id'] ) ? filter_var( $_POST['item_id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
        if ( ! $id ) { $this->fail( __( 'شناسه آیتم معتبر نیست.', 'ai-content-manager' ), 400 ); }
        if ( ! $this->valid_nonce( $_POST, 'acm_seo_apply_' . $id ) ) { $this->fail( __( 'اعتبار فرم پایان یافته یا نامعتبر است؛ مقایسه را از صف دوباره باز کنید.', 'ai-content-manager' ), 403 ); }
        if ( headers_sent() ) { $this->fail( __( 'به‌دلیل ارسال زودهنگام خروجی، عملیات آغاز نشد؛ از صف دوباره تلاش کنید.', 'ai-content-manager' ), 500 ); }
        $choices = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array();
        $signatures = isset( $_POST['signatures'] ) && is_array( $_POST['signatures'] ) ? wp_unslash( $_POST['signatures'] ) : array();
        $command = $_POST['seo_command'] ?? 'apply';
        if ( ! in_array( $command, array( 'apply', 'save_keyword' ), true ) ) { $this->fail( __( 'عملیات انتخاب‌شده معتبر نیست.', 'ai-content-manager' ), 400 ); }
        try {
            if ( 'save_keyword' === $command ) {
                $proposal = isset( $_POST['primary_keyword_proposal'] ) && is_string( $_POST['primary_keyword_proposal'] ) ? wp_unslash( $_POST['primary_keyword_proposal'] ) : null;
                $result = $this->seo->save_primary_keyword( $id, $proposal, $signatures['primary_keyword'] ?? null );
                $review = is_wp_error( $result );
                $notice = array( 'message' => $review ? __( 'پیشنهاد اصلاح‌شده ذخیره نشد؛ اطلاعات سئو را دوباره بررسی کنید.', 'ai-content-manager' ) : __( 'پیشنهاد اصلاح‌شده ذخیره شد؛ برای اعمال در Yoast، مقایسه تازه و انتخاب بازنویسی را بررسی کنید.', 'ai-content-manager' ),
                    'error' => $review, 'details' => $review ? array( $result->get_error_message() ) : array() );
            } else {
                // Browser edits are never applied here: only the stored, signed proposal is used.
                $result = $this->seo->apply( $id, $choices, $signatures );
                $review = is_wp_error( $result ) || ! empty( $result['review'] );
                $notice = array( 'message' => $review ? __( 'نتیجه اطلاعات سئو نیازمند بررسی است؛ پیش‌نویس حفظ شد.', 'ai-content-manager' ) : __( 'بررسی و اعمال اطلاعات سئو انجام شد.', 'ai-content-manager' ),
                    'error' => is_wp_error( $result ), 'warning' => $review, 'details' => self::details( $result ) );
            }
            $notice_key = 'acm_queue_notice_' . get_current_user_id();
            // WordPress may return false for an unchanged transient, not just a storage failure.
            $stored = set_transient( $notice_key, $notice, 600 ) || get_transient( $notice_key ) === $notice;
            $args = $this->page->return_args( $_POST );
            $args['seo_item'] = $id;
            // wp_nonce_url() escapes for HTML; a Location header must contain raw query separators.
            $args['_wpnonce'] = wp_create_nonce( 'acm_seo_compare_' . $id );
            $url = AdminPage::url( 'queue', $args );
        } catch ( \Throwable $error ) {
            $this->fail( __( 'پردازش یا ثبت نتیجه سئو کامل نشد؛ پیش از تلاش دوباره مقادیر Yoast و مقایسه را بررسی کنید.', 'ai-content-manager' ), 500 );
        }
        if ( ! $stored || headers_sent() || ! wp_safe_redirect( $url, 303 ) ) {
            $this->fail( __( 'بازگشت خودکار یا ثبت پیام نتیجه انجام نشد؛ پیش از ارسال دوباره، مقایسه و مقادیر Yoast را بررسی کنید.', 'ai-content-manager' ), 500, array_merge( array( $notice['message'] ), $notice['details'] ) );
        }
        exit;
    }

    private function valid_nonce( $source, $action ) {
        return isset( $source['_wpnonce'] ) && is_string( $source['_wpnonce'] ) && wp_verify_nonce( wp_unslash( $source['_wpnonce'] ), $action );
    }

    private function back_link() {
        return '<p><a href="' . esc_url( AdminPage::url( 'queue' ) ) . '">' . esc_html__( 'بازگشت به مدیریت صف', 'ai-content-manager' ) . '</a></p>';
    }

    private function fail( $message, $status, $details = array() ) {
        $html = '<p>' . esc_html( $message ) . '</p>';
        foreach ( $details as $detail ) { $html .= '<p>' . esc_html( $detail ) . '</p>'; }
        wp_die( $html . $this->back_link(), esc_html__( 'بررسی اطلاعات سئو', 'ai-content-manager' ), array( 'response' => $status ) );
    }
}
