<?php
namespace AIContentManager\Services;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Interactive commands are distinct from the internal lease-bound execution principal. */
final class CampaignService {
    private $repository;
    private $queue;
    private $wpml;
    public function __construct( $repository = null, $queue = null, $wpml = null ) {
        $this->repository = $repository ?: new CampaignRepository(); $this->queue = $queue ?: new QueueRepository(); $this->wpml = $wpml ?: new \AIContentManager\Integrations\Wpml();
    }
    private function error( $message ) { return new \WP_Error( 'acm_campaign_input', $message ); }
    public function create( $input ) {
        if ( ! current_user_can( 'manage_ai_content' ) || ! current_user_can( 'edit_posts' ) || ! CampaignRepository::available() ) { return $this->error( __( 'مجوز تولید نوشته یا ساختار آماده کمپین لازم است.', 'ai-content-manager' ) ); }
        $keyword = $input['primary_keyword'] ?? null; $language = $input['language'] ?? null;
        if ( ! is_string( $keyword ) || trim( $keyword ) !== sanitize_text_field( $keyword ) || '' === trim( $keyword ) || strlen( $keyword ) > 764 || preg_match( '/[,،]|\R/u', $keyword ) || ! preg_match( '//u', $keyword ) || ! preg_match( '/[\p{L}\p{N}]/u', $keyword ) || preg_match_all( '/./us', $keyword ) > 191 ) { return $this->error( __( 'کلمه کلیدی کمپین باید یک عبارت ساده و معتبر و حداکثر ۱۹۱ نویسه باشد.', 'ai-content-manager' ) ); }
        if ( 'post' !== ( $input['content_type'] ?? '' ) || ! is_string( $language ) || ! isset( $this->wpml->active_languages()[ $language ] ) ) { return $this->error( __( 'کمپین این مرحله فقط برای نوشته و زبان فعال انتخاب‌شده است.', 'ai-content-manager' ) ); }
        $target = $input['campaign_target'] ?? '3'; $calls = $input['campaign_max_calls'] ?? '3';
        if ( ! is_scalar( $target ) || ! preg_match( '/^\d+$/D', (string) $target ) || (int) $target < 1 || (int) $target > 10 || ! is_scalar( $calls ) || ! preg_match( '/^[2-5]$/D', (string) $calls ) ) { return $this->error( __( 'تعداد هدف باید ۱ تا ۱۰ و سقف فراخوانی OpenAI هر مقاله ۲ تا ۵ باشد.', 'ai-content-manager' ) ); }
        $policy = GenerationPolicy::values();
        if ( $policy['max_generation_items'] && (int) $target > $policy['max_generation_items'] ) { return $this->error( __( 'تعداد هدف از سقف آیتم‌های مجاز در تنظیمات کنترل مصرف بیشتر است.', 'ai-content-manager' ) ); }
        $budget = CampaignPricing::money( $input['campaign_budget'] ?? '1.00' );
        if ( false === $budget || $budget < 10000 || $budget > 1000000000 ) { return $this->error( __( 'سقف کمپین باید مبلغ دلاری معتبر از ۰٫۰۱ تا ۱۰۰۰ دلار باشد.', 'ai-content-manager' ) ); }
        $category = ( new QueueCategory( $this->queue, $this->wpml ) )->parse_choice( $input['category_choice'] ?? '', 'post', $language );
        if ( is_wp_error( $category ) ) { return $category; }
        $tone = $input['tone'] ?? 'informative'; $words = $input['word_count'] ?? '1000';
        if ( ! in_array( $tone, array( 'informative', 'professional', 'conversational', 'persuasive', 'formal' ), true ) || ! is_scalar( $words ) || ! preg_match( '/^\d+$/D', (string) $words ) || (int) $words < 100 || (int) $words > 10000 ) { return $this->error( __( 'لحن یا طول تقریبی مقاله معتبر نیست.', 'ai-content-manager' ) ); }
        $submission = $input['submission_id'] ?? '';
        if ( ! is_string( $submission ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $submission ) || ! is_string( $input['submission_nonce'] ?? null ) || ! wp_verify_nonce( $input['submission_nonce'], 'acm_submission_' . $submission ) ) { return $this->error( __( 'اعتبار ارسال کمپین معتبر نیست؛ فرم را دوباره باز کنید.', 'ai-content-manager' ) ); }
        $domains = get_option( 'acm_campaign_domains', array() );
        $config = array( 'target' => (int) $target, 'max_calls' => (int) $calls, 'budget_micros' => $budget, 'tone' => $tone, 'word_count' => (int) $words,
            'category' => $category, 'model' => CampaignPricing::MODEL, 'rates' => CampaignPricing::snapshot(), 'domains' => is_array( $domains ) ? $domains : array(), 'approval' => 'require_approval' );
        return $this->repository->create( trim( $keyword ), $language, $config, $submission );
    }
    public function accessible( $campaign ) {
        return $campaign && current_user_can( 'manage_ai_content' ) && ( (int) $campaign['owner_id'] === get_current_user_id() || current_user_can( 'manage_options' ) );
    }
    public function quote( $campaign, $purpose = 'start' ) {
        $config = json_decode( $campaign['config'], true ); $rates = CampaignPricing::snapshot();
        $valid = CampaignPricing::valid( $rates, $this->repository->now() );
        $estimate = $valid ? CampaignPricing::estimate( $config['target'], $config['max_calls'], $rates ) : null;
        return array( 'valid' => $valid, 'estimate' => $estimate, 'rates' => $rates,
            'signature' => hash_hmac( 'sha256', wp_json_encode( array( (int) $campaign['campaign_id'], $campaign['config'], $rates, get_current_user_id(), $purpose ) ), wp_salt( 'auth' ) ) );
    }
    public function source_signature( $campaign, $source ) {
        return hash_hmac( 'sha256', wp_json_encode( array( (int) $campaign['campaign_id'], (int) $source['step_id'], hash( 'sha256', $source['output'] ), get_current_user_id() ) ), wp_salt( 'auth' ) );
    }
    private function saved_source( $id, $signature ) {
        $campaign = $this->repository->get( $id ); $source = $this->repository->discovery( $id );
        if ( ! $this->accessible( $campaign ) || ! $source || 'admit' !== $campaign['phase'] || ! in_array( $campaign['status'], array( 'review','paused','budget' ), true ) ||
            $this->repository->has_items( $id ) || $this->repository->unsettled( $id ) || ! is_string( $signature ) || ! hash_equals( $this->source_signature( $campaign, $source ), $signature ) ) { return $this->error( __( 'بررسی خروجی ذخیره‌شده فقط برای کمپین متوقف در پذیرش، بدون آیتم پذیرفته‌شده و با فرم تازه مجاز است.', 'ai-content-manager' ) ); }
        $output = json_decode( $source['output'], true ); $topics = $output['value']['topics'] ?? null;
        if ( ! is_array( $topics ) || count( $topics ) > 10 ) { return $this->error( __( 'ساختار پیشنهادهای ذخیره‌شده معتبر نیست؛ خروجی اصلی حفظ شد.', 'ai-content-manager' ) ); }
        foreach ( $topics as $topic ) {
            foreach ( array( 'title','question','angle' ) as $field ) { if ( ! is_string( $topic[$field] ?? null ) || strlen( $topic[$field] ) > 2500 || ! preg_match( '//u', $topic[$field] ) ) { return $this->error( __( 'پروفایل پیشنهاد ذخیره‌شده معتبر نیست؛ هیچ داده‌ای پاک نشد.', 'ai-content-manager' ) ); } }
        }
        return array( $campaign, $source, $topics );
    }
    public function review_saved( $id, $signature ) {
        if ( ! current_user_can( 'manage_ai_content' ) || ! $this->queue->lock_worker() ) { return $this->error( __( 'مجوز مدیریت و آزادبودن اجرای سراسری برای بررسی محلی لازم است.', 'ai-content-manager' ) ); }
        $locked = false;
        try {
            $saved = $this->saved_source( $id, $signature ); if ( is_wp_error( $saved ) ) { return $saved; }
            if ( ! $this->queue->lock_topics() ) { return $this->error( __( 'پذیرش موضوع دیگری در جریان است؛ دوباره بررسی کنید.', 'ai-content-manager' ) ); } $locked = true;
            list( $campaign, $source, $topics ) = $saved;
            $corpus = $this->queue->duplicate_corpus(); if ( is_wp_error( $corpus ) ) { return $corpus; }
            $report = ( new TopicDiversity() )->review( $topics, $campaign['keyword'], $corpus, (int) $campaign['target_count'] );
            $recorded = $this->repository->record_admission( $campaign, $source, $report, 'manual' );
            return is_wp_error( $recorded ) ? $recorded : array( 'message' => __( 'بررسی محلی پیشنهادهای ذخیره‌شده ثبت شد؛ صفر درخواست شبکه و صفر مصرف تازه. هنوز آیتمی پذیرفته یا کمپینی فعال نشده است؛ پس از بررسی نتیجه، ادامه کمپین را جداگانه تأیید کنید.', 'ai-content-manager' ) );
        } catch ( \Throwable $error ) { return $this->error( __( 'بررسی محلی کامل نشد؛ هیچ درخواست شبکه‌ای ارسال نشد و خروجی و مصرف قبلی حفظ شدند.', 'ai-content-manager' ) ); }
        finally { if ( $locked ) { $this->queue->unlock_topics(); } $this->queue->unlock_worker(); }
    }
    public function rediscover( $id, $confirmed, $signature, $source_signature ) {
        if ( ! current_user_can( 'manage_ai_content' ) || ! $this->queue->lock_worker() ) { return $this->error( __( 'مجوز مدیریت و آزادبودن اجرای سراسری لازم است.', 'ai-content-manager' ) ); }
        try {
            $saved = $this->saved_source( $id, $source_signature ); if ( is_wp_error( $saved ) ) { return $saved; } list( $campaign, $source ) = $saved;
            $review = $this->repository->latest_admission( $id ); $quote = $this->quote( $campaign, 'rediscover' ); $owner = get_userdata( (int) $campaign['owner_id'] );
            if ( true !== $confirmed || ! is_string( $signature ) || ! hash_equals( $quote['signature'], $signature ) || ! $review ||
                ( $review['discovery_hash'] ?? '' ) !== hash( 'sha256', $source['output'] ) || ! empty( array_filter( $review['decisions'] ?? array(), static function ( $decision ) { return 'rejected' !== ( $decision['status'] ?? '' ); } ) ) ) { return $this->error( __( 'موضوع‌یابی تازه نیازمند بررسی مستند پیشنهادها، فرم تازه و تأیید جداگانه هزینه است؛ برای پیشنهاد قابل پذیرش، ابتدا ادامهٔ همان خروجی را بررسی کنید.', 'ai-content-manager' ) ); }
            if ( '0' === get_option( 'acm_campaign_enabled', '1' ) || ! $owner || ! user_can( $owner, 'manage_ai_content' ) || ! user_can( $owner, 'edit_posts' ) || ! $quote['valid'] || ! ( new \AIContentManager\Integrations\OpenAi() )->has_api_key() ) { return $this->error( __( 'مالک مجاز، افزونه فعال، کلید و قیمت تازه تأییدشده برای درخواست هزینه‌دار لازم‌اند.', 'ai-content-manager' ) ); }
            if ( $this->repository->call_count( $id, 0 ) >= 2 || $this->repository->retry_not_before( $id ) > $this->repository->now() ) { return $this->error( __( 'سقف دو تلاش موضوع‌یابی رسیده یا فاصله امن تلاش قبلی نگذشته است؛ مصرف و سقف تلاش بازنشانی نمی‌شوند.', 'ai-content-manager' ) ); }
            $sum = $this->repository->money_summary( $id ); $config = json_decode( $campaign['config'], true );
            if ( is_wp_error( $sum ) || $sum['actual'] + $sum['reserved'] + $quote['estimate']['per_call'] > $config['budget_micros'] ) { return $this->error( __( 'بودجه با احتساب مصرف و رزرو قبلی برای موضوع‌یابی تازه کافی نیست.', 'ai-content-manager' ) ); }
            $config['rates'] = $quote['rates'];
            return $this->repository->restart_discovery( $campaign, $config, $source );
        } finally { $this->queue->unlock_worker(); }
    }
    public function start( $id, $confirmed, $signature ) {
        $campaign = $this->repository->get( $id );
        if ( ! $this->accessible( $campaign ) || true !== $confirmed || ! in_array( $campaign['status'], array( 'draft', 'paused', 'review', 'budget' ), true ) ) { return $this->error( __( 'شروع/ادامه به دسترسی معتبر و تأیید صریح هزینه نیاز دارد.', 'ai-content-manager' ) ); }
        if ( '0' === get_option( 'acm_campaign_enabled', '1' ) ) { return $this->error( __( 'اجرای افزونه غیرفعال شده است؛ پس از فعال‌سازی، ادامه کمپین باید صریحاً تأیید شود.', 'ai-content-manager' ) ); }
        $quote = $this->quote( $campaign );
        if ( ! is_string( $signature ) || ! hash_equals( $quote['signature'], $signature ) ) { return $this->error( __( 'تنظیمات یا برآورد تغییر کرده است؛ برآورد تازه را بررسی و تأیید کنید.', 'ai-content-manager' ) ); }
        $owner = get_userdata( (int) $campaign['owner_id'] );
        if ( ! $owner || ! user_can( $owner, 'manage_ai_content' ) || ! user_can( $owner, 'edit_posts' ) ) { return $this->error( __( 'مالک کمپین حذف شده یا مجوز فعلی تولید را ندارد؛ ادامه ممکن نیست.', 'ai-content-manager' ) ); }
        $item_id = $this->repository->item_to_resume( $id ); $item = $item_id ? $this->queue->get( $item_id ) : null;
        $local_only = $this->repository->pending_result( $id ) || ( $item && in_array( $item['campaign_stage'], array( 'fetch', 'draft', 'seo' ), true ) );
        if ( ! $local_only && $this->repository->retry_not_before( $id ) > $this->repository->now() ) { return $this->error( __( 'فاصله امن تلاش قبلی هنوز نگذشته است؛ timeout یا خطای قبلی نباید به درخواست هزینه‌دار فوری تبدیل شود. چند دقیقه بعد دوباره بررسی کنید.', 'ai-content-manager' ) ); }
        if ( ! $local_only && ( ! $quote['valid'] || ! ( new \AIContentManager\Integrations\OpenAi() )->has_api_key() ) ) { return $this->error( __( 'مدل جست‌وجوی سازگار، کلید موجود و نرخ تازه تأییدشده لازم‌اند؛ در تنظیمات بررسی کنید. وجود کلید، تأیید دسترسی حساب به مدل نیست.', 'ai-content-manager' ) ); }
        $config = json_decode( $campaign['config'], true );
        if ( ! $local_only && $config['budget_micros'] < $quote['estimate']['per_call'] ) { return $this->error( __( 'سقف هزینه برای رزرو محافظه‌کارانه حتی یک درخواست کافی نیست؛ ابتدا سقف را اصلاح کنید.', 'ai-content-manager' ) ); }
        if ( $quote['valid'] ) { $config['rates'] = $quote['rates']; $saved = $this->repository->save_config( $id, $config ); if ( is_wp_error( $saved ) ) { return $saved; } }
        if ( $item_id && ! $this->repository->pending_result( $id ) && ! $this->repository->inflight( $id ) ) { $reset = $this->repository->reset_failed_generation( $item_id ); if ( is_wp_error( $reset ) ) { return $reset; } }
        return $this->repository->control( $id, 'active', __( 'شروع/ادامه با تأیید صریح کاربر ثبت شد؛ سقف‌ها پیش از هر درخواست دوباره کنترل می‌شوند.', 'ai-content-manager' ) );
    }
    public function control( $id, $action ) {
        if ( ! in_array( $action, array( 'pause', 'end' ), true ) ) { return $this->error( __( 'عملیات کمپین معتبر نیست.', 'ai-content-manager' ) ); }
        return $this->repository->control( $id, 'pause' === $action ? 'paused' : 'ended', 'pause' === $action ? __( 'کمپین متوقف شد؛ نتیجه درخواست درحال‌اجرا و مصرف آن حفظ می‌شود.', 'ai-content-manager' ) : __( 'کمپین پایان داده شد؛ نوشته‌ها و سوابق حذف نمی‌شوند.', 'ai-content-manager' ) );
    }
    public function budget( $id, $amount, $calls ) {
        $campaign = $this->repository->get( $id ); $money = CampaignPricing::money( $amount );
        if ( ! $this->accessible( $campaign ) || false === $money || $money < 10000 || $money > 1000000000 || ! is_scalar( $calls ) || ! preg_match( '/^[2-5]$/D', (string) $calls ) ) { return $this->error( __( 'دسترسی یا سقف هزینه/فراخوانی معتبر نیست؛ تغییر بودجه شروع خودکار ایجاد نمی‌کند.', 'ai-content-manager' ) ); }
        $config = json_decode( $campaign['config'], true ); $config['budget_micros'] = $money; $config['max_calls'] = (int) $calls;
        return $this->repository->save_config( $id, $config );
    }
}
