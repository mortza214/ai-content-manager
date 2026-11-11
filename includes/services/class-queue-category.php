<?php
namespace AIContentManager\Services;

use AIContentManager\Integrations\Wpml;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared category validation, draft completion and explicit queue repair. */
final class QueueCategory {
    private $repository;
    private $wpml;
    public function __construct( $repository = null, $wpml = null ) {
        $this->repository = $repository ?: new QueueRepository();
        $this->wpml = $wpml ?: new Wpml();
    }

    public function validate( $category, $type, $language, $translate = false ) {
        if ( null === $category ) { return null; }
        if ( ! is_array( $category ) || ! is_string( $category['taxonomy'] ?? null ) ||
            ( ! is_int( $category['term_id'] ?? null ) && ! is_string( $category['term_id'] ?? null ) ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $category['term_id'] ) ) {
            return $this->error( __( 'انتخاب دسته‌بندی معتبر نیست؛ دوباره یک دسته‌بندی انتخاب کنید.', 'ai-content-manager' ) );
        }
        $taxonomy = get_taxonomy( $category['taxonomy'] );
        $term = get_term( (int) $category['term_id'], $category['taxonomy'] );
        if ( ! $taxonomy || empty( $taxonomy->hierarchical ) || empty( $taxonomy->show_ui ) ||
            ! in_array( $type, $taxonomy->object_type, true ) || ! $term || is_wp_error( $term ) || $term->taxonomy !== $category['taxonomy'] || (int) $term->term_id !== (int) $category['term_id'] ) {
            return $this->error( __( 'دسته‌بندی حذف شده یا با نوع محتوا سازگار نیست؛ انتخاب قبلی حفظ شد و باید اصلاح شود.', 'ai-content-manager' ) );
        }
        if ( ! current_user_can( $taxonomy->cap->assign_terms ) ) { return $this->error( __( 'مجوز تخصیص دسته‌بندی انتخاب‌شده را ندارید.', 'ai-content-manager' ) ); }
        $resolved = $this->wpml->category_for_language( $term, $language );
        if ( is_wp_error( $resolved ) ) { return $resolved; }
        if ( ! $translate && (int) $resolved->term_id !== (int) $term->term_id ) {
            return $this->error( __( 'زبان دسته‌بندی انتخاب‌شده با زبان محتوا یکسان نیست؛ دسته‌بندی هم‌زبان را صریحاً انتخاب کنید.', 'ai-content-manager' ) );
        }
        return array( 'taxonomy' => $resolved->taxonomy, 'term_id' => (int) $resolved->term_id );
    }

    public function parse_choice( $choice, $type, $language ) {
        if ( '' === $choice ) { return null; }
        if ( ! is_string( $choice ) || strlen( $choice ) > 200 ) { return $this->validate( false, $type, $language ); }
        $parts = explode( '|', $choice );
        if ( 3 !== count( $parts ) || $parts[0] !== $type ) { return $this->validate( false, $type, $language ); }
        return $this->validate( array( 'taxonomy' => $parts[1], 'term_id' => $parts[2] ), $type, $language );
    }

