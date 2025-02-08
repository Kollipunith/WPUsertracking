<?php
/**
 * Plugin Name: User Tracking & Analytics
 * Plugin URI:  https://elixrtech.com/
 * Description: Tracks user behavior, assigns unique IDs, and stores session data.
 * Version:     1.7.1
 * Author:      Elixr Tech
 * Author URI:  https://elixrtech.com/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct access
}

/**
 * Helper: Get Browser Name
 */
function uta_get_browser() {
    $agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
    if ( strpos( $agent, 'Chrome' ) !== false ) {
        return 'Chrome';
    } elseif ( strpos( $agent, 'Firefox' ) !== false ) {
        return 'Firefox';
    } elseif ( strpos( $agent, 'Safari' ) !== false ) {
        return 'Safari';
    } elseif ( strpos( $agent, 'Edge' ) !== false ) {
        return 'Edge';
    } elseif ( strpos( $agent, 'MSIE' ) !== false || strpos( $agent, 'Trident' ) !== false ) {
        return 'Internet Explorer';
    }
    return 'Unknown';
}

/**
 * Helper: Get Device Type
 */
function uta_get_device_type() {
    $agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( $_SERVER['HTTP_USER_AGENT'] ) : '';
    if ( preg_match( '/mobile|android|iphone|ipad/i', $agent ) ) {
        return 'Mobile';
    }
    return 'Desktop';
}

/**
 * 1. Create Database Table for User Tracking
 * Runs on plugin activation to create a table for storing user tracking data.
 */
function uta_activate_plugin() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Create the main user tracking table.
    $table_name = $wpdb->prefix . "user_tracking";
    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id VARCHAR(255) NOT NULL,
        ip_address VARCHAR(100) DEFAULT 'N/A',
        browser VARCHAR(255) DEFAULT 'Unknown',
        device_type VARCHAR(255) DEFAULT 'Unknown',
        paste_detected BOOLEAN DEFAULT 0,
        session_start DATETIME NOT NULL,
        last_active DATETIME NOT NULL,
        pages_viewed LONGTEXT NOT NULL,
        utm_source VARCHAR(255) DEFAULT '',
        referrer VARCHAR(255) NOT NULL DEFAULT 'Direct',
        form_submissions INT DEFAULT 0,
        PRIMARY KEY (id),
        KEY user_id_index (user_id)
    ) $charset_collate;";
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Create a new table for tracking custom events.
    $table_name_events = $wpdb->prefix . "uta_events";
    $sql_events = "CREATE TABLE IF NOT EXISTS {$table_name_events} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id VARCHAR(255) NOT NULL,
        event VARCHAR(100) NOT NULL,
        page VARCHAR(255) NOT NULL,
        timestamp DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY user_id_index (user_id)
    ) $charset_collate;";
    dbDelta( $sql_events );
}
register_activation_hook( __FILE__, 'uta_activate_plugin' );

/**
 * 2. Assign Unique User ID & Track Session Start
 * This function assigns a unique visitor ID, tracks their details, and creates a database record if needed.
 */
