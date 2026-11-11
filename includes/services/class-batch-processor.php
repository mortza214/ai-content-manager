<?php
/** Lease-bound campaign processing through the existing queue.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** One lease-bound step per invocation; no multi-request API loop. */
final class BatchProcessor {
    const HOOK = 'acm_campaign_tick';
    private $campaigns;
    private $queue;
    private $research;
    private $generator;
    private $drafts;
    public function __construct( $campaigns = null, $queue = null, $research = null, $generator = null, $drafts = null ) {
        $this->campaigns = $campaigns ?: new CampaignRepository(); $this->queue = $queue ?: new QueueRepository();
        $this->research = $research ?: new WebResearch(); $this->generator = $generator ?: new ContentGeneratorService( $this->queue );
        $this->drafts = $drafts ?: new DraftCreator( $this->queue );
    }
    public function register() { add_action( self::HOOK, array( $this, 'tick' ) ); }
    private function cron_authorized() { return doing_action( self::HOOK ) && ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ); }
    public function schedule() {
        if ( CampaignRepository::available() && '0' !== get_option( 'acm_campaign_enabled', '1' ) && $this->campaigns->has_active() && ! wp_next_scheduled( self::HOOK ) ) {
            return wp_schedule_single_event( $this->campaigns->now() + 60, self::HOOK );
        }
        return true;
    }
    public function tick() {
        if ( ! $this->cron_authorized() ) { return; }
        $this->schedule(); // Schedule before dispatch so a PHP crash does not lose the next recovery tick.
        return $this->run_once();
    }
    public static function deactivate() {
        update_option( 'acm_campaign_enabled', '0', false ); wp_clear_scheduled_hook( self::HOOK );
        if ( CampaignRepository::available() ) {
            ( new CampaignRepository() )->pause_all();
        }
    }
    public function run_once() {
        if ( ! CampaignRepository::available() || '0' === get_option( 'acm_campaign_enabled', '1' ) || ( ! current_user_can( 'manage_ai_content' ) && ! $this->cron_authorized() ) ) {
            return new \WP_Error( 'acm_worker_forbidden', __( 'اجرای worker فقط با مجوز مدیریتی یا Cron ثبت‌شده مجاز است.', 'ai-content-manager' ) );
        }
        if ( ! $this->queue->lock_worker() ) { return new \WP_Error( 'acm_worker_busy', __( 'یک worker یا تولید دستی در سایت فعال است.', 'ai-content-manager' ) ); }
        try {
            $claim = $this->campaigns->claim();
            if ( ! $claim || is_wp_error( $claim ) ) { return $claim; }
            $result = ExecutionContext::run( $this->campaigns, $claim, function () use ( $claim ) { return $this->execute( $claim ); } );
            if ( is_wp_error( $result ) && 'acm_owner' === $result->get_error_code() ) { $this->campaigns->fail( $claim, $result->get_error_message() ); }
            return $result;
        } catch ( \Throwable $error ) {
            if ( ! empty( $claim['step_id'] ) ) { $this->campaigns->fail( $claim, __( 'اجرای مرحله قطع شد؛ همان آیتم و رزروها حفظ شده‌اند و نیاز به بررسی دارند.', 'ai-content-manager' ) ); }
            return new \WP_Error( 'acm_worker_error', __( 'مرحله کمپین کامل نشد؛ جزئیات امن در سابقه ثبت شد.', 'ai-content-manager' ) );
        } finally { $this->queue->unlock_worker(); $this->schedule(); }
    }
    private function execute( $claim ) {
        $campaign = $this->campaigns->get( $claim['campaign_id'] );
        if ( 'active' !== $campaign['status'] ) { return true; }
        $config = json_decode( $campaign['config'], true );
        $category = ( new QueueCategory( $this->queue ) )->validate( $config['category'], 'post', $campaign['language'], true );
        if ( is_wp_error( $category ) ) { $this->campaigns->fail( $claim, $category->get_error_message() ); return $category; }
        if ( ! empty( $claim['resume_result'] ) ) { $stored = $this->campaigns->step( $claim['step_id'] ); return $this->apply( $claim, json_decode( $stored['output'], true ) ); }
        $context = array( 'campaign_step_id' => $claim['step_id'], 'campaign_attempt_id' => $claim['attempt_id'] );
        $item = $claim['item_id'] ? $this->queue->get( $claim['item_id'] ) : null;
        switch ( $claim['kind'] ) {
            case 'discover': $response = $this->research->discover( $campaign, $context ); break;
            case 'admit':
                $discovered = $this->campaigns->latest_output( $campaign['campaign_id'], 'discover' );
                $response = array( 'value' => $discovered['value'] ?? array( 'topics' => array() ) ); break;
            case 'research': $response = $this->research->research( $item, $campaign, $context ); break;
            case 'fetch':
                $fetched = $this->research->fetch( $item, (int) $claim['source_index'], $context );
                $response = is_wp_error( $fetched ) ? array( 'error' => $fetched ) : array( 'value' => $fetched ); break;
            case 'generate': $response = $this->generator->campaign_output( $claim['item_id'], $claim ); break;
            case 'draft':
                $post_id = $this->drafts->create( $claim['item_id'] ); $seo = $this->drafts->seo_result();
                $response = is_wp_error( $post_id ) ? array( 'error' => $post_id ) : array( 'value' => array( 'post_id' => $post_id,
                    'next_stage' => is_wp_error( $seo ) || ! empty( $seo['review'] ) ? 'seo' : 'done' ) ); break;
            case 'seo':
                $seo = ( new QueueSeo( $this->queue ) )->apply( $claim['item_id'], array(), array(), true );
                $response = is_wp_error( $seo ) || ! empty( $seo['review'] ) ? array( 'error' => new \WP_Error( 'acm_seo_review', __( 'مقاله و همان پیش‌نویس حفظ شدند؛ فقط تکمیل سئو نیازمند بررسی یا اصلاح دستی است.', 'ai-content-manager' ) ) ) : array( 'value' => array( 'post_id' => (int) $item['post_id'], 'next_stage' => 'done' ) ); break;
            default: $response = array( 'error' => new \WP_Error( 'acm_step', __( 'مرحله کمپین شناخته‌شده نیست.', 'ai-content-manager' ) ) );
        }
        if ( isset( $response['error'] ) ) { $this->campaigns->fail( $claim, $response['error']->get_error_message(), $response['metadata'] ?? array() ); return $response['error']; }
        $saved = $this->campaigns->save_result( $claim, $response );
        if ( is_wp_error( $saved ) ) { return $saved; }
        if ( ! empty( $response['metadata']['billing_persist_failed'] ) ) {
            $this->campaigns->stop( $claim['campaign_id'], 'review', __( 'پاسخ مرحله حفظ شد اما ثبت قطعی مصرف شکست خورد؛ ادامه فقط پس از بررسی هزینه و با تأیید کاربر است.', 'ai-content-manager' ) ); return true;
        }
        $fresh = $this->campaigns->get( $claim['campaign_id'] );
        $owner = get_userdata( (int) $fresh['owner_id'] );
        if ( ! $owner || ! user_can( $owner, 'manage_ai_content' ) || ! user_can( $owner, 'edit_posts' ) ) { $this->campaigns->stop( $claim['campaign_id'], 'paused', __( 'مجوز مالک پس از درخواست تغییر کرد؛ نتیجه و مصرف حفظ شدند و درخواست تازه متوقف است.', 'ai-content-manager' ) ); return true; }
        if ( 'active' !== $fresh['status'] ) { return true; }
        return $this->apply( $claim, $response );
    }
    private function apply( $claim, $response ) {
        if ( ! empty( $response['metadata']['billing_persist_failed'] ) ) {
            $metadata = $response['metadata']; $metadata['billing_persist_failed'] = false; $metadata['error_message'] = '';
            $reconciled = $this->campaigns->finish_usage( $metadata, array( 'campaign_step_id' => $claim['step_id'], 'campaign_attempt_id' => $claim['attempt_id'] ) );
            if ( is_wp_error( $reconciled ) ) { $this->campaigns->stop( $claim['campaign_id'], 'review', $reconciled->get_error_message() ); return $reconciled; }
            $response['metadata'] = $metadata;
            if ( isset( $response['value']['metadata'] ) ) { $response['value']['metadata'] = $metadata; }
        }
        $topic_lock = 'admit' === $claim['kind'];
        if ( $topic_lock && ! $this->queue->lock_topics() ) { return new \WP_Error( 'acm_topics_busy', __( 'پذیرش موضوع در حال اجراست؛ نتیجه ذخیره‌شده بعداً اعمال می‌شود.', 'ai-content-manager' ) ); }
        try {
            $review = null;
            if ( $topic_lock ) {
                $source = $this->campaigns->discovery( $claim['campaign_id'] );
                $discovered = $source ? json_decode( $source['output'], true ) : null;
                if ( ! $source || ( $discovered['value']['topics'] ?? null ) !== ( $response['value']['topics'] ?? null ) ) { return $this->admission_error( $claim, new \WP_Error( 'acm_discovery_changed', __( 'خروجی موضوع‌یابی با نتیجه منتظر پذیرش همسان نیست؛ برای حفظ سابقه، پذیرش انجام نشد.', 'ai-content-manager' ) ) ); }
                $corpus = $this->queue->duplicate_corpus(); if ( is_wp_error( $corpus ) ) { return $this->admission_error( $claim, $corpus ); }
                $campaign = $this->campaigns->get( $claim['campaign_id'] );
                $review = ( new TopicDiversity() )->review( $response['value']['topics'], $campaign['keyword'], $corpus, (int) $campaign['target_count'] );
                // بیرون از تراکنش اعمال ذخیره می‌شود تا رد همهٔ پیشنهادها یا rollback، دلیل‌ها را پاک نکند.
                $recorded = $this->campaigns->record_admission( $campaign, $source, $review, 'worker', $claim['step_id'] );
                if ( is_wp_error( $recorded ) ) { return $this->admission_error( $claim, $recorded ); }
            }
            $result = $this->campaigns->finish( $claim, function ( $stored, $campaign ) use ( $claim, $response, $review ) {
                $value = $response['value'];
                switch ( $claim['kind'] ) {
                    case 'discover': return $this->campaigns->update_campaign( $campaign['campaign_id'], array( 'phase' => 'admit', 'next_run_at' => $this->campaigns->date() ) );
                    case 'admit':
                        $policy = GenerationPolicy::values();
                        if ( $policy['max_generation_items'] && (int) $campaign['target_count'] > $policy['max_generation_items'] ) { return new \WP_Error( 'acm_items_limit', __( 'تعداد هدف از سقف فعلی آیتم‌ها بیشتر است؛ خروجی موضوع‌یابی حفظ شد و پذیرش نیازمند بررسی تنظیمات است.', 'ai-content-manager' ) ); }
                        $topics = $review['accepted'];
                        if ( ! $topics ) { return new \WP_Error( 'acm_no_topics', __( 'با معیار این بررسی هیچ پیشنهادی پذیرفته نشد؛ دلیل، امتیاز و مرجع مقایسه در پیشنهادهای ذخیره‌شده قابل بررسی است. خروجی و مصرف قبلی حفظ شدند.', 'ai-content-manager' ) ); }
                        $ids = $this->queue->admit_campaign( $claim, $campaign, $topics ); if ( is_wp_error( $ids ) ) { return $ids; }
                        return $this->campaigns->update_campaign( $campaign['campaign_id'], array( 'phase' => 'articles', 'accepted_count' => count( $ids ), 'next_run_at' => $this->campaigns->date(),
                            'last_error' => count( $ids ) < $campaign['target_count'] ? __( 'موضوع مستقل کمتر از هدف پیدا شد؛ تعداد واقعی نمایش داده شده و موضوع تکراری وارد نشد.', 'ai-content-manager' ) : '' ) );
                    case 'research': return $this->queue->campaign_stage( $claim, 'fetch', $value );
                    case 'fetch': return $this->queue->campaign_stage( $claim, 'verified' === $value['status'] ? 'generate' : 'fetch', $value );
                    case 'generate': return $this->queue->campaign_output( $claim, $value );
                    case 'draft':
                    case 'seo':
                        $item = $this->queue->get( $claim['item_id'] ); $post = get_post( (int) $value['post_id'] );
                        if ( ! $post || 'draft' !== $post->post_status || (int) $item['post_id'] !== (int) $value['post_id'] || ! current_user_can( 'edit_post', $post->ID ) ||
                            $post->post_content_filtered !== 'acm-draft:' . $item['item_id'] . ':' . $item['draft_token'] ) { return new \WP_Error( 'acm_draft_changed', __( 'پیش‌نویس مرتبط یا وضعیت آن تغییر کرده است؛ انتشار یا ساخت نوشته جایگزین انجام نمی‌شود.', 'ai-content-manager' ) ); }
                        $changed = $this->queue->campaign_stage( $claim, $value['next_stage'] ); if ( is_wp_error( $changed ) ) { return $changed; }
                        if ( 'done' !== $value['next_stage'] ) { return true; }
                        global $wpdb;
                        $completed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acm_queue WHERE campaign_id = %d AND campaign_stage = 'done'", $campaign['campaign_id'] ) );
                        return $this->campaigns->update_campaign( $campaign['campaign_id'], array( 'completed_count' => $completed,
                            'status' => $completed >= $campaign['accepted_count'] ? 'completed' : 'active', 'next_run_at' => $completed >= $campaign['accepted_count'] ? null : $this->campaigns->next_day(),
                            'last_error' => $campaign['accepted_count'] < $campaign['target_count'] ? __( 'کمپین با موضوع مستقل کمتر از هدف پایان می‌یابد؛ سهمیه با تکرار تکمیل نمی‌شود.', 'ai-content-manager' ) : '' ) );
                }
                return new \WP_Error( 'acm_stage', __( 'مرحله قابل اعمال نیست.', 'ai-content-manager' ) );
            } );
        } finally { if ( $topic_lock ) { $this->queue->unlock_topics(); } }
        if ( is_wp_error( $result ) && 'acm_claim_stopped' !== $result->get_error_code() ) { $this->campaigns->stop( $claim['campaign_id'], 'review', __( 'نتیجه مرحله ذخیره شده اما اعمال آن کامل نشد؛ ادامه همان نتیجه را بدون تولید دوباره بررسی می‌کند. ', 'ai-content-manager' ) . $result->get_error_message() ); }
        return $result;
    }
    private function admission_error( $claim, $error ) {
        $this->campaigns->stop( $claim['campaign_id'], 'review', $error->get_error_message() ); return $error;
    }
}
