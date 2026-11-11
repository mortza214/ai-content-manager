<?php
namespace AIContentManager\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ArticleOutputValidator {
    public static function schema() {
        $string = array( 'type' => 'string' );
        $properties = array();
        foreach ( array( 'title', 'slug', 'excerpt', 'content', 'primary_keyword', 'suggested_meta_description', 'image_prompt' ) as $field ) {
            $properties[ $field ] = $string;
        }
        $properties['secondary_keywords'] = array( 'type' => 'array', 'items' => $string );
        $properties['headings'] = array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => false,
            'properties' => array( 'level' => array( 'type' => 'integer', 'enum' => array( 2, 3 ) ), 'text' => $string ), 'required' => array( 'level', 'text' ) ) );
        $properties['faq'] = array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => false,
            'properties' => array( 'question' => $string, 'answer' => $string ), 'required' => array( 'question', 'answer' ) ) );
        return array( 'type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys( $properties ) );
    }

    public function validate( $raw ) {
        $object = is_string( $raw ) ? json_decode( $raw ) : null;
        if ( ! $this->matches( $object, self::schema() ) ) {
            return new \WP_Error( 'acm_invalid_json', __( 'ساختار JSON یا فیلدهای الزامی معتبر نیست.', 'ai-content-manager' ) );
        }
        $data = json_decode( $raw, true );
        if ( '' === sanitize_title( $data['slug'] ) || empty( $data['headings'] ) ||
            preg_match( '/<\s*\/?\s*h1\b/i', $data['content'] ) ||
            ! preg_match( '/<(?:p|h2|h3|ul|ol|blockquote)\b/i', $data['content'] ) ||
            '' === trim( wp_strip_all_tags( $data['content'] ) ) ) {
            return new \WP_Error( 'acm_invalid_html', __( 'محتوا باید HTML مقاله بدون H1 و دارای تیترهای معتبر باشد.', 'ai-content-manager' ) );
        }
        $safe = wp_kses( $data['content'], self::allowed_html() );
        if ( $safe !== $data['content'] ) {
            return new \WP_Error( 'acm_unsafe_html', __( 'محتوا شامل HTML غیرمجاز است.', 'ai-content-manager' ) );
        }
        return $data;
    }

    /** Preserve strict generation validation, but keep invalid SEO independent of draft content. */
    public function validate_for_draft( $raw ) {
        $data = is_string( $raw ) ? json_decode( $raw, true ) : null;
        if ( ! is_array( $data ) ) { return $this->validate( $raw ); }
        $article = $data;
        foreach ( array( 'primary_keyword', 'suggested_meta_description' ) as $field ) {
            if ( array_key_exists( $field, $article ) ) { $article[ $field ] = 'seo-validation'; }
        }
        $valid = $this->validate( wp_json_encode( $article ) );
        return is_wp_error( $valid ) ? $valid : $data;
    }

    public static function allowed_html() {
        return array( 'p' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(), 'ul' => array(), 'ol' => array(),
            'li' => array(), 'strong' => array(), 'em' => array(), 'b' => array(), 'i' => array(), 'br' => array(),
            'blockquote' => array(), 'a' => array( 'href' => true, 'title' => true ), 'code' => array(), 'pre' => array(),
            'table' => array(), 'thead' => array(), 'tbody' => array(), 'tr' => array(), 'th' => array(), 'td' => array() );
    }

    private function matches( $value, $schema ) {
        if ( 'string' === $schema['type'] ) {
            return is_string( $value ) && '' !== trim( $value );
        }
        if ( 'integer' === $schema['type'] ) {
            return is_int( $value ) && in_array( $value, $schema['enum'], true );
        }
        if ( 'array' === $schema['type'] ) {
            if ( ! is_array( $value ) ) { return false; }
            foreach ( $value as $entry ) {
                if ( ! $this->matches( $entry, $schema['items'] ) ) { return false; }
            }
            return true;
        }
        if ( ! is_object( $value ) || count( get_object_vars( $value ) ) !== count( $schema['properties'] ) ) { return false; }
        foreach ( $schema['properties'] as $key => $child ) {
            if ( ! property_exists( $value, $key ) || ! $this->matches( $value->$key, $child ) ) { return false; }
        }
        return true;
    }
}
