<?php
namespace AIContentManager\Services;

use AIContentManager\Integrations\OpenAi;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ContentGeneratorService {
    private $repository;
    private $openai;
    private $validator;

    public function __construct( $repository, $openai = null, $validator = null ) {
        $this->repository = $repository;
        $this->openai = $openai ?: new OpenAi();
        $this->validator = $validator ?: new ArticleOutputValidator();
    }

    /** Generate exactly one pending item. No posts or other workflows are invoked. */
    public function generate( $item_id, $user_id ) {
        if ( ! current_user_can( 'manage_ai_content' ) ) {
            return new \WP_Error( 'acm_forbidden', __( 'مجوز تولید مقاله را ندارید.', 'ai-content-manager' ) );
        }
        $user_id = get_current_user_id();
        $item = $this->repository->get( $item_id );
        if ( ! $item || 'pending' !== $item['status'] ) {
            return new \WP_Error( 'acm_not_pending', __( 'فقط آیتم در انتظار قابل تولید است.', 'ai-content-manager' ) );
        }
        $claimed = $this->repository->transition_generation( $item_id, 'pending', 'generating', $user_id );
        if ( is_wp_error( $claimed ) ) { return $claimed; }
        $claimed_item = $this->repository->get( $item_id );
        $processing_token = $claimed_item['processing_token'] ?? null;
        $settings = get_option( 'acm_settings', array() );
        $model = is_array( $settings ) && isset( $settings['openai_model'] ) ? $settings['openai_model'] : 'gpt-4o-mini';
        $metadata = array( 'model' => $model, 'input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null,
            'request_time' => 0, 'requested_at' => gmdate( 'c' ), 'error_message' => '', 'attempted' => false );
        $started = microtime( true );
        $output = null;
        try {
            $payload = json_decode( $item['payload'], true );
            if ( ! is_array( $payload ) || empty( $payload['topic'] ) || empty( $item['language'] ) ) {
                $metadata['error_message'] = __( 'اطلاعات موضوع یا زبان آیتم معتبر نیست.', 'ai-content-manager' );
            } else {
                $brief = array_intersect_key( $payload, array_flip( array( 'topic', 'primary_keyword', 'secondary_keywords', 'keyword_mode', 'word_count', 'tone' ) ) );
                $brief['language'] = $item['language'];
                $response = $this->openai->generate_article( $model, $brief, ArticleOutputValidator::schema(), array( 'item_id' => $item_id, 'processing_token' => $processing_token ) );
                $metadata = $response['metadata'];
                if ( '' === $metadata['error_message'] ) {
                    $output = $this->validator->validate( $response['raw'] );
                    if ( is_wp_error( $output ) ) {
                        $metadata['error_message'] = $output->get_error_message();
                        $output = null;
                    }
                }
            }
        } catch ( \Throwable $error ) {
            $metadata['error_message'] = __( 'خطای داخلی در پردازش محتوا رخ داد.', 'ai-content-manager' );
            $output = null;
        }
        if ( empty( $metadata['request_time'] ) ) { $metadata['request_time'] = round( microtime( true ) - $started, 3 ); }
        // Preserve an already-reserved attempt if a transport extension threw unexpectedly.
        $current = $this->repository->get( $item_id );
        $reserved = json_decode( $current['api_metadata'] ?? '', true );
        if ( empty( $metadata['attempted'] ) && ! empty( $reserved['attempted'] ) && ( $current['processing_token'] ?? null ) === $processing_token ) {
            $metadata = array_merge( $reserved, array( 'error_message' => $metadata['error_message'] ) );
        }
        $status = null !== $output ? 'generated' : 'failed';
        $saved = $this->repository->transition_generation( $item_id, 'generating', $status, $user_id, $metadata, $output, $processing_token );
        if ( is_wp_error( $saved ) && 'generated' === $status ) {
            $metadata['error_message'] = __( 'ذخیره خروجی تولیدشده انجام نشد.', 'ai-content-manager' );
            $this->repository->transition_generation( $item_id, 'generating', 'failed', $user_id, $metadata, null, $processing_token );
        }
        if ( is_wp_error( $saved ) ) { return $saved; }
        return 'generated' === $status ? true : new \WP_Error( 'acm_generation_failed', $metadata['error_message'] );
    }
}
