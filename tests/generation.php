<?php
// Isolated contract tests; no network or WordPress installation required.
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text, $domain = '' ) { return $text; }
function current_user_can( $cap ) { return true; }
function get_current_user_id() { return 1; }
function get_option( $key, $default = null ) { return $default; }
function sanitize_title( $value ) { return trim( $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_kses( $value, $allowed ) { return strip_tags( $value, '<p><h2><h3><a>' ); }
require __DIR__ . '/../includes/services/class-article-output-validator.php';
require __DIR__ . '/../includes/services/class-content-generator-service.php';
require __DIR__ . '/../includes/services/class-campaign-repository.php';
function verify( $condition, $label ) {
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    echo "PASS: $label\n";
}
class MemoryQueue {
    public function lock_worker() { return true; }
    public function generation_busy() { return false; }
    public function unlock_worker() {}
    public $status = 'pending';
    public $events = array();
    public $output;
    public $metadata;
    public $reject_claim = false;
    public function get( $id ) { return array( 'status' => $this->status, 'processing_token' => 'fixture-token', 'payload' => '{"topic":"Example","web_research":true,"target_languages":["fr"]}', 'language' => 'en' ); }
    public function transition_generation( $id, $from, $to, $user, $metadata = null, $output = null ) {
        if ( $this->status !== $from || ( $this->reject_claim && 'generating' === $to ) ) { return new WP_Error( 'conflict', 'conflict' ); }
        $this->events[] = $to;
        $this->status = $to;
        $this->metadata = $metadata;
        $this->output = $output;
        return true;
    }
}
class FakeOpenAi {
    public $raw;
    public $error = '';
    public $calls = 0;
    public $throws = false;
    public $queue;
    public function generate_article( $model, $brief, $schema ) {
        $this->calls++;
        verify( 'generating' === $this->queue->status, 'claim before API' );
        verify( ! isset( $brief['web_research'], $brief['target_languages'] ), 'minimal article brief' );
        if ( $this->throws ) { throw new RuntimeException( 'secret' ); }
        return array( 'raw' => $this->raw, 'metadata' => array( 'model' => $model, 'input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30, 'request_time' => 1, 'error_message' => $this->error ) );
    }
}
$valid = array( 'title' => 'Example', 'slug' => 'example', 'excerpt' => 'Summary', 'content' => '<h2>Section</h2><p>Article</p>', 'primary_keyword' => 'example', 'secondary_keywords' => array( 'article' ), 'suggested_meta_description' => 'Description', 'headings' => array( array( 'level' => 2, 'text' => 'Section' ) ), 'faq' => array( array( 'question' => 'Why?', 'answer' => 'Because.' ) ), 'image_prompt' => 'An article illustration' );
$validator = new AIContentManager\Services\ArticleOutputValidator();
verify( ! is_wp_error( $validator->validate( json_encode( $valid ) ) ), 'valid output' );
foreach ( array( 'missing', 'type', 'h1', 'unsafe', 'json', 'extra', 'heading' ) as $case ) {
    $data = $valid;
    if ( 'missing' === $case ) { unset( $data['title'] ); }
    if ( 'type' === $case ) { $data['secondary_keywords'] = new stdClass(); }
    if ( 'h1' === $case ) { $data['content'] = '<h1>Title</h1><p>Article</p>'; }
    if ( 'unsafe' === $case ) { $data['content'] .= '<script>alert(1)</script>'; }
    if ( 'extra' === $case ) { $data['extra'] = 'unexpected'; }
    if ( 'heading' === $case ) { $data['headings'][0]['level'] = 1; }
    verify( is_wp_error( $validator->validate( 'json' === $case ? '{broken' : json_encode( $data ) ) ), 'reject ' . $case );
}
foreach ( array( 'success', 'invalid', 'timeout', 'exception', 'conflict', 'not_pending' ) as $case ) {
    $queue = new MemoryQueue();
    $api = new FakeOpenAi();
    $api->queue = $queue;
    $api->raw = 'invalid' === $case ? '{}' : json_encode( $valid );
    $api->error = 'timeout' === $case ? 'timeout' : '';
    $api->throws = 'exception' === $case;
    $queue->reject_claim = 'conflict' === $case;
    if ( 'not_pending' === $case ) { $queue->status = 'generated'; }
    $service = new AIContentManager\Services\ContentGeneratorService( $queue, $api, $validator );
    $result = $service->generate( 1, 1 );
    verify( ( 'success' === $case ) === ( true === $result ), 'result ' . $case );
    if ( in_array( $case, array( 'conflict', 'not_pending' ), true ) ) {
        verify( 0 === $api->calls, 'no API on conflict' );
    } else {
        verify( array( 'generating', 'success' === $case ? 'generated' : 'failed' ) === $queue->events, 'events ' . $case );
        verify( ( 'success' === $case ) === is_array( $queue->output ), 'output persistence ' . $case );
        verify( false === strpos( json_encode( $queue->metadata ), 'secret' ), 'no exception disclosure' );
    }
}
