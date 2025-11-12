<?php
/**
 * Coupon Manager
 * 
 * Generates and manages affiliate coupons
 * 
 * @package WC_MLM_Affiliate
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_MLM_Coupon_Manager {
    
    /**
     * Generate coupon for affiliate
     */
    public static function generate_affiliate_coupon($affiliate_id, $referral_id, $city = '') {
        // Create coupon code (AFF-CITY-12345)
        $city_code = !empty($city) ? strtoupper(substr($city, 0, 3)) : 'AFF';
        $coupon_code = 'AFF-' . $city_code . '-' . substr($referral_id, -5);
        
        // Check if coupon already exists
        $coupon_id = wc_get_coupon_id_by_code($coupon_code);
        
        if ($coupon_id) {
            return $coupon_code; // Already exists
        }
        
        // Get discount amount from settings
        $discount_amount = floatval(get_option('wc_mlm_coupon_discount', 10.00));
        $min_order_value = floatval(get_option('wc_mlm_min_order_value', 500.00));
        $max_discount_cap = floatval(get_option('wc_mlm_max_discount_cap', 5000.00));
        
        // Create coupon
        $coupon = array(
            'post_title' => $coupon_code,
            'post_content' => '',
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => 'shop_coupon',
        );
        
        $new_coupon_id = wp_insert_post($coupon);
        
        if ($new_coupon_id) {
            // Set coupon meta
            update_post_meta($new_coupon_id, 'discount_type', 'percent');
            update_post_meta($new_coupon_id, 'coupon_amount', $discount_amount);
            update_post_meta($new_coupon_id, 'individual_use', 'no');
            update_post_meta($new_coupon_id, 'product_ids', '');
            update_post_meta($new_coupon_id, 'exclude_product_ids', '');
            update_post_meta($new_coupon_id, 'usage_limit', '');
            update_post_meta($new_coupon_id, 'usage_limit_per_user', '');
            update_post_meta($new_coupon_id, 'limit_usage_to_x_items', '');
            update_post_meta($new_coupon_id, 'usage_count', '0');
            update_post_meta($new_coupon_id, 'expiry_date', '');
            update_post_meta($new_coupon_id, 'free_shipping', 'no');
            update_post_meta($new_coupon_id, 'exclude_sale_items', 'no');
            update_post_meta($new_coupon_id, 'minimum_amount', $min_order_value);
            update_post_meta($new_coupon_id, 'maximum_amount', $max_discount_cap);
            
            // Store affiliate ID in coupon meta
            update_post_meta($new_coupon_id, '_mlm_affiliate_id', $affiliate_id);
            update_post_meta($new_coupon_id, '_mlm_coupon_type', 'primary');
            
            // Log the action
            WC_MLM_Database::log_action(
                $affiliate_id,
                'coupon_generated',
                'coupon',
                $new_coupon_id,
                array('coupon_code' => $coupon_code)
            );
            
            return $coupon_code;
        }
        
        return false;
    }
    
    /**
     * Create custom campaign coupon
     */
    public static function create_campaign_coupon($affiliate_id, $campaign_name, $discount_type = 'percent', $discount_amount = 10) {
        // Sanitize campaign name
        $campaign_slug = sanitize_title($campaign_name);
        $affiliate = WC_MLM_Database::get_affiliate_by_user_id($affiliate_id);
        
        if (!$affiliate) {
            return false;
        }
        
        // Create unique coupon code
        $coupon_code = strtoupper($affiliate->referral_id . '-' . substr($campaign_slug, 0, 10));
        
        // Check if exists
        $coupon_id = wc_get_coupon_id_by_code($coupon_code);
        
        if ($coupon_id) {
            return false; // Already exists
        }
        
        // Create coupon
        $coupon = array(
            'post_title' => $coupon_code,
            'post_content' => $campaign_name,
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => 'shop_coupon',
        );
        
        $new_coupon_id = wp_insert_post($coupon);
        
        if ($new_coupon_id) {
            // Set coupon meta
            update_post_meta($new_coupon_id, 'discount_type', $discount_type);
            update_post_meta($new_coupon_id, 'coupon_amount', $discount_amount);
            update_post_meta($new_coupon_id, 'individual_use', 'no');
            update_post_meta($new_coupon_id, 'usage_limit', '');
            update_post_meta($new_coupon_id, 'expiry_date', '');
            update_post_meta($new_coupon_id, 'minimum_amount', get_option('wc_mlm_min_order_value', 500.00));
            
            // Store affiliate ID
            update_post_meta($new_coupon_id, '_mlm_affiliate_id', $affiliate_id);
            update_post_meta($new_coupon_id, '_mlm_coupon_type', 'campaign');
            update_post_meta($new_coupon_id, '_mlm_campaign_name', $campaign_name);
            
            // Log the action
            WC_MLM_Database::log_action(
                $affiliate_id,
                'campaign_coupon_created',
                'coupon',
                $new_coupon_id,
                array(
                    'coupon_code' => $coupon_code,
                    'campaign_name' => $campaign_name,
                )
            );
            
            return $coupon_code;
        }
        
        return false;
    }
    
    /**
     * Get all coupons for affiliate
     */
    public static function get_affiliate_coupons($affiliate_id) {
        global $wpdb;
        
        $coupon_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_mlm_affiliate_id' 
             AND meta_value = %d",
            $affiliate_id
        ));
        
        if (empty($coupon_ids)) {
            return array();
        }
        
        $coupons = array();
        
        foreach ($coupon_ids as $coupon_id) {
            $coupon = new WC_Coupon($coupon_id);
            
            if ($coupon->get_id()) {
                $coupons[] = array(
                    'id' => $coupon->get_id(),
                    'code' => $coupon->get_code(),
                    'amount' => $coupon->get_amount(),
                    'type' => $coupon->get_discount_type(),
                    'usage_count' => $coupon->get_usage_count(),
                    'usage_limit' => $coupon->get_usage_limit(),
                    'coupon_type' => get_post_meta($coupon_id, '_mlm_coupon_type', true),
                    'campaign_name' => get_post_meta($coupon_id, '_mlm_campaign_name', true),
                );
            }
        }
        
        return $coupons;
    }
    
    /**
     * Get coupon usage stats
     */
    public static function get_coupon_stats($coupon_code) {
        $coupon = new WC_Coupon($coupon_code);
        
        if (!$coupon->get_id()) {
            return false;
        }
        
        // Get orders that used this coupon
        global $wpdb;
        
        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID as order_id, p.post_date, pm.meta_value as order_total
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
             WHERE p.post_type = 'shop_order'
             AND p.ID IN (
                 SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items
                 WHERE order_item_type = 'coupon'
                 AND order_item_name = %s
             )
             ORDER BY p.post_date DESC",
            $coupon_code
        ));
        
        $total_revenue = 0;
        foreach ($orders as $order) {
            $total_revenue += floatval($order->order_total);
        }
        
        return array(
            'usage_count' => $coupon->get_usage_count(),
            'usage_limit' => $coupon->get_usage_limit(),
            'total_orders' => count($orders),
            'total_revenue' => $total_revenue,
            'orders' => $orders,
        );
    }
    
    /**
     * Disable coupon
     */
    public static function disable_coupon($coupon_code) {
        $coupon_id = wc_get_coupon_id_by_code($coupon_code);
        
        if ($coupon_id) {
            wp_update_post(array(
                'ID' => $coupon_id,
                'post_status' => 'draft',
            ));
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Enable coupon
     */
    public static function enable_coupon($coupon_code) {
        $coupon_id = wc_get_coupon_id_by_code($coupon_code);
        
        if ($coupon_id) {
            wp_update_post(array(
                'ID' => $coupon_id,
                'post_status' => 'publish',
            ));
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Delete coupon
     */
    public static function delete_coupon($coupon_code) {
        $coupon_id = wc_get_coupon_id_by_code($coupon_code);
        
        if ($coupon_id) {
            wp_delete_post($coupon_id, true);
            return true;
        }
        
        return false;
    }
}
