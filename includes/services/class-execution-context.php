<?php
namespace AIContentManager\Services;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Internal lease-bound owner scope. No controller accepts this object or a client user_id. */
final class ExecutionContext {
    private static $claim;
    private static $repository;
    public static function active() { return null !== self::$claim; }
    public static function claim() { return self::$claim; }
    public static function repository() { return self::$repository; }
    public static function run( $repository, $claim, $callback ) {
        if ( self::active() || ! $repository->valid_claim( $claim ) ) { return new \WP_Error( 'acm_claim', __( 'شناسه یا مهلت اجرای داخلی معتبر نیست.', 'ai-content-manager' ) ); }
        $campaign = $repository->get( $claim['campaign_id'] );
        $owner = $campaign ? get_userdata( (int) $campaign['owner_id'] ) : null;
        if ( ! $owner || ! user_can( $owner, 'manage_ai_content' ) || ! user_can( $owner, 'edit_posts' ) ) {
            $repository->stop( $claim['campaign_id'], 'paused', __( 'مالک کمپین حذف شده یا مجوز فعلی تولید را ندارد.', 'ai-content-manager' ) );
            return new \WP_Error( 'acm_owner', __( 'مجوز فعلی مالک کمپین معتبر نیست؛ اجرای تازه متوقف شد.', 'ai-content-manager' ) );
        }
        $previous = get_current_user_id();
        self::$claim = $claim; self::$repository = $repository;
        try {
            wp_set_current_user( (int) $campaign['owner_id'] );
            return call_user_func( $callback );
        } finally {
            wp_set_current_user( $previous ); self::$claim = null; self::$repository = null;
        }
    }
    public static function may_request( $context ) {
        if ( ! self::active() || ! self::$repository->valid_claim( self::$claim ) ||
            (int) ( $context['campaign_step_id'] ?? 0 ) !== (int) self::$claim['step_id'] ||
            ( $context['campaign_attempt_id'] ?? '' ) !== self::$claim['attempt_id'] || '0' === get_option( 'acm_campaign_enabled', '1' ) ) { return false; }
        $campaign = self::$repository->get( self::$claim['campaign_id'] );
        $owner = $campaign ? get_userdata( (int) $campaign['owner_id'] ) : null;
        return $owner && (int) $campaign['owner_id'] === get_current_user_id() && 'active' === $campaign['status'] &&
            user_can( $owner, 'manage_ai_content' ) && user_can( $owner, 'edit_posts' );
    }
    /** Completion of an already-started request survives pause/revocation, never authorizes a new one. */
    public static function may_persist( $item_id ) {
        return self::active() && (int) self::$claim['item_id'] === (int) $item_id && self::$repository->valid_claim( self::$claim );
    }
}