add_action( 'init', 'uta_set_user_id', 1 );
function uta_set_user_id() {
    global $wpdb;
    $table_name = $wpdb->prefix . "user_tracking";

    // Use today's date in Y-m-d format for comparing session_start (which is stored as current_time('mysql'))
    $today = current_time( 'Y-m-d' );

    // If no cookie is set, generate one
    if ( ! isset( $_COOKIE['user_tracking_id'] ) || $_COOKIE['user_tracking_id'] == "0" ) {
        // Count sessions starting today. Note: session_start begins with YYYY-MM-DD.
        $user_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE session_start LIKE %s", $today . '%' ) );
        $increment   = str_pad( $user_count + 1, 4, '0', STR_PAD_LEFT );
        $user_id     = "Visitor_" . $increment . "_" . str_replace( '-', '', $today );
    } else {
        $user_id = sanitize_text_field( wp_unslash( $_COOKIE['user_tracking_id'] ) );
    }

    // Check if the user already exists in our table
    $existing_user = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE user_id = %s", $user_id ) );

    // If not found, create a new record with a fresh unique ID
    if ( ! $existing_user ) {
        $user_count   = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
        $visitor_num  = str_pad( $user_count + 1, 4, '0', STR_PAD_LEFT );
        $user_id      = "Visitor_" . $visitor_num . "_" . str_replace( '-', '', $today );
    }

    // Retrieve user details
    $ip_address = 'N/A';
    if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
        $ip_address = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
    } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $ip_address = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
    } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
        $ip_address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
    }

    $browser     = uta_get_browser();
    $device_type = uta_get_device_type();
    $referrer    = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : 'Direct';

    // Set the cookie (ensure this happens before any output)
    setcookie( 'user_tracking_id', $user_id, time() + ( 30 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN );

    // Insert new user record or update last_active if record already exists
    if ( $existing_user ) {
        $wpdb->update(
            $table_name,
            [
                'ip_address'  => $ip_address,
                'browser'     => $browser,
                'device_type' => $device_type,
                'last_active' => current_time( 'mysql' )
            ],
            [ 'user_id' => $user_id ],
            [
                '%s',
                '%s',
                '%s',
                '%s'
            ],
            [ '%s' ]
        );
    } else {
        $wpdb->insert(
            $table_name,
            [
                'user_id'        => $user_id,
                'ip_address'     => $ip_address,
                'browser'        => $browser,
                'device_type'    => $device_type,
                'session_start'  => current_time( 'mysql' ),
                'last_active'    => current_time( 'mysql' ),
                'pages_viewed'   => json_encode( [] ),
                'utm_source'     => '',
                'referrer'       => $referrer,
                'form_submissions' => 0
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d'
            ]
        );
    }
    setcookie( 'user_tracking_id', $user_id, time() + ( 30 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN );
}



/**
 * 3. Track Page Visits
 * This function updates the user's session by appending the visited page.
 * (Note: We have removed duplicate activity functions for clarity.(ignoring CSS and JS requests) )
 */
function uta_track_page_visit() {
    global $wpdb;
    $table_name = $wpdb->prefix . "user_tracking";
    
    if ( isset( $_COOKIE['user_tracking_id'] ) ) {
        $user_id      = sanitize_text_field( wp_unslash( $_COOKIE['user_tracking_id'] ) );
        $current_time = current_time( 'mysql' );
        $page_url     = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
        
        // Ignore tracking if:
        //   - The URL contains "wp-content" OR
        //   - The URL ends with .css, .js, .gif, .png, .jpg, or .jpeg (optionally with a query string)
        if ( strpos( $page_url, 'wp-content' ) !== false || preg_match( '/\.(css|js|gif|png|jpe?g)(\?.*)?$/i', $page_url ) ) {
            return;
        }
        
        // Fetch existing user data.
        $user_data = $wpdb->get_row(
            $wpdb->prepare( "SELECT pages_viewed FROM $table_name WHERE user_id = %s", $user_id )
        );
        
        // Decode and update visited pages.
        $pages = ! empty( $user_data->pages_viewed ) ? json_decode( $user_data->pages_viewed, true ) : [];
        $pages[] = [ 'page' => $page_url, 'timestamp' => $current_time, 'time_spent' => 0 ];
        
        // Update user activity (note: form_submissions is not updated here).
        $wpdb->update(
            $table_name,
            [
                'last_active'  => $current_time,
                'pages_viewed' => wp_json_encode( $pages )
            ],
            [ 'user_id' => $user_id ]
        );
    }
}
add_action( 'wp_head', 'uta_track_page_visit' );

/**
 * 4. Add User Tracking to Admin Menu
 */
function uta_admin_submenu() {
    add_menu_page(
        'User Tracking & Analytics',  // Page title
        'User Tracking',              // Menu title
        'manage_options',             // Capability
        'uta_tracking',               // Menu slug
        'uta_display_tracking_dashboard', // Callback function
        'dashicons-visibility',       // Icon
        25
    );

    add_submenu_page(
        'uta_tracking',
        'User Detail',
        'User Detail',
        'manage_options',
        'uta_user_detail',
        'uta_display_user_detail'
    );

    // New Data Management page.
    add_submenu_page(
        'uta_tracking',
        'Data Management',
        'Data Management',
        'manage_options',
        'uta_data_management',
        'uta_data_management_page'
    );
}
add_action('admin_menu', 'uta_admin_submenu');

/**
 * 5. Display User Tracking Dashboard
 */
function uta_display_tracking_dashboard() {
    global $wpdb;
    $table_name   = $wpdb->prefix . "user_tracking";
    $per_page     = 10;
    $paged        = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
    $offset       = ( $paged - 1 ) * $per_page;
    $search_query = isset( $_GET['search_user'] ) ? sanitize_text_field( wp_unslash( $_GET['search_user'] ) ) : '';

    // Allowed sortable columns.
    $allowed_orderby = array( 'user_id', 'ip_address', 'browser', 'device_type', 'referrer', 'last_active', 'form_submissions' );
    $orderby = ( isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], $allowed_orderby, true ) )
        ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) )
        : 'last_active';
    $order = ( isset( $_GET['order'] ) && strtolower( $_GET['order'] ) === 'asc' )
        ? 'ASC'
        : 'DESC';

    // Build query (using DISTINCT on user_id).
    if ( ! empty( $search_query ) ) {
        $total_users = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id) FROM $table_name WHERE user_id LIKE %s",
                '%' . $wpdb->esc_like( $search_query ) . '%'
            )
        );
        $users = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT user_id, ip_address, browser, device_type, referrer, last_active, pages_viewed, form_submissions
                 FROM $table_name
                 WHERE user_id LIKE %s
                 ORDER BY {$orderby} {$order}
                 LIMIT %d OFFSET %d",
                '%' . $wpdb->esc_like( $search_query ) . '%',
                $per_page,
                $offset
            )
        );
    } else {
        $total_users = $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM $table_name" );
        $users = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT user_id, ip_address, browser, device_type, referrer, last_active, pages_viewed, form_submissions
                 FROM $table_name
                 ORDER BY {$orderby} {$order}
                 LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );
    }
    $total_pages = ceil( $total_users / $per_page );

    echo "<div class='wrap'><h1>User Tracking Dashboard</h1>";
    echo "<form method='GET' action=''>
            <input type='hidden' name='page' value='uta_tracking'>
            <input type='text' name='search_user' placeholder='Search User ID' value='" . esc_attr( $search_query ) . "'>
            <button type='submit' class='button'>Search</button>
          </form><br>";

    echo "<table class='wp-list-table widefat fixed striped'>";
    echo "<thead><tr>
            <th>" . uta_sortable_link( 'user_id', 'User ID', $orderby, $order, 'uta_tracking', array( 'search_user' => $search_query ) ) . "</th>
            <th>" . uta_sortable_link( 'ip_address', 'IP Address', $orderby, $order, 'uta_tracking', array( 'search_user' => $search_query ) ) . "</th>
            <th>" . uta_sortable_link( 'browser', 'Browser', $orderby, $order, 'uta_tracking', array( 'search_user' => $search_query ) ) . "</th>
            <th>" . uta_sortable_link( 'device_type', 'Device', $orderby, $order, 'uta_tracking', array( 'search_user' => $search_query ) ) . "</th>
            <th>" . uta_sortable_link( 'referrer', 'Referrer', $orderby, $order, 'uta_tracking', array( 'search_user' => $search_query ) ) . "</th>
            <th>" . uta_sortable_link( 'last_active', 'Last Active', $orderby, $order, 'uta_tracking', array( 'search_user' => $search_query ) ) . "</th>
            <th>Pages Visited</th>
            <th>" . uta_sortable_link( 'form_submissions', 'Form Submissions', $orderby, $order, 'uta_tracking', array( 'search_user' => $search_query ) ) . "</th>
          </tr></thead><tbody>";

    if ( $users ) {
        foreach ( $users as $user ) {
            $user_id         = isset( $user->user_id ) ? esc_html( $user->user_id ) : 'N/A';
            $ip_address      = isset( $user->ip_address ) ? esc_html( $user->ip_address ) : 'N/A';
            $browser         = isset( $user->browser ) ? esc_html( $user->browser ) : 'Unknown';
            $device_type     = isset( $user->device_type ) ? esc_html( $user->device_type ) : 'Unknown';
            $referrer        = isset( $user->referrer ) ? esc_html( $user->referrer ) : 'Direct';
            $last_active     = isset( $user->last_active ) ? esc_html( $user->last_active ) : 'N/A';
            $form_submissions = isset( $user->form_submissions ) ? intval( $user->form_submissions ) : 0;
            $pages           = isset( $user->pages_viewed ) ? json_decode( $user->pages_viewed, true ) : [];
            $pages_count     = is_array( $pages ) ? count( $pages ) : 0;

            echo "<tr>
                    <td><a href='" . admin_url( "admin.php?page=uta_user_detail&user_id=" . urlencode( $user_id ) ) . "'>{$user_id}</a></td>
                    <td>{$ip_address}</td>
                    <td>{$browser}</td>
                    <td>{$device_type}</td>
                    <td>{$referrer}</td>
                    <td>{$last_active}</td>
                    <td>" . intval( $pages_count ) . "</td>
                    <td>{$form_submissions}</td>
                  </tr>";
        }
    } else {
        echo "<tr><td colspan='8'>" . esc_html__( 'No users found.', 'uta' ) . "</td></tr>";
    }
    echo "</tbody></table>";

    // WooCommerce‑style Pagination
    $pagination_base = add_query_arg( array(
        'paged'       => '%#%',
        'orderby'     => $orderby,
        'order'       => strtolower( $order ),
        'search_user' => $search_query
    ), admin_url( 'admin.php?page=uta_tracking' ) );

    echo '<nav class="woocommerce-pagination">';
    echo paginate_links( array(
        'base'      => $pagination_base,
        'format'    => '',
        'current'   => $paged,
        'total'     => $total_pages,
        'prev_text' => __( '&larr; Previous', 'uta' ),
        'next_text' => __( 'Next &rarr;', 'uta' ),
    ) );
    echo '</nav>';

    echo "</div>";
}




