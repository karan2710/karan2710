<?php
/**
 * Affiliate Sync Handler
 * 
 * Syncs WordPress users with MLM affiliate database
 * 
 * @package WC_MLM_Affiliate
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_MLM_Affiliate_Sync {
    
    /**
     * Initialize
     */
    public static function init() {
        // Hook when user role is changed
        add_action('set_user_role', array(__CLASS__, 'sync_on_role_change'), 10, 3);
        
        // Hook when new user is created
        add_action('user_register', array(__CLASS__, 'sync_new_user'), 10, 1);
        
        // Add sync button to admin
        add_action('admin_notices', array(__CLASS__, 'show_sync_notice'));
        add_action('admin_post_wc_mlm_sync_affiliates', array(__CLASS__, 'handle_manual_sync'));
    }
    
    /**
     * Sync when user role is changed
     */
    public static function sync_on_role_change($user_id, $role, $old_roles) {
        // Check if new role is an MLM role
        $mlm_roles = array('direct_affiliate', 'city_head', 'state_head');
        
        if (in_array($role, $mlm_roles)) {
            self::create_affiliate_record($user_id, $role);
        }
    }
    
    /**
     * Sync new user registration
     */
    public static function sync_new_user($user_id) {
        $user = get_userdata($user_id);
        
        if (!$user) {
            return;
        }
        
        // Check if user has MLM role
        $mlm_roles = array('direct_affiliate', 'city_head', 'state_head');
        $user_roles = $user->roles;
        
        foreach ($user_roles as $role) {
            if (in_array($role, $mlm_roles)) {
                self::create_affiliate_record($user_id, $role);
                break;
            }
        }
    }
    
    /**
     * Create affiliate record for user
     */
    private static function create_affiliate_record($user_id, $role = 'direct_affiliate') {
        // Check if affiliate record already exists
        $existing = WC_MLM_Database::get_affiliate_by_user_id($user_id);
        
        if ($existing) {
            // Update role if different
            if ($existing->role !== $role) {
                WC_MLM_Database::update_affiliate($existing->id, array('role' => $role));
            }
            return $existing->id;
        }
        
        // Get user data
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        
        // Get city/state from user meta (if available)
        $city = get_user_meta($user_id, 'billing_city', true);
        $state = get_user_meta($user_id, 'billing_state', true);
        
        // If no city, try to detect from other fields
        if (empty($city)) {
            $city = 'Unknown';
        }
        
        if (empty($state)) {
            $state = 'Unknown';
        }
        
        // Generate unique referral ID
        $city_code = strtoupper(substr($city, 0, 3));
        if ($city === 'Unknown') {
            $city_code = 'AFF';
        }
        
        $referral_id = WC_MLM_Database::generate_referral_id($city_code);
        
        if (!$referral_id) {
            $referral_id = 'AFF' . str_pad($user_id, 5, '0', STR_PAD_LEFT);
        }
        
        // Create affiliate record
        $affiliate_id = WC_MLM_Database::create_affiliate(array(
            'user_id' => $user_id,
            'referral_id' => $referral_id,
            'sponsor_id' => null, // Will be set during registration
            'role' => $role,
            'city' => $city,
            'state' => $state,
            'status' => 'active', // Auto-activate for admin-created users
            'kyc_verified' => 0,
            'joined_date' => current_time('mysql'),
            'approved_date' => current_time('mysql'),
            'approved_by' => get_current_user_id(),
        ));
        
        if ($affiliate_id) {
            // Store referral ID in user meta for quick access
            update_user_meta($user_id, 'mlm_referral_id', $referral_id);
            
            // Auto-generate coupon if setting is enabled
            if (get_option('wc_mlm_auto_generate_coupon', 'yes') === 'yes') {
                WC_MLM_Coupon_Manager::generate_affiliate_coupon($user_id, $referral_id, $city);
            }
            
            // Log the sync
            WC_MLM_Database::log_action(
                get_current_user_id(),
                'affiliate_synced',
                'user',
                $user_id,
                array(
                    'affiliate_id' => $affiliate_id,
                    'referral_id' => $referral_id,
                )
            );
        }
        
        return $affiliate_id;
    }
    
    /**
     * Show sync notice in admin
     */
    public static function show_sync_notice() {
        $screen = get_current_screen();
        
        if ($screen && $screen->id === 'toplevel_page_wc-mlm-dashboard') {
            // Count users with MLM roles but no affiliate record
            $unsynced = self::count_unsynced_users();
            
            if ($unsynced > 0) {
                ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php _e('MLM Affiliate Sync Required:', 'wc-mlm-affiliate'); ?></strong>
                        <?php printf(__('Found %d users with MLM roles that are not synced to the affiliate database.', 'wc-mlm-affiliate'), $unsynced); ?>
                    </p>
                    <p>
                        <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wc_mlm_sync_affiliates'), 'wc_mlm_sync_affiliates'); ?>" 
                           class="button button-primary">
                            <?php _e('Sync Now', 'wc-mlm-affiliate'); ?>
                        </a>
                    </p>
                </div>
                <?php
            }
        }
    }
    
    /**
     * Count unsynced users
     */
    private static function count_unsynced_users() {
        global $wpdb;
        
        $mlm_roles = array('direct_affiliate', 'city_head', 'state_head');
        $affiliate_table = $wpdb->prefix . 'mlm_affiliates';
        
        $unsynced = 0;
        
        foreach ($mlm_roles as $role) {
            // Get users with this role
            $users = get_users(array('role' => $role));
            
            foreach ($users as $user) {
                // Check if affiliate record exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $affiliate_table WHERE user_id = %d",
                    $user->ID
                ));
                
                if (!$exists) {
                    $unsynced++;
                }
            }
        }
        
        return $unsynced;
    }
    
    /**
     * Handle manual sync request
     */
    public static function handle_manual_sync() {
        // Check nonce
        check_admin_referer('wc_mlm_sync_affiliates');
        
        // Check permissions
        if (!current_user_can('manage_mlm_system')) {
            wp_die(__('You do not have permission to perform this action.', 'wc-mlm-affiliate'));
        }
        
        // Sync all MLM users
        $synced = self::sync_all_mlm_users();
        
        // Redirect back with success message
        wp_redirect(add_query_arg(array(
            'page' => 'wc-mlm-dashboard',
            'synced' => $synced,
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Sync all MLM users
     */
    public static function sync_all_mlm_users() {
        $mlm_roles = array('direct_affiliate', 'city_head', 'state_head');
        $synced = 0;
        
        foreach ($mlm_roles as $role) {
            // Get users with this role
            $users = get_users(array('role' => $role));
            
            foreach ($users as $user) {
                // Check if affiliate record exists
                $existing = WC_MLM_Database::get_affiliate_by_user_id($user->ID);
                
                if (!$existing) {
                    // Create affiliate record
                    $result = self::create_affiliate_record($user->ID, $role);
                    if ($result) {
                        $synced++;
                    }
                }
            }
        }
        
        return $synced;
    }
    
    /**
     * Get all affiliates (joined with WordPress users)
     */
    public static function get_all_affiliates_with_users($limit = 50, $offset = 0, $search = '') {
        global $wpdb;
        $affiliate_table = $wpdb->prefix . 'mlm_affiliates';
        $users_table = $wpdb->users;
        
        $sql = "SELECT a.*, u.user_login, u.user_email, u.display_name 
                FROM $affiliate_table a
                LEFT JOIN $users_table u ON a.user_id = u.ID
                WHERE 1=1";
        
        if (!empty($search)) {
            $search = $wpdb->esc_like($search);
            $sql .= $wpdb->prepare(" AND (u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR a.referral_id LIKE %s)",
                "%$search%", "%$search%", "%$search%", "%$search%"
            );
        }
        
        $sql .= " ORDER BY a.joined_date DESC";
        $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        
        return $wpdb->get_results($sql);
    }
    
    /**
     * Count total affiliates
     */
    public static function count_affiliates($search = '') {
        global $wpdb;
        $affiliate_table = $wpdb->prefix . 'mlm_affiliates';
        $users_table = $wpdb->users;
        
        $sql = "SELECT COUNT(*) FROM $affiliate_table a
                LEFT JOIN $users_table u ON a.user_id = u.ID
                WHERE 1=1";
        
        if (!empty($search)) {
            $search = $wpdb->esc_like($search);
            $sql .= $wpdb->prepare(" AND (u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR a.referral_id LIKE %s)",
                "%$search%", "%$search%", "%$search%", "%$search%"
            );
        }
        
        return $wpdb->get_var($sql);
    }
}

// Initialize
WC_MLM_Affiliate_Sync::init();