<?php
/** Queue status definitions.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Defines supported queue lifecycle states and their labels. */
final class QueueStatus {
    /** Return the supported status labels. */
    public static function all() {
        return array(
            'pending'         => __( 'در انتظار', 'ai-content-manager' ),
            'researching'     => __( 'در حال پژوهش', 'ai-content-manager' ),
            'generating'      => __( 'در حال تولید', 'ai-content-manager' ),
            'generated'       => __( 'تولیدشده', 'ai-content-manager' ),
            'awaiting_review' => __( 'در انتظار بازبینی', 'ai-content-manager' ),
            'approved'        => __( 'تأییدشده', 'ai-content-manager' ),
            'scheduled'       => __( 'زمان‌بندی‌شده', 'ai-content-manager' ),
            'published'       => __( 'منتشرشده', 'ai-content-manager' ),
            'failed'          => __( 'ناموفق', 'ai-content-manager' ),
            'trashed'         => __( 'زباله‌دان', 'ai-content-manager' ),
        );
    }

    /** Check whether a status is supported. */
    public static function is_valid( $status ) {
        return isset( self::all()[ $status ] );
    }
}
