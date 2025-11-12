<?php
/**
 * MLM Plugin Debug Tool
 * 
 * Add this file to your plugin root as 'debug-affiliates.php'
 * Then access it via: yourdomain.com/wp-content/plugins/your-plugin/debug-affiliates.php
 * 
 * This will help identify why affiliates aren't showing on dashboard
 */

// Load WordPress
require_once('../../../wp-load.php');

// Check if user is admin
if (!current_user_can('manage_options')) {
    wp_die('Access denied. Admin only.');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>MLM Debug Tool</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .section { background: white; padding: 20px; margin-bottom: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h2 { color: #333; border-bottom: 2px solid #0073aa; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #0073aa; color: white; }
        tr:hover { background: #f9f9f9; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        pre { background: #f9f9f9; padding: 10px; border-left: 3px solid #0073aa; overflow-x: auto; }
        .fix-btn { background: #0073aa; color: white; padding: 8px 15px; border: none; border-radius: 3px; cursor: pointer; }
        .fix-btn:hover { background: #005177; }
    </style>
</head>
<body>
    <h1>🔍 MLM Plugin Debug Tool</h1>

    <?php
    global $wpdb;

    // =============================================
    // 1. CHECK CUSTOM TABLES
    // =============================================
    echo '<div class="section">';
    echo '<h2>1. Database Tables Check</h2>';
    
    $tables_to_check = [
        'wp_mlm_affiliates',
        'wp_mlm_commissions',
        'wp_mlm_payouts',
        'wp_mlm_commission_rates',
        'wp_mlm_referral_clicks',
        'wp_mlm_notifications',
        'wp_mlm_audit_log'
    ];
    
    echo '<table>';
    echo '<tr><th>Table Name</th><th>Status</th><th>Row Count</th></tr>';
    
    foreach ($tables_to_check as $table) {
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if ($table_exists) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
            echo "<tr><td>$table</td><td class='success'>✓ Exists</td><td>$count rows</td></tr>";
        } else {
            echo "<tr><td>$table</td><td class='error'>✗ Missing</td><td>-</td></tr>";
        }
    }
    
    echo '</table>';
    echo '</div>';

    // =============================================
    // 2. CHECK CUSTOM USER ROLES
    // =============================================
    echo '<div class="section">';
    echo '<h2>2. Custom User Roles Check</h2>';
    
    $roles_to_check = ['direct_affiliate', 'city_head', 'state_head'];
    $wp_roles = wp_roles();
    
    echo '<table>';
    echo '<tr><th>Role</th><th>Status</th><th>User Count</th></tr>';
    
    foreach ($roles_to_check as $role) {
        $role_exists = $wp_roles->is_role($role);
        if ($role_exists) {
            $users = get_users(['role' => $role]);
            $count = count($users);
            echo "<tr><td>$role</td><td class='success'>✓ Exists</td><td>$count users</td></tr>";
        } else {
            echo "<tr><td>$role</td><td class='error'>✗ Missing</td><td>-</td></tr>";
        }
    }
    
    echo '</table>';
    echo '</div>';

    // =============================================
    // 3. LIST ALL AFFILIATES FROM DATABASE
    // =============================================
    echo '<div class="section">';
    echo '<h2>3. Affiliates in Database (wp_mlm_affiliates table)</h2>';
    
    $affiliates_table = $wpdb->get_results("SELECT * FROM wp_mlm_affiliates ORDER BY id DESC LIMIT 50");
    
    if ($affiliates_table) {
        echo '<p class="success">Found ' . count($affiliates_table) . ' affiliate records</p>';
        echo '<table>';
        echo '<tr><th>ID</th><th>User ID</th><th>Referral ID</th><th>Role</th><th>City</th><th>State</th><th>Status</th><th>Joined Date</th></tr>';
        
        foreach ($affiliates_table as $aff) {
            $user_info = get_userdata($aff->user_id);
            $username = $user_info ? $user_info->user_login : 'Unknown';
            
            echo '<tr>';
            echo '<td>' . $aff->id . '</td>';
            echo '<td>' . $aff->user_id . ' (' . $username . ')</td>';
            echo '<td>' . $aff->referral_id . '</td>';
            echo '<td>' . $aff->role . '</td>';
            echo '<td>' . $aff->city . '</td>';
            echo '<td>' . $aff->state . '</td>';
            echo '<td>' . $aff->status . '</td>';
            echo '<td>' . $aff->joined_date . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
    } else {
        echo '<p class="error">No affiliates found in wp_mlm_affiliates table!</p>';
        echo '<p>This could mean:</p>';
        echo '<ul>';
        echo '<li>No affiliates have been registered yet</li>';
        echo '<li>Registration form is not saving data correctly</li>';
        echo '<li>Database table structure issue</li>';
        echo '</ul>';
    }
    
    echo '</div>';

    // =============================================
    // 4. LIST ALL USERS WITH AFFILIATE ROLES
    // =============================================
    echo '<div class="section">';
    echo '<h2>4. WordPress Users with Affiliate Roles</h2>';
    
    $all_affiliate_users = get_users([
        'role__in' => ['direct_affiliate', 'city_head', 'state_head']
    ]);
    
    if ($all_affiliate_users) {
        echo '<p class="success">Found ' . count($all_affiliate_users) . ' users with affiliate roles</p>';
        echo '<table>';
        echo '<tr><th>User ID</th><th>Username</th><th>Email</th><th>Role</th><th>Registered</th></tr>';
        
        foreach ($all_affiliate_users as $user) {
            echo '<tr>';
            echo '<td>' . $user->ID . '</td>';
            echo '<td>' . $user->user_login . '</td>';
            echo '<td>' . $user->user_email . '</td>';
            echo '<td>' . implode(', ', $user->roles) . '</td>';
            echo '<td>' . $user->user_registered . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
    } else {
        echo '<p class="error">No users found with affiliate roles!</p>';
    }
    
    echo '</div>';

    // =============================================
    // 5. CHECK SYNC BETWEEN TABLES
    // =============================================
    echo '<div class="section">';
    echo '<h2>5. Data Sync Check</h2>';
    
    // Users with roles but not in affiliates table
    $users_not_in_table = [];
    foreach ($all_affiliate_users as $user) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM wp_mlm_affiliates WHERE user_id = %d",
            $user->ID
        ));
        if ($exists == 0) {
            $users_not_in_table[] = $user;
        }
    }
    
    if (!empty($users_not_in_table)) {
        echo '<p class="warning">⚠️ Found ' . count($users_not_in_table) . ' users with affiliate role but NOT in wp_mlm_affiliates table:</p>';
        echo '<table>';
        echo '<tr><th>User ID</th><th>Username</th><th>Email</th><th>Action</th></tr>';
        foreach ($users_not_in_table as $user) {
            echo '<tr>';
            echo '<td>' . $user->ID . '</td>';
            echo '<td>' . $user->user_login . '</td>';
            echo '<td>' . $user->user_email . '</td>';
            echo '<td><button class="fix-btn" onclick="syncUser(' . $user->ID . ')">Sync to Table</button></td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="success">✓ All users with affiliate roles are properly synced in database table</p>';
    }
    
    // Records in table but user doesn't have correct role
    if ($affiliates_table) {
        echo '<br><p><strong>Checking reverse sync...</strong></p>';
        $table_not_in_users = [];
        foreach ($affiliates_table as $aff) {
            $user = get_userdata($aff->user_id);
            if ($user && !in_array($aff->role, $user->roles)) {
                $table_not_in_users[] = $aff;
            }
        }
        
        if (!empty($table_not_in_users)) {
            echo '<p class="warning">⚠️ Found ' . count($table_not_in_users) . ' database records where user role doesn\'t match:</p>';
            echo '<table>';
            echo '<tr><th>DB ID</th><th>User ID</th><th>Expected Role</th><th>Current WP Role</th><th>Action</th></tr>';
            foreach ($table_not_in_users as $aff) {
                $user = get_userdata($aff->user_id);
                $current_role = $user ? implode(', ', $user->roles) : 'User not found';
                echo '<tr>';
                echo '<td>' . $aff->id . '</td>';
                echo '<td>' . $aff->user_id . '</td>';
                echo '<td>' . $aff->role . '</td>';
                echo '<td>' . $current_role . '</td>';
                echo '<td><button class="fix-btn" onclick="fixRole(' . $aff->user_id . ', \'' . $aff->role . '\')">Fix Role</button></td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<p class="success">✓ All database records have matching WordPress user roles</p>';
        }
    }
    
    echo '</div>';

    // =============================================
    // 6. CHECK DASHBOARD PAGE
    // =============================================
    echo '<div class="section">';
    echo '<h2>6. Dashboard Configuration Check</h2>';
    
    // Check if dashboard page exists
    $dashboard_page = get_page_by_path('affiliate-dashboard');
    if ($dashboard_page) {
        echo '<p class="success">✓ Dashboard page exists (ID: ' . $dashboard_page->ID . ')</p>';
        echo '<p>URL: <a href="' . get_permalink($dashboard_page->ID) . '" target="_blank">' . get_permalink($dashboard_page->ID) . '</a></p>';
        
        // Check for shortcode
        if (has_shortcode($dashboard_page->post_content, 'mlm_dashboard')) {
            echo '<p class="success">✓ [mlm_dashboard] shortcode found in page</p>';
        } else {
            echo '<p class="warning">⚠️ [mlm_dashboard] shortcode NOT found in dashboard page</p>';
            echo '<p>Page content:</p>';
            echo '<pre>' . esc_html($dashboard_page->post_content) . '</pre>';
        }
    } else {
        echo '<p class="error">✗ Dashboard page not found (slug: affiliate-dashboard)</p>';
    }
    
    echo '</div>';

    // =============================================
    // 7. SAMPLE QUERY TEST
    // =============================================
    echo '<div class="section">';
    echo '<h2>7. Sample Dashboard Query Test</h2>';
    echo '<p>Testing the actual query that dashboard would use:</p>';
    
    $test_query = "
        SELECT a.*, u.user_login, u.user_email 
        FROM wp_mlm_affiliates a 
        LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID 
        WHERE a.status = 'approved' 
        ORDER BY a.id DESC 
        LIMIT 10
    ";
    
    echo '<p><strong>Query:</strong></p>';
    echo '<pre>' . $test_query . '</pre>';
    
    $test_results = $wpdb->get_results($test_query);
    
    if ($test_results) {
        echo '<p class="success">✓ Query returned ' . count($test_results) . ' results</p>';
        echo '<table>';
        echo '<tr><th>ID</th><th>User Login</th><th>Email</th><th>Referral ID</th><th>Role</th><th>Status</th></tr>';
        foreach ($test_results as $row) {
            echo '<tr>';
            echo '<td>' . $row->id . '</td>';
            echo '<td>' . $row->user_login . '</td>';
            echo '<td>' . $row->user_email . '</td>';
            echo '<td>' . $row->referral_id . '</td>';
            echo '<td>' . $row->role . '</td>';
            echo '<td>' . $row->status . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="error">✗ Query returned 0 results</p>';
        echo '<p>Possible reasons:</p>';
        echo '<ul>';
        echo '<li>No affiliates with status = "approved"</li>';
        echo '<li>All affiliates have status = "pending"</li>';
        echo '<li>Table is empty</li>';
        echo '</ul>';
        
        // Check what statuses exist
        $status_counts = $wpdb->get_results("SELECT status, COUNT(*) as count FROM wp_mlm_affiliates GROUP BY status");
        if ($status_counts) {
            echo '<p><strong>Affiliate counts by status:</strong></p>';
            echo '<ul>';
            foreach ($status_counts as $status) {
                echo '<li>' . $status->status . ': ' . $status->count . '</li>';
            }
            echo '</ul>';
        }
    }
    
    echo '</div>';

    // =============================================
    // 8. PHP ERROR LOG CHECK
    // =============================================
    echo '<div class="section">';
    echo '<h2>8. Recent PHP Errors</h2>';
    
    $error_log = ini_get('error_log');
    if ($error_log && file_exists($error_log)) {
        echo '<p>Error log location: ' . $error_log . '</p>';
        $recent_errors = tail($error_log, 20);
        if ($recent_errors) {
            echo '<pre>' . esc_html($recent_errors) . '</pre>';
        }
    } else {
        echo '<p>Error log not found or not configured</p>';
    }
    
    echo '</div>';

    // Helper function to read last n lines
    function tail($filename, $lines = 10) {
        $fp = @fopen($filename, "r");
        if (!$fp) return false;
        
        fseek($fp, -1, SEEK_END);
        $pos = ftell($fp);
        $lastLine = "";
        $lines_arr = [];
        
        while ($lines > 0 && $pos > 0) {
            $char = fgetc($fp);
            if ($char == "\n") {
                $lines_arr[] = $lastLine;
                $lastLine = "";
                $lines--;
            } else {
                $lastLine = $char . $lastLine;
            }
            fseek($fp, --$pos);
        }
        
        fclose($fp);
        return implode("\n", array_reverse($lines_arr));
    }
    ?>

    <script>
    function syncUser(userId) {
        if (confirm('Sync user ID ' + userId + ' to affiliates table?')) {
            alert('This function needs to be implemented in your plugin. User ID: ' + userId);
            // You would make an AJAX call here to your plugin's sync function
        }
    }
    
    function fixRole(userId, role) {
        if (confirm('Fix role for user ID ' + userId + ' to ' + role + '?')) {
            alert('This function needs to be implemented in your plugin. User ID: ' + userId + ', Role: ' + role);
            // You would make an AJAX call here to your plugin's fix role function
        }
    }
    </script>

    <div class="section">
        <h2>🔧 Next Steps</h2>
        <ol>
            <li>Review all sections above for errors marked in <span class="error">red</span> or warnings in <span class="warning">orange</span></li>
            <li>If tables are missing, reactivate the plugin to recreate them</li>
            <li>If roles are missing, check your plugin's activation hook</li>
            <li>If there's a sync issue (users have roles but not in table), you need to fix the registration process</li>
            <li>If affiliates exist but have "pending" status, approve them from admin dashboard</li>
            <li>Check your dashboard PHP file for correct database queries</li>
        </ol>
    </div>

</body>
</html>