/**
 * 6. Display User Detail Page
 */
function uta_display_user_detail() {
    global $wpdb;
    $table_name = $wpdb->prefix . "user_tracking";
    $user_id    = isset( $_GET['user_id'] ) ? sanitize_text_field( wp_unslash( $_GET['user_id'] ) ) : '';

    if ( empty( $user_id ) ) {
        echo "<h2>User Not Found</h2>";
        return;
    }

    // Allowed sortable columns for session summary.
    $allowed_orderby = array( 'ip_address', 'browser', 'device_type', 'referrer', 'last_active', 'form_submissions' );
    $session_orderby = ( isset( $_GET['session_orderby'] ) && in_array( $_GET['session_orderby'], $allowed_orderby, true ) )
        ? sanitize_text_field( wp_unslash( $_GET['session_orderby'] ) )
        : 'last_active';
    $session_order = ( isset( $_GET['session_order'] ) && strtolower( $_GET['session_order'] ) === 'asc' )
        ? 'ASC'
        : 'DESC';

    // Query sessions for the user, sorted by the chosen column.
    $user_sessions = $wpdb->get_results(
        "SELECT * FROM $table_name WHERE user_id = '" . esc_sql( $user_id ) . "' ORDER BY {$session_orderby} {$session_order}"
    );

    if ( ! $user_sessions ) {
        echo "<h2>User Not Found</h2>";
        return;
    }

    echo "<div class='wrap'><h1>User Detail: " . esc_html( $user_id ) . "</h1>";

    // -------------------------------
    // SESSION SUMMARY TABLE
    // -------------------------------
    echo "<h2>Session Summary</h2>";
    echo "<table class='wp-list-table widefat fixed striped'>
            <thead>
                <tr>
                    <th>IP Address</th>
                    <th>Browser</th>
                    <th>Device</th>
                    <th>Referrer</th>
                    <th>Last Active</th>
                    <th>Pages Visited</th>
                    <th>Form Submissions</th>
                </tr>
            </thead>
            <tbody>";
    foreach ( $user_sessions as $session ) {
        $pages = ! empty( $session->pages_viewed ) ? json_decode( $session->pages_viewed, true ) : [];
        $pages_count = is_array( $pages ) ? count( $pages ) : 0;
        echo "<tr>
                <td>" . esc_html( $session->ip_address ) . "</td>
                <td>" . esc_html( $session->browser ) . "</td>
                <td>" . esc_html( $session->device_type ) . "</td>
                <td>" . esc_html( $session->referrer ) . "</td>
                <td>" . esc_html( $session->last_active ) . "</td>
                <td>" . intval( $pages_count ) . "</td>
                <td>" . intval( $session->form_submissions ) . "</td>
              </tr>";
    }
    echo "</tbody></table>";

    // -------------------------------
    // DETAILED ACTIVITY TABLE (Pages Visited)
    // -------------------------------
    echo "<h2>Pages Visited Details</h2>";
    echo "<table class='wp-list-table widefat fixed striped'>
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Page</th>
                    <th>Timestamp</th>
                    <th>Time Spent (Seconds)</th>
                </tr>
            </thead>
            <tbody>";
    $global_counter = 1;
    foreach ( $user_sessions as $session ) {
        $pages = ! empty( $session->pages_viewed ) ? json_decode( $session->pages_viewed, true ) : [];
        if ( is_array( $pages ) && ! empty( $pages ) ) {
            $num_pages = count( $pages );
            for ( $i = 0; $i < $num_pages; $i++ ) {
                $page = $pages[ $i ];
                // Skip static resources such as wp-content files, CSS, JS, GIF, PNG, JPG, or JPEG files.
                if ( strpos( $page['page'], 'wp-content' ) !== false || preg_match( '/\.(css|js|gif|png|jpe?g)(\?.*)?$/i', $page['page'] ) ) {
                    continue;
                }
                // Calculate time spent: if not the last page, subtract the timestamp of the next page.
                $time_spent = ( $i < $num_pages - 1 ) ? ( strtotime( $pages[ $i + 1 ]['timestamp'] ) - strtotime( $page['timestamp'] ) ) : 0;
                echo "<tr>
                        <td>" . $global_counter . "</td>
                        <td><a href='" . esc_url( $page['page'] ) . "' target='_blank'>" . esc_html( $page['page'] ) . "</a></td>
                        <td>" . esc_html( $page['timestamp'] ) . "</td>
                        <td>" . intval( $time_spent ) . " sec</td>
                      </tr>";
                $global_counter++;
            }
        }
    }
    echo "</tbody></table>";

    echo "<br><a href='" . admin_url( 'admin.php?page=uta_tracking' ) . "' class='button'>Back to User List</a>";
    echo "</div>";
}