    public function options( $types, $languages ) {
        $options = array();
        foreach ( $types as $type => $label ) {
            foreach ( get_object_taxonomies( $type, 'objects' ) as $tax ) {
                if ( empty( $tax->hierarchical ) || empty( $tax->show_ui ) || ! current_user_can( $tax->cap->assign_terms ) ) { continue; }
                foreach ( $languages as $language => $language_label ) {
                    $terms = $this->wpml->in_language( $language, static function () use ( $tax ) { return get_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false ) ); } );
                    if ( is_wp_error( $terms ) ) { return $this->error( __( 'خواندن دسته‌بندی‌ها انجام نشد؛ فرم را دوباره باز کنید.', 'ai-content-manager' ) ); }
                    foreach ( $terms as $term ) {
                        $category = $this->validate( array( 'taxonomy' => $tax->name, 'term_id' => $term->term_id ), $type, $language );
                        if ( is_wp_error( $category ) ) { continue; }
                        $key = $type . '|' . $tax->name . '|' . $term->term_id;
                        if ( ! isset( $options[ $key ] ) ) { $options[ $key ] = array( 'type' => $type, 'label' => $label . ' — ' . $tax->labels->name . ' — ' . $term->name, 'languages' => array() ); }
                        $options[ $key ]['languages'][] = $language;
                    }
                }
            }
        }
        return $options;
    }

    public function describe( $category ) {
        if ( null === $category ) { return __( 'بدون انتخاب دسته‌بندی؛ دسته پیش‌فرض وردپرس حذف نمی‌شود.', 'ai-content-manager' ); }
        if ( ! is_array( $category ) || ! is_string( $category['taxonomy'] ?? null ) || ! is_scalar( $category['term_id'] ?? null ) ) { return __( 'انتخاب ذخیره‌شده نامعتبر است.', 'ai-content-manager' ); }
        $term = get_term( (int) $category['term_id'], $category['taxonomy'] );
        return $category['taxonomy'] . ' #' . (int) $category['term_id'] . ' — ' . ( $term && ! is_wp_error( $term ) ? $term->name : __( 'حذف‌شده یا نامعتبر', 'ai-content-manager' ) );
    }

    public function current( $post_id, $taxonomy ) {
        $ids = $this->wpml->object_term_ids( $post_id, $taxonomy );
        if ( is_wp_error( $ids ) || ! is_array( $ids ) ) { return $this->error( __( 'خواندن دسته‌بندی واقعی پیش‌نویس انجام نشد.', 'ai-content-manager' ) ); }
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) ); sort( $ids, SORT_NUMERIC );
        return $ids;
    }

    private function context( $item_id ) {
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->error( __( 'مجوز مدیریت محتوای هوشمند لازم است.', 'ai-content-manager' ) ); }
        $item = $this->repository->get( $item_id );
        if ( ! $item || 'generated' !== $item['status'] ) { return $this->error( __( 'آیتم تولیدشده معتبر و خارج از زباله‌دان لازم است.', 'ai-content-manager' ) ); }
        $id = (int) ( $item['post_id'] ?? 0 );
        if ( $id ) { wp_cache_delete( $id, 'posts' ); }
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || 'draft' !== $post->post_status || $post->post_type !== $item['content_type'] || empty( $item['draft_token'] ) ||
            ( $post->post_content_filtered ?? '' ) !== 'acm-draft:' . (int) $item_id . ':' . $item['draft_token'] ) {
            return $this->error( __( 'فقط پیش‌نویس مرتبط با نشان معتبر قابل بررسی یا اصلاح دسته‌بندی است؛ نوشته دیگری ساخته نمی‌شود.', 'ai-content-manager' ) );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) { return $this->error( __( 'مجوز ویرایش پیش‌نویس مرتبط را ندارید.', 'ai-content-manager' ) ); }
        if ( ! is_array( json_decode( $item['payload'], true ) ) ) { return $this->error( __( 'اطلاعات انتخاب دسته‌بندی در صف قابل خواندن نیست؛ خالی فرض نمی‌شود.', 'ai-content-manager' ) ); }
        return $item;
    }

    /** Before language hooks, persist a baseline for safe retries on a newly created draft. */
    public function prepare( $item, $category, $created ) {
        if ( ! $category ) { return true; }
        $current = $this->current( (int) $item['post_id'], $category['taxonomy'] );
        if ( is_wp_error( $current ) ) { return $current; }
        if ( $current === array( $category['term_id'] ) ) { return true; }
        $payload = json_decode( $item['payload'], true );
        $attempt = is_array( $payload ) ? ( $payload['category_attempt'] ?? null ) : null;
        $binding = array( 'post_id' => (int) $item['post_id'], 'draft_token' => $item['draft_token'], 'category' => $category, 'language' => $item['language'] );
        if ( $created ) { return $this->repository->save_category_attempt( $item, array_merge( $binding, array( 'baseline' => $current ) ) ); }
        if ( is_array( $attempt ) && array_intersect_key( $attempt, $binding ) === $binding && ( $attempt['baseline'] ?? null ) === $current ) { return true; }
        return $this->error( __( 'پیش‌نویس مرتبط حفظ شد اما دسته‌بندی با وضعیت قابل تکمیل خودکار یکسان نیست؛ برای حفظ تغییر دستی، از «بررسی و اصلاح دسته‌بندی» و تأیید صریح استفاده کنید.', 'ai-content-manager' ) );
    }

    public function verify( $item, $category ) {
        if ( ! $category ) { return true; }
        $fresh = $this->context( $item['item_id'] );
        if ( is_wp_error( $fresh ) ) { return $fresh; }
        $resolved = $this->validate( json_decode( $fresh['payload'], true )['category'] ?? null, $fresh['content_type'], $fresh['language'], true );
        if ( is_wp_error( $resolved ) || $resolved !== $category || $fresh['post_id'] != $item['post_id'] || $fresh['draft_token'] !== $item['draft_token'] || $fresh['language'] !== $item['language'] ) { return $this->error( __( 'انتخاب یا رابطه دسته‌بندی تغییر کرده است؛ پیش‌نویس حفظ شد و باید دوباره بررسی شود.', 'ai-content-manager' ) ); }
        $current = $this->current( (int) $item['post_id'], $category['taxonomy'] );
        if ( is_wp_error( $current ) ) { return $current; }
        if ( $current !== array( $category['term_id'] ) || ! $this->wpml->verify_language( (int) $item['post_id'], $item['content_type'], $item['language'] ) ) {
            return $this->error( __( 'تکمیل پیش‌نویس ناقص است: دسته‌بندی واقعی یا زبان نهایی با انتخاب صف تطابق ندارد؛ همان پیش‌نویس حفظ شد. تغییر دستی خودکار بازنویسی نمی‌شود.', 'ai-content-manager' ) );
        }
        return true;
    }

    public function assign( $item, $category ) {
        if ( ! $category ) { return true; }
        $fresh = $this->context( $item['item_id'] );
        if ( is_wp_error( $fresh ) ) { return $fresh; }
        $target = $this->validate( json_decode( $fresh['payload'], true )['category'] ?? null, $fresh['content_type'], $fresh['language'], true );
        if ( is_wp_error( $target ) || $target !== $category || $fresh['post_id'] != $item['post_id'] || $fresh['draft_token'] !== $item['draft_token'] || $fresh['language'] !== $item['language'] || ! $this->wpml->verify_language( (int) $item['post_id'], $item['content_type'], $item['language'] ) ) {
            return $this->error( __( 'پیش‌نویس حفظ شد اما زبان، دسترسی یا انتخاب دسته‌بندی پیش از تخصیص معتبر نیست؛ تکمیل انجام نشد.', 'ai-content-manager' ) );
        }
        $current = $this->current( (int) $item['post_id'], $category['taxonomy'] );
        if ( is_wp_error( $current ) ) { return $current; }
        if ( $current !== array( $category['term_id'] ) ) {
            $written = wp_set_object_terms( (int) $item['post_id'], array( (int) $category['term_id'] ), $category['taxonomy'], false );
            if ( is_wp_error( $written ) || ! is_array( $written ) ) { return $this->error( __( 'تکمیل پیش‌نویس ناقص است: تخصیص دسته‌بندی انجام نشد؛ همان نوشته و رابطه آن حفظ شدند.', 'ai-content-manager' ) ); }
        }
        return $this->verify( $item, $category );
    }

    private function signature( $item, $category, $current ) {
        $payload = json_decode( $item['payload'], true );
        return hash_hmac( 'sha256', wp_json_encode( array( (int) $item['item_id'], (int) $item['post_id'], $item['draft_token'], $item['language'],
            $payload['category'] ?? null, $category, $current, get_current_user_id() ) ), wp_salt( 'auth' ) );
    }

    public function compare( $item_id ) {
        try {
            $item = $this->context( $item_id );
            if ( is_wp_error( $item ) ) { return $item; }
            $raw = json_decode( $item['payload'], true )['category'] ?? null;
            $category = $this->validate( $raw, $item['content_type'], $item['language'], true );
            $taxonomies = get_object_taxonomies( $item['content_type'], 'objects' ); $actual = array(); $current = array();
            foreach ( $taxonomies as $taxonomy ) {
                if ( empty( $taxonomy->hierarchical ) ) { continue; }
                $ids = $this->current( (int) $item['post_id'], $taxonomy->name );
                if ( is_wp_error( $ids ) ) { return $ids; }
                foreach ( $ids as $id ) { $actual[] = $this->describe( array( 'taxonomy' => $taxonomy->name, 'term_id' => $id ) ); }
                if ( is_array( $category ) && $category['taxonomy'] === $taxonomy->name ) { $current = $ids; }
            }
            $message = is_wp_error( $category ) ? $category->get_error_message() : ( ! $category ? __( 'انتخابی در صف ثبت نشده است؛ دسته‌بندی‌های فعلی تغییر نمی‌کنند.', 'ai-content-manager' ) : '' );
            if ( ! $message && ! $this->wpml->verify_language( (int) $item['post_id'], $item['content_type'], $item['language'] ) ) { $message = __( 'زبان فعلی پیش‌نویس با صف یکسان نیست؛ ابتدا تکمیل زبان را بررسی کنید.', 'ai-content-manager' ); }
            return array( 'post_id' => (int) $item['post_id'], 'selected' => $this->describe( $raw ), 'target' => is_array( $category ) ? $this->describe( $category ) : '',
                'current' => $actual, 'message' => $message, 'equal' => is_array( $category ) && $current === array( $category['term_id'] ),
                'signature' => '' === $message ? $this->signature( $item, $category, $current ) : '' );
        } catch ( \Throwable $error ) { return $this->error( __( 'بررسی دسته‌بندی انجام نشد؛ پیش‌نویس و اطلاعات صف حفظ شدند.', 'ai-content-manager' ) ); }
    }

    public function apply( $item_id, $signature, $confirmed ) {
        if ( ! current_user_can( 'manage_ai_content' ) || true !== $confirmed ) { return $this->error( __( 'مجوز مدیریت و تأیید صریح جایگزینی دسته‌بندی لازم است.', 'ai-content-manager' ) ); }
        if ( ! $this->repository->lock_item( $item_id ) ) { return $this->error( __( 'آیتم در حال پردازش است؛ دوباره تلاش کنید.', 'ai-content-manager' ) ); }
        try {
            $item = $this->context( $item_id );
            if ( is_wp_error( $item ) ) { return $item; }
            $category = $this->validate( json_decode( $item['payload'], true )['category'] ?? null, $item['content_type'], $item['language'], true );
            if ( is_wp_error( $category ) ) { return $category; }
            if ( ! $category ) { return $this->error( __( 'انتخاب دسته‌بندی در صف وجود ندارد؛ هیچ دسته‌ای پاک نمی‌شود.', 'ai-content-manager' ) ); }
            $current = $this->current( (int) $item['post_id'], $category['taxonomy'] );
            if ( is_wp_error( $current ) ) { return $current; }
            if ( ! is_string( $signature ) || ! hash_equals( $this->signature( $item, $category, $current ), $signature ) ) { return $this->error( __( 'دسته‌بندی یا انتخاب صف پس از نمایش تغییر کرده است؛ مقایسه تازه را بررسی کنید. تغییر دستی حفظ شد.', 'ai-content-manager' ) ); }
            $result = $this->assign( $item, $category );
            if ( is_wp_error( $result ) ) { return $result; }
            if ( $current !== array( $category['term_id'] ) ) {
                $logged = $this->repository->record_category_result( $item, $category );
                if ( is_wp_error( $logged ) ) { return $this->error( __( 'دسته‌بندی تأیید شد اما ثبت نتیجه انجام نشد؛ پیش از تلاش دوباره مقایسه را بررسی کنید.', 'ai-content-manager' ) ); }
            }
            return true;
        } catch ( \Throwable $error ) { return $this->error( __( 'تکمیل دسته‌بندی انجام نشد؛ همان پیش‌نویس حفظ شد و باید بررسی شود.', 'ai-content-manager' ) ); }
        finally { $this->repository->unlock_item( $item_id ); }
    }

    private function error( $message ) { return new \WP_Error( 'acm_category_error', $message ); }
}
