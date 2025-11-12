<?php
/**
 * Sync Affiliates Script
 * 
 * This script will:
 * 1. Check if the affiliates table exists
 * 2. Sync all users with affiliate roles to the database table
 * 3. Create test affiliates if needed
 * 
 * Access at: yoursite.com/wp-content/plugins/wc-mlm-affiliate/sync-affiliates.php
 */

// Load WordPress
require_once('../../../../wp-load.php');

// Check if user is admin
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to access this page.');
}

global $wpdb;

echo '<html><head><title>Affiliate Sync Tool</title>';
echo '<style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .info { color: blue; }
    .section { background: #f5f5f5; padding: 15px; margin: 15px 0; border-radius: 5px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #4CAF50; color: white; }
</style></head><body>';

echo '<h1>🔧 Affiliate Sync Tool</h1>';

// 1. CHECK TABLE EXISTS
echo '<div class="section">';
echo '<h2>1. Checking Database Table</h2>';

$table_name = $wpdb->prefix . 'mlm_affiliates';
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;

if ($table_exists) {
    echo '<p class="success">✅ Table exists: ' . $table_name . '</p>';
    
    // Check table structure
    $columns = $wpdb->get_results("DESCRIBE $table_name");
    echo '<p class="info">Table has ' . count($columns) . ' columns</p>';
    
    // Count existing records
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    echo '<p class="info">Current record count: ' . $count . '</p>';
} else {
    echo '<p class="error">❌ Table does NOT exist: ' . $table_name . '</p>';
    echo '<p>The table needs to be created. Check your plugin activation/install code.</p>';
}
echo '</div>';

// 2. FIND USERS WITH AFFILIATE ROLES
echo '<div class="section">';
echo '<h2>2. Finding Users with Affiliate Roles</h2>';

$affiliate_roles = array('direct_affiliate', 'city_head', 'state_head');
$affiliate_users = get_users(array(
    'role__in' => $affiliate_roles,
    'fields' => 'all'
));

echo '<p class="info">Found ' . count($affiliate_users) . ' users with affiliate roles</p>';

if (count($affiliate_users) > 0) {
    echo '<table>';
    echo '<tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>In Database?</th></tr>';
    
    foreach ($affiliate_users as $user) {
        $user_roles = $user->roles;
        $role = '';
        foreach ($affiliate_roles as $check_role) {
            if (in_array($check_role, $user_roles)) {
                $role = $check_role;
                break;
            }
        }
        
        // Check if in database
        $in_db = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE user_id = %d",
            $user->ID
        ));
        
        $status = $in_db ? '<span class="success">✅ Yes</span>' : '<span class="error">❌ No</span>';
        
        echo '<tr>';
        echo '<td>' . $user->ID . '</td>';
        echo '<td>' . $user->user_login . '</td>';
        echo '<td>' . $user->user_email . '</td>';
        echo '<td>' . $role . '</td>';
        echo '<td>' . $status . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}
echo '</div>';

// 3. SYNC MISSING AFFILIATES
if (isset($_GET['action']) && $_GET['action'] == 'sync') {
    echo '<div class="section">';
    echo '<h2>3. Syncing Affiliates to Database</h2>';
    
    $synced = 0;
    $errors = 0;
    
    foreach ($affiliate_users as $user) {
        // Check if already in database
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE user_id = %d",
            $user->ID
        ));
        
        if ($exists) {
            continue; // Skip if already exists
        }
        
        $user_roles = $user->roles;
        $role = '';
        foreach ($affiliate_roles as $check_role) {
            if (in_array($check_role, $user_roles)) {
                $role = $check_role;
                break;
            }
        }
        
        // Generate referral ID
        $referral_id = strtoupper(substr(md5($user->user_login . time()), 0, 10));
        
        // Insert into database
        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user->ID,
                'referral_id' => $referral_id,
                'sponsor_id' => null,
                'role' => $role,
                'city' => get_user_meta($user->ID, 'city', true) ?: 'Unknown',
                'state' => get_user_meta($user->ID, 'state', true) ?: 'Unknown',
                'status' => 'active',
                'kyc_verified' => 1,
                'joined_date' => current_time('mysql'),
                'approved_date' => current_time('mysql'),
                'approved_by' => get_current_user_id(),
            ),
            array('%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d')
        );
        
        if ($result !== false) {
            $synced++;
            echo '<p class="success">✅ Synced: ' . $user->user_login . ' (ID: ' . $user->ID . ') - Referral: ' . $referral_id . '</p>';
        } else {
            $errors++;
            echo '<p class="error">❌ Failed to sync: ' . $user->user_login . ' - Error: ' . $wpdb->last_error . '</p>';
        }
    }
    
    echo '<p class="info"><strong>Summary: Synced ' . $synced . ' affiliates, ' . $errors . ' errors</strong></p>';
    echo '<p><a href="' . admin_url('admin.php?page=wc-mlm-affiliates') . '">→ View Affiliates Dashboard</a></p>';
    echo '</div>';
}

// 4. ACTION BUTTONS
if (!isset($_GET['action'])) {
    echo '<div class="section">';
    echo '<h2>Actions</h2>';
    echo '<p><a href="?action=sync" style="background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">🔄 Sync All Affiliates to Database</a></p>';
    echo '</div>';
}

echo '</body></html>';
?>
