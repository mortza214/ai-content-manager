<?php
/** Admin menu registration.
 *
 * @package AIContentManager
 */

namespace AIContentManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Registers a single plugin page and redirects legacy bookmarks. */
final class Menu {
    private $page;

    /** @param AdminPage $page Tabbed page coordinator. */
    public function __construct( AdminPage $page ) {
        $this->page = $page;
        add_action( 'admin_init', array( $this, 'redirect_legacy' ) );
    }

    /** Register content workflow, queue, and settings screens. */
    public function register() {
        $hook = add_menu_page(
            __( 'مدیریت محتوای هوشمند', 'ai-content-manager' ),
            __( 'مدیریت محتوای هوشمند', 'ai-content-manager' ),
            'manage_ai_content',
            'acm-content',
            array( $this->page, 'render' ),
            'dashicons-edit-page'
        );
        $this->page->set_hook( $hook );
    }

    public function redirect_legacy() {
        if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return; }
        $slug = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $tabs = array( 'acm-queue' => 'queue', 'ai-content-manager' => 'settings' );
        if ( ! isset( $tabs[ $slug ] ) ) { return; }
        $this->page->authorize( $tabs[ $slug ] );
        wp_safe_redirect( $this->page->legacy_url( $tabs[ $slug ] ) );
        exit;
    }
}
