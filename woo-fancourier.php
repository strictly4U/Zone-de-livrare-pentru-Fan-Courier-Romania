<?php
/**
 * Plugin Name: HgE: Shipping Zones for FAN Courier Romania
 * Plugin URI: https://github.com/strictly4U/Zone-de-livrare-pentru-Fan-Courier-Romania.git
 * Description: WooCommerce shipping zones integration for FAN Courier Romania (Standard service). Manual AWB generation, PDF label display for orders, and AWB generation history.
 * Version: 1.0.10
 * Requires at least: 5.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: hge-zone-de-livrare-pentru-fan-courier-romania
 * Author: Hurubaru George Emanuel
 * Author URI: https://www.linkedin.com/in/hurubarugeorgesemanuel/
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) exit;

/**
 * Declare HPOS compatibility
 * @since 1.0.6
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
});

define('HGEZLPFCR_PLUGIN_FILE', __FILE__);
define('HGEZLPFCR_PLUGIN_VER', '1.0.10');
define('HGEZLPFCR_OPTION_GROUP', 'hgezlpfcr_settings');

// HGEZLPFCR_LOG_ENABLED will be defined dynamically based on 'hgezlpfcr_debug' setting
// Don't define it here - it will be checked in HGEZLPFCR_Logger class

// Plugin constants
define('HGEZLPFCR_LOCK_TTL', 300);                    // 5 minutes lock timeout
define('HGEZLPFCR_CACHE_TTL', 300);                   // 5 minutes cache timeout
define('HGEZLPFCR_RATE_LIMIT_MAX_CALLS', 60);         // Max API calls per minute
define('HGEZLPFCR_RATE_LIMIT_WINDOW', 60);            // Rate limit window in seconds
define('HGEZLPFCR_TRACKING_BATCH_SIZE', 100);         // Orders to process per tracking batch
define('HGEZLPFCR_RETRY_DELAY_MS', 200);              // Initial retry delay in milliseconds
define('HGEZLPFCR_RETRY_MAX_DELAY_MS', 2000);         // Maximum retry delay in milliseconds
define('HGEZLPFCR_DEFAULT_TIMEOUT', 20);              // Default API timeout in seconds
define('HGEZLPFCR_DEFAULT_RETRIES', 2);               // Default number of retries
define('HGEZLPFCR_DEFAULT_WEIGHT_KG', 1.0);           // Default package weight in kg
define('HGEZLPFCR_CRON_INITIAL_DELAY', 600);          // Initial CRON delay in seconds (10 minutes)
define('HGEZLPFCR_PACKAGE_DEFAULT_LENGTH', 30);       // Default package length in cm
define('HGEZLPFCR_PACKAGE_DEFAULT_WIDTH', 20);        // Default package width in cm
define('HGEZLPFCR_PACKAGE_DEFAULT_HEIGHT', 10);       // Default package height in cm
define('HGEZLPFCR_MIN_DECLARED_VALUE', 1);            // Minimum declared value

// Set activation flag on plugin activation - MUST be before any other hooks
register_activation_hook(HGEZLPFCR_PLUGIN_FILE, function () {
    add_option('hgezlpfcr_activation_redirect', true);

    // Generate file integrity hashes and schedule cron.
    require_once plugin_dir_path( HGEZLPFCR_PLUGIN_FILE ) . 'includes/class-hgezlpfcr-logger.php';
    require_once plugin_dir_path( HGEZLPFCR_PLUGIN_FILE ) . 'includes/class-hgezlpfcr-integrity.php';
    HGEZLPFCR_Integrity::on_activation();
});

// Clean up integrity cron on deactivation.
register_deactivation_hook(HGEZLPFCR_PLUGIN_FILE, function () {
    require_once plugin_dir_path( HGEZLPFCR_PLUGIN_FILE ) . 'includes/class-hgezlpfcr-logger.php';
    require_once plugin_dir_path( HGEZLPFCR_PLUGIN_FILE ) . 'includes/class-hgezlpfcr-integrity.php';
    HGEZLPFCR_Integrity::on_deactivation();
});

// Add Settings link in plugins list
add_filter('plugin_action_links_' . plugin_basename(HGEZLPFCR_PLUGIN_FILE), function ($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=hgezlpfcr') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Add plugin meta links (View details, etc.)
add_filter('plugin_row_meta', function ($plugin_meta, $plugin_file) {
    if (plugin_basename(HGEZLPFCR_PLUGIN_FILE) === $plugin_file) {
        $plugin_meta[] = '<a href="' . admin_url('admin.php?page=wc-settings&tab=hgezlpfcr') . '">Plugin Configuration</a>';
        $plugin_meta[] = '<a href="https://github.com/georgeshurubaru/FcRapid1923/wiki" target="_blank">Documentation</a>';
    }
    return $plugin_meta;
}, 10, 2);

// Show admin notice after plugin activation
add_action('admin_notices', function () {
    // Check if we should show the notice
    if (get_option('hgezlpfcr_activation_redirect', false)) {
        // Don't show notice if already on the settings page
        $current_screen = get_current_screen();
        if ($current_screen && $current_screen->id === 'woocommerce_page_wc-settings') {
            // Check if we're on the plugin tab
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for display
            $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
            if ($tab === 'hgezlpfcr') {
                // User is already on settings page, dismiss the notice automatically
                delete_option('hgezlpfcr_activation_redirect');
                return;
            }
        }

        $settings_url = admin_url('admin.php?page=wc-settings&tab=hgezlpfcr');

        // Enqueue inline script for notice dismissal
        wp_enqueue_script('jquery');
        $inline_script = "
            jQuery(document).ready(function($) {
                $('.hgezlpfcr-activation-notice').on('click', '.notice-dismiss', function() {
                    $.post(ajaxurl, {
                        action: 'hgezlpfcr_dismiss_activation_notice'
                    });
                });
                // Auto-dismiss when clicking the button
                $('.hgezlpfcr-activation-notice .button').on('click', function() {
                    $.post(ajaxurl, {
                        action: 'hgezlpfcr_dismiss_activation_notice'
                    });
                });
            });
        ";
        wp_add_inline_script('jquery', $inline_script);

        ?>
        <div class="notice notice-success is-dismissible hgezlpfcr-activation-notice">
            <p>
                <strong>✓ HgE: Shipping Zones for FAN Courier Romania</strong> has been activated successfully!
                <a href="<?php echo esc_url($settings_url); ?>" class="button button-primary" style="margin-left: 10px;">Configure plugin now</a>
            </p>
            <p style="margin-top: 5px;">
                <em>Enter API credentials (Username, Password, Client ID) and sender settings to generate AWB labels.</em>
            </p>
        </div>
        <?php
    }
});

// AJAX handler to dismiss activation notice
add_action('wp_ajax_hgezlpfcr_dismiss_activation_notice', function() {
    delete_option('hgezlpfcr_activation_redirect');
    wp_die();
});

// Check minimum requirements
add_action('admin_init', function () {
    $min_wp_version = '5.0';
    $min_php_version = '8.1';

    // Check WordPress version
    if (version_compare(get_bloginfo('version'), $min_wp_version, '<')) {
        deactivate_plugins(plugin_basename(HGEZLPFCR_PLUGIN_FILE));

        $current_wp_version = esc_html(get_bloginfo('version'));
        $min_wp_version_safe = esc_html($min_wp_version);

        wp_die(
            wp_kses(
                sprintf(
                    /* translators: 1: Plugin name, 2: Required WordPress version, 3: Current WordPress version */
                    __('%1$s requires WordPress %2$s or newer. Your version is %3$s.', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                    '<strong>HgE: Shipping Zones for FAN Courier Romania</strong>',
                    $min_wp_version_safe,
                    $current_wp_version
                ),
                ['strong' => []]
            ),
            esc_html__('Plugin deactivated', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            ['back_link' => true]
        );
    }

    // Check PHP version
    if (version_compare(PHP_VERSION, $min_php_version, '<')) {
        deactivate_plugins(plugin_basename(HGEZLPFCR_PLUGIN_FILE));

        $current_php_version = esc_html(PHP_VERSION);
        $min_php_version_safe = esc_html($min_php_version);

        wp_die(
            wp_kses(
                sprintf(
                    /* translators: 1: Plugin name, 2: Required PHP version, 3: Current PHP version */
                    __('%1$s requires PHP %2$s or newer. Your version is %3$s.', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                    '<strong>HgE: Shipping Zones for FAN Courier Romania</strong>',
                    $min_php_version_safe,
                    $current_php_version
                ),
                ['strong' => []]
            ),
            esc_html__('Plugin deactivated', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            ['back_link' => true]
        );
    }
});

