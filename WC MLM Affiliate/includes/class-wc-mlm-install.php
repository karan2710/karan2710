<?php
/**
 * Installation and Activation Handler
 * 
 * @package WC_MLM_Affiliate
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_MLM_Install {
    
    /**
     * Plugin activation
     */
    public static function activate() {
        // Check WordPress and WooCommerce versions
        self::check_version();
        
        // Create database tables
        self::create_tables();
        
        // Create custom roles
        WC_MLM_Roles::create_roles();
        
        // Set default options
        self::set_default_options();
        
        // Schedule cron jobs
        self::schedule_cron_jobs();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Set activation flag
        update_option('wc_mlm_activated', current_time('mysql'));
        update_option('wc_mlm_version', WC_MLM_VERSION);
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled cron jobs
        wp_clear_scheduled_hook('wc_mlm_daily_cron');
        wp_clear_scheduled_hook('wc_mlm_commission_release');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Check version requirements
     */
    private static function check_version() {
        global $wp_version;
        
        // Check WordPress version
        if (version_compare($wp_version, '5.8', '<')) {
            deactivate_plugins(WC_MLM_PLUGIN_BASENAME);
            wp_die(__('WooCommerce MLM Affiliate requires WordPress 5.8 or higher.', 'wc-mlm-affiliate'));
        }
        
        // Check WooCommerce version
        if (defined('WC_VERSION') && version_compare(WC_VERSION, '6.0', '<')) {
            deactivate_plugins(WC_MLM_PLUGIN_BASENAME);
            wp_die(__('WooCommerce MLM Affiliate requires WooCommerce 6.0 or higher.', 'wc-mlm-affiliate'));
        }
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(WC_MLM_PLUGIN_BASENAME);
            wp_die(__('WooCommerce MLM Affiliate requires PHP 7.4 or higher.', 'wc-mlm-affiliate'));
        }
    }
    
    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Table 1: Affiliates
        $table_affiliates = $wpdb->prefix . 'mlm_affiliates';
        $sql_affiliates = "CREATE TABLE IF NOT EXISTS $table_affiliates (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            referral_id varchar(10) NOT NULL UNIQUE,
            sponsor_id bigint(20) UNSIGNED DEFAULT NULL,
            role varchar(20) NOT NULL DEFAULT 'direct_affiliate',
            city varchar(100) DEFAULT NULL,
            state varchar(100) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            kyc_verified tinyint(1) DEFAULT 0,
            bank_details longtext DEFAULT NULL,
            joined_date datetime DEFAULT CURRENT_TIMESTAMP,
            approved_date datetime DEFAULT NULL,
            approved_by bigint(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY referral_id (referral_id),
            KEY sponsor_id (sponsor_id),
            KEY status (status),
            KEY city (city),
            KEY state (state)
        ) $charset_collate;";
        dbDelta($sql_affiliates);
        
        // Table 2: Commissions
        $table_commissions = $wpdb->prefix . 'mlm_commissions';
        $sql_commissions = "CREATE TABLE IF NOT EXISTS $table_commissions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            affiliate_id bigint(20) UNSIGNED NOT NULL,
            order_id bigint(20) UNSIGNED NOT NULL,
            customer_id bigint(20) UNSIGNED DEFAULT NULL,
            amount decimal(10,2) NOT NULL DEFAULT 0.00,
            type varchar(20) NOT NULL,
            product_id bigint(20) UNSIGNED DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_date datetime DEFAULT CURRENT_TIMESTAMP,
            approved_date datetime DEFAULT NULL,
            hold_until datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY affiliate_id (affiliate_id),
            KEY order_id (order_id),
            KEY status (status),
            KEY type (type),
            KEY created_date (created_date)
        ) $charset_collate;";
        dbDelta($sql_commissions);
        
        // Table 3: Payouts
        $table_payouts = $wpdb->prefix . 'mlm_payouts';
        $sql_payouts = "CREATE TABLE IF NOT EXISTS $table_payouts (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            affiliate_id bigint(20) UNSIGNED NOT NULL,
            amount decimal(10,2) NOT NULL DEFAULT 0.00,
            tds_amount decimal(10,2) DEFAULT 0.00,
            net_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            method varchar(50) NOT NULL,
            transaction_id varchar(100) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            request_date datetime DEFAULT CURRENT_TIMESTAMP,
            processed_date datetime DEFAULT NULL,
            processed_by bigint(20) UNSIGNED DEFAULT NULL,
            notes text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY affiliate_id (affiliate_id),
            KEY status (status),
            KEY request_date (request_date)
        ) $charset_collate;";
        dbDelta($sql_payouts);
        
        // Table 4: Commission Rates
        $table_rates = $wpdb->prefix . 'mlm_commission_rates';
        $sql_rates = "CREATE TABLE IF NOT EXISTS $table_rates (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id bigint(20) UNSIGNED DEFAULT NULL,
            category_id bigint(20) UNSIGNED DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            affiliate_id bigint(20) UNSIGNED DEFAULT NULL,
            direct_rate decimal(5,2) NOT NULL DEFAULT 10.00,
            city_head_rate decimal(5,2) NOT NULL DEFAULT 3.00,
            state_head_rate decimal(5,2) NOT NULL DEFAULT 2.00,
            priority int(11) NOT NULL DEFAULT 0,
            created_date datetime DEFAULT CURRENT_TIMESTAMP,
            updated_date datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY category_id (category_id),
            KEY city (city),
            KEY affiliate_id (affiliate_id),
            KEY priority (priority)
        ) $charset_collate;";
        dbDelta($sql_rates);
        
        // Table 5: Referral Clicks
        $table_clicks = $wpdb->prefix . 'mlm_referral_clicks';
        $sql_clicks = "CREATE TABLE IF NOT EXISTS $table_clicks (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            affiliate_id bigint(20) UNSIGNED NOT NULL,
            ip_address varchar(45) NOT NULL,
            user_agent text DEFAULT NULL,
            referrer_url text DEFAULT NULL,
            landing_url text DEFAULT NULL,
            converted tinyint(1) DEFAULT 0,
            conversion_date datetime DEFAULT NULL,
            click_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY affiliate_id (affiliate_id),
            KEY ip_address (ip_address),
            KEY click_date (click_date),
            KEY converted (converted)
        ) $charset_collate;";
        dbDelta($sql_clicks);
        
        // Table 6: Notifications
        $table_notifications = $wpdb->prefix . 'mlm_notifications';
        $sql_notifications = "CREATE TABLE IF NOT EXISTS $table_notifications (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            type varchar(50) NOT NULL,
            title varchar(255) NOT NULL,
            message text NOT NULL,
            is_read tinyint(1) DEFAULT 0,
            created_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY is_read (is_read),
            KEY created_date (created_date)
        ) $charset_collate;";
        dbDelta($sql_notifications);
        
        // Table 7: Audit Log
        $table_audit = $wpdb->prefix . 'mlm_audit_log';
        $sql_audit = "CREATE TABLE IF NOT EXISTS $table_audit (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            action varchar(100) NOT NULL,
            target_type varchar(50) DEFAULT NULL,
            target_id bigint(20) UNSIGNED DEFAULT NULL,
            details longtext DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            created_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY action (action),
            KEY created_date (created_date)
        ) $charset_collate;";
        dbDelta($sql_audit);
        
        // Table 8: Fraud Checks
        $table_fraud = $wpdb->prefix . 'mlm_fraud_checks';
        $sql_fraud = "CREATE TABLE IF NOT EXISTS $table_fraud (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id bigint(20) UNSIGNED NOT NULL,
            affiliate_id bigint(20) UNSIGNED NOT NULL,
            customer_email varchar(100) NOT NULL,
            customer_phone varchar(20) DEFAULT NULL,
            customer_ip varchar(45) DEFAULT NULL,
            fraud_score int(11) NOT NULL DEFAULT 0,
            flags longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            reviewed_by bigint(20) UNSIGNED DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY affiliate_id (affiliate_id),
            KEY status (status),
            KEY fraud_score (fraud_score)
        ) $charset_collate;";
        dbDelta($sql_fraud);
        
        // Table 9: Cities
        $table_cities = $wpdb->prefix . 'mlm_cities';
        $sql_cities = "CREATE TABLE IF NOT EXISTS $table_cities (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            state varchar(100) NOT NULL,
            country varchar(100) NOT NULL DEFAULT 'India',
            is_active tinyint(1) DEFAULT 1,
            created_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY name (name),
            KEY state (state)
        ) $charset_collate;";
        dbDelta($sql_cities);
        
        // Table 10: Pin Codes
        $table_pincodes = $wpdb->prefix . 'mlm_pincodes';
        $sql_pincodes = "CREATE TABLE IF NOT EXISTS $table_pincodes (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            pincode varchar(10) NOT NULL,
            city_id bigint(20) UNSIGNED NOT NULL,
            area varchar(255) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY pincode (pincode),
            KEY city_id (city_id)
        ) $charset_collate;";
        dbDelta($sql_pincodes);
        
        // Log table creation
        error_log('WC MLM Affiliate: Database tables created successfully');
    }
    
    /**
     * Set default options
     */
    private static function set_default_options() {
        $default_options = array(
            'wc_mlm_global_direct_rate' => '10.00',
            'wc_mlm_global_city_head_rate' => '3.00',
            'wc_mlm_global_state_head_rate' => '2.00',
            'wc_mlm_commission_hold_days' => '7',
            'wc_mlm_min_payout_amount' => '1000.00',
            'wc_mlm_tds_rate' => '5.00',
            'wc_mlm_tds_threshold' => '50000.00',
            'wc_mlm_coupon_discount' => '10.00',
            'wc_mlm_min_order_value' => '500.00',
            'wc_mlm_max_discount_cap' => '5000.00',
            'wc_mlm_fraud_email_score' => '40',
            'wc_mlm_fraud_phone_score' => '30',
            'wc_mlm_fraud_address_score' => '20',
            'wc_mlm_fraud_ip_score' => '10',
            'wc_mlm_high_risk_threshold' => '50',
            'wc_mlm_medium_risk_threshold' => '30',
            'wc_mlm_auto_generate_coupon' => 'yes', // NEW: Auto-generate on approval
        );
        
        foreach ($default_options as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }
    
    /**
     * Schedule cron jobs
     */
    private static function schedule_cron_jobs() {
        // Daily cron for commission release
        if (!wp_next_scheduled('wc_mlm_commission_release')) {
            wp_schedule_event(time(), 'daily', 'wc_mlm_commission_release');
        }
        
        // Daily cron for general maintenance
        if (!wp_next_scheduled('wc_mlm_daily_cron')) {
            wp_schedule_event(time(), 'daily', 'wc_mlm_daily_cron');
        }
    }
}