/**
 * 7. AJAX Handler for Live Tracking
 */
function uta_live_tracking_ajax() {
    global $wpdb;
    $table_name   = $wpdb->prefix . "user_tracking";
    $current_time = current_time( 'mysql' );
    $ten_minutes_ago = date( 'Y-m-d H:i:s', strtotime( '-10 minutes', strtotime( $current_time ) ) );

    $live_users = $wpdb->get_results( "SELECT * FROM $table_name WHERE last_active >= '$ten_minutes_ago' ORDER BY last_active DESC" );
    wp_send_json( $live_users );
}
add_action( 'wp_ajax_uta_live_tracking', 'uta_live_tracking_ajax' );
add_action( 'wp_ajax_nopriv_uta_live_tracking', 'uta_live_tracking_ajax' );

/**
 * 8. Load JavaScript for AJAX Live Tracking in Admin
 */
function uta_enqueue_activity_script( $hook ) {
    // Load only on our plugin’s admin pages
    if ( strpos( $hook, 'uta_tracking' ) === false && strpos( $hook, 'uta_user_detail' ) === false ) {
        return;
    }
    wp_enqueue_script(
        'uta-activity-live',
        plugin_dir_url( __FILE__ ) . 'js/activity-live.js', // renamed file
        [ 'jquery' ],
        null,
        true
    );
    wp_localize_script( 'uta-activity-live', 'uta_ajax', [
        'ajax_url' => admin_url( 'admin-ajax.php' )
    ] );
}
add_action( 'admin_enqueue_scripts', 'uta_enqueue_activity_script' );

