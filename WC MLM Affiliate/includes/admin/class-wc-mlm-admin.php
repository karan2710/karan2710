<?php
/**
 * Admin Initialization
 * 
 * @package WC_MLM_Affiliate
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_MLM_Admin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Initialize admin menu
        WC_MLM_Admin_Menu::get_instance();
    }
    
    public function enqueue_scripts($hook) {
        // Only load on MLM admin pages
        if (strpos($hook, 'wc-mlm') === false) {
            return;
        }
        
        // Admin CSS
        wp_enqueue_style(
            'wc-mlm-admin',
            WC_MLM_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WC_MLM_VERSION
        );
        
        // Admin JS
        wp_enqueue_script(
            'wc-mlm-admin',
            WC_MLM_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WC_MLM_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('wc-mlm-admin', 'wcMLM', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_mlm_admin_nonce'),
        ));
    }
}