add_action('plugins_loaded', function () {
    // WordPress automatically loads plugin translations from WordPress.org since WP 4.6
    // No need to call load_plugin_textdomain() manually

    if (!class_exists('WooCommerce')) {
        // Show admin notice if WooCommerce is not active
        add_action('admin_notices', function () {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('HgE: Shipping Zones for FAN Courier Romania', 'hge-zone-de-livrare-pentru-fan-courier-romania'); ?></strong>
                    <?php esc_html_e('requires WooCommerce to work. Please install and activate WooCommerce.', 'hge-zone-de-livrare-pentru-fan-courier-romania'); ?>
                </p>
            </div>
            <?php
        });
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/class-hgezlpfcr-logger.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-hgezlpfcr-settings.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-hgezlpfcr-shipping-standard.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-hgezlpfcr-api-client.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-hgezlpfcr-admin-order.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-hgezlpfcr-healthcheck.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-hgezlpfcr-integrity.php';

    HGEZLPFCR_Settings::init();
    HGEZLPFCR_Admin_Order::init();
    HGEZLPFCR_Healthcheck::init();
    HGEZLPFCR_Integrity::instance();

    // Register shipping methods
    add_filter('woocommerce_shipping_methods', function ($methods) {
        $methods['fc_standard'] = 'HGEZLPFCR_Shipping_Standard';
        return $methods;
    });


});