/**Enqueue a Front‑End Tracking Script */
function uta_enqueue_tracking_scripts() {
    wp_enqueue_script(
        'uta-tracking', 
        plugin_dir_url( __FILE__ ) . 'js/uta-tracking.js', 
        array( 'jquery' ), 
        null, 
        true
    );
    wp_localize_script( 'uta-tracking', 'uta_tracking_vars', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'uta_tracking_nonce' )
    ) );
}
add_action( 'wp_enqueue_scripts', 'uta_enqueue_tracking_scripts' );


/**
 * 9. AJAX Handler for Tracking Events
 */

function uta_track_event_callback() {
    // Verify the nonce for security.
    check_ajax_referer( 'uta_tracking_nonce', 'nonce' );

    $event_data = isset( $_POST['event_data'] ) ? $_POST['event_data'] : '';
    if ( empty( $event_data ) ) {
        wp_send_json_error( 'No event data provided.' );
    }

    global $wpdb;
    $table_name_events = $wpdb->prefix . "uta_events";

    // Retrieve the current user_tracking cookie (if available) to associate with the event.
    $user_id = isset( $_COOKIE['user_tracking_id'] ) ? sanitize_text_field( $_COOKIE['user_tracking_id'] ) : 'unknown';
    $event   = isset( $event_data['event'] ) ? sanitize_text_field( $event_data['event'] ) : 'click';
    $page    = isset( $event_data['page'] ) ? esc_url_raw( $event_data['page'] ) : '';
    $timestamp = isset( $event_data['timestamp'] ) ? sanitize_text_field( $event_data['timestamp'] ) : current_time( 'mysql' );

    // Ensure timestamp is in the proper MySQL format.
    $timestamp = date( 'Y-m-d H:i:s', strtotime( $timestamp ) );

    $inserted = $wpdb->insert(
        $table_name_events,
        array(
            'user_id'   => $user_id,
            'event'     => $event,
            'page'      => $page,
            'timestamp' => $timestamp,
        ),
        array( '%s', '%s', '%s', '%s' )
    );

    if ( $inserted ) {
        wp_send_json_success( 'Event recorded.' );
    } else {
        wp_send_json_error( 'Failed to record event.' );
    }
}
add_action( 'wp_ajax_uta_track_event', 'uta_track_event_callback' );
add_action( 'wp_ajax_nopriv_uta_track_event', 'uta_track_event_callback' );


