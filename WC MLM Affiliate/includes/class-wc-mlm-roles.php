<?php
/**
 * Custom User Roles Handler
 * 
 * @package WC_MLM_Affiliate
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_MLM_Roles {
    
    /**
     * Initialize
     */
    public static function init() {
        // Register custom capabilities
        add_action('init', array(__CLASS__, 'maybe_update_roles'));
    }
    
    /**
     * Create custom roles
     */
    public static function create_roles() {
        global $wp_roles;
        
        if (!class_exists('WP_Roles')) {
            return;
        }
        
        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }
        
        // Define base capabilities (similar to subscriber)
        $base_caps = array(
            'read' => true,
        );
        
        // 1. Direct Affiliate Role
        $direct_affiliate_caps = array_merge($base_caps, array(
            'view_mlm_dashboard' => true,
            'view_own_commissions' => true,
            'view_own_referrals' => true,
            'request_payout' => true,
            'generate_referral_link' => true,
            'view_marketing_materials' => true,
            'submit_support_ticket' => true,
        ));
        
        add_role('direct_affiliate', __('Direct Affiliate', 'wc-mlm-affiliate'), $direct_affiliate_caps);
        
        // 2. City Head Role
        $city_head_caps = array_merge($direct_affiliate_caps, array(
            'view_city_dashboard' => true,
            'view_city_affiliates' => true,
            'view_city_sales' => true,
            'view_city_commissions' => true,
            'send_city_announcements' => true,
            'export_city_reports' => true,
        ));
        
        add_role('city_head', __('City Head', 'wc-mlm-affiliate'), $city_head_caps);
        
        // 3. State Head Role
        $state_head_caps = array_merge($city_head_caps, array(
            'view_state_dashboard' => true,
            'view_state_affiliates' => true,
            'view_state_sales' => true,
            'view_state_commissions' => true,
            'approve_affiliate_registrations' => true,
            'promote_to_city_head' => true,
            'send_state_announcements' => true,
            'export_state_reports' => true,
            'view_fraud_reports' => true,
            'review_flagged_orders' => true,
        ));
        
        add_role('state_head', __('State Head', 'wc-mlm-affiliate'), $state_head_caps);
        
        // Add capabilities to Administrator
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_caps = array(
                'manage_mlm_system' => true,
                'manage_mlm_affiliates' => true,
                'manage_mlm_commissions' => true,
                'manage_mlm_payouts' => true,
                'manage_commission_rates' => true,
                'manage_cities_states' => true,
                'view_all_affiliates' => true,
                'approve_affiliate_registrations' => true,
                'promote_demote_affiliates' => true,
                'suspend_affiliates' => true,
                'delete_affiliates' => true,
                'adjust_commissions' => true,
                'process_payouts' => true,
                'view_fraud_reports' => true,
                'manage_fraud_checks' => true,
                'view_audit_log' => true,
                'manage_mlm_settings' => true,
                'export_all_reports' => true,
            );
            
            foreach ($admin_caps as $cap => $grant) {
                $admin_role->add_cap($cap, $grant);
            }
        }
        
        // Log role creation
        error_log('WC MLM Affiliate: Custom roles created successfully');
    }
    
    /**
     * Remove custom roles
     */
    public static function remove_roles() {
        global $wp_roles;
        
        if (!class_exists('WP_Roles')) {
            return;
        }
        
        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }
        
        // Remove custom roles
        remove_role('direct_affiliate');
        remove_role('city_head');
        remove_role('state_head');
        
        // Remove admin capabilities
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_caps = array(
                'manage_mlm_system',
                'manage_mlm_affiliates',
                'manage_mlm_commissions',
                'manage_mlm_payouts',
                'manage_commission_rates',
                'manage_cities_states',
                'view_all_affiliates',
                'approve_affiliate_registrations',
                'promote_demote_affiliates',
                'suspend_affiliates',
                'delete_affiliates',
                'adjust_commissions',
                'process_payouts',
                'view_fraud_reports',
                'manage_fraud_checks',
                'view_audit_log',
                'manage_mlm_settings',
                'export_all_reports',
            );
            
            foreach ($admin_caps as $cap) {
                $admin_role->remove_cap($cap);
            }
        }
    }
    
    /**
     * Maybe update roles (for plugin updates)
     */
    public static function maybe_update_roles() {
        $version = get_option('wc_mlm_roles_version');
        
        if ($version !== WC_MLM_VERSION) {
            self::create_roles();
            update_option('wc_mlm_roles_version', WC_MLM_VERSION);
        }
    }
    
    /**
     * Check if user has MLM role
     */
    public static function is_mlm_user($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $user = get_userdata($user_id);
        
        if (!$user) {
            return false;
        }
        
        $mlm_roles = array('direct_affiliate', 'city_head', 'state_head');
        
        foreach ($mlm_roles as $role) {
            if (in_array($role, (array) $user->roles)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get user MLM role
     */
    public static function get_user_mlm_role($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $user = get_userdata($user_id);
        
        if (!$user) {
            return false;
        }
        
        $mlm_roles = array('state_head', 'city_head', 'direct_affiliate');
        
        foreach ($mlm_roles as $role) {
            if (in_array($role, (array) $user->roles)) {
                return $role;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user is Direct Affiliate
     */
    public static function is_direct_affiliate($user_id = null) {
        return self::get_user_mlm_role($user_id) === 'direct_affiliate';
    }
    
    /**
     * Check if user is City Head
     */
    public static function is_city_head($user_id = null) {
        return self::get_user_mlm_role($user_id) === 'city_head';
    }
    
    /**
     * Check if user is State Head
     */
    public static function is_state_head($user_id = null) {
        return self::get_user_mlm_role($user_id) === 'state_head';
    }
    
    /**
     * Get role display name
     */
    public static function get_role_display_name($role) {
        $names = array(
            'direct_affiliate' => __('Direct Affiliate', 'wc-mlm-affiliate'),
            'city_head' => __('City Head', 'wc-mlm-affiliate'),
            'state_head' => __('State Head', 'wc-mlm-affiliate'),
        );
        
        return isset($names[$role]) ? $names[$role] : $role;
    }
}
