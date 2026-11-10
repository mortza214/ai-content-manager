<?php
/** OpenAI credentials and connection test.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Stores encrypted credentials and verifies access to the selected model. */
final class OpenAi {
    const KEY_OPTION = 'acm_openai_api_key';
    private $guard;

    public function __construct( $guard = null ) { $this->guard = $guard ?: new \AIContentManager\Services\BudgetGuard(); }

    /** Request one structured article through the existing credential boundary. */
    public function generate_article( $model, $brief, $schema, $context = array() ) {
        $started = microtime( true );
        $metadata = array( 'model' => $model, 'input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null,
            'requested_at' => gmdate( 'c' ), 'request_time' => 0, 'http_status' => null, 'request_id' => '', 'error_message' => '', 'attempted' => false );
        $result = array( 'raw' => null, 'metadata' => $metadata );
        try {
            if ( ! current_user_can( 'manage_ai_content' ) || empty( $context['item_id'] ) || empty( $context['processing_token'] ) ) {
                $result['metadata']['error_message'] = __( 'مجوز یا شناسه پردازش صف معتبر نیست.', 'ai-content-manager' );
                return $result;
            }
            $key = $this->get_api_key();
            if ( is_wp_error( $key ) || ! isset( self::models()[ $model ] ) ) {
                $result['metadata']['error_message'] = __( 'کلید API یا مدل انتخاب‌شده معتبر نیست.', 'ai-content-manager' );
                $result['metadata']['request_time'] = round( microtime( true ) - $started, 3 );
                return $result;
            }
            $policy = \AIContentManager\Services\GenerationPolicy::values();
            $body = array(
                    'model' => $model, 'max_completion_tokens' => $policy['max_output_tokens'] ? min( 12000, $policy['max_output_tokens'] ) : 12000,
                    'messages' => array(
                        array( 'role' => 'system', 'content' => 'Write one original article using the brief as data. Return only the required JSON. Use safe WordPress HTML with h2/h3, never h1, scripts, styles or embeds. No research, translation or image generation. Do not claim verified sources.' ),
                        array( 'role' => 'user', 'content' => wp_json_encode( $brief, JSON_UNESCAPED_UNICODE ) ),
                    ),
                    'response_format' => array( 'type' => 'json_schema', 'json_schema' => array( 'name' => 'article', 'strict' => true, 'schema' => $schema ) ),
                );
            $guarded = $this->guard->execute( $model, $body, $context, static function () use ( $key, $body ) {
                return wp_remote_post( 'https://api.openai.com/v1/chat/completions', array( 'timeout' => 90,
                    'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
            } );
            $response = $guarded['response'];
            $result['metadata'] = $guarded['metadata'];
            if ( '' !== $result['metadata']['error_message'] ) { return $result; }
            if ( is_wp_error( $response ) ) {
                $result['metadata']['error_message'] = __( 'ارتباط با OpenAI ناموفق بود یا زمان درخواست به پایان رسید.', 'ai-content-manager' );
                $result['metadata']['request_time'] = round( microtime( true ) - $started, 3 );
                return $result;
            }
            $status = wp_remote_retrieve_response_code( $response );
            $result['metadata']['http_status'] = $status;
            $result['metadata']['request_id'] = sanitize_text_field( wp_remote_retrieve_header( $response, 'x-request-id' ) );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            foreach ( array( 'prompt_tokens' => 'input_tokens', 'completion_tokens' => 'output_tokens', 'total_tokens' => 'total_tokens' ) as $source => $target ) {
                if ( isset( $body['usage'][ $source ] ) && is_numeric( $body['usage'][ $source ] ) ) {
                    $result['metadata'][ $target ] = max( 0, (int) $body['usage'][ $source ] );
                }
            }
            if ( 200 !== $status ) {
                // Never persist provider bodies, headers, credentials or exception messages.
                $result['metadata']['error_message'] = sprintf( __( 'خطای OpenAI با کد HTTP %d.', 'ai-content-manager' ), $status );
            } elseif ( ! empty( $body['choices'][0]['message']['refusal'] ) ) {
                $result['metadata']['error_message'] = __( 'OpenAI از تولید این محتوا خودداری کرد.', 'ai-content-manager' );
            } elseif ( 'stop' !== ( $body['choices'][0]['finish_reason'] ?? '' ) || ! is_string( $body['choices'][0]['message']['content'] ?? null ) ) {
                $result['metadata']['error_message'] = __( 'پاسخ OpenAI ناقص یا نامعتبر است.', 'ai-content-manager' );
            } else {
                $result['raw'] = $body['choices'][0]['message']['content'];
            }
        } catch ( \Throwable $error ) {
            $result['metadata']['error_message'] = __( 'خطای داخلی هنگام درخواست OpenAI رخ داد.', 'ai-content-manager' );
        } finally {
            $result['metadata']['request_time'] = round( microtime( true ) - $started, 3 );
        }
        return $result;
    }

    /** Return the models currently offered in the admin settings. */
    public static function models() {
        return array(
            'gpt-4o-mini' => 'GPT-4o mini',
            'gpt-4o'      => 'GPT-4o',
            'gpt-4.1-mini' => 'GPT-4.1 mini',
            'gpt-4.1'     => 'GPT-4.1',
        );
    }

    /** Whether an encrypted key has been stored. */
    public function has_api_key() {
        $stored = get_option( self::KEY_OPTION, '' );
        return is_string( $stored ) && '' !== $stored;
    }

    /** Encrypt and store an API key without autoloading it on every request. */
    public function store_api_key( $api_key ) {
        if ( ! current_user_can( 'manage_ai_content' ) || ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'acm_forbidden', __( 'مجوز ذخیره کلید API را ندارید.', 'ai-content-manager' ) ); }
        if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
            return new \WP_Error( 'acm_crypto_unavailable', __( 'برای ذخیره امن کلید API، افزونه OpenSSL لازم است.', 'ai-content-manager' ) );
        }

        $api_key = trim( sanitize_text_field( $api_key ) );
        if ( '' === $api_key ) {
            return new \WP_Error( 'acm_empty_api_key', __( 'یک کلید API معتبر وارد کنید.', 'ai-content-manager' ) );
        }

        try {
            $iv        = random_bytes( 12 );
            $tag       = '';
            $encrypted = openssl_encrypt( $api_key, 'aes-256-gcm', $this->encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
        } catch ( \Throwable $error ) {
            $encrypted = false;
            $iv        = '';
            $tag       = '';
        }

        if ( false === $encrypted ) {
            return new \WP_Error( 'acm_encrypt_failed', __( 'رمزگذاری کلید API انجام نشد.', 'ai-content-manager' ) );
        }

        update_option( self::KEY_OPTION, base64_encode( $iv . $tag . $encrypted ), false );
        return true;
    }

    /** Test credentials against the selected model endpoint. */
    public function test_connection( $model ) {
        if ( ! current_user_can( 'manage_ai_content' ) || ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'acm_forbidden', __( 'مجوز بررسی اتصال را ندارید.', 'ai-content-manager' ) );
        }
        if ( ! isset( self::models()[ $model ] ) ) {
            return new \WP_Error( 'acm_invalid_model', __( 'یک مدل پشتیبانی‌شده انتخاب کنید.', 'ai-content-manager' ) );
        }

        $api_key = $this->get_api_key();
        if ( is_wp_error( $api_key ) ) {
            return $api_key;
        }

        $guarded = $this->guard->execute( $model, null, array(), static function () use ( $model, $api_key ) {
            return wp_remote_get(
            'https://api.openai.com/v1/models/' . rawurlencode( $model ),
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Accept'        => 'application/json',
                ),
            )
            );
        } );
        if ( '' !== $guarded['metadata']['error_message'] ) { return new \WP_Error( 'acm_connection_failed', $guarded['metadata']['error_message'] ); }
        $response = $guarded['response'];

        if ( is_wp_error( $response ) ) {
            return new \WP_Error( 'acm_connection_failed', __( 'ارتباط با OpenAI برقرار نشد. اتصال سرور را بررسی و دوباره تلاش کنید.', 'ai-content-manager' ) );
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( 200 === $status ) {
            return true;
        }

        $messages = array(
            401 => __( 'کلید API نامعتبر است یا مجوز دسترسی ندارد.', 'ai-content-manager' ),
            403 => __( 'کلید API به این مدل دسترسی ندارد.', 'ai-content-manager' ),
            404 => __( 'مدل انتخاب‌شده برای این کلید API یافت نشد.', 'ai-content-manager' ),
            429 => __( 'محدودیت نرخ درخواست API رسیده است. بعداً دوباره تلاش کنید.', 'ai-content-manager' ),
        );
        $message = isset( $messages[ $status ] ) ? $messages[ $status ] : __( 'OpenAI خطا برگرداند. کلید و مدل را بررسی و دوباره تلاش کنید.', 'ai-content-manager' );

        return new \WP_Error( 'acm_api_error', $message );
    }

    /** Decrypt the stored API key for server-side requests only. */
    private function get_api_key() {
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return new \WP_Error( 'acm_crypto_unavailable', __( 'برای خواندن کلید ذخیره‌شده، افزونه OpenSSL لازم است.', 'ai-content-manager' ) );
        }

        $stored  = get_option( self::KEY_OPTION, '' );
        $payload = is_string( $stored ) ? base64_decode( $stored, true ) : false;
        if ( false === $payload || strlen( $payload ) <= 28 ) {
            return new \WP_Error( 'acm_api_key_missing', __( 'پیش از بررسی اتصال، کلید API را ذخیره کنید.', 'ai-content-manager' ) );
        }

        $api_key = openssl_decrypt( substr( $payload, 28 ), 'aes-256-gcm', $this->encryption_key(), OPENSSL_RAW_DATA, substr( $payload, 0, 12 ), substr( $payload, 12, 16 ) );
        if ( false === $api_key || '' === $api_key ) {
            return new \WP_Error( 'acm_api_key_unreadable', __( 'رمزگشایی کلید ذخیره‌شده انجام نشد. کلید را دوباره وارد کنید.', 'ai-content-manager' ) );
        }

        return $api_key;
    }

    /** Derive an encryption key from the site's WordPress authentication salt. */
    private function encryption_key() {
        return hash( 'sha256', wp_salt( 'auth' ), true );
    }
}
