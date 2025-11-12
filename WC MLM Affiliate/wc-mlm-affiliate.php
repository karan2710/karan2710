<?php
/**
 * Plugin Name: WooCommerce MLM Affiliate System
 * Plugin URI: https://yourwebsite.com/wc-mlm-affiliate
 * Description: Multi-level marketing (MLM) affiliate system with three-tier hierarchy, commission tracking, and comprehensive management
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-mlm-affiliate
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_MLM_VERSION', '1.0.0');
define('WC_MLM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_MLM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_MLM_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main WC_MLM_Affiliate Class
 */
class WC_MLM_Affiliate {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Initialize plugin
        $this->init();
    }
    
    /**
     * Initialize plugin
     */
    private function init() {
        // Load plugin textdomain
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Include required files
        $this->includes();
        
        // Initialize hooks
        $this->init_hooks();
    }
    
    /**
     * Check if WooCommerce is active
     */
    private function is_woocommerce_active() {
        // Check if WooCommerce class exists
        if (class_exists('WooCommerce')) {
            return true;
        }
        
        // Alternative check for WooCommerce plugin file
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            return true;
        }
        
        // Check for multisite
        if (is_multisite()) {
            $plugins = get_site_option('active_sitewide_plugins');
            if (isset($plugins['woocommerce/woocommerce.php'])) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><strong><?php _e('WooCommerce MLM Affiliate System', 'wc-mlm-affiliate'); ?></strong></p>
            <p><?php _e('This plugin requires WooCommerce to be installed and activated.', 'wc-mlm-affiliate'); ?></p>
            <p>
                <a href="<?php echo admin_url('plugin-install.php?s=woocommerce&tab=search&type=term'); ?>" class="button button-primary">
                    <?php _e('Install WooCommerce', 'wc-mlm-affiliate'); ?>
                </a>
                <?php if (file_exists(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php')) : ?>
                    <a href="<?php echo wp_nonce_url(admin_url('plugins.php?action=activate&plugin=woocommerce/woocommerce.php'), 'activate-plugin_woocommerce/woocommerce.php'); ?>" class="button">
                        <?php _e('Activate WooCommerce', 'wc-mlm-affiliate'); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>
        <?php
        
        // Also deactivate this plugin
        deactivate_plugins(WC_MLM_PLUGIN_BASENAME);
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('wc-mlm-affiliate', false, dirname(WC_MLM_PLUGIN_BASENAME) . '/languages');
    }
    
    /**
     * Include required files
     */
    private function includes() {
        // Check if files exist before including
        $required_files = array(
            'includes/class-wc-mlm-install.php',
            'includes/class-wc-mlm-roles.php',
            'includes/class-wc-mlm-database.php',
            'includes/class-wc-mlm-frontend.php',
            'includes/class-wc-mlm-affiliate-sync.php', // NEW: Sync handler
            // Phase 2 files
            'includes/class-wc-mlm-fraud-detector.php',
            'includes/class-wc-mlm-commission-engine.php',
            'includes/class-wc-mlm-order-handler.php',
            'includes/class-wc-mlm-coupon-manager.php',
            'includes/class-wc-mlm-cron-handler.php',
        );
        
        foreach ($required_files as $file) {
            $file_path = WC_MLM_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                add_action('admin_notices', function() use ($file) {
                    echo '<div class="error"><p>' . sprintf(__('Required file missing: %s', 'wc-mlm-affiliate'), $file) . '</p></div>';
                });
                return;
            }
        }
        
        // Admin classes
        if (is_admin()) {
            $admin_files = array(
                'includes/admin/class-wc-mlm-admin.php',
                'includes/admin/class-wc-mlm-admin-menu.php',
            );
            
            foreach ($admin_files as $file) {
                $file_path = WC_MLM_PLUGIN_DIR . $file;
                if (file_exists($file_path)) {
                    require_once $file_path;
                } else {
                    add_action('admin_notices', function() use ($file) {
                        echo '<div class="error"><p>' . sprintf(__('Required admin file missing: %s', 'wc-mlm-affiliate'), $file) . '</p></div>';
                    });
                }
            }
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Declare WooCommerce HPOS compatibility
        add_action('before_woocommerce_init', array($this, 'declare_wc_compatibility'));
        
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));
        
        // Initialize roles
        if (class_exists('WC_MLM_Roles')) {
            add_action('init', array('WC_MLM_Roles', 'init'));
        }
        
        // Initialize admin
        if (is_admin() && class_exists('WC_MLM_Admin')) {
            WC_MLM_Admin::get_instance();
        }
        
        // Initialize frontend
        if (class_exists('WC_MLM_Frontend')) {
            WC_MLM_Frontend::get_instance();
        }
        
        // Initialize Phase 2 components
        if (class_exists('WC_MLM_Affiliate_Sync')) {
            WC_MLM_Affiliate_Sync::init();
        }
        
        if (class_exists('WC_MLM_Fraud_Detector')) {
            WC_MLM_Fraud_Detector::init();
        }
        
        if (class_exists('WC_MLM_Commission_Engine')) {
            WC_MLM_Commission_Engine::init();
        }
        
        if (class_exists('WC_MLM_Order_Handler')) {
            WC_MLM_Order_Handler::init();
        }
        
        if (class_exists('WC_MLM_Cron_Handler')) {
            WC_MLM_Cron_Handler::init();
        }
    }
    
    /**
     * Declare WooCommerce HPOS compatibility
     */
    public function declare_wc_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate_plugin() {
        if (!class_exists('WC_MLM_Install')) {
            wp_die(__('Installation class not found. Please ensure all plugin files are uploaded correctly.', 'wc-mlm-affiliate'));
        }
        
        WC_MLM_Install::activate();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate_plugin() {
        if (class_exists('WC_MLM_Install')) {
            WC_MLM_Install::deactivate();
        }
    }
}

/**
 * Initialize plugin
 */
function wc_mlm_affiliate() {
    return WC_MLM_Affiliate::get_instance();
}

// Start the plugin
wc_mlm_affiliate();