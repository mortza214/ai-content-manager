<?php
/** Supported content type registry.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Resolves supported post types and permits future integrations to extend them. */
final class ContentTypeRegistry {
    /** Return currently available content types. */
    public function all() {
        $types = array(
            'post' => __( 'نوشته', 'ai-content-manager' ),
            'page' => __( 'برگه', 'ai-content-manager' ),
        );

        if ( post_type_exists( 'product' ) ) {
            $types['product'] = __( 'محصول', 'ai-content-manager' );
        }

        $types = apply_filters( 'acm_supported_content_types', $types );
        $valid = array();
        foreach ( is_array( $types ) ? $types : array() as $type => $label ) {
            $type = sanitize_key( $type );
            if ( post_type_exists( $type ) && is_string( $label ) ) {
                $valid[ $type ] = $label;
            }
        }

        return $valid;
    }

    /** Check whether a post type is enabled for queue requests. */
    public function supports( $type ) {
        $types = $this->all();
        return isset( $types[ $type ] );
    }
}