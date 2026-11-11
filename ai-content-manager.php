<?php
/**
 * Plugin Name: مدیریت محتوای هوشمند
 * Description: مدیریت درخواست‌های محتوا، تولید دستی مقاله و پیش‌نمایش خروجی در صف وردپرس.
 * Version: 0.6.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ai-content-manager
 * Domain Path: /languages
 *
 * @package AIContentManager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ACM_VERSION', '0.6.1' );
define( 'ACM_PLUGIN_FILE', __FILE__ );
define( 'ACM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
    static function ( $class_name ) {
        $namespace = 'AIContentManager\\';
        if ( 0 !== strpos( $class_name, $namespace ) ) {
            return;
        }

        $class_path = substr( $class_name, strlen( $namespace ) );
        $parts      = explode( '\\', $class_path );
        $class      = array_pop( $parts );
        $class      = strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $class ) );
        $directory  = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
        $file       = ACM_PLUGIN_DIR . 'includes/' . $directory . 'class-' . $class . '.php';

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
);

register_activation_hook( __FILE__, array( 'AIContentManager\\Infrastructure\\Installer', 'activate' ) );

add_action(
    'plugins_loaded',
    static function () {
        AIContentManager\Plugin::instance()->init();
    }
);
