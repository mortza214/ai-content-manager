<?php
namespace AIContentManager\Admin;
use AIContentManager\Services\BudgetGuard;
use AIContentManager\Services\GenerationPolicy;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class UsageDashboard {
    private $guard;
    public function __construct( $repository ) { $this->guard = new BudgetGuard( $repository ); }
    public function render() {
        if ( ! current_user_can( 'manage_ai_content' ) ) { return; }
        $summary = $this->guard->summary();
        echo '<section aria-label="' . esc_attr__( 'خلاصه مصرف OpenAI', 'ai-content-manager' ) . '"><h3>' . esc_html__( 'مصرف OpenAI', 'ai-content-manager' ) . '</h3>';
        if ( is_wp_error( $summary ) ) { echo '<p>' . esc_html( $summary->get_error_message() ) . '</p></section>'; return; }
        $limits = GenerationPolicy::values();
        foreach ( array( 'day' => __( 'امروز', 'ai-content-manager' ), 'month' => __( 'ماه جاری', 'ai-content-manager' ) ) as $period => $label ) {
            $prefix = 'day' === $period ? 'daily' : 'monthly';
            echo '<h4>' . esc_html( $label ) . '</h4><dl>';
            foreach ( array( 'requests' => __( 'درخواست‌ها', 'ai-content-manager' ), 'input_tokens' => __( 'توکن ورودی واقعی', 'ai-content-manager' ),
                'output_tokens' => __( 'توکن خروجی واقعی', 'ai-content-manager' ), 'total_tokens' => __( 'مجموع توکن واقعی', 'ai-content-manager' ),
                'reserved_tokens' => __( 'توکن رزروشده یا نامشخص (تخمینی)', 'ai-content-manager' ), 'unknown_requests' => __( 'درخواست با مصرف نامشخص', 'ai-content-manager' ) ) as $key => $title ) {
                $value = (string) $summary[ $period ][ $key ];
                if ( 'requests' === $key || 'total_tokens' === $key ) {
                    $limit = $limits[ $prefix . ( 'requests' === $key ? '_request_limit' : '_token_limit' ) ];
                    $value .= ' / ' . ( $limit ? $limit : __( 'بدون محدودیت', 'ai-content-manager' ) );
                }
                echo '<dt>' . esc_html( $title ) . '</dt><dd dir="ltr">' . esc_html( $value ) . '</dd>';
            }
            echo '</dl>';
        }
        echo '<p>' . esc_html__( 'مرز روز و ماه بر اساس منطقه زمانی سایت است. بررسی اتصال نیز در تعداد درخواست‌ها حساب می‌شود. مصرف واقعی و رزرو تخمینی جدا نمایش داده می‌شوند؛ کنترل بودجه مجموع هر دو را لحاظ می‌کند. این گزارش درخواست API ارسال نمی‌کند.', 'ai-content-manager' ) . '</p></section>';
    }
}
