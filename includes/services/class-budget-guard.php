<?php
namespace AIContentManager\Services;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Reads existing usage, reserves atomically, then replaces estimates with actual usage. */
final class BudgetGuard {
    private $repository;
    public function __construct( $repository = null ) { $this->repository = $repository ?: new QueueRepository(); }

    /** Conservative byte-based estimate including protocol overhead; not an exact tokenizer. */
    public function estimate_input_tokens( $body ) {
        return strlen( wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) + 256;
    }

    /** Calendar boundaries follow the WordPress site timezone; stored timestamps are UTC. */
    public function summary( $now = null ) {
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $this->error( __( 'مجوز مشاهده مصرف را ندارید.', 'ai-content-manager' ) ); }
        $now = $now ?: ( ExecutionContext::active() ? new \DateTimeImmutable( '@' . ExecutionContext::repository()->now() ) : new \DateTimeImmutable( 'now', wp_timezone() ) );
        $now = $now->setTimezone( wp_timezone() );
        $day = $now->setTime( 0, 0 );
        $month = $day->modify( 'first day of this month' );
        $empty = array( 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'reserved_tokens' => 0, 'unknown_requests' => 0,
            'search_calls' => 0, 'reserved_search_calls' => 0, 'cost_micros' => 0, 'reserved_cost_micros' => 0, 'unknown_cost_requests' => 0 );
        $summary = array( 'day' => $empty, 'month' => $empty );
        $limits = GenerationPolicy::values();
        $legacy_reserve = ( $limits['max_input_tokens'] ?: 30000 ) + ( $limits['max_output_tokens'] ?: 12000 );
        try {
            foreach ( $this->repository->usage_metadata() as $metadata ) {
                if ( isset( $metadata['attempted'] ) && ! $metadata['attempted'] ) { continue; }
                if ( empty( $metadata['requested_at'] ) ) { continue; }
                if ( ! isset( $metadata['total_tokens'] ) && isset( $metadata['input_tokens'], $metadata['output_tokens'] ) ) {
                    $metadata['total_tokens'] = max( 0, (int) $metadata['input_tokens'] ) + max( 0, (int) $metadata['output_tokens'] );
                }
                try { $when = new \DateTimeImmutable( $metadata['requested_at'], new \DateTimeZone( 'UTC' ) ); } catch ( \Exception $error ) { continue; }
                if ( $when > $now || $when < $month ) { continue; }
                foreach ( array( 'day' => $day, 'month' => $month ) as $period => $start ) {
                    if ( $when < $start ) { continue; }
                    $summary[ $period ]['requests']++;
                    foreach ( array( 'input_tokens', 'output_tokens', 'total_tokens' ) as $key ) {
                        $summary[ $period ][ $key ] += max( 0, (int) ( $metadata[ $key ] ?? 0 ) );
                    }
                    if ( ! isset( $metadata['total_tokens'] ) ) {
                        $summary[ $period ]['unknown_requests']++;
                        $summary[ $period ]['reserved_tokens'] += max( 0, (int) ( $metadata['reserved_tokens'] ?? $legacy_reserve ) );
                    }
                    $summary[ $period ]['search_calls'] += (int) ( $metadata['search_calls'] ?? 0 );
                    if ( ! isset( $metadata['search_calls'] ) ) { $summary[ $period ]['reserved_search_calls'] += (int) ( $metadata['reserved_search_calls'] ?? 0 ); }
                    if ( isset( $metadata['actual_cost_micros'] ) ) { $summary[ $period ]['cost_micros'] += (int) $metadata['actual_cost_micros']; }
                    else { $summary[ $period ]['unknown_cost_requests']++; $summary[ $period ]['reserved_cost_micros'] += (int) ( $metadata['reserved_cost_micros'] ?? 0 ); }
                }
            }
        } catch ( \Throwable $error ) { return $this->error( __( 'خواندن گزارش مصرف ممکن نشد؛ برای حفظ بودجه درخواست جدید مسدود است.', 'ai-content-manager' ) ); }
        return $summary;
    }

    public function check_limits( $summary, $input, $output, $limits, $extra = 0 ) {
        if ( $limits['max_input_tokens'] && $input > $limits['max_input_tokens'] ) { return $this->error( __( 'تخمین توکن ورودی از سقف هر درخواست بیشتر است.', 'ai-content-manager' ) ); }
        if ( $limits['max_output_tokens'] && $output > $limits['max_output_tokens'] ) { return $this->error( __( 'توکن خروجی درخواستی از سقف هر درخواست بیشتر است.', 'ai-content-manager' ) ); }
        foreach ( array( 'day' => 'daily', 'month' => 'monthly' ) as $period => $prefix ) {
            $request_limit = $limits[ $prefix . '_request_limit' ];
            $token_limit = $limits[ $prefix . '_token_limit' ];
            $used = $summary[ $period ]['total_tokens'] + $summary[ $period ]['reserved_tokens'];
            if ( $request_limit && $summary[ $period ]['requests'] >= $request_limit ) {
                return $this->error( 'day' === $period ? __( 'سقف تعداد درخواست روزانه OpenAI رسیده است.', 'ai-content-manager' ) : __( 'سقف تعداد درخواست ماهانه OpenAI رسیده است.', 'ai-content-manager' ) );
            }
            if ( $token_limit && ( $used >= $token_limit || $used + $input + $output + $extra > $token_limit ) ) {
                return $this->error( 'day' === $period ? __( 'بودجه توکن روزانه برای این درخواست کافی نیست.', 'ai-content-manager' ) : __( 'بودجه توکن ماهانه برای این درخواست کافی نیست.', 'ai-content-manager' ) );
            }
        }
        return true;
    }

    /** The callback is the only transport path; it is never called when a guard fails. */
    public function execute( $model, $body, $context, $transport ) {
        $metadata = array( 'model' => $model, 'requested_at' => gmdate( 'c' ), 'input_tokens' => null, 'output_tokens' => null,
            'total_tokens' => null, 'request_time' => 0, 'error_message' => '', 'attempted' => false, 'http_status' => null, 'request_id' => '' );
        $denied = function ( $error ) use ( &$metadata ) {
            $metadata['error_message'] = $error->get_error_message();
            return array( 'response' => $error, 'metadata' => $metadata );
        };
        if ( ! current_user_can( 'manage_ai_content' ) ) { return $denied( $this->error( __( 'مجوز اجرای درخواست OpenAI را ندارید.', 'ai-content-manager' ) ) ); }
        $campaign = ! empty( $context['campaign_step_id'] );
        if ( $campaign && ! ExecutionContext::may_request( $context ) ) { return $denied( $this->error( __( 'کمپین، مالک یا مهلت اجرای داخلی مجوز درخواست تازه ندارد.', 'ai-content-manager' ) ) ); }
        $limits = GenerationPolicy::values();
        $input = null === $body ? 0 : $this->estimate_input_tokens( $body );
        $output = null === $body ? 0 : (int) ( $body['max_completion_tokens'] ?? $body['max_output_tokens'] ?? 0 );
        $search = $campaign && ! empty( $body['tools'] ) ? 1 : 0;
        $extra = $search ? 131072 : 0;
        $rates = null;
        if ( $campaign ) {
            $claim = ExecutionContext::claim(); $row = ExecutionContext::repository()->get( $claim['campaign_id'] ); $config = json_decode( $row['config'], true ); $rates = $config['rates'] ?? null;
            if ( ! CampaignPricing::valid( $rates, ExecutionContext::repository()->now() ) || $rates !== CampaignPricing::snapshot() || $model !== CampaignPricing::MODEL ) { $metadata['budget_denied'] = true; return $denied( $this->error( __( 'مدل یا قیمت تأییدشده کمپین معتبر نیست یا نرخ‌ها تغییر کرده‌اند؛ ادامه با بازبینی نرخ لازم است.', 'ai-content-manager' ) ) ); }
            $metadata['requested_at'] = gmdate( 'c', ExecutionContext::repository()->now() );
            $metadata['campaign_step_id'] = $claim['step_id']; $metadata['campaign_id'] = $claim['campaign_id']; $metadata['item_id'] = $claim['item_id']; $metadata['stage'] = $claim['kind'];
            $metadata['rates'] = $rates; $metadata['reserved_search_calls'] = $search; $metadata['search_calls'] = $search ? null : 0;
            $metadata['reserved_cost_micros'] = CampaignPricing::cost( $input + $extra, $output, $search, $rates );
            $metadata['actual_cost_micros'] = null;
        }
        if ( ! $this->repository->lock_usage() ) { return $denied( $this->error( __( 'مصرف در حال بررسی است؛ دوباره تلاش کنید.', 'ai-content-manager' ) ) ); }
        try {
            $summary = $this->summary();
            $check = is_wp_error( $summary ) ? $summary : $this->check_limits( $summary, $input, $output, $limits, $extra );
            if ( is_wp_error( $check ) ) { $metadata['budget_denied'] = true; return $denied( $check ); }
            if ( $campaign && ! ExecutionContext::may_request( $context ) ) { return $denied( $this->error( __( 'کمپین پیش از رزرو متوقف شد؛ درخواست تازه ارسال نشد.', 'ai-content-manager' ) ) ); }
            $metadata['attempted'] = true;
            $metadata['attempt_id'] = wp_generate_uuid4();
            $metadata['estimated_input_tokens'] = $input;
            $metadata['reserved_tokens'] = $input + $output + $extra;
            $ticket = $this->repository->reserve_usage( $metadata, $context );
            if ( is_wp_error( $ticket ) ) { $metadata['attempted'] = false; $metadata['budget_denied'] = 'acm_campaign_budget' === $ticket->get_error_code(); return $denied( $ticket ); }
        } catch ( \Throwable $error ) { return $denied( $this->error( __( 'ثبت رزرو مصرف انجام نشد؛ درخواست ارسال نشد.', 'ai-content-manager' ) ) ); }
        finally { $this->repository->unlock_usage(); }

        $started = microtime( true );
        try {
            if ( $campaign && ! ExecutionContext::may_request( $context ) ) {
                // Proven non-dispatch is the only safe zero-cost release on this path.
                $metadata['not_dispatched'] = true; $metadata['attempted'] = false;
                $metadata['input_tokens'] = $metadata['output_tokens'] = $metadata['total_tokens'] = $metadata['reserved_tokens'] = 0;
                $metadata['search_calls'] = $metadata['reserved_search_calls'] = $metadata['actual_cost_micros'] = $metadata['reserved_cost_micros'] = 0;
                $response = $this->error( __( 'کمپین پیش از ارسال متوقف شد؛ درخواست تازه اجرا نشد و رزرو برای بررسی حفظ است.', 'ai-content-manager' ) );
            } else { $response = $transport(); }
            if ( is_wp_error( $response ) ) {
                $metadata['error_message'] = ! empty( $metadata['not_dispatched'] ) ? __( 'کمپین پیش از ارسال متوقف شد؛ بدون ارسال اثبات‌شده، هزینه‌ای ثبت نشد.', 'ai-content-manager' ) : __( 'ارتباط با OpenAI ناموفق بود یا زمان درخواست پایان یافت؛ مصرف نامشخص به‌صورت محافظه‌کارانه رزرو می‌ماند.', 'ai-content-manager' );
            } else {
                $metadata['http_status'] = wp_remote_retrieve_response_code( $response );
                $metadata['request_id'] = sanitize_text_field( wp_remote_retrieve_header( $response, 'x-request-id' ) );
                $data = json_decode( wp_remote_retrieve_body( $response ), true );
                foreach ( array( 'prompt_tokens' => 'input_tokens', 'completion_tokens' => 'output_tokens', 'total_tokens' => 'total_tokens' ) as $source => $target ) {
                    if ( isset( $data['usage'][ $source ] ) && is_numeric( $data['usage'][ $source ] ) ) { $metadata[ $target ] = max( 0, (int) $data['usage'][ $source ] ); }
                }
                if ( $campaign ) {
                    foreach ( array( 'input_tokens', 'output_tokens', 'total_tokens' ) as $key ) { $value = $data['usage'][ $key ] ?? null; $metadata[ $key ] = is_int( $value ) && $value >= 0 ? $value : null; }
                    if ( isset( $metadata['input_tokens'], $metadata['output_tokens'], $metadata['total_tokens'] ) && $metadata['total_tokens'] < $metadata['input_tokens'] + $metadata['output_tokens'] ) { $metadata['input_tokens'] = $metadata['output_tokens'] = $metadata['total_tokens'] = null; }
                    if ( $search && isset( $data['output'] ) && is_array( $data['output'] ) ) {
                        $metadata['search_calls'] = 0;
                        foreach ( $data['output'] as $part ) {
                            if ( 'web_search_call' !== ( $part['type'] ?? '' ) ) { continue; }
                            if ( 'completed' !== ( $part['status'] ?? '' ) ) { $metadata['search_calls'] = null; break; }
                            $metadata['search_calls']++; // Each completed hosted tool invocation may be billable, including open/find.
                        }
                    }
                }
                if ( ! isset( $metadata['total_tokens'] ) && isset( $metadata['input_tokens'], $metadata['output_tokens'] ) ) {
                    $metadata['total_tokens'] = $metadata['input_tokens'] + $metadata['output_tokens'];
                }
                if ( null === $body ) { $metadata['input_tokens'] = $metadata['output_tokens'] = $metadata['total_tokens'] = 0; }
                if ( $campaign && isset( $metadata['input_tokens'], $metadata['output_tokens'], $metadata['search_calls'] ) &&
                    preg_match( '/^' . preg_quote( CampaignPricing::MODEL, '/' ) . '(?:-\d{4}-\d{2}-\d{2})?$/D', $data['model'] ?? '' ) && in_array( $data['service_tier'] ?? 'default', array( 'default', 'standard' ), true ) ) {
                    $metadata['actual_cost_micros'] = CampaignPricing::cost( $metadata['input_tokens'], $metadata['output_tokens'], $metadata['search_calls'], $rates );
                }
                if ( 200 !== $metadata['http_status'] ) { $metadata['error_message'] = sprintf( __( 'خطای OpenAI با کد HTTP %d.', 'ai-content-manager' ), $metadata['http_status'] ); }
            }
        } catch ( \Throwable $error ) {
            $response = $this->error( __( 'درخواست OpenAI کامل نشد.', 'ai-content-manager' ) );
            $metadata['error_message'] = $response->get_error_message();
        }
        $metadata['request_time'] = round( microtime( true ) - $started, 3 );
        if ( ! $this->repository->lock_usage() ) {
            $metadata['billing_persist_failed'] = true;
            $metadata['error_message'] = __( 'نتیجه درخواست دریافت شد اما ثبت قطعی مصرف ممکن نشد؛ رزرو محافظه‌کارانه حفظ شده است.', 'ai-content-manager' );
        } else {
            try {
                $saved = $this->repository->finish_usage( $metadata, $ticket );
                if ( is_wp_error( $saved ) ) { $metadata['billing_persist_failed'] = true; $metadata['error_message'] = $saved->get_error_message(); }
            } catch ( \Throwable $error ) {
                $metadata['billing_persist_failed'] = true;
                $metadata['error_message'] = __( 'ثبت نتیجه مصرف ممکن نشد؛ رزرو مصرف حفظ شده است.', 'ai-content-manager' );
            } finally { $this->repository->unlock_usage(); }
        }
        return array( 'response' => $response, 'metadata' => $metadata );
    }

    private function error( $message ) { return new \WP_Error( 'acm_budget_guard', $message ); }
}
