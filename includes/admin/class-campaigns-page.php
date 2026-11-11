<?php
namespace AIContentManager\Admin;
use AIContentManager\Services\CampaignRepository;
use AIContentManager\Services\CampaignService;
use AIContentManager\Services\CampaignPricing;
use AIContentManager\Services\BatchProcessor;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Campaign controls live in the existing queue tab; every mutation uses authenticated POST. */
final class CampaignsPage {
    private $repository;
    private $service;
    public function __construct( $repository = null, $service = null ) {
        $this->repository = $repository ?: new CampaignRepository();
        $this->service = $service ?: new CampaignService( $this->repository );
        add_action( 'admin_post_acm_campaign_control', array( $this, 'handle' ) );
    }
    public static function stage_label( $stage ) {
        return array( 'articles' => 'پردازش مرحله‌ای مقاله‌ها', 'discover' => 'موضوع‌یابی', 'admit' => 'بررسی تنوع موضوع', 'research' => 'پژوهش وب', 'fetch' => 'بررسی منابع', 'generate' => 'تولید مقاله', 'draft' => 'تکمیل پیش‌نویس', 'seo' => 'بررسی سئو', 'done' => 'آماده بررسی برای انتشار' )[ $stage ] ?? 'نیازمند بررسی';
    }
    public function command( $input ) {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_ai_content' ) || ! is_array( $input ) ) { return new \WP_Error( 'acm_forbidden', 'مجوز مدیریت کمپین و ارسال معتبر لازم است.' ); }
        $id = $input['campaign_id'] ?? '';
        if ( ! is_scalar( $id ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $id ) || ! is_string( $input['_wpnonce'] ?? null ) || ! wp_verify_nonce( $input['_wpnonce'], 'acm_campaign_' . $id ) ) { return new \WP_Error( 'acm_nonce', 'اعتبار فرم کمپین منقضی یا نامعتبر است؛ صفحه را دوباره باز کنید.' ); }
        switch ( $input['operation'] ?? '' ) {
            case 'start': return $this->service->start( (int) $id, '1' === ( $input['confirm_cost'] ?? '' ), $input['quote'] ?? '' );
            case 'pause': case 'end': return $this->service->control( (int) $id, $input['operation'] );
            case 'budget': return $this->service->budget( (int) $id, $input['budget'] ?? '', $input['max_calls'] ?? '' );
        }
        return new \WP_Error( 'acm_operation', 'عملیات کمپین معتبر نیست.' );
    }
    public function handle() {
        $result = $this->command( wp_unslash( $_POST ) );
        if ( ! is_wp_error( $result ) ) { ( new BatchProcessor() )->schedule(); }
        set_transient( 'acm_campaign_notice_' . get_current_user_id(), array( 'error' => is_wp_error( $result ), 'message' => is_wp_error( $result ) ? $result->get_error_message() : 'عملیات ثبت شد؛ وضعیت تازه کمپین را بررسی کنید.' ), 600 );
        wp_safe_redirect( AdminPage::url( 'queue' ) ); exit;
    }
    private function form( $id, $operation ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="acm_campaign_control"><input type="hidden" name="campaign_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
        wp_nonce_field( 'acm_campaign_' . $id );
    }
    public function render() {
        if ( ! current_user_can( 'manage_ai_content' ) ) { return; }
        $notice = get_transient( 'acm_campaign_notice_' . get_current_user_id() );
        if ( is_array( $notice ) ) { delete_transient( 'acm_campaign_notice_' . get_current_user_id() ); echo '<div class="notice ' . ( ! empty( $notice['error'] ) ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>'; }
        echo '<h2>کمپین‌های کلمه کلیدی</h2><p>ایجاد کمپین هیچ هزینه‌ای ندارد. شروع جداگانه تأیید می‌شود؛ خروجی فقط پیش‌نویس و نیازمند بازبینی انسانی است. هر نوبت یک مرحله انجام می‌شود. WP-Cron به ترافیک سایت وابسته است؛ ساعت دقیق تضمین نمی‌شود.</p>';
        if ( ! CampaignRepository::available() ) { echo '<p role="alert">ساختار کمپین آماده نیست؛ وضعیت ارتقای افزایشی دیتابیس را بررسی کنید.</p>'; return; }
        $states = array( 'draft' => 'غیرفعال', 'active' => 'در حال اجرا', 'paused' => 'متوقف', 'review' => 'نیازمند بررسی', 'budget' => 'متوقف به علت محدودیت مصرف', 'ended' => 'پایان دستی', 'completed' => 'تکمیل‌شده؛ نیازمند بازبینی انسانی' );
        $rows = $this->repository->list_campaigns( current_user_can( 'manage_options' ) ? 0 : get_current_user_id() );
        if ( ! $rows ) { echo '<p>هنوز کمپینی ثبت نشده است؛ در تب تولید محتوا حالت خودکار را انتخاب کنید.</p>'; }
        foreach ( $rows ?: array() as $row ) {
            $id = (int) $row['campaign_id']; $config = json_decode( $row['config'], true ); $quote = $this->service->quote( $row ); $sum = $this->repository->money_summary( $id );
            echo '<details><summary>' . esc_html( '#' . $id . ' — ' . $row['keyword'] . ' — ' . ( $states[ $row['status'] ] ?? 'نامشخص' ) ) . '</summary>';
            echo '<p>' . esc_html( sprintf( 'زبان: %s؛ هدف: %d؛ موضوع پذیرفته: %d؛ پیش‌نویس تکمیل‌شده: %d؛ مرحله: %s؛ سقف: %s؛ فراخوانی هر مقاله: %d', $row['language'], $row['target_count'], $row['accepted_count'], $row['completed_count'], self::stage_label( $row['phase'] ), CampaignPricing::display( $config['budget_micros'] ), $config['max_calls'] ) ) . '</p>';
            echo '<p>' . esc_html( 'دسته‌بندی: ' . ( new \AIContentManager\Services\QueueCategory() )->describe( $config['category'] ) . '؛ نوبت بعدی: ' . ( $row['next_run_at'] ? get_date_from_gmt( $row['next_run_at'], 'Y-m-d H:i' ) : 'زمان‌بندی نشده' ) ) . '</p>';
            if ( ! empty( $row['last_error'] ) ) { echo '<p role="status">' . esc_html( $row['last_error'] ) . '</p>'; }
            if ( is_wp_error( $sum ) ) { echo '<p role="alert">' . esc_html( $sum->get_error_message() ) . '</p>'; }
            else { echo '<p>' . esc_html( sprintf( 'درخواست: %d؛ جست‌وجوی ثبت‌شده: %d؛ برآورد مصرف ثبت‌شده: %s؛ رزرو آزاد نشده: %s؛ تلاش با مصرف نامشخص: %d. مبالغ برآوردند و صورتحساب نیستند.', $sum['requests'], $sum['search'], CampaignPricing::display( $sum['actual'] ), CampaignPricing::display( $sum['reserved'] ), $sum['unknown'] ) ) . '</p>'; }
            if ( $quote['valid'] ) { echo '<p>' . esc_html( sprintf( 'مدل پژوهش: %s؛ درخواست برنامه‌ریزی‌شده: %d؛ حداکثر با تلاش مجدد: %d؛ برآورد محافظه‌کارانه حداکثر: %s؛ رزرو هر درخواست: %s. سقف کمپین می‌تواند اجرای کامل را متوقف کند.', CampaignPricing::MODEL, $quote['estimate']['planned_requests'], $quote['estimate']['maximum_requests'], CampaignPricing::display( $quote['estimate']['maximum_estimate'] ), CampaignPricing::display( $quote['estimate']['per_call'] ) ) ) . '</p>'; }
            else { echo '<p role="alert">قیمت معتبر و تازه تأیید نشده است؛ هزینه صفر نیست. پیش از درخواست هزینه‌دار، نرخ مدل و جست‌وجو را در تنظیمات تأیید کنید.</p>'; }
            if ( in_array( $row['status'], array( 'draft', 'paused', 'review', 'budget' ), true ) ) {
                $this->form( $id, 'start' ); echo '<input type="hidden" name="quote" value="' . esc_attr( $quote['signature'] ) . '"><label><input type="checkbox" name="confirm_cost" value="1" required> شروع/ادامه و احتمال هزینه تا سقف کمپین، از جمله تلاش مجدد پس از مصرف نامشخص، را تأیید می‌کنم.</label> <button class="button" type="submit">شروع یا ادامه کمپین</button></form>';
                $this->form( $id, 'budget' ); echo '<p><label>سقف دلار <input name="budget" type="number" min="0.01" max="1000" step="0.01" value="' . esc_attr( $config['budget_micros'] / 1000000 ) . '"></label> <label>فراخوانی هر مقاله <input name="max_calls" type="number" min="2" max="5" value="' . esc_attr( $config['max_calls'] ) . '"></label> <button type="submit" class="button">اصلاح محدودیت‌ها بدون شروع</button></p></form>';
            }
            if ( 'active' === $row['status'] ) { $this->form( $id, 'pause' ); echo '<button class="button" type="submit">توقف؛ حفظ نتیجه درحال‌اجرا</button></form>'; }
            if ( ! in_array( $row['status'], array( 'ended', 'completed' ), true ) ) { $this->form( $id, 'end' ); echo '<button class="button" type="submit">پایان کمپین؛ حفظ سوابق</button></form>'; }
            echo '<details><summary>سابقه مراحل و مصرف</summary><ul>';
            foreach ( $this->repository->steps( $id ) ?: array() as $step ) {
                $meta = json_decode( $step['api_metadata'] ?? '', true );
                $labels = array( 'claimed' => 'رزروشده برای اجرا', 'reserved' => 'رزرو هزینه', 'result' => 'نتیجه محفوظ؛ هنوز اعمال نشده', 'complete' => 'تکمیل', 'failed' => 'ناموفق', 'unknown' => 'مصرف نامشخص', 'late' => 'پاسخ دیررس؛ اعمال نشده' );
                echo '<li>' . esc_html( '#' . $step['step_id'] . '؛ آیتم #' . $step['item_id'] . '؛ ' . ( 'control' === $step['kind'] ? 'کنترل کمپین' : self::stage_label( $step['kind'] ) ) . '؛ ' . ( $labels[ $step['state'] ] ?? 'نامشخص' ) . '؛ ' . $step['error_message'] . ( $meta ? '؛ توکن: ' . ( $meta['total_tokens'] ?? 'نامشخص' ) . '؛ جست‌وجو: ' . ( $meta['search_calls'] ?? 'نامشخص' ) : '' ) ) . '</li>';
            }
            echo '</ul></details></details><hr>';
        }
    }
}
