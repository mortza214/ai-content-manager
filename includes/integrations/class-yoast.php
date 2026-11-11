<?php
/** Yoast SEO metadata integration.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Uses native metadata hooks; Yoast's watcher maintains indexables at shutdown. */
final class Yoast {
    const FIELDS = array( 'primary_keyword' => 'focuskw', 'suggested_meta_description' => 'metadesc' );

    public function available() {
        return defined( 'WPSEO_VERSION' ) && is_callable( array( 'WPSEO_Meta', 'get_value' ) ) && is_callable( array( 'WPSEO_Meta', 'set_value' ) );
    }

    public static function labels() {
        return array( 'primary_keyword' => __( 'عبارت کلیدی اصلی', 'ai-content-manager' ), 'suggested_meta_description' => __( 'توضیحات متا', 'ai-content-manager' ) );
    }

    public static function statuses() {
        return array( 'applied' => __( 'اعمال شد', 'ai-content-manager' ), 'equal' => __( 'از قبل برابر بود', 'ai-content-manager' ),
            'preserved' => __( 'مقدار موجود حفظ شد', 'ai-content-manager' ), 'invalid' => __( 'ورودی نامعتبر بود', 'ai-content-manager' ),
            'error' => __( 'خطا', 'ai-content-manager' ), 'conflict' => __( 'تعارض؛ مقدار یا پیشنهاد پس از نمایش مقایسه تغییر کرده است.', 'ai-content-manager' ),
            'skipped' => __( 'انتخاب نشد', 'ai-content-manager' ), 'pending' => __( 'فیلد خالی تکمیل می‌شود', 'ai-content-manager' ) );
    }

    /** Reject unsafe text rather than silently rewriting it. Count Unicode code points. */
    public function validate( $field, $value ) {
        if ( ! isset( self::FIELDS[ $field ] ) || ! is_string( $value ) || ! preg_match( '//u', $value ) ||
            preg_match( '/[\x00-\x1F\x7F<>]/u', $value ) || wp_strip_all_tags( $value ) !== $value ||
            preg_match( '/[<>]/u', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
            return new \WP_Error( 'acm_seo_invalid', __( 'فقط متن ساده و معتبر، بدون HTML یا نویسه کنترلی مجاز است.', 'ai-content-manager' ) );
        }
        $value = trim( $value );
        $length = preg_match_all( '/./us', $value );
        if ( '' === $value || ! preg_match( '/[\p{L}\p{N}]/u', $value ) || ( 'primary_keyword' === $field && preg_match( '/[,،;\x{2028}\x{2029}]/u', $value ) ) ) {
            return new \WP_Error( 'acm_seo_invalid', __( 'ورودی خالی یا نامعتبر است؛ عبارت اصلی باید یک عبارت متنی باشد. هیچ مقداری پاک نمی‌شود.', 'ai-content-manager' ) );
        }
        if ( $length > ( 'primary_keyword' === $field ? 191 : 1000 ) ) {
            return new \WP_Error( 'acm_seo_length', __( 'متن بیش از حد طولانی است؛ عبارت اصلی حداکثر ۱۹۱ و توضیحات متا حداکثر ۱۰۰۰ نویسه می‌پذیرد. متن را بازبینی کنید؛ برش خودکار انجام نمی‌شود.', 'ai-content-manager' ) );
        }
        return $value;
    }

    public function guidance( $field, $value ) {
        $length = preg_match_all( '/./us', $value );
        return 'suggested_meta_description' === $field && ( $length < 120 || $length > 160 )
            ? __( 'طول پیشنهادی توضیحات متا معمولاً ۱۲۰ تا ۱۶۰ نویسه است؛ نمایش به عرض متن و موتور جستجو بستگی دارد. متن کامل حفظ می‌شود؛ در صورت نیاز پیشنهاد ذخیره‌شده را بازبینی کنید.', 'ai-content-manager' ) : '';
    }

    public function read( $post_id, $field ) {
        wp_cache_delete( $post_id, 'post_meta' );
        return \WPSEO_Meta::get_value( self::FIELDS[ $field ], $post_id );
    }

    public function write( $post_id, $field, $value ) {
        // set_value already applies wp_slash; native meta hooks notify Yoast's watcher.
        return \WPSEO_Meta::set_value( self::FIELDS[ $field ], $value, $post_id );
    }
}