/**
 * Helper: Build a sortable header link.
 *
 * @param string $column         The column key (must be in the allowed list).
 * @param string $title          The header title.
 * @param string $current_orderby The current orderby parameter.
 * @param string $current_order   The current order (ASC or DESC).
 * @param string $page           The admin page slug.
 * @param array  $extra_args     Any extra query args to pass (for example, search terms or user_id).
 * @return string                An HTML link for sorting.
 */
function uta_sortable_link( $column, $title, $current_orderby, $current_order, $page, $extra_args = array() ) {
    // Toggle order: if the current column is already sorted ASC, switch to DESC; otherwise, default to ASC.
    $new_order = ( $current_orderby === $column && strtoupper( $current_order ) === 'ASC' ) ? 'desc' : 'asc';

    // Build query args.
    $args = array_merge( $extra_args, array(
        'orderby' => $column,
        'order'   => $new_order,
    ) );

    $link = add_query_arg( $args, admin_url( "admin.php?page={$page}" ) );
    return '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>';
}


/**
 * 9. Display Live Users in Dashboard
 */
function uta_display_live_users() {
    echo "<div class='wrap'><h2>Live Users (Last 10 Minutes)</h2>";
    echo "<table class='wp-list-table widefat fixed striped' id='live-users-table'>";
    echo "<thead><tr>
            <th>User ID</th>
            <th>IP Address</th>
            <th>Last Active</th>
            <th>Current Page</th>
        </tr></thead><tbody></tbody></table>";
    ?>
    <script>
        jQuery(document).ready(function($) {
            function fetchLiveUsers() {
                $.get(uta_ajax.ajax_url, { action: 'uta_live_tracking' }, function(data) {
                    var tableBody = $('#live-users-table tbody');
                    tableBody.empty();
                    $.each(data, function(index, user) {
                        var pages = [];
                        try {
                            pages = JSON.parse(user.pages_viewed);
                        } catch (e) {
                            pages = [];
                        }
                        var currentPage = (pages.length > 0 && pages[pages.length - 1].page) ? pages[pages.length - 1].page : 'N/A';
                        tableBody.append('<tr><td>' + user.user_id + '</td><td>' + user.ip_address + '</td><td>' + user.last_active + '</td><td>' + currentPage + '</td></tr>');
                    });
                });
            }
            setInterval(fetchLiveUsers, 10000);
            fetchLiveUsers();
        });
    </script>
    <?php
    echo "</div>";
}

