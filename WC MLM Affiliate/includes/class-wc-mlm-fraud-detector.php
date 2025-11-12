<?php
/**
 * Fraud Detection Handler
 * 
 * Cross-verifies customers against affiliate database to prevent self-purchases
 * 
 * @package WC_MLM_Affiliate
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_MLM_Fraud_Detector {
    
    /**
     * Initialize
     */
    public static function init() {
        // Hook into checkout process (before order creation)
        add_action('woocommerce_after_checkout_validation', array(__CLASS__, 'validate_checkout'), 10, 2);
        
        // Hook after order is created (additional check)
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'check_order_fraud'), 5, 3);
    }
    
    /**
     * Validate checkout for fraud
     */
    public static function validate_checkout($data, $errors) {
        // Get applied coupons
        $coupons = WC()->cart->get_applied_coupons();
        
        if (empty($coupons)) {
            return; // No affiliate coupon used
        }
        
        // Check if any coupon is an affiliate coupon
        $affiliate_id = null;
        foreach ($coupons as $coupon_code) {
            $affiliate_id = self::get_affiliate_by_coupon($coupon_code);
            if ($affiliate_id) {
                break;
            }
        }
        
        if (!$affiliate_id) {
            return; // Not an affiliate coupon
        }
        
        // Get customer details
        $customer_email = sanitize_email($data['billing_email']);
        $customer_phone = sanitize_text_field($data['billing_phone']);
        
        // Check for fraud
        $fraud_result = self::check_customer_affiliate_match($customer_email, $customer_phone, $affiliate_id);
        
        if ($fraud_result['is_fraud']) {
            // Block checkout
            $errors->add('validation', sprintf(
                __('This email/phone is registered as an affiliate (ID: %s). You cannot use your own affiliate coupon for purchases. Please use a different email or remove the coupon.', 'wc-mlm-affiliate'),
                $fraud_result['affiliate_referral_id']
            ));
            
            // Log the attempt
            WC_MLM_Database::log_action(
                $affiliate_id,
                'fraud_self_purchase_blocked',
                'checkout',
                null,
                array(
                    'customer_email' => $customer_email,
                    'customer_phone' => $customer_phone,
                    'fraud_score' => $fraud_result['fraud_score'],
                    'flags' => $fraud_result['flags']
                )
            );
        }
    }
    
    /**
     * Check order for fraud after creation
     */
    public static function check_order_fraud($order_id, $posted_data, $order) {
        // Get affiliate ID from order meta (set by coupon)
        $affiliate_id = get_post_meta($order_id, '_mlm_affiliate_id', true);
        
        if (!$affiliate_id) {
            return; // Not an affiliate order
        }
        
        // Get customer details from order
        $customer_email = $order->get_billing_email();
        $customer_phone = $order->get_billing_phone();
        $customer_ip = $order->get_customer_ip_address();
        
        // Get affiliate details
        $affiliate = WC_MLM_Database::get_affiliate_by_user_id($affiliate_id);
        
        if (!$affiliate) {
            return;
        }
        
        // Perform fraud check
        $fraud_check = self::perform_fraud_check(
            $order_id,
            $affiliate_id,
            $customer_email,
            $customer_phone,
            $customer_ip,
            $order
        );
        
        // Store fraud check result in order meta
        update_post_meta($order_id, '_mlm_fraud_check', $fraud_check);
        
        // If high risk, hold order for review
        if ($fraud_check['fraud_score'] >= get_option('wc_mlm_high_risk_threshold', 50)) {
            // Add order note
            $order->add_order_note(
                sprintf(
                    __('[MLM] HIGH RISK ORDER - Fraud Score: %d. Commission payment on hold pending admin review.', 'wc-mlm-affiliate'),
                    $fraud_check['fraud_score']
                ),
                false,
                true
            );
            
            // Notify admin
            self::notify_admin_fraud_detected($order_id, $fraud_check);
        } elseif ($fraud_check['fraud_score'] >= get_option('wc_mlm_medium_risk_threshold', 30)) {
            // Medium risk - just add note
            $order->add_order_note(
                sprintf(
                    __('[MLM] MEDIUM RISK - Fraud Score: %d. Please review if necessary.', 'wc-mlm-affiliate'),
                    $fraud_check['fraud_score']
                ),
                false,
                true
            );
        }
        
        // Save fraud check to database
        WC_MLM_Database::create_fraud_check($fraud_check);
    }
    
    /**
     * Perform comprehensive fraud check
     */
    private static function perform_fraud_check($order_id, $affiliate_id, $customer_email, $customer_phone, $customer_ip, $order) {
        global $wpdb;
        
        $fraud_score = 0;
        $flags = array();
        
        // Get affiliate user data
        $affiliate = WC_MLM_Database::get_affiliate_by_user_id($affiliate_id);
        $affiliate_user = get_userdata($affiliate_id);
        
        // Check 1: Email match (40 points)
        if (strtolower($customer_email) === strtolower($affiliate_user->user_email)) {
            $fraud_score += intval(get_option('wc_mlm_fraud_email_score', 40));
            $flags[] = 'email_match';
        }
        
        // Check 2: Phone match (30 points)
        $affiliate_phone = get_user_meta($affiliate_id, 'billing_phone', true);
        if (!empty($affiliate_phone) && self::normalize_phone($customer_phone) === self::normalize_phone($affiliate_phone)) {
            $fraud_score += intval(get_option('wc_mlm_fraud_phone_score', 30));
            $flags[] = 'phone_match';
        }
        
        // Check 3: Address match (20 points)
        $billing_address = $order->get_billing_address_1() . ' ' . $order->get_billing_address_2();
        $shipping_address = $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2();
        
        $affiliate_address = get_user_meta($affiliate_id, 'billing_address_1', true);
        
        if (!empty($affiliate_address)) {
            if (stripos($billing_address, $affiliate_address) !== false || 
                stripos($shipping_address, $affiliate_address) !== false) {
                $fraud_score += intval(get_option('wc_mlm_fraud_address_score', 20));
                $flags[] = 'address_match';
            }
        }
        
        // Check 4: IP match (10 points) - check last login IP
        $affiliate_last_ip = get_user_meta($affiliate_id, 'last_login_ip', true);
        if (!empty($affiliate_last_ip) && $customer_ip === $affiliate_last_ip) {
            $fraud_score += intval(get_option('wc_mlm_fraud_ip_score', 10));
            $flags[] = 'ip_match';
        }
        
        // Check 5: Name similarity (15 points)
        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $affiliate_name = $affiliate_user->display_name;
        
        $similarity = 0;
        similar_text(strtolower($customer_name), strtolower($affiliate_name), $similarity);
        
        if ($similarity > 80) {
            $fraud_score += 15;
            $flags[] = 'name_similarity';
        }
        
        // Determine status
        $high_risk = intval(get_option('wc_mlm_high_risk_threshold', 50));
        $medium_risk = intval(get_option('wc_mlm_medium_risk_threshold', 30));
        
        $status = 'clear';
        if ($fraud_score >= $high_risk) {
            $status = 'high_risk';
        } elseif ($fraud_score >= $medium_risk) {
            $status = 'medium_risk';
        }
        
        return array(
            'order_id' => $order_id,
            'affiliate_id' => $affiliate_id,
            'customer_email' => $customer_email,
            'customer_phone' => $customer_phone,
            'customer_ip' => $customer_ip,
            'fraud_score' => $fraud_score,
            'flags' => json_encode($flags),
            'status' => $status,
            'created_at' => current_time('mysql'),
        );
    }
    
    /**
     * Check customer-affiliate match
     */
    private static function check_customer_affiliate_match($customer_email, $customer_phone, $affiliate_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_affiliates';
        
        $fraud_score = 0;
        $flags = array();
        $matched_affiliate = null;
        
        // Check email match
        $email_match = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, u.user_email FROM $table a 
             INNER JOIN {$wpdb->users} u ON a.user_id = u.ID 
             WHERE LOWER(u.user_email) = LOWER(%s) AND a.status = 'active'",
            $customer_email
        ));
        
        if ($email_match) {
            $fraud_score += intval(get_option('wc_mlm_fraud_email_score', 40));
            $flags[] = 'email_match';
            $matched_affiliate = $email_match;
        }
        
        // Check phone match
        if (!empty($customer_phone)) {
            $normalized_phone = self::normalize_phone($customer_phone);
            
            // This is simplified - in production, you'd store phone in affiliates table
            $phone_match = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->usermeta} 
                 WHERE meta_key = 'billing_phone' 
                 AND meta_value LIKE %s",
                '%' . $wpdb->esc_like($normalized_phone) . '%'
            ));
            
            if ($phone_match > 0) {
                $fraud_score += intval(get_option('wc_mlm_fraud_phone_score', 30));
                $flags[] = 'phone_match';
            }
        }
        
        $is_fraud = $fraud_score >= intval(get_option('wc_mlm_high_risk_threshold', 50));
        
        return array(
            'is_fraud' => $is_fraud,
            'fraud_score' => $fraud_score,
            'flags' => $flags,
            'affiliate_referral_id' => $matched_affiliate ? $matched_affiliate->referral_id : null,
        );
    }
    
    /**
     * Get affiliate ID by coupon code
     */
    private static function get_affiliate_by_coupon($coupon_code) {
        $coupon = new WC_Coupon($coupon_code);
        
        if (!$coupon->get_id()) {
            return false;
        }
        
        // Check if it's an affiliate coupon (has meta)
        $affiliate_id = get_post_meta($coupon->get_id(), '_mlm_affiliate_id', true);
        
        return $affiliate_id ? intval($affiliate_id) : false;
    }
    
    /**
     * Normalize phone number
     */
    private static function normalize_phone($phone) {
        // Remove all non-numeric characters
        return preg_replace('/[^0-9]/', '', $phone);
    }
    
    /**
     * Notify admin of fraud detection
     */
    private static function notify_admin_fraud_detected($order_id, $fraud_check) {
        $admin_email = get_option('admin_email');
        $order = wc_get_order($order_id);
        
        $subject = sprintf(__('[MLM Fraud Alert] High Risk Order #%d', 'wc-mlm-affiliate'), $order_id);
        
        $message = sprintf(
            __("A high-risk order has been detected:\n\nOrder ID: #%d\nFraud Score: %d\nFlags: %s\n\nCustomer Email: %s\nCustomer Phone: %s\n\nPlease review this order in the admin panel.", 'wc-mlm-affiliate'),
            $order_id,
            $fraud_check['fraud_score'],
            implode(', ', json_decode($fraud_check['flags'], true)),
            $fraud_check['customer_email'],
            $fraud_check['customer_phone']
        );
        
        wp_mail($admin_email, $subject, $message);
        
        // Also create notification for admin
        WC_MLM_Database::create_notification(
            1, // Admin user ID
            'fraud_alert',
            sprintf(__('High Risk Order #%d', 'wc-mlm-affiliate'), $order_id),
            sprintf(__('Fraud score: %d. Please review immediately.', 'wc-mlm-affiliate'), $fraud_check['fraud_score'])
        );
    }
    
    /**
     * Get fraud check for order
     */
    public static function get_order_fraud_check($order_id) {
        return WC_MLM_Database::get_fraud_check_by_order($order_id);
    }
    
    /**
     * Approve fraud check (admin action)
     */
    public static function approve_fraud_check($fraud_check_id, $admin_user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_fraud_checks';
        
        $result = $wpdb->update(
            $table,
            array(
                'status' => 'approved',
                'reviewed_by' => $admin_user_id,
                'reviewed_at' => current_time('mysql'),
            ),
            array('id' => $fraud_check_id),
            array('%s', '%d', '%s'),
            array('%d')
        );
        
        if ($result) {
            // Log the action
            WC_MLM_Database::log_action(
                $admin_user_id,
                'fraud_check_approved',
                'fraud_check',
                $fraud_check_id,
                array('fraud_check_id' => $fraud_check_id)
            );
        }
        
        return $result;
    }
}
