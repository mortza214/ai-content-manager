<?php
/** Content generation compatibility facade.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Keep the original service entry point backed by the single generation engine. */
final class ContentGenerator {
    private $service;

    public function __construct( $repository = null, $openai = null, $validator = null ) {
        $this->service = new ContentGeneratorService( $repository ?: new QueueRepository(), $openai, $validator );
    }

    public function generate( $item_id, $user_id ) {
        return $this->service->generate( $item_id, $user_id );
    }
}
