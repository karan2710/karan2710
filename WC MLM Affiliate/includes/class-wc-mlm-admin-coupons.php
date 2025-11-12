<?php
/**
 * Admin Coupon Management
 * 
 * @package WC_MLM_Affiliate
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_MLM_Admin_Coupons {
    
    /**
     * Initialize
     */
    public static function init() {
        // Handle coupon generation requests
        add_action('admin_post_wc_mlm_generate_coupon', array(__CLASS__, 'handle_generate_coupon'));
        add_action('admin_post_wc_mlm_bulk_generate_coupons', array(__CLASS__, 'handle_bulk_generate'));
    }
    
    /**
     * Render coupons management page
     */
    public static function render_page() {
        // Check permissions
        if (!current_user_can('manage_mlm_system')) {
            wp_die(__('You do not have permission to access this page.', 'wc-mlm-affiliate'));
        }
        
        // Handle actions
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'generate':
                    self::render_generate_form();
                    return;
                case 'bulk':
                    self::render_bulk_generate_form();
                    return;
            }
        }
        
        // Default: Show coupons list
        self::render_coupons_list();
    }
    
    /**
     * Render coupons list
     */
    private static function render_coupons_list() {
        global $wpdb;
        
        // Get all affiliate coupons
        $coupons = $wpdb->get_results("
            SELECT p.ID, p.post_title as code, pm1.meta_value as affiliate_id, 
                   pm2.meta_value as discount_amount, pm3.meta_value as usage_count
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND