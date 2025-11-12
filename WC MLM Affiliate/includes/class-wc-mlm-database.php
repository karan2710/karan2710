<?php
/**
 * Database Operations Handler
 * 
 * @package WC_MLM_Affiliate
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_MLM_Database {
    
    /**
     * Get table name
     */
    private static function get_table($table_name) {
        global $wpdb;
        return $wpdb->prefix . 'mlm_' . $table_name;
    }
    
    /**
     * AFFILIATE OPERATIONS
     */
    
    /**
     * Get affiliate by user ID
     */
    public static function get_affiliate_by_user_id($user_id) {
        global $wpdb;
        $table = self::get_table('affiliates');
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d",
            $user_id
        ));
    }
    
    /**
     * Get affiliate by referral ID
     */
    public static function get_affiliate_by_referral_id($referral_id) {
        global $wpdb;
        $table = self::get_table('affiliates');
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE referral_id = %s",
            $referral_id
        ));
    }
    
    /**
     * Create affiliate record
     */
    public static function create_affiliate($data) {
        global $wpdb;
        $table = self::get_table('affiliates');
        
        $defaults = array(
            'user_id' => 0,
            'referral_id' => '',
            'sponsor_id' => null,
            'role' => 'direct_affiliate',
            'city' => null,
            'state' => null,
            'status' => 'pending',
            'kyc_verified' => 0,
            'bank_details' => null,
            'joined_date' => current_time('mysql'),
        );
        
        $data = wp_parse_args($data, $defaults);
        
        $result = $wpdb->insert($table, $data);
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Update affiliate
     */
    public static function update_affiliate($affiliate_id, $data) {
        global $wpdb;
        $table = self::get_table('affiliates');
        
        return $wpdb->update(
            $table,
            $data,
            array('id' => $affiliate_id),
            null,
            array('%d')
        );
    }
    
    /**
     * Get affiliates by sponsor
     */
    public static function get_downline($sponsor_id, $direct_only = false) {
        global $wpdb;
        $table = self::get_table('affiliates');
        
        if ($direct_only) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE sponsor_id = %d ORDER BY joined_date DESC",
                $sponsor_id
            ));
        } else {
            // Get all downline (recursive would be needed for complete tree)
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE sponsor_id = %d ORDER BY joined_date DESC",
                $sponsor_id
            ));
        }
    }
    
    /**
     * Get affiliates by city
     */
    public static function get_affiliates_by_city($city, $status = 'active') {
        global $wpdb;
        $table = self::get_table('affiliates');
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE city = %s AND status = %s ORDER BY joined_date DESC",
            $city,
            $status
        ));
    }
    
    /**
     * Get affiliates by state
     */
    public static function get_affiliates_by_state($state, $status = 'active') {
        global $wpdb;
        $table = self::get_table('affiliates');
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE state = %s AND status = %s ORDER BY joined_date DESC",
            $state,
            $status
        ));
    }
    
    /**
     * Generate unique referral ID
     */
    public static function generate_referral_id($city_code = '') {
        global $wpdb;
        $table = self::get_table('affiliates');
        
        if (empty($city_code)) {
            $city_code = 'AFF';
        }
        
        $attempts = 0;
        $max_attempts = 100;
        
        do {
            $random = str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $referral_id = strtoupper($city_code) . $random;
            
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE referral_id = %s",
                $referral_id
            ));
            
            $attempts++;
        } while ($exists > 0 && $attempts < $max_attempts);
        
        return $attempts < $max_attempts ? $referral_id : false;
    }
    
    /**
     * COMMISSION OPERATIONS
     */
    
    /**
     * Create commission record
     */
    public static function create_commission($data) {
        global $wpdb;
        $table = self::get_table('commissions');
        
        $defaults = array(
            'affiliate_id' => 0,
            'order_id' => 0,
            'customer_id' => null,
            'amount' => 0.00,
            'type' => 'direct',
            'product_id' => null,
            'status' => 'pending',
            'created_date' => current_time('mysql'),
            'hold_until' => null,
        );
        
        $data = wp_parse_args($data, $defaults);
        
        $result = $wpdb->insert($table, $data, array(
            '%d', '%d', '%d', '%f', '%s', '%d', '%s', '%s', '%s'
        ));
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Get commissions by affiliate
     */
    public static function get_commissions($affiliate_id, $status = null, $limit = null) {
        global $wpdb;
        $table = self::get_table('commissions');
        
        $sql = $wpdb->prepare("SELECT * FROM $table WHERE affiliate_id = %d", $affiliate_id);
        
        if ($status) {
            $sql .= $wpdb->prepare(" AND status = %s", $status);
        }
        
        $sql .= " ORDER BY created_date DESC";
        
        if ($limit) {
            $sql .= $wpdb->prepare(" LIMIT %d", $limit);
        }
        
        return $wpdb->get_results($sql);
    }
    
    /**
     * Get total commissions by affiliate
     */
    public static function get_total_commissions($affiliate_id, $status = 'approved') {
        global $wpdb;
        $table = self::get_table('commissions');
        
        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM $table WHERE affiliate_id = %d AND status = %s",
            $affiliate_id,
            $status
        ));
    }
    
    /**
     * Update commission status
     */
    public static function update_commission_status($commission_id, $status) {
        global $wpdb;
        $table = self::get_table('commissions');
        
        $data = array('status' => $status);
        
        if ($status === 'approved') {
            $data['approved_date'] = current_time('mysql');
        }
        
        return $wpdb->update(
            $table,
            $data,
            array('id' => $commission_id),
            array('%s', '%s'),
            array('%d')
        );
    }
    
    /**
     * PAYOUT OPERATIONS
     */
    
    /**
     * Create payout request
     */
    public static function create_payout($data) {
        global $wpdb;
        $table = self::get_table('payouts');
        
        $defaults = array(
            'affiliate_id' => 0,
            'amount' => 0.00,
            'tds_amount' => 0.00,
            'net_amount' => 0.00,
            'method' => 'bank_transfer',
            'status' => 'pending',
            'request_date' => current_time('mysql'),
        );
        
        $data = wp_parse_args($data, $defaults);
        
        $result = $wpdb->insert($table, $data);
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Get payouts by affiliate
     */
    public static function get_payouts($affiliate_id, $status = null) {
        global $wpdb;
        $table = self::get_table('payouts');
        
        $sql = $wpdb->prepare("SELECT * FROM $table WHERE affiliate_id = %d", $affiliate_id);
        
        if ($status) {
            $sql .= $wpdb->prepare(" AND status = %s", $status);
        }
        
        $sql .= " ORDER BY request_date DESC";
        
        return $wpdb->get_results($sql);
    }
    
    /**
     * FRAUD CHECK OPERATIONS
     */
    
    /**
     * Create fraud check record
     */
    public static function create_fraud_check($data) {
        global $wpdb;
        $table = self::get_table('fraud_checks');
        
        $defaults = array(
            'order_id' => 0,
            'affiliate_id' => 0,
            'customer_email' => '',
            'customer_phone' => '',
            'customer_ip' => '',
            'fraud_score' => 0,
            'flags' => '',
            'status' => 'pending',
            'created_at' => current_time('mysql'),
        );
        
        $data = wp_parse_args($data, $defaults);
        
        $result = $wpdb->insert($table, $data);
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Get fraud check by order ID
     */
    public static function get_fraud_check_by_order($order_id) {
        global $wpdb;
        $table = self::get_table('fraud_checks');
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d",
            $order_id
        ));
    }
    
    /**
     * NOTIFICATION OPERATIONS
     */
    
    /**
     * Create notification
     */
    public static function create_notification($user_id, $type, $title, $message) {
        global $wpdb;
        $table = self::get_table('notifications');
        
        return $wpdb->insert($table, array(
            'user_id' => $user_id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'is_read' => 0,
            'created_date' => current_time('mysql'),
        ));
    }
    
    /**
     * Get unread notifications
     */
    public static function get_unread_notifications($user_id) {
        global $wpdb;
        $table = self::get_table('notifications');
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND is_read = 0 ORDER BY created_date DESC",
            $user_id
        ));
    }
    
    /**
     * Mark notification as read
     */
    public static function mark_notification_read($notification_id) {
        global $wpdb;
        $table = self::get_table('notifications');
        
        return $wpdb->update(
            $table,
            array('is_read' => 1),
            array('id' => $notification_id),
            array('%d'),
            array('%d')
        );
    }
    
    /**
     * AUDIT LOG OPERATIONS
     */
    
    /**
     * Log action
     */
    public static function log_action($user_id, $action, $target_type = null, $target_id = null, $details = null) {
        global $wpdb;
        $table = self::get_table('audit_log');
        
        return $wpdb->insert($table, array(
            'user_id' => $user_id,
            'action' => $action,
            'target_type' => $target_type,
            'target_id' => $target_id,
            'details' => is_array($details) ? json_encode($details) : $details,
            'ip_address' => self::get_user_ip(),
            'created_date' => current_time('mysql'),
        ));
    }
    
    /**
     * Get user IP address
     */
    private static function get_user_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }
    
    /**
     * CITY OPERATIONS
     */
    
    /**
     * Get all cities
     */
    public static function get_cities($state = null) {
        global $wpdb;
        $table = self::get_table('cities');
        
        if ($state) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE state = %s AND is_active = 1 ORDER BY name ASC",
                $state
            ));
        }
        
        return $wpdb->get_results("SELECT * FROM $table WHERE is_active = 1 ORDER BY state ASC, name ASC");
    }
    
    /**
     * Get city by pincode
     */
    public static function get_city_by_pincode($pincode) {
        global $wpdb;
        $pincode_table = self::get_table('pincodes');
        $city_table = self::get_table('cities');
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.* FROM $city_table c 
             INNER JOIN $pincode_table p ON c.id = p.city_id 
             WHERE p.pincode = %s LIMIT 1",
            $pincode
        ));
    }
}
