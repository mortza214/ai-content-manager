<?php
namespace AIContentManager\Services;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class QueueRecovery {
    private $repository;
    public function __construct( $repository ) { $this->repository = $repository; }
    public function cutoff( $now = null ) {
        $settings = GenerationPolicy::values();
        return gmdate( 'Y-m-d H:i:s', ( null === $now ? time() : $now ) - 60 * $settings['generating_timeout_minutes'] );
    }
    public function count() {
        if ( ! current_user_can( 'manage_ai_content' ) ) { return new \WP_Error( 'acm_forbidden', __( 'مجوز بازیابی صف را ندارید.', 'ai-content-manager' ) ); }
        return $this->repository->count_stale( $this->cutoff() );
    }
    public function recover() {
        if ( ! current_user_can( 'manage_ai_content' ) ) { return new \WP_Error( 'acm_forbidden', __( 'مجوز بازیابی صف را ندارید.', 'ai-content-manager' ) ); }
        $settings = GenerationPolicy::values();
        return $this->repository->recover_stale( $this->cutoff(), $settings['recovery_mode'] );
    }
}
