<?php
namespace AIContentManager\Services;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit, expiring price attestation; calculations are estimates, never invoices. */
final class CampaignPricing {
    const MODEL = 'gpt-6-luna';
    const SOURCE = 'https://developers.openai.com/api/docs/pricing';
    const REVIEWED = '2026-11-11';
    public static function money( $value ) {
        if ( ! is_scalar( $value ) || ! preg_match( '/^(\d{1,6})(?:\.(\d{1,6}))?$/D', (string) $value, $m ) ) { return false; }
        return (int) $m[1] * 1000000 + (int) str_pad( $m[2] ?? '', 6, '0' );
    }
    public static function display( $micros ) { return number_format( (int) $micros / 1000000, 6, '.', '' ) . ' USD'; }
    public static function snapshot() { return get_option( 'acm_campaign_pricing', array() ); }
    public static function valid( $rates, $now = null ) {
        $now = null === $now ? time() : $now;
        return is_array( $rates ) && ( $rates['model'] ?? '' ) === self::MODEL && ( $rates['source'] ?? '' ) === self::SOURCE &&
            is_numeric( $rates['confirmed_at'] ?? null ) && $rates['confirmed_at'] <= $now && $rates['confirmed_at'] >= $now - 30 * 86400 &&
            (int) ( $rates['input'] ?? 0 ) > 0 && (int) ( $rates['output'] ?? 0 ) > 0 && (int) ( $rates['search'] ?? 0 ) > 0;
    }
    public static function cost( $input, $output, $search, $rates ) {
        return (int) ceil( ( $input * $rates['input'] + $output * $rates['output'] ) / 1000000 ) + (int) ceil( $search * $rates['search'] / 1000 );
    }
    public static function estimate( $target, $calls, $rates ) {
        $limits = GenerationPolicy::values();
        $per_call = self::cost( ( $limits['max_input_tokens'] ?: 30000 ) + 131072, min( 12000, $limits['max_output_tokens'] ?: 8000 ), 1, $rates );
        return array( 'planned_requests' => 1 + 2 * $target, 'maximum_requests' => 2 + $target * $calls,
            'maximum_estimate' => $per_call * ( 2 + $target * $calls ), 'per_call' => $per_call );
    }
}
