<?php
/**
 * WooCommerce Order Handler
 * 
 * Handles order status changes and affiliate attribution
 * 
 * @package WC_MLM_Affiliate
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_MLM_Order_Handler {
    
    /**
     * Initialize
     */
    public static function init() {
        // Track coupon usage and assign affiliate
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'assign_affiliate_to_order'), 10, 3);
        
        // Handle order status changes
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'handle_status_change'), 10, 4);
        
        // Track referral clicks
        add_action('template_redirect', array(__CLASS__, 'track_referral_click'));
        
        // Set affiliate cookie
        add_action('init', array(__CLASS__, 'set_affiliate_cookie'));
    }
    
    /**
     * Assign affiliate to order based on coupon
     */
    public static function assign_affiliate_to_order($order_id, $posted_data, $order) {
        // Check if affiliate already assigned
        if (get_post_meta($order_id, '_mlm_affiliate_id', true)) {
            return;
        }
        
        $affiliate_id = null;
        
        // Method 1: Check for affiliate coupon
        $coupons = $order->get_coupon_codes();
        
        foreach ($coupons as $coupon_code) {
            $coupon = new WC_Coupon($coupon_code);
            $affiliate_id = get_post_meta($coupon->get_id(), '_mlm_affiliate_id', true);
            
            if ($affiliate_id) {
                break;
            }
        }
        
        // Method 2: Check for affiliate cookie (if no coupon used)
        if (!$affiliate_id && isset($_COOKIE['mlm_ref'])) {
            $referral_id = sanitize_text_field($_COOKIE['mlm_ref']);
            $affiliate = WC_MLM_Database::get_affiliate_by_referral_id($referral_id);
            
            if ($affiliate && $affiliate->status === 'active') {
                $affiliate_id = $affiliate->user_id;
            }
        }
        
        // Method 3: Check if customer has previous orders with affiliate
        if (!$affiliate_id && $order->get_customer_id()) {
            $affiliate_id = self::get_customer_affiliate($order->get_customer_id());
        }
        
        if ($affiliate_id) {
            // Assign affiliate to order
            update_post_meta($order_id, '_mlm_affiliate_id', $affiliate_id);
            
            // Also assign customer to affiliate permanently
            if ($order->get_customer_id()) {
                update_user_meta($order->get_customer_id(), '_mlm_assigned_affiliate', $affiliate_id);
            }
            
            // Update referral click as converted
            self::mark_referral_converted($affiliate_id, $order->get_customer_ip_address());
            
            // Add order note
            $affiliate = WC_MLM_Database::get_affiliate_by_user_id($affiliate_id);
            if ($affiliate) {
                $order->add_order_note(
                    sprintf(__('[MLM] Order attributed to affiliate: %s (ID: %s)', 'wc-mlm-affiliate'), 
                        $affiliate->referral_id,
                        $affiliate_id
                    )
                );
            }
        }
    }
    
    /**
     * Handle order status changes
     */
    public static function handle_status_change($order_id, $old_status, $new_status, $order) {
        switch ($new_status) {
            case 'completed':
                // Commissions are calculated by Commission Engine
                break;
                
            case 'refunded':
            case 'cancelled':
            case 'failed':
                // Reverse commissions
                WC_MLM_Commission_Engine::reverse_commissions($order_id);
                break;
        }
    }
    
    /**
     * Track referral clicks
     */
    public static function track_referral_click() {
        // Check if referral parameter exists
        if (!isset($_GET['ref'])) {
            return;
        }
        
        $referral_id = sanitize_text_field($_GET['ref']);
        
        // Get affiliate by referral ID
        $affiliate = WC_MLM_Database::get_affiliate_by_referral_id($referral_id);
        
        if (!$affiliate || $affiliate->status !== 'active') {
            return;
        }
        
        // Get visitor info
        $ip_address = self::get_user_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
        $referrer_url = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';
        $landing_url = esc_url_raw($_SERVER['REQUEST_URI']);
        
        // Check if click already recorded (prevent duplicate clicks)
        global $wpdb;
        $clicks_table = $wpdb->prefix . 'mlm_referral_clicks';
        
        $existing_click = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $clicks_table 
             WHERE affiliate_id = %d 
             AND ip_address = %s 
             AND click_date > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $affiliate->user_id,
            $ip_address
        ));
        
        if (!$existing_click) {
            // Record the click
            $wpdb->insert($clicks_table, array(
                'affiliate_id' => $affiliate->user_id,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'referrer_url' => $referrer_url,
                'landing_url' => $landing_url,
                'converted' => 0,
                'click_date' => current_time('mysql'),
            ));
        }
        
        // Set cookie for 30 days
        setcookie('mlm_ref', $referral_id, time() + (30 * DAY_IN_SECONDS), '/');
    }
    
    /**
     * Set affiliate cookie from query parameter
     */
    public static function set_affiliate_cookie() {
        if (isset($_GET['ref']) && !headers_sent()) {
            $referral_id = sanitize_text_field($_GET['ref']);
            $affiliate = WC_MLM_Database::get_affiliate_by_referral_id($referral_id);
            
            if ($affiliate && $affiliate->status === 'active') {
                setcookie('mlm_ref', $referral_id, time() + (30 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
            }
        }
    }
    
    /**
     * Mark referral as converted
     */
    private static function mark_referral_converted($affiliate_id, $ip_address) {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_referral_clicks';
        
        $wpdb->update(
            $table,
            array(
                'converted' => 1,
                'conversion_date' => current_time('mysql'),
            ),
            array(
                'affiliate_id' => $affiliate_id,
                'ip_address' => $ip_address,
                'converted' => 0,
            ),
            array('%d', '%s'),
            array('%d', '%s', '%d')
        );
    }
    
    /**
     * Get customer's assigned affiliate
     */
    private static function get_customer_affiliate($customer_id) {
        return get_user_meta($customer_id, '_mlm_assigned_affiliate', true);
    }
    
    /**
     * Get user IP address
     */
    private static function get_user_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return sanitize_text_field($_SERVER['HTTP_CLIENT_IP']);
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return sanitize_text_field($_SERVER['HTTP_X_FORWARDED_FOR']);
        } else {
            return sanitize_text_field($_SERVER['REMOTE_ADDR']);
        }
    }
    
    /**
     * Get order affiliate ID
     */
    public static function get_order_affiliate($order_id) {
        return get_post_meta($order_id, '_mlm_affiliate_id', true);
    }
    
    /**
     * Check if order is affiliate order
     */
    public static function is_affiliate_order($order_id) {
        return !empty(self::get_order_affiliate($order_id));
    }
}

// Initialize
WC_MLM_Order_Handler::init();
