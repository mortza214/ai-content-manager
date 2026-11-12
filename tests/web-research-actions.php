<?php
/** Responses tool-action regression tests; all HTTP and WordPress calls are fixtures. */
define( 'ACM_TOPIC_BOOTSTRAP_ONLY', true );
require __DIR__ . '/campaigns.php';
use AIContentManager\Services\WebResearch;

function action_response( $type, $status = 'completed', $url = 'https://docs.example.org/first' ) {
    return array( 'data' => array( 'output' => array(
        array( 'type' => 'web_search_call', 'status' => $status, 'action' => array( 'type' => $type, 'url' => $url ) ),
        array( 'type' => 'message', 'content' => array( array( 'type' => 'output_text', 'text' => '{"sources":[]}' ) ) ),
    ) ), 'metadata' => array() );
}
$research = new WebResearch();
foreach ( array( 'open_page', 'find_in_page' ) as $action ) {
    $result = $research->decode( action_response( $action ) );
    verify( ! is_wp_error( $result ) && isset( $result['retrieved_urls']['https://docs.example.org/first'] ), 'completed ' . $action . ' supplies a grounded source without search-only rejection' );
    verify( is_wp_error( $research->decode( action_response( $action, 'failed' ) ) ), 'failed ' . $action . ' cannot authorize research' );
    verify( is_wp_error( $research->decode( action_response( $action, 'completed', 'http://127.0.0.1/private' ) ) ), 'unsafe ' . $action . ' URL is rejected' );
}
verify( is_wp_error( $research->decode( action_response( 'unknown_action' ) ) ), 'unknown tool action cannot authorize research' );
$response = action_response( 'open_page' ); unset( $response['data']['output'][0] );
$response['data']['output'][1]['content'][0]['annotations'] = array( array( 'type' => 'url_citation', 'url' => 'https://docs.example.org/first' ) );
verify( is_wp_error( $research->decode( $response ) ), 'citations without actual completed web tool remain insufficient' );

// Full worker pipeline: topic discovery is a search, article research opens a page.
setup_campaign(); $id = create_started();
$original = $GLOBALS['fixture_http'];
$GLOBALS['fixture_http'] = static function ( $url, $args ) use ( $original ) {
    $response = $original( $url, $args );
    $body = json_decode( $args['body'], true );
    if ( isset( $body['text']['format']['schema']['properties']['sources'] ) ) {
        $data = json_decode( $response['body'], true );
        $data['output'][0]['action'] = array( 'type' => 'open_page', 'url' => 'https://docs.example.org/first' );
        $data['output'][1]['content'][0]['annotations'] = array( array( 'type' => 'url_citation', 'url' => 'https://docs.example.org/second' ) );
        $response['body'] = wp_json_encode( $data );
    }
    return $response;
};
for ( $day = 0; $day < 3; $day++ ) {
    if ( $day ) { $clock = strtotime( $campaigns->get( $id )['next_run_at'] . ' UTC' ); }
    for ( $step = 0; $step < ( $day ? 5 : 7 ); $step++ ) { step_ok(); }
}
verify( 'completed' === $campaigns->get( $id )['status'] && 3 === $insert_calls && 7 === $api_calls, 'open-page research reaches three actual linked draft fixtures within existing call limits' );
foreach ( $repo->find( array(), 20, 0 ) as $item ) {
    $post = get_post( $item['post_id'] );
    verify( 'draft' === $post->post_status && '' !== $post->post_content && 'done' === $item['campaign_stage'], 'article text and unpublished linked draft both exist after research' );
}
verify( 4 === $campaigns->money_summary( $id )['search'], 'open-page tool usage remains in the existing monetary ledger' );
