<?php
/**
 * Cron Job Handler
 * 
 * Handles scheduled tasks like commission release
 * 
 * @package WC_MLM_Affiliate
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_MLM_Cron_Handler {
    
    /**
     * Initialize
     */
    public static function init() {
        // Hook into cron events
        add_action('wc_mlm_commission_release', array(__CLASS__, 'release_held_commissions'));
        add_action('wc_mlm_daily_cron', array(__CLASS__, 'daily_maintenance'));
    }
    
    /**
     * Release held commissions
     * Runs daily
     */
    public static function release_held_commissions() {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_commissions';
        
        // Get all pending commissions where hold period has expired
        $commissions = $wpdb->get_results(
            "SELECT * FROM $table 
             WHERE status = 'pending' 
             AND hold_until IS NOT NULL 
             AND hold_until <= NOW()"
        );
        
        if (empty($commissions)) {
            return;
        }
        
        $released_count = 0;
        $total_amount = 0;
        
        foreach ($commissions as $commission) {
            // Check if order is still completed
            $order = wc_get_order($commission->order_id);
            
            if (!$order) {
                continue;
            }
            
            $order_status = $order->get_status();
            
            // Only release if order is still completed
            if ($order_status === 'completed') {
                // Update commission status to approved
                $wpdb->update(
                    $table,
                    array(
                        'status' => 'approved',
                        'approved_date' => current_time('mysql'),
                    ),
                    array('id' => $commission->id),
                    array('%s', '%s'),
                    array('%d')
                );
                
                $released_count++;
                $total_amount += floatval($commission->amount);
                
                // Notify affiliate
                WC_MLM_Database::create_notification(
                    $commission->affiliate_id,
                    'commission_released',
                    __('Commission Released', 'wc-mlm-affiliate'),
                    sprintf(
                        __('Your commission of %s from order #%d has been released to your wallet.', 'wc-mlm-affiliate'),
                        wc_price($commission->amount),
                        $commission->order_id
                    )
                );
                
                // Log the action
                WC_MLM_Database::log_action(
                    $commission->affiliate_id,
                    'commission_released',
                    'commission',
                    $commission->id,
                    array(
                        'amount' => $commission->amount,
                        'order_id' => $commission->order_id,
                    )
                );
            } else {
                // Order status changed, reverse commission
                $wpdb->update(
                    $table,
                    array('status' => 'reversed'),
                    array('id' => $commission->id),
                    array('%s'),
                    array('%d')
                );
                
                // Notify affiliate
                WC_MLM_Database::create_notification(
                    $commission->affiliate_id,
                    'commission_reversed',
                    __('Commission Not Released', 'wc-mlm-affiliate'),
                    sprintf(
                        __('Commission from order #%d was not released because the order status changed to "%s".', 'wc-mlm-affiliate'),
                        $commission->order_id,
                        wc_get_order_status_name($order_status)
                    )
                );
            }
        }
        
        // Log summary
        if ($released_count > 0) {
            error_log(sprintf(
                'WC MLM: Released %d commissions totaling %s',
                $released_count,
                wc_price($total_amount)
            ));
        }
    }
    
    /**
     * Daily maintenance tasks
     */
    public static function daily_maintenance() {
        // Clean up old referral clicks (older than 90 days)
        self::cleanup_old_clicks();
        
        // Clean up old notifications (older than 30 days and read)
        self::cleanup_old_notifications();
        
        // Send daily summary emails
        self::send_daily_summaries();
    }
    
    /**
     * Clean up old referral clicks
     */
    private static function cleanup_old_clicks() {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_referral_clicks';
        
        $deleted = $wpdb->query(
            "DELETE FROM $table 
             WHERE click_date < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        
        if ($deleted > 0) {
            error_log(sprintf('WC MLM: Cleaned up %d old referral clicks', $deleted));
        }
    }
    
    /**
     * Clean up old notifications
     */
    private static function cleanup_old_notifications() {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_notifications';
        
        $deleted = $wpdb->query(
            "DELETE FROM $table 
             WHERE is_read = 1 
             AND created_date < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        if ($deleted > 0) {
            error_log(sprintf('WC MLM: Cleaned up %d old notifications', $deleted));
        }
    }
    
    /**
     * Send daily summary emails (optional feature)
     */
    private static function send_daily_summaries() {
        // Check if daily summaries are enabled
        if (get_option('wc_mlm_daily_summaries', 'no') !== 'yes') {
            return;
        }
        
        global $wpdb;
        $affiliates_table = $wpdb->prefix . 'mlm_affiliates';
        
        // Get all active affiliates
        $affiliates = $wpdb->get_results(
            "SELECT * FROM $affiliates_table WHERE status = 'active'"
        );
        
        foreach ($affiliates as $affiliate) {
            // Get yesterday's data
            $yesterday_start = date('Y-m-d 00:00:00', strtotime('-1 day'));
            $yesterday_end = date('Y-m-d 23:59:59', strtotime('-1 day'));
            
            // Get commissions earned yesterday
            $commissions_table = $wpdb->prefix . 'mlm_commissions';
            $yesterday_commissions = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) as count, SUM(amount) as total 
                 FROM $commissions_table 
                 WHERE affiliate_id = %d 
                 AND created_date BETWEEN %s AND %s",
                $affiliate->user_id,
                $yesterday_start,
                $yesterday_end
            ));
            
            // Only send if there's activity
            if ($yesterday_commissions->count > 0) {
                self::send_daily_summary_email($affiliate, $yesterday_commissions);
            }
        }
    }
    
    /**
     * Send daily summary email to affiliate
     */
    private static function send_daily_summary_email($affiliate, $data) {
        $user = get_userdata($affiliate->user_id);
        
        if (!$user) {
            return;
        }
        
        $subject = sprintf(__('[%s] Your Daily Affiliate Summary', 'wc-mlm-affiliate'), get_bloginfo('name'));
        
        $message = sprintf(
            __("Hello %s,\n\nHere's your affiliate performance for yesterday:\n\n", 'wc-mlm-affiliate'),
            $user->display_name
        );
        
        $message .= sprintf(
            __("Total Commissions Earned: %s\nNumber of Orders: %d\n\n", 'wc-mlm-affiliate'),
            wc_price($data->total),
            $data->count
        );
        
        $message .= sprintf(
            __("View your full dashboard: %s\n\n", 'wc-mlm-affiliate'),
            home_url('/affiliate-dashboard/')
        );
        
        $message .= __("Keep up the great work!\n\nBest regards,\nThe Team", 'wc-mlm-affiliate');
        
        wp_mail($user->user_email, $subject, $message);
    }
    
    /**
     * Manual commission release (for admin use)
     */
    public static function manual_release_commission($commission_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_commissions';
        
        $result = $wpdb->update(
            $table,
            array(
                'status' => 'approved',
                'approved_date' => current_time('mysql'),
            ),
            array('id' => $commission_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result) {
            $commission = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d",
                $commission_id
            ));
            
            if ($commission) {
                // Notify affiliate
                WC_MLM_Database::create_notification(
                    $commission->affiliate_id,
                    'commission_released',
                    __('Commission Manually Released', 'wc-mlm-affiliate'),
                    sprintf(
                        __('Your commission of %s from order #%d has been manually released by admin.', 'wc-mlm-affiliate'),
                        wc_price($commission->amount),
                        $commission->order_id
                    )
                );
            }
        }
        
        return $result;
    }
}

// Initialize
WC_MLM_Cron_Handler::init();