function uta_increment_form_submissions( $user_id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . "user_tracking";

    // Get the current count
    $current_count = $wpdb->get_var( $wpdb->prepare("SELECT form_submissions FROM $table_name WHERE user_id = %s", $user_id) );
    $current_count = intval( $current_count );

    // Increment the count
    $wpdb->update(
        $table_name,
        [ 'form_submissions' => $current_count + 1 ],
        [ 'user_id' => $user_id ],
        [ '%d' ],
        [ '%s' ]
    );
}
// Hook this function to your form submission event.
// Example for an AJAX form submission:
add_action('wp_ajax_uta_form_submit', 'uta_handle_form_submission');
add_action('wp_ajax_nopriv_uta_form_submit', 'uta_handle_form_submission');

function uta_handle_form_submission() {
    // Always verify nonce and sanitize inputs accordingly.
    $user_id = isset( $_COOKIE['user_tracking_id'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['user_tracking_id'] ) ) : 'unknown';
    uta_increment_form_submissions( $user_id );
    wp_send_json_success( 'Form submission recorded.' );
}


/**
 * 10. Data Management Functions
 */

 function uta_data_management_page() {
    ?>
    <div class="wrap">
        <h1>Data Management</h1>
        
        <?php if ( isset( $_GET['message'] ) ) : ?>
            <div class="updated notice">
                <p><?php echo esc_html( $_GET['message'] ); ?></p>
            </div>
        <?php elseif ( isset( $_GET['error'] ) ) : ?>
            <div class="error notice">
                <p><?php echo esc_html( $_GET['error'] ); ?></p>
            </div>
        <?php endif; ?>

        <!-- Section 1: Delete Data Older Than X Days -->
        <h2>Delete Data Older Than Specified Days</h2>
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <?php wp_nonce_field( 'uta_delete_old_data_nonce', 'uta_delete_old_data_nonce_field' ); ?>
            <input type="hidden" name="action" value="uta_delete_old_data">
            <p>
                <label for="uta_delete_days">Delete data older than (days): </label>
                <input type="number" id="uta_delete_days" name="uta_delete_days" value="30" min="1">
            </p>
            <p>
                <input type="submit" class="button button-secondary" value="Delete Old Data">
            </p>
        </form>

        <hr>

        <!-- Section 2: Delete Data for a Specific User -->
        <h2>Delete Data For a Specific User</h2>
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <?php wp_nonce_field( 'uta_delete_user_data_nonce', 'uta_delete_user_data_nonce_field' ); ?>
            <input type="hidden" name="action" value="uta_delete_user_data">
            <p>
                <label for="uta_user_id">User ID: </label>
                <input type="text" id="uta_user_id" name="uta_user_id" value="">
            </p>
            <p>
                <input type="submit" class="button button-secondary" value="Delete User Data">
            </p>
        </form>

        <hr>

        <!-- Section 3: Delete All Tracking Data -->
        <h2>Delete All Tracking Data</h2>
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" onsubmit="return confirm('Are you sure you want to delete ALL tracking data? This action cannot be undone.');">
            <?php wp_nonce_field( 'uta_delete_all_data_nonce', 'uta_delete_all_data_nonce_field' ); ?>
            <input type="hidden" name="action" value="uta_delete_all_data">
            <p>
                <input type="submit" class="button button-secondary" value="Delete All Tracking Data">
            </p>
        </form>

        <hr>

        <!-- Section 4: Delete Data by Date Range with Preview -->
        <h2>Delete Data by Date Range</h2>
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <?php wp_nonce_field( 'uta_delete_date_range_nonce', 'uta_delete_date_range_nonce_field' ); ?>
            <input type="hidden" name="action" value="uta_delete_date_range_data">
            <p>
                <label for="uta_start_date">Start Date: </label>
                <input type="date" id="uta_start_date" name="uta_start_date" value="">
            </p>
            <p>
                <label for="uta_end_date">End Date: </label>
                <input type="date" id="uta_end_date" name="uta_end_date" value="">
            </p>
            <p>
                <input type="submit" class="button button-secondary" name="uta_preview" value="Preview Records">
                <input type="submit" class="button button-secondary" name="uta_delete" value="Delete Records" onclick="return confirm('Are you sure you want to delete all data for the selected date range?');">
            </p>
        </form>
    </div>
    <?php
}


