<?php
namespace AIContentManager\Services;
use AIContentManager\Integrations\Wpml;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Converts one validated result to a draft without invoking generation or budget services. */
final class DraftCreator {
    private $repository;
    private $wpml;
    private $validator;
    private $seo;
    private $seo_result;
    private $completed = false;
    private $categories;
    public function __construct( $repository, $wpml = null, $validator = null, $seo = null, $categories = null ) {
        $this->repository = $repository;
        $this->wpml = $wpml ?: new Wpml();
        $this->validator = $validator ?: new ArticleOutputValidator();
        $this->seo = $seo ?: new QueueSeo( $repository );
        $this->categories = $categories ?: new QueueCategory( $repository, $this->wpml );
    }

    public function seo_result() { return $this->seo_result; }

    public function create( $item_id ) {
        $this->seo_result = null;
        $this->completed = false;
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->error( __( 'مجوز ایجاد پیش‌نویس هوشمند را ندارید.', 'ai-content-manager' ) ); }
        if ( ! $this->repository->lock_item( $item_id ) ) { return $this->error( __( 'این آیتم در حال پردازش است؛ دوباره تلاش کنید.', 'ai-content-manager' ) ); }
        try { $result = $this->create_locked( $item_id ); }
        catch ( \Throwable $error ) { return $this->error( __( 'تکمیل پیش‌نویس انجام نشد. دوباره همین عملیات را اجرا کنید؛ نوشته جدید خودکار جایگزین نمی‌شود.', 'ai-content-manager' ) ); }
        finally { $this->repository->unlock_item( $item_id ); }
        // SEO has its own result and lock; it can never turn a completed draft into a failure.
        if ( ! is_wp_error( $result ) && $this->completed ) {
            try {
                if ( $this->seo->enabled() ) { $this->seo_result = $this->seo->apply( $item_id, array(), array(), true ); }
            } catch ( \Throwable $error ) { $this->seo_result = new \WP_Error( 'acm_seo_error', __( 'تکمیل اطلاعات سئو نیازمند بررسی است.', 'ai-content-manager' ) ); }
        }
        return $result;
    }

    private function create_locked( $item_id ) {
        $item = $this->repository->get( $item_id );
        if ( ! $item || 'generated' !== $item['status'] ) { return $this->error( __( 'فقط خروجی تولیدشده خارج از زباله‌دان قابل تبدیل به پیش‌نویس است.', 'ai-content-manager' ) ); }
        if ( ! in_array( $item['content_type'], array( 'post', 'page' ), true ) ) { return $this->error( __( 'ایجاد پیش‌نویس فقط برای نوشته و برگه پشتیبانی می‌شود؛ محصول پشتیبانی نمی‌شود.', 'ai-content-manager' ) ); }
        $type = get_post_type_object( $item['content_type'] );
        if ( ! $type || ! current_user_can( $type->cap->create_posts ) ) { return $this->error( __( 'مجوز ایجاد این نوع نوشته را ندارید.', 'ai-content-manager' ) ); }
        $output = $this->validator->validate_for_draft( $item['generated_output'] ?? '' );
        if ( is_wp_error( $output ) ) { return $output; }
        $payload = json_decode( $item['payload'], true );
        if ( ! is_array( $payload ) ) { return $this->error( __( 'اطلاعات ذخیره‌شده صف قابل خواندن نیست؛ انتخاب دسته‌بندی نمی‌تواند خالی فرض شود.', 'ai-content-manager' ) ); }
        $category = $this->categories->validate( $payload['category'] ?? null, $item['content_type'], $item['language'], true );
        if ( is_wp_error( $category ) ) { return $category; }
        $post_id = (int) ( $item['post_id'] ?? 0 );
        $state = $item['draft_state'] ?? 'none';
        $token = $item['draft_token'] ?? '';
        $marker = $token ? 'acm-draft:' . (int) $item_id . ':' . $token : '';
        $created = false;

        if ( ! $post_id && $token ) {
            $found = $this->repository->find_draft_marker( $marker );
            if ( is_wp_error( $found ) ) { return $found; }
            if ( $found ) { $post_id = $found; }
            elseif ( 'creating' === $state || 'linked' === $state || 'ready' === $state ) {
                return $this->error( __( 'تلاش قبلی ثبت شده اما نوشته پیدا نشد. برای جلوگیری از تکرار یا جایگزینی نوشته حذف‌شده، مدیر باید ارتباط را بررسی کند.', 'ai-content-manager' ) );
            }
        }

        if ( ! $post_id ) {
            $token = wp_generate_uuid4();
            $marker = 'acm-draft:' . (int) $item_id . ':' . $token;
            $claimed = $this->repository->save_draft_fields( $item_id, array( 'draft_token' => $token, 'draft_state' => 'creating' ), __( 'ایجاد پیش‌نویس آغاز شد؛ نشان پایدار پیش از درج نوشته ثبت شد.', 'ai-content-manager' ) );
            if ( is_wp_error( $claimed ) ) { return $claimed; }
            $creator_id = (int) ( $item['created_by'] ?? 0 );
            $creator = $creator_id ? get_userdata( $creator_id ) : false;
            $author = $creator && user_can( $creator, $type->cap->create_posts ) ? $creator_id : get_current_user_id();
            if ( $author !== get_current_user_id() && ! current_user_can( $type->cap->edit_others_posts ) ) { $author = get_current_user_id(); }
            $post_type = $item['content_type'];
            $force_draft = static function ( $data, $postarr ) use ( $token, $marker, $post_type ) {
                if ( ( $postarr['_acm_draft_token'] ?? '' ) === $token && empty( $postarr['ID'] ) ) {
                    $data['post_status'] = 'draft';
                    $data['post_type'] = $post_type;
                    $data['post_content_filtered'] = $marker;
                }
                return $data;
            };
            add_filter( 'wp_insert_post_data', $force_draft, PHP_INT_MAX, 2 );
            try {
                $post_id = wp_insert_post( wp_slash( array( 'post_type' => $item['content_type'], 'post_status' => 'draft',
                    'post_author' => $author, 'post_title' => sanitize_text_field( $output['title'] ), 'post_content' => $output['content'],
                    'post_excerpt' => sanitize_textarea_field( $output['excerpt'] ), 'post_name' => sanitize_title( $output['slug'] ), 'post_content_filtered' => $marker,
                    '_acm_draft_token' => $token, 'comment_status' => 'closed', 'ping_status' => 'closed' ) ), true );
            } finally { remove_filter( 'wp_insert_post_data', $force_draft, PHP_INT_MAX ); }
            if ( is_wp_error( $post_id ) || ! $post_id ) {
                return $this->error( __( 'ایجاد پیش‌نویس کامل نشد؛ نشان تلاش حفظ شده است. اجرای دوباره فقط نوشته دارای همین نشان را بازیابی می‌کند و نسخه دوم نمی‌سازد.', 'ai-content-manager' ) );
            }
            $created = true;
            $author_note = $author === $creator_id ? __( 'نویسنده از سازنده معتبر صف تعیین شد.', 'ai-content-manager' ) : __( 'سازنده معتبر یا مجاز نبود؛ کاربر اجراکننده نویسنده شد.', 'ai-content-manager' );
        }

        $post = get_post( $post_id );
        if ( ! $post ) { return $this->error( __( 'نوشته مرتبط حذف شده است؛ نوشته جایگزین خودکار ساخته نمی‌شود.', 'ai-content-manager' ) ); }
        if ( 'trash' === $post->post_status ) { return $this->error( __( 'نوشته مرتبط در زباله‌دان وردپرس است؛ ابتدا آن را در وردپرس بازگردانید. نوشته دوم ساخته نمی‌شود.', 'ai-content-manager' ) ); }
        if ( $post->post_type !== $item['content_type'] || 'draft' !== $post->post_status ) { return $this->error( __( 'وضعیت یا نوع نوشته مرتبط تغییر کرده است؛ بدون تغییر نوشته، وضعیت واقعی آن را بررسی کنید.', 'ai-content-manager' ) ); }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return $this->error( __( 'مجوز ویرایش پیش‌نویس مرتبط را ندارید.', 'ai-content-manager' ) ); }
        if ( ! $token || ( $post->post_content_filtered ?? '' ) !== $marker ) { return $this->error( __( 'نشان پیش‌نویس مرتبط معتبر نیست؛ هیچ نوشته یا دسته‌بندی تغییر نکرد.', 'ai-content-manager' ) ); }

        if ( empty( $item['post_id'] ) ) {
            $linked = $this->repository->save_draft_fields( $item_id, array( 'post_id' => (int) $post_id, 'draft_state' => 'linked' ),
                sprintf( __( 'پیش‌نویس #%d مرتبط شد؛ وضعیت تولید همچنان تولیدشده است. ', 'ai-content-manager' ), $post_id ) . ( $author_note ?? __( 'ارتباط از نشان پایدار تلاش قبلی بازیابی شد.', 'ai-content-manager' ) ) );
            if ( is_wp_error( $linked ) ) { return $linked; }
        }
        $item = $this->repository->get( $item_id );
        if ( 'ready' === $state ) {
            $verified = $this->categories->verify( $item, $category );
            return is_wp_error( $verified ) ? $verified : (int) $post_id;
        }
        $prepared = $this->categories->prepare( $item, $category, $created );
        if ( is_wp_error( $prepared ) ) { return $prepared; }
        $language = $this->wpml->assign_language( $post_id, $item['content_type'], $item['language'] );
        if ( is_wp_error( $language ) ) { return $language; }
        $assigned = $this->categories->assign( $item, $category );
        if ( is_wp_error( $assigned ) ) { return $assigned; }
        $ready = $this->repository->save_draft_fields( $item_id, array( 'draft_state' => 'ready' ), sprintf( __( 'پیش‌نویس #%d با موفقیت تکمیل شد؛ انتشار انجام نشد.', 'ai-content-manager' ), $post_id ) );
        $this->completed = ! is_wp_error( $ready );
        return is_wp_error( $ready ) ? $ready : (int) $post_id;
    }

    private function error( $message ) { return new \WP_Error( 'acm_draft_error', $message ); }
}
