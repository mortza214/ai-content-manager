<?php
/** Bounded web research for keyword campaigns.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Bounded hosted search plus safe, independently fetched evidence. No untrusted tools. */
final class WebResearch {
    private $openai;
    public function __construct( $openai = null ) { $this->openai = $openai ?: new \AIContentManager\Integrations\OpenAi(); }
    public static function intents() { return array( 'definition', 'comparison', 'implementation', 'troubleshooting', 'selection', 'examples', 'security', 'performance', 'cost', 'alternatives' ); }
    private static function object_schema( $properties ) { return array( 'type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys( $properties ) ); }
    public static function topics_schema() {
        $string = array( 'type' => 'string' ); $strings = array( 'type' => 'array', 'items' => $string );
        $topic = self::object_schema( array( 'title' => $string, 'intent' => array( 'type' => 'string', 'enum' => self::intents() ), 'question' => $string,
            'angle' => $string, 'summary' => $string, 'entities' => $strings, 'source_urls' => $strings ) );
        return self::object_schema( array( 'topics' => array( 'type' => 'array', 'items' => $topic ) ) );
    }
    public static function research_schema() {
        $string = array( 'type' => 'string' );
        return self::object_schema( array( 'sources' => array( 'type' => 'array', 'items' => self::object_schema( array( 'url' => $string, 'title' => $string ) ) ),
            'claims' => array( 'type' => 'array', 'items' => self::object_schema( array( 'claim' => $string, 'evidence' => $string, 'url' => $string ) ) ),
            'unsupported_claims' => array( 'type' => 'array', 'items' => $string ) ) );
    }
    private function error( $message ) { return new \WP_Error( 'acm_research', $message ); }
    public static function safe_url( $url ) {
        if ( ! is_string( $url ) || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) { return false; }
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) { return false; }
        $host = strtolower( $parts['host'] ?? '' );
        if ( ! preg_match( '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,63}$/D', $host ) ||
            preg_match( '/(?:^|\.)(?:localhost|local|internal|test|invalid)$/D', $host ) || in_array( $host, array( 'metadata.google.internal', 'instance-data.ec2.internal' ), true ) ||
            isset( $parts['query'] ) && preg_match( '/(?:^|[&;])(?:[^=&;]*(?:token|api[_-]?key|password|cookie|nonce|authorization|session)[^=&;]*)=/i', rawurldecode( $parts['query'] ) ) ) { return false; }
        return 'https://' . $host . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
    }
    public static function domain_allowed( $url, $domains ) {
        if ( ! $domains ) { return true; }
        $host = parse_url( $url, PHP_URL_HOST );
        foreach ( $domains as $domain ) { if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) { return true; } }
        return false;
    }
    private function plain( $value, $max = 1500 ) {
        return is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= $max && preg_match( '//u', $value ) && wp_strip_all_tags( $value ) === $value && ! preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value );
    }
    public function decode( $response, $require_search = true ) {
        $data = $response['data'] ?? null;
        if ( ! is_array( $data ) || ( ! empty( $response['metadata']['error_message'] ) && empty( $response['metadata']['billing_persist_failed'] ) ) ) { return $this->error( $response['metadata']['error_message'] ?? __( 'پاسخ پژوهش دریافت نشد.', 'ai-content-manager' ) ); }
        $text = ''; $urls = array(); $searches = 0;
        foreach ( $data['output'] ?? array() as $part ) {
            if ( 'web_search_call' === ( $part['type'] ?? '' ) && 'completed' === ( $part['status'] ?? '' ) && 'search' === ( $part['action']['type'] ?? '' ) ) {
                $searches++;
                foreach ( $part['action']['sources'] ?? array() as $source ) { $url = self::safe_url( $source['url'] ?? null ); if ( $url ) { $urls[ $url ] = true; } }
            }
            if ( 'message' !== ( $part['type'] ?? '' ) ) { continue; }
            foreach ( $part['content'] ?? array() as $content ) {
                if ( 'refusal' === ( $content['type'] ?? '' ) ) { return $this->error( __( 'مدل از پاسخ خودداری کرد؛ کمپین نیازمند بررسی است.', 'ai-content-manager' ) ); }
                if ( 'output_text' === ( $content['type'] ?? '' ) && is_string( $content['text'] ?? null ) ) { $text .= $content['text']; }
                foreach ( $content['annotations'] ?? array() as $annotation ) { if ( 'url_citation' === ( $annotation['type'] ?? '' ) ) { $url = self::safe_url( $annotation['url'] ?? null ); if ( $url ) { $urls[ $url ] = true; } } }
            }
        }
        if ( $require_search && ( 1 !== $searches || ! $urls ) ) { return $this->error( __( 'اجرای واقعی ابزار جست‌وجو یا منبع معتبر آن تأیید نشد؛ تولید بدون پژوهش متوقف است.', 'ai-content-manager' ) ); }
        $object = strlen( $text ) <= 200000 ? json_decode( $text, true ) : null;
        return is_array( $object ) ? array( 'value' => $object, 'retrieved_urls' => $urls ) : $this->error( __( 'ساختار پاسخ پژوهش معتبر نیست.', 'ai-content-manager' ) );
    }
    public function discover( $campaign, $context ) {
        $config = json_decode( $campaign['config'], true );
        $response = $this->openai->campaign_response( array( 'task' => 'Search primary reliable sources, then propose at most target_count independent article topics. Each topic must answer a different core question and search intent, not paraphrase another title. Fewer topics are better than duplicates. Every source_url must be from actual search results. Do not copy source text.',
            'keyword' => $campaign['keyword'], 'language' => $campaign['language'], 'target_count' => (int) $campaign['target_count'] ), self::topics_schema(), true, $context, $config['domains'] );
        $decoded = $this->decode( $response );
        if ( is_wp_error( $decoded ) ) { return array( 'error' => $decoded, 'metadata' => $response['metadata'] ); }
        $topics = $decoded['value']['topics'] ?? null;
        if ( ! is_array( $topics ) || count( $topics ) > min( 10, (int) $campaign['target_count'] ) ) { return array( 'error' => $this->error( __( 'تعداد یا ساختار موضوع‌ها معتبر نیست.', 'ai-content-manager' ) ), 'metadata' => $response['metadata'] ); }
        foreach ( $topics as $topic ) {
            foreach ( array( 'title' => 1000, 'question' => 1500, 'angle' => 1500, 'summary' => 2500 ) as $field => $max ) { if ( ! $this->plain( $topic[ $field ] ?? null, $max ) ) { return array( 'error' => $this->error( __( 'پروفایل موضوع کامل و معتبر نیست.', 'ai-content-manager' ) ), 'metadata' => $response['metadata'] ); } }
            if ( ! in_array( $topic['intent'] ?? '', self::intents(), true ) || ! is_array( $topic['entities'] ?? null ) || count( $topic['entities'] ) < 2 || count( $topic['entities'] ) > 12 || ! is_array( $topic['source_urls'] ?? null ) || ! $topic['source_urls'] || count( $topic['source_urls'] ) > 3 ) { return array( 'error' => $this->error( __( 'قصد جست‌وجو، موجودیت یا منبع موضوع معتبر نیست.', 'ai-content-manager' ) ), 'metadata' => $response['metadata'] ); }
            foreach ( $topic['entities'] as $entity ) { if ( ! $this->plain( $entity, 200 ) ) { return array( 'error' => $this->error( __( 'موجودیت موضوع معتبر نیست.', 'ai-content-manager' ) ), 'metadata' => $response['metadata'] ); } }
            foreach ( $topic['source_urls'] as $url ) { $safe = self::safe_url( $url ); if ( ! $safe || ! isset( $decoded['retrieved_urls'][ $safe ] ) || ! self::domain_allowed( $safe, $config['domains'] ) ) { return array( 'error' => $this->error( __( 'منبع موضوع در خروجی واقعی جست‌وجو پیدا نشد.', 'ai-content-manager' ) ), 'metadata' => $response['metadata'] ); } }
        }
        return array( 'value' => array( 'topics' => $topics ), 'metadata' => $response['metadata'] );
    }
    public function research( $item, $campaign, $context ) {
        $config = json_decode( $campaign['config'], true ); $payload = json_decode( $item['payload'], true );
        $response = $this->openai->campaign_response( array( 'task' => 'Research the topic using 2 or 3 distinct primary reliable source pages. Return 2 to 8 necessary factual claims with short exact verbatim evidence quotes (20 to 700 bytes) and source URLs. Prefer original official technical documentation. Report all unsupported or uncertain claims in unsupported_claims; do not invent claims, sources or quotes. The server will independently fetch each page and check every quote.',
            'topic' => $payload['topic_profile'], 'language' => $campaign['language'] ), self::research_schema(), true, $context, $config['domains'] );
        $decoded = $this->decode( $response );
        if ( is_wp_error( $decoded ) ) { return array( 'error' => $decoded, 'metadata' => $response['metadata'] ); }
        $value = $decoded['value']; $sources = $value['sources'] ?? null; $claims = $value['claims'] ?? null;
        if ( ! is_array( $value['unsupported_claims'] ?? null ) || $value['unsupported_claims'] || ! is_array( $sources ) || count( $sources ) < 2 || count( $sources ) > 3 || ! is_array( $claims ) || count( $claims ) < 2 || count( $claims ) > 8 ) { return array( 'error' => $this->error( __( 'پژوهش شامل ادعای تأییدنشده است یا منابع/شواهد کافی ندارد؛ تولید متوقف شد.', 'ai-content-manager' ) ), 'metadata' => $response['metadata'] ); }
        $safe_sources = array();
        foreach ( $sources as $source ) {
            $url = self::safe_url( $source['url'] ?? null );
            if ( ! $url || ! isset( $decoded['retrieved_urls'][ $url ] ) || ! self::domain_allowed( $url, $config['domains'] ) || isset( $safe_sources[ $url ] ) ) { return array( 'error' => $this->error( __( 'URL منبع با منابع واقعی ابزار یا دامنه‌های مجاز تطابق ندارد.', 'ai-content-manager' ) ), 'metadata' => $response['metadata'] ); }
            $safe_sources[ $url ] = array( 'url' => $url, 'title' => '', 'verified' => false, 'accessed_at' => null, 'evidence' => array() );
        }
        foreach ( $claims as &$claim ) {
            $url = self::safe_url( $claim['url'] ?? null );
            if ( ! $url || ! isset( $safe_sources[ $url ] ) || ! $this->plain( $claim['claim'] ?? null, 2000 ) || ! $this->plain( $claim['evidence'] ?? null, 700 ) || strlen( $claim['evidence'] ) < 20 ) { return array( 'error' => $this->error( __( 'ادعا یا شاهد پژوهش معتبر نیست.', 'ai-content-manager' ) ), 'metadata' => $response['metadata'] ); }
            $claim['url'] = $url; $safe_sources[ $url ]['evidence'][] = $claim['evidence'];
        } unset( $claim );
        foreach ( $safe_sources as $source ) { if ( ! $source['evidence'] ) { return array( 'error' => $this->error( __( 'منبع بدون شاهد قابل استفاده نیست.', 'ai-content-manager' ) ), 'metadata' => $response['metadata'] ); } }
        return array( 'value' => array( 'status' => 'pending', 'sources' => array_values( $safe_sources ), 'claims' => $claims, 'unsupported_claims' => array() ), 'metadata' => $response['metadata'] );
    }
    private static function normalize( $text ) { return trim( preg_replace( '/[\s\p{Z}]+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ); }
    public function fetch( $item, $index, $context ) {
        if ( ! ExecutionContext::may_request( $context ) ) { return $this->error( __( 'کمپین متوقف است یا مالک مجوز دریافت تازه ندارد.', 'ai-content-manager' ) ); }
        $research = json_decode( $item['research_output'] ?? '', true ); $source = $research['sources'][ $index ] ?? null;
        $url = $source ? self::safe_url( $source['url'] ?? null ) : false;
        if ( ! $url || ! wp_http_validate_url( $url ) ) { return $this->error( __( 'نشانی منبع ناامن، خصوصی یا نامعتبر است؛ دریافت انجام نشد.', 'ai-content-manager' ) ); }
        $response = wp_safe_remote_get( $url, array( 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 262144, 'sslverify' => true,
            'reject_unsafe_urls' => true, 'cookies' => array(), 'headers' => array( 'Accept' => 'text/html,text/plain' ) ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return $this->error( __( 'دریافت منبع ناموفق یا دارای redirect بود؛ پژوهش بدون شاهد تکمیل نمی‌شود.', 'ai-content-manager' ) ); }
        $body = wp_remote_retrieve_body( $response ); $type = strtolower( wp_remote_retrieve_header( $response, 'content-type' ) );
        if ( strlen( $body ) >= 262144 || ! preg_match( '//u', $body ) || ( false === strpos( $type, 'text/html' ) && false === strpos( $type, 'text/plain' ) ) ) { return $this->error( __( 'حجم، کدگذاری یا نوع منبع برای دریافت امن مناسب نیست.', 'ai-content-manager' ) ); }
        $plain = self::normalize( wp_strip_all_tags( preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $body ) ) );
        foreach ( $source['evidence'] as $evidence ) { if ( false === strpos( $plain, self::normalize( $evidence ) ) ) { return $this->error( __( 'شاهد ادعا در متن منبع دریافت‌شده پیدا نشد؛ تولید بدون تأیید متوقف است.', 'ai-content-manager' ) ); } }
        preg_match( '#<title\b[^>]*>(.*?)</title>#is', $body, $match );
        $title = isset( $match[1] ) ? self::normalize( wp_strip_all_tags( $match[1] ) ) : '';
        $source['title'] = '' !== $title ? preg_replace( '/^(.{0,250}).*$/us', '$1', $title ) : parse_url( $url, PHP_URL_HOST ) . ' — ' . __( 'منبع بدون عنوان صفحه', 'ai-content-manager' );
        $source['verified'] = true; $source['accessed_at'] = gmdate( 'c', ExecutionContext::repository()->now() );
        $research['sources'][ $index ] = $source;
        $research['status'] = count( array_filter( $research['sources'], static function ( $row ) { return ! empty( $row['verified'] ); } ) ) === count( $research['sources'] ) ? 'verified' : 'pending';
        return $research;
    }
    public function article_references( $output, $research ) {
        if ( 'verified' !== ( $research['status'] ?? '' ) ) { return $this->error( __( 'پژوهش معتبر برای تولید مقاله وجود ندارد.', 'ai-content-manager' ) ); }
        $urls = array_column( $research['sources'], null, 'url' );
        preg_match_all( '/href\s*=\s*([\x22\x27])(.*?)\1/is', $output['content'], $matches );
        foreach ( $matches[2] as $href ) { $url = self::safe_url( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) ); if ( ! $url || ! isset( $urls[ $url ] ) ) { return $this->error( __( 'مقاله شامل پیوندی خارج از منابع تأییدشده است و نیازمند بررسی است.', 'ai-content-manager' ) ); } }
        $output['content'] .= '<h2>' . __( 'منابع قابل بررسی', 'ai-content-manager' ) . '</h2><ul>';
        foreach ( $research['sources'] as $source ) { $output['content'] .= '<li><a href="' . esc_url( $source['url'] ) . '">' . esc_html( $source['title'] ) . '</a></li>'; }
        $output['content'] .= '</ul>';
        return $output;
    }
}
