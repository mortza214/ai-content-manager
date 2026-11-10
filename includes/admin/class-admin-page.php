<?php
/** Coordinates navigation without owning section forms or business logic. */
namespace AIContentManager\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AdminPage {
    private $settings;
    private $content;
    private $queue;
    private $generation;
    private $hook = '';

    public function __construct( $settings, $content, $queue, $generation ) {
        $this->settings = $settings;
        $this->content = $content;
        $this->queue = $queue;
        $this->generation = $generation;
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    public function set_hook( $hook ) { $this->hook = $hook; }

    public static function active_tab() {
        $tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'content';
        return in_array( $tab, array( 'content', 'queue', 'settings' ), true ) ? $tab : 'content';
    }

    public static function url( $tab, $args = array() ) {
        $tab = in_array( $tab, array( 'content', 'queue', 'settings' ), true ) ? $tab : 'content';
        return add_query_arg( array_merge( $args, array( 'page' => 'acm-content', 'tab' => $tab ) ), admin_url( 'admin.php' ) );
    }

    public function authorize( $tab ) {
        if ( ! current_user_can( 'manage_ai_content' ) || ( 'settings' === $tab && ! current_user_can( 'manage_options' ) ) ) {
            wp_die( esc_html__( 'اجازه دسترسی به این بخش را ندارید.', 'ai-content-manager' ), '', array( 'response' => 403 ) );
        }
    }

    public function legacy_url( $tab ) {
        $args = 'queue' === $tab ? $this->queue->navigation_args() : array();
        if ( 'settings' === $tab && isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
            $args['settings-updated'] = 'true';
        }
        return self::url( $tab, $args );
    }

    public function enqueue( $hook ) {
        if ( ! $this->hook || $hook !== $this->hook ) { return; }
        $tab = self::active_tab();
        $this->authorize( $tab );
        wp_enqueue_style( 'acm-admin', ACM_PLUGIN_URL . 'assets/css/admin.css', array(), ACM_VERSION );
        if ( 'content' === $tab ) { $this->content->enqueue_assets( $hook ); }
        if ( 'queue' === $tab ) { $this->generation->enqueue( $hook ); }
        if ( 'settings' === $tab ) { $this->settings->enqueue_assets( $hook ); }
    }

    public function render() {
        $tab = self::active_tab();
        $this->authorize( $tab );
        $tabs = array( 'content' => __( 'تولید محتوا', 'ai-content-manager' ), 'queue' => __( 'مدیریت صف', 'ai-content-manager' ) );
        if ( current_user_can( 'manage_options' ) ) { $tabs['settings'] = __( 'تنظیمات', 'ai-content-manager' ); }
        echo '<div class="wrap acm-admin" dir="rtl"><h1>' . esc_html__( 'مدیریت محتوای هوشمند', 'ai-content-manager' ) . '</h1>';
        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'بخش‌های افزونه', 'ai-content-manager' ) . '">';
        foreach ( $tabs as $key => $label ) {
            echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $key ) ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
        if ( 'content' === $tab ) { $this->content->render(); }
        if ( 'queue' === $tab ) { $this->queue->render(); }
        if ( 'settings' === $tab ) { $this->settings->render_page(); }
        echo '</div>';
    }
}
