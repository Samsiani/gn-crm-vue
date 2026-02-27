<?php
/**
 * Plugin Name: CIG Headless API
 * Description: Custom Invoice/Group headless REST API backend for Vue.js SPA
 * Version: 4.4.17
 * Author: GN Industrial
 * Text Domain: cig-headless
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CIG_VERSION', '4.4.17' );
define( 'CIG_DB_VERSION', '1.4' );
define( 'CIG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CIG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CIG_API_NAMESPACE', 'cig/v1' );

// Autoload Composer dependencies (firebase/php-jwt, plugin-update-checker)
if ( file_exists( CIG_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once CIG_PLUGIN_DIR . 'vendor/autoload.php';
}

// Auto-update via GitHub Releases (yahnis-elsts/plugin-update-checker v5)
if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
    $cigUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/Samsiani/gn-crm-vue/',
        __FILE__,
        'gn-crm-vue'
    );
    $cigUpdateChecker->getVcsApi()->enableReleaseAssets();
}

// Core includes
require_once CIG_PLUGIN_DIR . 'includes/class-cig-activator.php';
require_once CIG_PLUGIN_DIR . 'includes/class-cig-deactivator.php';
require_once CIG_PLUGIN_DIR . 'includes/class-cig-loader.php';
require_once CIG_PLUGIN_DIR . 'includes/class-cig-frontend.php';

// Models
require_once CIG_PLUGIN_DIR . 'models/class-cig-invoice.php';
require_once CIG_PLUGIN_DIR . 'models/class-cig-customer.php';
require_once CIG_PLUGIN_DIR . 'models/class-cig-product.php';
require_once CIG_PLUGIN_DIR . 'models/class-cig-user.php';
require_once CIG_PLUGIN_DIR . 'models/class-cig-deposit.php';
require_once CIG_PLUGIN_DIR . 'models/class-cig-delivery.php';
require_once CIG_PLUGIN_DIR . 'models/class-cig-company.php';

// Middleware
require_once CIG_PLUGIN_DIR . 'middleware/class-cig-auth-middleware.php';
require_once CIG_PLUGIN_DIR . 'middleware/class-cig-rbac.php';

// API Controllers
require_once CIG_PLUGIN_DIR . 'api/class-cig-rest-controller.php';
require_once CIG_PLUGIN_DIR . 'api/class-cig-invoices-controller.php';
require_once CIG_PLUGIN_DIR . 'api/class-cig-customers-controller.php';
require_once CIG_PLUGIN_DIR . 'api/class-cig-products-controller.php';
require_once CIG_PLUGIN_DIR . 'api/class-cig-users-controller.php';
require_once CIG_PLUGIN_DIR . 'api/class-cig-company-controller.php';
require_once CIG_PLUGIN_DIR . 'api/class-cig-deposits-controller.php';
require_once CIG_PLUGIN_DIR . 'api/class-cig-deliveries-controller.php';
require_once CIG_PLUGIN_DIR . 'api/class-cig-auth-controller.php';
require_once CIG_PLUGIN_DIR . 'api/class-cig-kpi-controller.php';

// Activation hook
register_activation_hook( __FILE__, [ 'CIG_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'CIG_Deactivator', 'deactivate' ] );

// Initialize plugin
add_action( 'plugins_loaded', function() {
    // Run DB upgrade if version changed
    if ( get_option( 'cig_db_version' ) !== CIG_DB_VERSION ) {
        CIG_Activator::add_fulltext_indexes();
        CIG_Activator::add_media_columns();
        CIG_Activator::add_performance_indexes();
        update_option( 'cig_db_version', CIG_DB_VERSION );
    }

    $loader = new CIG_Loader();
    $loader->init();

    $frontend = new CIG_Frontend();
    $frontend->init();
});

// WP-CLI commands
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once CIG_PLUGIN_DIR . 'migration/class-cig-id-mapper.php';
    require_once CIG_PLUGIN_DIR . 'migration/class-cig-data-validator.php';
    require_once CIG_PLUGIN_DIR . 'migration/class-cig-migrator.php';
    require_once CIG_PLUGIN_DIR . 'cli/class-cig-cli-commands.php';
    WP_CLI::add_command( 'cig', 'CIG_CLI_Commands' );
}
