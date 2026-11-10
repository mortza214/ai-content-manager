<?php
namespace AIContentManager\Services;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared, bounded settings. Zero disables a budget limit, never recovery. */
final class GenerationPolicy {
    public static function defaults() {
        return array( 'daily_request_limit' => 20, 'monthly_request_limit' => 300,
            'daily_token_limit' => 200000, 'monthly_token_limit' => 3000000,
            'max_output_tokens' => 8000, 'max_input_tokens' => 30000, 'max_generation_items' => 20,
            'generating_timeout_minutes' => 15, 'recovery_mode' => 'failed' );
    }

    public static function sanitize( $input ) {
        $values = self::defaults();
        foreach ( $values as $key => $default ) {
            if ( 'recovery_mode' === $key ) {
                $values[ $key ] = isset( $input[ $key ] ) && in_array( $input[ $key ], array( 'failed', 'pending' ), true ) ? $input[ $key ] : $default;
            } elseif ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) && preg_match( '/^\d+$/D', (string) $input[ $key ] ) ) {
                $maximum = 'generating_timeout_minutes' === $key ? 10080 : 1000000000;
                $values[ $key ] = (int) min( $maximum, (float) $input[ $key ] );
                if ( 'generating_timeout_minutes' === $key ) { $values[ $key ] = max( 2, $values[ $key ] ); }
            }
        }
        return $values;
    }

    public static function values() {
        $saved = get_option( 'acm_settings', array() );
        return self::sanitize( is_array( $saved ) ? $saved : array() );
    }
}
