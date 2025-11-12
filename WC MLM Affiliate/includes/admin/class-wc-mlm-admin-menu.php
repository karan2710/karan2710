<?php
/**
 * Admin Menu Handler
 * 
 * @package WC_MLM_Affiliate
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_MLM_Admin_Menu {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'register_menu'));
    }
    
    public function register_menu() {
        // Main menu
        add_menu_page(
            __('MLM Affiliate', 'wc-mlm-affiliate'),
            __('MLM Affiliate', 'wc-mlm-affiliate'),
            'manage_mlm_system',
            'wc-mlm-dashboard',
            array($this, 'dashboard_page'),
            'dashicons-groups',
            56
        );
        
        // Dashboard submenu
        add_submenu_page(
            'wc-mlm-dashboard',
            __('Dashboard', 'wc-mlm-affiliate'),
            __('Dashboard', 'wc-mlm-affiliate'),
            'manage_mlm_system',
            'wc-mlm-dashboard',
            array($this, 'dashboard_page')
        );
        
        // Affiliates submenu
        add_submenu_page(
            'wc-mlm-dashboard',
            __('Affiliates', 'wc-mlm-affiliate'),
            __('Affiliates', 'wc-mlm-affiliate'),
            'manage_mlm_affiliates',
            'wc-mlm-affiliates',
            array($this, 'affiliates_page')
        );
        
        // Commissions submenu
        add_submenu_page(
            'wc-mlm-dashboard',
            __('Commissions', 'wc-mlm-affiliate'),
            __('Commissions', 'wc-mlm-affiliate'),
            'manage_mlm_commissions',
            'wc-mlm-commissions',
            array($this, 'commissions_page')
        );
        
        // Payouts submenu
        add_submenu_page(
            'wc-mlm-dashboard',
            __('Payouts', 'wc-mlm-affiliate'),
            __('Payouts', 'wc-mlm-affiliate'),
            'manage_mlm_payouts',
            'wc-mlm-payouts',
            array($this, 'payouts_page')
        );
        
        // Reports submenu
        add_submenu_page(
            'wc-mlm-dashboard',
            __('Reports', 'wc-mlm-affiliate'),
            __('Reports', 'wc-mlm-affiliate'),
            'manage_mlm_system',
            'wc-mlm-reports',
            array($this, 'reports_page')
        );
        
        // Settings submenu
        add_submenu_page(
            'wc-mlm-dashboard',
            __('Settings', 'wc-mlm-affiliate'),
            __('Settings', 'wc-mlm-affiliate'),
            'manage_mlm_settings',
            'wc-mlm-settings',
            array($this, 'settings_page')
        );
        
        // Test Data submenu (only in development)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_submenu_page(
                'wc-mlm-dashboard',
                __('Test Data', 'wc-mlm-affiliate'),
                __('🧪 Test Data', 'wc-mlm-affiliate'),
                'manage_mlm_system',
                'wc-mlm-test-data',
                array($this, 'test_data_page')
            );
        }
    }
    
    public function dashboard_page() {
        ?>
        <div class="wrap wc-mlm-admin">
            <h1><?php _e('MLM Affiliate Dashboard', 'wc-mlm-affiliate'); ?></h1>
            
            <div class="wc-mlm-dashboard-stats">
                <div class="stat-box">
                    <h3><?php _e('Total Affiliates', 'wc-mlm-affiliate'); ?></h3>
                    <p class="stat-number"><?php echo $this->get_total_affiliates(); ?></p>
                </div>
                
                <div class="stat-box">
                    <h3><?php _e('Total Sales', 'wc-mlm-affiliate'); ?></h3>
                    <p class="stat-number"><?php echo wc_price($this->get_total_sales()); ?></p>
                </div>
                
                <div class="stat-box">
                    <h3><?php _e('Total Commissions Paid', 'wc-mlm-affiliate'); ?></h3>
                    <p class="stat-number"><?php echo wc_price($this->get_total_commissions_paid()); ?></p>
                </div>
                
                <div class="stat-box">
                    <h3><?php _e('Pending Payouts', 'wc-mlm-affiliate'); ?></h3>
                    <p class="stat-number"><?php echo wc_price($this->get_pending_payouts()); ?></p>
                </div>
            </div>
            
            <div class="wc-mlm-dashboard-content">
                <h2><?php _e('Recent Activity', 'wc-mlm-affiliate'); ?></h2>
                <p><?php _e('Phase 1: Foundation setup complete. Commission engine and dashboards coming in Phase 2-4.', 'wc-mlm-affiliate'); ?></p>
            </div>
        </div>
        <?php
    }
    
    public function affiliates_page() {
        // Handle coupon generation
        if (isset($_POST['generate_coupon']) && isset($_POST['affiliate_id'])) {
            check_admin_referer('wc_mlm_generate_coupon');
            
            $affiliate_id = intval($_POST['affiliate_id']);
            $affiliate = WC_MLM_Database::get_affiliate_by_user_id($affiliate_id);
            
            if ($affiliate) {
                $coupon_code = WC_MLM_Coupon_Manager::generate_affiliate_coupon(
                    $affiliate_id,
                    $affiliate->referral_id,
                    $affiliate->city
                );
                
                if ($coupon_code) {
                    echo '<div class="notice notice-success"><p>' . 
                         sprintf(__('Coupon "%s" generated successfully!', 'wc-mlm-affiliate'), $coupon_code) . 
                         '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . 
                         __('Failed to generate coupon. It may already exist.', 'wc-mlm-affiliate') . 
                         '</p></div>';
                }
            }
        }
        
        // Get all affiliates
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_affiliates';
        $affiliates = $wpdb->get_results("SELECT * FROM $table ORDER BY joined_date DESC LIMIT 50");
        
        ?>
        <div class="wrap">
            <h1><?php _e('Manage Affiliates', 'wc-mlm-affiliate'); ?></h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Referral ID', 'wc-mlm-affiliate'); ?></th>
                        <th><?php _e('Name', 'wc-mlm-affiliate'); ?></th>
                        <th><?php _e('Email', 'wc-mlm-affiliate'); ?></th>
                        <th><?php _e('Role', 'wc-mlm-affiliate'); ?></th>
                        <th><?php _e('City', 'wc-mlm-affiliate'); ?></th>
                        <th><?php _e('Status', 'wc-mlm-affiliate'); ?></th>
                        <th><?php _e('Coupon', 'wc-mlm-affiliate'); ?></th>
                        <th><?php _e('Actions', 'wc-mlm-affiliate'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($affiliates)): ?>
                        <tr>
                            <td colspan="8"><?php _e('No affiliates found.', 'wc-mlm-affiliate'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($affiliates as $affiliate): ?>
                            <?php
                            $user = get_userdata($affiliate->user_id);
                            $coupons = WC_MLM_Coupon_Manager::get_affiliate_coupons($affiliate->user_id);
                            $has_coupon = !empty($coupons);
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($affiliate->referral_id); ?></strong></td>
                                <td><?php echo $user ? esc_html($user->display_name) : '-'; ?></td>
                                <td><?php echo $user ? esc_html($user->user_email) : '-'; ?></td>
                                <td><?php echo esc_html(ucwords(str_replace('_', ' ', $affiliate->role))); ?></td>
                                <td><?php echo esc_html($affiliate->city ?: '-'); ?></td>
                                <td>
                                    <span class="status-<?php echo esc_attr($affiliate->status); ?>">
                                        <?php echo esc_html(ucfirst($affiliate->status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($has_coupon): ?>
                                        <strong><?php echo esc_html($coupons[0]['code']); ?></strong>
                                        <br>
                                        <small><?php printf(__('Used %d times', 'wc-mlm-affiliate'), $coupons[0]['usage_count']); ?></small>
                                    <?php else: ?>
                                        <span style="color: #999;"><?php _e('No coupon', 'wc-mlm-affiliate'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$has_coupon && $affiliate->status === 'active'): ?>
                                        <form method="post" style="display:inline;">
                                            <?php wp_nonce_field('wc_mlm_generate_coupon'); ?>
                                            <input type="hidden" name="affiliate_id" value="<?php echo esc_attr($affiliate->user_id); ?>">
                                            <button type="submit" name="generate_coupon" class="button button-primary button-small">
                                                <?php _e('Generate Coupon', 'wc-mlm-affiliate'); ?>
                                            </button>
                                        </form>
                                    <?php elseif ($has_coupon): ?>
                                        <a href="<?php echo admin_url('post.php?post=' . $coupons[0]['id'] . '&action=edit'); ?>" 
                                           class="button button-small">
                                            <?php _e('View Coupon', 'wc-mlm-affiliate'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <br>
            
            <div class="card">
                <h2><?php _e('Bulk Actions', 'wc-mlm-affiliate'); ?></h2>
                <p><?php _e('Generate coupons for all active affiliates who don\'t have one yet.', 'wc-mlm-affiliate'); ?></p>
                
                <form method="post">
                    <?php wp_nonce_field('wc_mlm_bulk_generate'); ?>
                    <button type="submit" name="bulk_generate_coupons" class="button button-secondary">
                        <?php _e('Bulk Generate Coupons', 'wc-mlm-affiliate'); ?>
                    </button>
                </form>
            </div>
            
            <?php
            // Handle bulk generation
            if (isset($_POST['bulk_generate_coupons'])) {
                check_admin_referer('wc_mlm_bulk_generate');
                
                $generated = 0;
                foreach ($affiliates as $affiliate) {
                    if ($affiliate->status !== 'active') {
                        continue;
                    }
                    
                    $coupons = WC_MLM_Coupon_Manager::get_affiliate_coupons($affiliate->user_id);
                    if (empty($coupons)) {
                        $coupon_code = WC_MLM_Coupon_Manager::generate_affiliate_coupon(
                            $affiliate->user_id,
                            $affiliate->referral_id,
                            $affiliate->city
                        );
                        if ($coupon_code) {
                            $generated++;
                        }
                    }
                }
                
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('Successfully generated %d coupons!', 'wc-mlm-affiliate'), $generated) . 
                     '</p></div>';
            }
            ?>
        </div>
        
        <style>
            .status-active { color: #46b450; font-weight: bold; }
            .status-pending { color: #ffb900; font-weight: bold; }
            .status-inactive { color: #dc3232; font-weight: bold; }
        </style>
        <?php
    }
    
    public function commissions_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Manage Commissions', 'wc-mlm-affiliate'); ?></h1>
            <p><?php _e('Commission management interface will be built in Phase 5.', 'wc-mlm-affiliate'); ?></p>
        </div>
        <?php
    }
    
    public function payouts_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Manage Payouts', 'wc-mlm-affiliate'); ?></h1>
            <p><?php _e('Payout management interface will be built in Phase 5.', 'wc-mlm-affiliate'); ?></p>
        </div>
        <?php
    }
    
    public function reports_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Reports & Analytics', 'wc-mlm-affiliate'); ?></h1>
            <p><?php _e('Reports interface will be built in Phase 6.', 'wc-mlm-affiliate'); ?></p>
        </div>
        <?php
    }
    
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('MLM Settings', 'wc-mlm-affiliate'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('wc_mlm_settings');
                do_settings_sections('wc_mlm_settings');
                ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Global Direct Affiliate Rate (%)', 'wc-mlm-affiliate'); ?></th>
                        <td>
                            <input type="number" step="0.01" name="wc_mlm_global_direct_rate" value="<?php echo esc_attr(get_option('wc_mlm_global_direct_rate', '10.00')); ?>" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Global City Head Rate (%)', 'wc-mlm-affiliate'); ?></th>
                        <td>
                            <input type="number" step="0.01" name="wc_mlm_global_city_head_rate" value="<?php echo esc_attr(get_option('wc_mlm_global_city_head_rate', '3.00')); ?>" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Global State Head Rate (%)', 'wc-mlm-affiliate'); ?></th>
                        <td>
                            <input type="number" step="0.01" name="wc_mlm_global_state_head_rate" value="<?php echo esc_attr(get_option('wc_mlm_global_state_head_rate', '2.00')); ?>" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Commission Hold Period (Days)', 'wc-mlm-affiliate'); ?></th>
                        <td>
                            <input type="number" name="wc_mlm_commission_hold_days" value="<?php echo esc_attr(get_option('wc_mlm_commission_hold_days', '7')); ?>" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Minimum Payout Amount', 'wc-mlm-affiliate'); ?></th>
                        <td>
                            <input type="number" step="0.01" name="wc_mlm_min_payout_amount" value="<?php echo esc_attr(get_option('wc_mlm_min_payout_amount', '1000.00')); ?>" />
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    // Helper functions for dashboard stats
    private function get_total_affiliates() {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_affiliates';
        return $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'active'");
    }
    
    private function get_total_sales() {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_commissions';
        return $wpdb->get_var("SELECT SUM(amount) FROM $table WHERE status = 'approved'") ?: 0;
    }
    
    private function get_total_commissions_paid() {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_payouts';
        return $wpdb->get_var("SELECT SUM(net_amount) FROM $table WHERE status = 'completed'") ?: 0;
    }
    
    private function get_pending_payouts() {
        global $wpdb;
        $table = $wpdb->prefix . 'mlm_payouts';
        return $wpdb->get_var("SELECT SUM(net_amount) FROM $table WHERE status = 'pending'") ?: 0;
    }
    
    /**
     * Test Data Page
     */
    public function test_data_page() {
        if (!current_user_can('manage_mlm_system')) {
            wp_die(__('You do not have permission to access this page.', 'wc-mlm-affiliate'));
        }
        
        // Handle test data creation
        if (isset($_POST['create_test_affiliates'])) {
            check_admin_referer('wc_mlm_test_data');
            $this->create_test_affiliates();
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('🧪 Test Data Generator', 'wc-mlm-affiliate'); ?></h1>
            
            <div class="notice notice-warning">
                <p><strong><?php _e('⚠️ WARNING:', 'wc-mlm-affiliate'); ?></strong> <?php _e('This page is only for testing and development. Do not use on production sites!', 'wc-mlm-affiliate'); ?></p>
            </div>
            
            <div class="card">
                <h2><?php _e('Create Test Affiliates', 'wc-mlm-affiliate'); ?></h2>
                <p><?php _e('This will create 3 test affiliates with the following details:', 'wc-mlm-affiliate'); ?></p>
                
                <ul>
                    <li><strong>Affiliate 1:</strong> test_affiliate_hyd / affiliate.hyd@test.com (Hyderabad)</li>
                    <li><strong>Affiliate 2:</strong> test_affiliate_mum / affiliate.mum@test.com (Mumbai)</li>
                    <li><strong>Affiliate 3:</strong> test_affiliate_del / affiliate.del@test.com (Delhi)</li>
                    <li><strong>Password for all:</strong> TestPassword123!</li>
                </ul>
                
                <p><?php _e('All test affiliates will be created as "Active" status so you can immediately generate coupons.', 'wc-mlm-affiliate'); ?></p>
                
                <form method="post">
                    <?php wp_nonce_field('wc_mlm_test_data'); ?>
                    <button type="submit" name="create_test_affiliates" class="button button-primary">
                        <?php _e('Create Test Affiliates', 'wc-mlm-affiliate'); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Create test affiliates
     */
    private function create_test_affiliates() {
        $test_affiliates = array(
            array(
                'username' => 'test_affiliate_hyd',
                'email' => 'affiliate.hyd@test.com',
                'display_name' => 'John Doe',
                'city' => 'Hyderabad',
                'state' => 'Telangana',
            ),
            array(
                'username' => 'test_affiliate_mum',
                'email' => 'affiliate.mum@test.com',
                'display_name' => 'Jane Smith',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
            ),
            array(
                'username' => 'test_affiliate_del',
                'email' => 'affiliate.del@test.com',
                'display_name' => 'Robert Brown',
                'city' => 'Delhi',
                'state' => 'Delhi',
            ),
        );
        
        $created = 0;
        $skipped = 0;
        
        foreach ($test_affiliates as $data) {
            // Check if user already exists
            if (username_exists($data['username']) || email_exists($data['email'])) {
                $skipped++;
                continue;
            }
            
            // Create WordPress user
            $user_id = wp_create_user(
                $data['username'],
                'TestPassword123!',
                $data['email']
            );
            
            if (is_wp_error($user_id)) {
                continue;
            }
            
            // Update user display name
            wp_update_user(array(
                'ID' => $user_id,
                'display_name' => $data['display_name'],
            ));
            
            // Set user role
            $user = new WP_User($user_id);
            $user->set_role('direct_affiliate');
            
            // Generate referral ID
            $referral_id = WC_MLM_Database::generate_referral_id(strtoupper(substr($data['city'], 0, 3)));
            
            // Create affiliate record
            $affiliate_id = WC_MLM_Database::create_affiliate(array(
                'user_id' => $user_id,
                'referral_id' => $referral_id,
                'sponsor_id' => null,
                'role' => 'direct_affiliate',
                'city' => $data['city'],
                'state' => $data['state'],
                'status' => 'active',
                'kyc_verified' => 1,
                'joined_date' => current_time('mysql'),
                'approved_date' => current_time('mysql'),
                'approved_by' => get_current_user_id(),
            ));
            
            if ($affiliate_id) {
                $created++;
            }
        }
        
        if ($created > 0) {
            echo '<div class="notice notice-success"><p>' . 
                 sprintf(__('✅ Successfully created %d test affiliates! ', 'wc-mlm-affiliate'), $created) .
                 '<a href="' . admin_url('admin.php?page=wc-mlm-affiliates') . '">' . __('View Affiliates', 'wc-mlm-affiliate') . '</a>' .
                 '</p></div>';
        }
        
        if ($skipped > 0) {
            echo '<div class="notice notice-info"><p>' . 
                 sprintf(__('ℹ️ Skipped %d affiliates (already exist).', 'wc-mlm-affiliate'), $skipped) .
                 '</p></div>';
        }
    }
}