function uta_process_delete_date_range_data() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Not allowed' );
    }
    
    // Verify nonce
    check_admin_referer( 'uta_delete_date_range_nonce', 'uta_delete_date_range_nonce_field' );

    // Retrieve and sanitize start and end dates
    $start_date = isset( $_POST['uta_start_date'] ) ? sanitize_text_field( $_POST['uta_start_date'] ) : '';
    $end_date   = isset( $_POST['uta_end_date'] ) ? sanitize_text_field( $_POST['uta_end_date'] ) : '';

    if ( empty( $start_date ) || empty( $end_date ) ) {
        wp_redirect( admin_url( 'admin.php?page=uta_data_management&error=' . urlencode('Both start and end dates must be provided.') ) );
        exit;
    }

    // Append times to the dates (start at 00:00:00 and end at 23:59:59)
    $start_datetime = $start_date . ' 00:00:00';
    $end_datetime   = $end_date . ' 23:59:59';

    global $wpdb;
    $table_tracking = $wpdb->prefix . "user_tracking";
    $table_events   = $wpdb->prefix . "uta_events";

    // Debug log the dates
    error_log("Deleting records between: $start_datetime and $end_datetime");

    // Count matching records
    $tracking_count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_tracking WHERE last_active BETWEEN %s AND %s",
        $start_datetime,
        $end_datetime
    ) );
    
    $events_count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_events WHERE timestamp BETWEEN %s AND %s",
        $start_datetime,
        $end_datetime
    ) );

    // If preview button was clicked
    if ( isset( $_POST['uta_preview'] ) ) {
        $message = sprintf(
            'Preview: %d records found in User Tracking and %d records found in Events for the selected date range.',
            intval( $tracking_count ),
            intval( $events_count )
        );
        wp_redirect( admin_url( 'admin.php?page=uta_data_management&message=' . urlencode( $message ) ) );
        exit;
    }

    // If delete button was clicked
    if ( isset( $_POST['uta_delete'] ) ) {
        // Debug log deletion intent
        error_log("Deleting $tracking_count tracking records and $events_count event records.");

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table_tracking WHERE last_active BETWEEN %s AND %s",
            $start_datetime,
            $end_datetime
        ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table_events WHERE timestamp BETWEEN %s AND %s",
            $start_datetime,
            $end_datetime
        ) );
        $message = sprintf(
            'Deleted: %d records removed from User Tracking and %d records removed from Events for the selected date range.',
            intval( $tracking_count ),
            intval( $events_count )
        );
        wp_redirect( admin_url( 'admin.php?page=uta_data_management&message=' . urlencode( $message ) ) );
        exit;
    }
}
add_action( 'admin_post_uta_delete_date_range_data', 'uta_process_delete_date_range_data' );

