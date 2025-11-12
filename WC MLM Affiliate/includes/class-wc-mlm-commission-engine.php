<?php
/**
 * Commission Calculation Engine
 * 
 * Calculates commissions for Direct Affiliate, City Head, and State Head
 * 
 * @package WC_MLM_Affiliate
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_MLM_Commission_Engine {
    
    /**
     * Initialize
     */
    public static function init() {
        // Calculate commissions on order completion
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'calculate_commissions'), 10, 2);
    }
    
    /**
     * Calculate commissions for order
     */
    public static function calculate_commissions($order_id, $order = null) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            return;
        }
        
        // Check if commissions already calculated
        if (get_post_meta($order_id, '_mlm_commissions_calculated', true)) {
            return; // Already processed
        }
        
        // Get affiliate ID from order
        $affiliate_id = get_post_meta($order_id, '_mlm_affiliate_id', true);
        
        if (!$affiliate_id) {
            return; // Not an affiliate order
        }
        
        // Check fraud status
        $fraud_check = get_post_meta($order_id, '_mlm_fraud_check', true);
        if (is_array($fraud_check) && isset($fraud_check['status']) && $fraud_check['status'] === 'high_risk') {
            // Don't calculate commissions for high-risk orders
            $order->add_order_note(__('[MLM] Commission calculation skipped - High risk order pending review.', 'wc-mlm-affiliate'));
            return;
        }
        
        // Get affiliate details
        $affiliate = WC_MLM_Database::get_affiliate_by_user_id($affiliate_id);
        
        if (!$affiliate || $affiliate->status !== 'active') {
            return; // Inactive affiliate
        }
        
        // Calculate commissionable amount
        $commissionable_amount = self::get_commissionable_amount($order);
        
        if ($commissionable_amount <= 0) {
            return;
        }
        
        // Get commission rates
        $rates = self::get_commission_rates($order, $affiliate);
        
        // Calculate hold period
        $hold_days = intval(get_option('wc_mlm_commission_hold_days', 7));
        $hold_until = date('Y-m-d H:i:s', strtotime("+{$hold_days} days"));
        
        $total_commission = 0;
        $commissions_created = array();
        
        // 1. Direct Affiliate Commission
        $direct_commission = ($commissionable_amount * $rates['direct_rate']) / 100;
        if ($direct_commission > 0) {
            $commission_id = WC_MLM_Database::create_commission(array(
                'affiliate_id' => $affiliate_id,
                'order_id' => $order_id,
                'customer_id' => $order->get_customer_id(),
                'amount' => $direct_commission,
                'type' => 'direct',
                'status' => 'pending',
                'hold_until' => $hold_until,
            ));
            
            if ($commission_id) {
                $commissions_created[] = array('type' => 'direct', 'amount' => $direct_commission);
                $total_commission += $direct_commission;
                
                // Notify affiliate
                WC_MLM_Database::create_notification(
                    $affiliate_id,
                    'commission_earned',
                    __('Commission Earned', 'wc-mlm-affiliate'),
                    sprintf(__('You earned %s commission from order #%d', 'wc-mlm-affiliate'), wc_price($direct_commission), $order_id)
                );
            }
        }
        
        // 2. City Head Commission (if exists)
        if (!empty($affiliate->city)) {
            $city_head = self::get_city_head($affiliate->city);
            
            if ($city_head && $city_head->user_id != $affiliate_id) {
                $city_commission = ($commissionable_amount * $rates['city_head_rate']) / 100;
                
                if ($city_commission > 0) {
                    $commission_id = WC_MLM_Database::create_commission(array(
                        'affiliate_id' => $city_head->user_id,
                        'order_id' => $order_id,
                        'customer_id' => $order->get_customer_id(),
                        'amount' => $city_commission,
                        'type' => 'city_head',
                        'status' => 'pending',
                        'hold_until' => $hold_until,
                    ));
                    
                    if ($commission_id) {
                        $commissions_created[] = array('type' => 'city_head', 'amount' => $city_commission);
                        $total_commission += $city_commission;
                        
                        // Notify city head
                        WC_MLM_Database::create_notification(
                            $city_head->user_id,
                            'commission_earned',
                            __('City Head Commission Earned', 'wc-mlm-affiliate'),
                            sprintf(__('You earned %s override commission from order #%d in your city.', 'wc-mlm-affiliate'), wc_price($city_commission), $order_id)
                        );
                    }
                }
            }
        }
        
        // 3. State Head Commission (if exists)
        if (!empty($affiliate->state)) {
            $state_head = self::get_state_head($affiliate->state);
            
            if ($state_head && $state_head->user_id != $affiliate_id) {
                $state_commission = ($commissionable_amount * $rates['state_head_rate']) / 100;
                
                if ($state_commission > 0) {
                    $commission_id = WC_MLM_Database::create_commission(array(
                        'affiliate_id' => $state_head->user_id,
                        'order_id' => $order_id,
                        'customer_id' => $order->get_customer_id(),
                        'amount' => $state_commission,
                        'type' => 'state_head',
                        'status' => 'pending',
                        'hold_until' => $hold_until,
                    ));
                    
                    if ($commission_id) {
                        $commissions_created[] = array('type' => 'state_head', 'amount' => $state_commission);
                        $total_commission += $state_commission;
                        
                        // Notify state head
                        WC_MLM_Database::create_notification(
                            $state_head->user_id,
                            'commission_earned',
                            __('State Head Commission Earned', 'wc-mlm-affiliate'),
                            sprintf(__('You earned %s override commission from order #%d in your state.', 'wc-mlm-affiliate'), wc_price($state_commission), $order_id)
                        );
                    }
                }
            }
        }
        
        // Mark as calculated
        update_post_meta($order_id, '_mlm_commissions_calculated', true);
        update_post_meta($order_id, '_mlm_total_commission', $total_commission);
        update_post_meta($order_id, '_mlm_commissions_breakdown', $commissions_created);
        
        // Add order note
        $order->add_order_note(
            sprintf(
                __('[MLM] Commissions calculated: %s (will be released after %d days)', 'wc-mlm-affiliate'),
                wc_price($total_commission),
                $hold_days
            )
        );
        
        // Log the action
        WC_MLM_Database::log_action(
            $affiliate_id,
            'commissions_calculated',
            'order',
            $order_id,
            array(
                'total_commission' => $total_commission,
                'breakdown' => $commissions_created,
            )
        );
    }
    
    /**
     * Get commissionable amount from order
     */
    private static function get_commissionable_amount($order) {
        // Start with order subtotal
        $amount = $order->get_subtotal();
        
        // Subtract tax
        $amount -= $order->get_total_tax();
        
        // Subtract shipping
        $amount -= $order->get_shipping_total();
        
        // Note: Affiliate coupon discount is NOT subtracted
        // Commission is calculated on pre-discount amount
        
        return max(0, $amount);
    }
    
    /**
     * Get commission rates for order
     */
    private static function get_commission_rates($order, $affiliate) {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_commission_rates';
        
        // Default rates
        $rates = array(
            'direct_rate' => floatval(get_option('wc_mlm_global_direct_rate', 10.00)),
            'city_head_rate' => floatval(get_option('wc_mlm_global_city_head_rate', 3.00)),
            'state_head_rate' => floatval(get_option('wc_mlm_global_state_head_rate', 2.00)),
        );
        
        // Check for custom rates (in priority order)
        $items = $order->get_items();
        
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            
            // Priority 1: Affiliate-specific + Product-specific + City-specific
            $custom_rate = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table 
                 WHERE affiliate_id = %d AND product_id = %d AND city = %s 
                 ORDER BY priority DESC LIMIT 1",
                $affiliate->user_id,
                $product_id,
                $affiliate->city
            ));
            
            if ($custom_rate) {
                $rates = array(
                    'direct_rate' => floatval($custom_rate->direct_rate),
                    'city_head_rate' => floatval($custom_rate->city_head_rate),
                    'state_head_rate' => floatval($custom_rate->state_head_rate),
                );
                break;
            }
            
            // Priority 2: Product + City specific
            $custom_rate = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table 
                 WHERE product_id = %d AND city = %s AND affiliate_id IS NULL 
                 ORDER BY priority DESC LIMIT 1",
                $product_id,
                $affiliate->city
            ));
            
            if ($custom_rate) {
                $rates = array(
                    'direct_rate' => floatval($custom_rate->direct_rate),
                    'city_head_rate' => floatval($custom_rate->city_head_rate),
                    'state_head_rate' => floatval($custom_rate->state_head_rate),
                );
                break;
            }
            
            // Priority 3: Product-specific only
            $custom_rate = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table 
                 WHERE product_id = %d AND city IS NULL AND affiliate_id IS NULL 
                 ORDER BY priority DESC LIMIT 1",
                $product_id
            ));
            
            if ($custom_rate) {
                $rates = array(
                    'direct_rate' => floatval($custom_rate->direct_rate),
                    'city_head_rate' => floatval($custom_rate->city_head_rate),
                    'state_head_rate' => floatval($custom_rate->state_head_rate),
                );
                break;
            }
            
            // Priority 4: City-specific only
            $custom_rate = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table 
                 WHERE city = %s AND product_id IS NULL AND affiliate_id IS NULL 
                 ORDER BY priority DESC LIMIT 1",
                $affiliate->city
            ));
            
            if ($custom_rate) {
                $rates = array(
                    'direct_rate' => floatval($custom_rate->direct_rate),
                    'city_head_rate' => floatval($custom_rate->city_head_rate),
                    'state_head_rate' => floatval($custom_rate->state_head_rate),
                );
                break;
            }
        }
        
        return $rates;
    }
    
    /**
     * Get City Head for city
     */
    private static function get_city_head($city) {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_affiliates';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE city = %s AND role = 'city_head' AND status = 'active' LIMIT 1",
            $city
        ));
    }
    
    /**
     * Get State Head for state
     */
    private static function get_state_head($state) {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_affiliates';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE state = %s AND role = 'state_head' AND status = 'active' LIMIT 1",
            $state
        ));
    }
    
    /**
     * Reverse commissions (for refunds/cancellations)
     */
    public static function reverse_commissions($order_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_commissions';
        
        // Get all commissions for this order
        $commissions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d AND status != 'reversed'",
            $order_id
        ));
        
        if (empty($commissions)) {
            return;
        }
        
        foreach ($commissions as $commission) {
            // Update status to reversed
            $wpdb->update(
                $table,
                array('status' => 'reversed'),
                array('id' => $commission->id),
                array('%s'),
                array('%d')
            );
            
            // Deduct from affiliate wallet if already approved
            if ($commission->status === 'approved') {
                // This will be handled by wallet system in Phase 5
            }
            
            // Notify affiliate
            WC_MLM_Database::create_notification(
                $commission->affiliate_id,
                'commission_reversed',
                __('Commission Reversed', 'wc-mlm-affiliate'),
                sprintf(__('Commission of %s from order #%d has been reversed due to refund/cancellation.', 'wc-mlm-affiliate'), 
                    wc_price($commission->amount), 
                    $order_id
                )
            );
        }
        
        // Add order note
        $order = wc_get_order($order_id);
        if ($order) {
            $order->add_order_note(__('[MLM] All commissions reversed due to refund/cancellation.', 'wc-mlm-affiliate'));
        }
    }
}

// Initialize
WC_MLM_Commission_Engine::init();
