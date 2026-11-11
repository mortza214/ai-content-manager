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

    public function language_code( $language ) {
        $languages = $this->active_languages();
        $code = isset( $languages[ $language ] ) ? $language : strtolower( substr( $language, 0, 2 ) );
        return isset( $languages[ $code ] ) ? $code : null;
    }

    /** Resolve an existing translation only; taxonomy language IDs are term_taxonomy_id. */
    public function category_for_language( $term, $language ) {
        if ( ! $this->is_available() || ! apply_filters( 'wpml_is_translated_taxonomy', false, $term->taxonomy ) ) { return $term; }
        $code = $this->language_code( $language );
        if ( ! $code ) { return new \WP_Error( 'acm_category_language', __( 'زبان انتخاب‌شده در WPML فعال نیست.', 'ai-content-manager' ) ); }
        $id = apply_filters( 'wpml_object_id', (int) $term->term_id, $term->taxonomy, false, $code );
        $translated = ( is_int( $id ) || is_string( $id ) ) && preg_match( '/^[1-9][0-9]*$/D', (string) $id ) ? get_term( (int) $id, $term->taxonomy ) : null;
        if ( ! $translated || is_wp_error( $translated ) || $translated->taxonomy !== $term->taxonomy || (int) $translated->term_id !== (int) $id ||
            empty( $translated->term_taxonomy_id ) || $code !== apply_filters( 'wpml_element_language_code', null,
                array( 'element_id' => (int) $translated->term_taxonomy_id, 'element_type' => $term->taxonomy ) ) ) {
            return new \WP_Error( 'acm_category_translation', __( 'ترجمه معتبر دسته‌بندی برای زبان محتوا پیدا نشد؛ ابتدا ترجمه را در WPML بررسی کنید. دسته جایگزین یا تازه ساخته نمی‌شود.', 'ai-content-manager' ) );
        }
        return $translated;
    }

    /** Scope term queries and restore even on errors; WPML 4.x and 5.x restore differently. */
    public function in_language( $language, $callback ) {
        if ( ! $this->is_available() ) { return call_user_func( $callback ); }
        $previous = apply_filters( 'wpml_current_language', null );
        try {
            do_action( 'wpml_switch_language', $language );
            return call_user_func( $callback );
        } finally {
            $restore = defined( 'ICL_SITEPRESS_VERSION' ) && version_compare( ICL_SITEPRESS_VERSION, '5.0', '<' ) && is_string( $previous ) && '' !== $previous ? $previous : null;
            do_action( 'wpml_switch_language', $restore );
        }
    }

    public function verify_language( $post_id, $post_type, $language ) {
        return ! $this->is_available() || ( $this->language_code( $language ) && $this->language_code( $language ) ===
            apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $post_id, 'element_type' => $post_type ) ) );
    }

    /** Read actual relationships without a language-filtered projection (Cron has no admin 'all' scope). */
    public function object_term_ids( $post_id, $taxonomy ) {
        if ( ! $this->is_available() ) { return wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) ); }
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY tt.term_id", $post_id, $taxonomy ) );
        return $wpdb->last_error ? new \WP_Error( 'acm_terms_read', __( 'خواندن رابطه واقعی دسته‌بندی ممکن نشد.', 'ai-content-manager' ) ) : $ids;
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
