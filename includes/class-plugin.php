<?php
/** Plugin application entry point.
 *
 * @package AIContentManager
 */

namespace AIContentManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Coordinates plugin modules. */
final class Plugin {
    /** @var Plugin|null */
    private static $instance = null;

    /** @var bool */
    private $initialized = false;

    /** @return Plugin */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /** Register the modules that are active in this initial release. */
    public function init() {
        if ( $this->initialized ) {
            return;
        }

        $this->initialized = true;
        load_plugin_textdomain( 'ai-content-manager', false, dirname( plugin_basename( ACM_PLUGIN_FILE ) ) . '/languages' );
        Infrastructure\Installer::maybe_upgrade();

        if ( is_admin() ) {
            $settings = new Admin\Settings();
            $repository    = new Services\QueueRepository();
            $content_types = new Services\ContentTypeRegistry();
            $wpml          = new Integrations\Wpml();
            $queue         = new Services\ContentQueue( $repository, $content_types );
            $content_page  = new Admin\ContentPage( $queue, $content_types, $wpml );
            $queue_page    = new Admin\QueueManagerPage( $repository, $content_types, $wpml );
            new Admin\QueueActions( new Services\DraftCreator( $repository, $wpml ), new Services\QueueTrash( $repository ), $queue_page );
            $generation = new Admin\QueueGeneration( new Services\ContentGeneratorService( $repository ) );
            $admin_page = new Admin\AdminPage( $settings, $content_page, $queue_page, $generation );
            $menu = new Admin\Menu( $admin_page );
            new Admin\OpenAiConnection();

            add_action( 'admin_init', array( $settings, 'register' ) );
            add_action( 'admin_menu', array( $menu, 'register' ) );
        }
    }
}
