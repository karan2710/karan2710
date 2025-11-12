<?php
/**
 * Frontend Handler
 * 
 * @package WC_MLM_Affiliate
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_MLM_Frontend {
    
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
        // Enqueue frontend scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Register shortcodes
        add_shortcode('mlm_dashboard', array($this, 'dashboard_shortcode'));
        add_shortcode('mlm_register', array($this, 'register_shortcode'));
        add_shortcode('mlm_login', array($this, 'login_shortcode'));
    }
    
    public function enqueue_scripts() {
        // Frontend CSS
        wp_enqueue_style(
            'wc-mlm-frontend',
            WC_MLM_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            WC_MLM_VERSION
        );
        
        // Frontend JS
        wp_enqueue_script(
            'wc-mlm-frontend',
            WC_MLM_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            WC_MLM_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('wc-mlm-frontend', 'wcMLM', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_mlm_frontend_nonce'),
        ));
    }
    
    public function dashboard_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to view your dashboard.', 'wc-mlm-affiliate') . '</p>';
        }
        
        $user_id = get_current_user_id();
        $affiliate = WC_MLM_Database::get_affiliate_by_user_id($user_id);
        
        if (!$affiliate) {
            return '<p>' . __('You are not registered as an affiliate.', 'wc-mlm-affiliate') . '</p>';
        }
        
        ob_start();
        ?>
        <div class="wc-mlm-dashboard">
            <h2><?php _e('Affiliate Dashboard', 'wc-mlm-affiliate'); ?></h2>
            <p><?php printf(__('Welcome, %s!', 'wc-mlm-affiliate'), wp_get_current_user()->display_name); ?></p>
            <p><?php printf(__('Your Referral ID: %s', 'wc-mlm-affiliate'), $affiliate->referral_id); ?></p>
            <p><?php printf(__('Status: %s', 'wc-mlm-affiliate'), ucfirst($affiliate->status)); ?></p>
            <p><?php _e('Full dashboard features will be available in Phase 4.', 'wc-mlm-affiliate'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function register_shortcode($atts) {
        ob_start();
        ?>
        <div class="wc-mlm-register">
            <h2><?php _e('Affiliate Registration', 'wc-mlm-affiliate'); ?></h2>
            <p><?php _e('Registration form will be built in Phase 3.', 'wc-mlm-affiliate'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function login_shortcode($atts) {
        if (is_user_logged_in()) {
            return '<p>' . __('You are already logged in.', 'wc-mlm-affiliate') . ' <a href="' . wp_logout_url() . '">' . __('Logout', 'wc-mlm-affiliate') . '</a></p>';
        }
        
        return wp_login_form(array('echo' => false));
    }
}
