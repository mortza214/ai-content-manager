<?php
/** WPML language discovery integration.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Provides active and default languages with a WordPress locale fallback. */
final class Wpml {
    /** Whether WPML is loaded. */
    public function is_available() {
        return defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' );
    }

    /** Return active language codes and display names without hard-coded locales. */
    public function active_languages() {
        if ( $this->is_available() ) {
            $languages = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );
            if ( is_array( $languages ) && $languages ) {
                $available = array();
                foreach ( $languages as $language_code => $language ) {
                    if ( ! is_array( $language ) ) {
                        continue;
                    }

                    $code = isset( $language['language_code'] ) ? sanitize_key( $language['language_code'] ) : sanitize_key( $language_code );
                    if ( '' === $code ) {
                        continue;
                    }

                    $label = '';
                    if ( ! empty( $language['native_name'] ) ) {
                        $label = sanitize_text_field( $language['native_name'] );
                    } elseif ( ! empty( $language['translated_name'] ) ) {
                        $label = sanitize_text_field( $language['translated_name'] );
                    }

                    $available[ $code ] = $this->language_label( $code, '' !== $label ? $label : $code );
                }

                if ( $available ) {
                    return $available;
                }
            }
        }

        $locale = get_option( 'WPLANG' );
        if ( ! is_string( $locale ) || '' === $locale ) {
            $locale = get_locale();
        }
        $locale = sanitize_text_field( $locale );

        $available = array( $locale => $this->language_label( $locale, $locale ) );
        foreach ( array( 'fa', 'en', 'ar' ) as $code ) {
            if ( $code !== strtolower( substr( $locale, 0, 2 ) ) ) {
                $available[ $code ] = $this->language_label( $code, $code );
            }
        }
        return $available;
    }

    private function language_label( $code, $fallback ) {
        $labels = array( 'fa' => __( 'فارسی', 'ai-content-manager' ), 'en' => __( 'انگلیسی', 'ai-content-manager' ), 'ar' => __( 'عربی', 'ai-content-manager' ) );
        return $labels[ strtolower( substr( $code, 0, 2 ) ) ] ?? $fallback;
    }

    /** Assign only the original article language, then read it back; never translate. */
    public function assign_language( $post_id, $post_type, $language ) {
        if ( ! $this->is_available() ) { return true; }
        $languages = $this->active_languages();
        $code = isset( $languages[ $language ] ) ? $language : strtolower( substr( $language, 0, 2 ) );
        if ( ! isset( $languages[ $code ] ) ) { return new \WP_Error( 'acm_wpml_language', __( 'پیش‌نویس مرتبط شد اما زبان آیتم در WPML فعال نیست؛ پس از اصلاح زبان، همین عملیات را دوباره اجرا کنید.', 'ai-content-manager' ) ); }
        try {
            $element_type = apply_filters( 'wpml_element_type', $post_type );
            $trid = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );
            do_action( 'wpml_set_element_language_details', array( 'element_id' => $post_id, 'element_type' => $element_type,
                'trid' => $trid ?: false, 'language_code' => $code, 'source_language_code' => null, 'check_duplicates' => true ) );
            $assigned = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $post_id, 'element_type' => $post_type ) );
            if ( $assigned === $code ) { return true; }
        } catch ( \Throwable $error ) { /* Safe retry keeps the existing draft. */ }
        return new \WP_Error( 'acm_wpml_language', __( 'پیش‌نویس ساخته و مرتبط شد اما تأیید زبان WPML ممکن نشد؛ اجرای دوباره همان پیش‌نویس را تکمیل می‌کند.', 'ai-content-manager' ) );
    }

    /** Return WPML's default language or the first available locale. */
    public function default_language() {
        $languages = $this->active_languages();
        if ( $this->is_available() ) {
            $default = apply_filters( 'wpml_default_language', null );
            if ( is_string( $default ) && isset( $languages[ $default ] ) ) {
                return $default;
            }
        }

        $keys = array_keys( $languages );
        return reset( $keys );
    }
}
