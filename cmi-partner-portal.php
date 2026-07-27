<?php
/**
 * Plugin Name: CMI Partner Portal
 * Plugin URI:  https://cmihealthcare.in
 * Description: Partner login, report upload/download, prescriptions and guest report access for CMI Healthcare.
 * Version:     1.0.21
 * Author:      CMI Healthcare
 * Text Domain: cmi-partner-portal
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Conflict check: Check if classes from companion or older versions are already loaded
if ( class_exists( 'CMI_Roles' ) || class_exists( 'CMI_HT_Checkout' ) ) {
    add_action( 'admin_notices', 'cmi_pp_conflict_notice' );
    return;
}

function cmi_pp_conflict_notice() {
    echo '<div class="error notice is-dismissible">';
    echo '<p><strong>CMI Partner Portal:</strong> Another conflicting plugin (like CMI Home Testing or an older version of CMI Partner Portal) is active. Please deactivate and delete it before activating this plugin to prevent conflicts.</p>';
    echo '</div>';
}

define( 'CMI_PP_VERSION', '1.0.21' );
define( 'CMI_PP_PATH',    plugin_dir_path( __FILE__ ) );
define( 'CMI_PP_URL',     plugin_dir_url( __FILE__ ) );
define( 'CMI_PP_UPLOAD_DIR', WP_CONTENT_DIR . '/cmi-secure-reports' );
define( 'CMI_PP_UPLOAD_URL', WP_CONTENT_URL . '/cmi-secure-reports' );

require_once CMI_PP_PATH . 'includes/class-roles.php';
require_once CMI_PP_PATH . 'includes/class-cpt.php';
require_once CMI_PP_PATH . 'includes/class-upload.php';
require_once CMI_PP_PATH . 'includes/class-download.php';
require_once CMI_PP_PATH . 'includes/class-sms.php';
require_once CMI_PP_PATH . 'includes/class-sms-trigger-registry.php';
require_once CMI_PP_PATH . 'includes/class-sms-context-resolver.php';
require_once CMI_PP_PATH . 'includes/class-sms-listener.php';
require_once CMI_PP_PATH . 'includes/class-portal-dashboards.php';
require_once CMI_PP_PATH . 'includes/class-guest-access.php';
require_once CMI_PP_PATH . 'includes/class-admin.php';
require_once CMI_PP_PATH . 'includes/class-otp.php';

// Home Testing Modules
require_once CMI_PP_PATH . 'includes/class-db.php';
require_once CMI_PP_PATH . 'includes/class-checkout.php';
require_once CMI_PP_PATH . 'includes/class-partner-workflow.php';
require_once CMI_PP_PATH . 'includes/class-reschedule.php';
require_once CMI_PP_PATH . 'includes/class-notifications.php';
require_once CMI_PP_PATH . 'includes/class-shortcodes.php';
require_once CMI_PP_PATH . 'includes/class-consultations.php';

// Override WooCommerce My Account & Checkout Login/Register Templates with CMI Healthcare Card UI
add_filter( 'woocommerce_locate_template', 'cmi_override_wc_login_template', 10, 3 );
function cmi_override_wc_login_template( $template, $template_name, $template_path ) {
    if ( in_array( $template_name, [ 'myaccount/form-login.php', 'checkout/form-login.php', 'global/form-login.php' ], true ) ) {
        $custom_template = CMI_PP_PATH . 'templates/wc-form-login.php';
        if ( file_exists( $custom_template ) ) {
            return $custom_template;
        }
    }
    return $template;
}

// Redirect unauthenticated checkout users to custom /my-account/ login page with return URL
add_action( 'template_redirect', 'cmi_redirect_unauthenticated_checkout_user' );
function cmi_redirect_unauthenticated_checkout_user() {
    if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_user_logged_in() && ! is_wc_endpoint_url( 'order-received' ) ) {
        if ( get_option( 'woocommerce_enable_guest_checkout' ) === 'no' ) {
            $myaccount_id    = get_option( 'woocommerce_myaccount_page_id' );
            $myaccount_url   = $myaccount_id ? get_permalink( $myaccount_id ) : home_url( '/my-account/' );
            $checkout_url    = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
            $redirect_target = add_query_arg( 'redirect_to', urlencode( $checkout_url ), $myaccount_url );
            wp_safe_redirect( $redirect_target );
            exit;
        }
    }
}

// Filter default WP/WC login URLs to point to custom /my-account/ page
add_filter( 'login_url', 'cmi_redirect_wp_login_url', 10, 3 );
function cmi_redirect_wp_login_url( $login_url, $redirect, $force_reauth ) {
    $myaccount_id  = get_option( 'woocommerce_myaccount_page_id' );
    $myaccount_url = $myaccount_id ? get_permalink( $myaccount_id ) : home_url( '/my-account/' );
    if ( ! empty( $redirect ) ) {
        return add_query_arg( 'redirect_to', urlencode( $redirect ), $myaccount_url );
    }
    return $myaccount_url;
}


register_activation_hook( __FILE__, 'cmi_pp_activate' );
register_deactivation_hook( __FILE__, 'cmi_pp_deactivate' );

function cmi_pp_activate() {
    CMI_Roles::create_roles();
    CMI_CPT::register();
    cmi_pp_register_endpoints();
    flush_rewrite_rules();

    // Create secure upload directory outside public web root
    if ( ! file_exists( CMI_PP_UPLOAD_DIR ) ) {
        wp_mkdir_p( CMI_PP_UPLOAD_DIR );
    }
    
    if ( is_writable( CMI_PP_UPLOAD_DIR ) ) {
        // Block direct access
        file_put_contents( CMI_PP_UPLOAD_DIR . '/.htaccess', "Options -Indexes\nDeny from all\n" );
        file_put_contents( CMI_PP_UPLOAD_DIR . '/index.php', '<?php // silence' );
    }

    // Run database migrations for home testing
    require_once CMI_PP_PATH . 'includes/class-db.php';
    CMI_HT_DB::create_tables();

    // Create OTP table
    global $wpdb;
    $table = $wpdb->prefix . 'cmi_otp';
    $charset_collate = $wpdb->get_charset_collate();
    // mobile column stores mobile number or email address (VARCHAR 255)
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        mobile VARCHAR(255) NOT NULL,
        otp VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY mobile (mobile(191))
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    
    // Extend existing columns if table exists (for upgrades / column size fixes)
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table ) {
        $wpdb->query( "ALTER TABLE $table MODIFY COLUMN mobile VARCHAR(255) NOT NULL" );
        $wpdb->query( "ALTER TABLE $table MODIFY COLUMN otp VARCHAR(255) NOT NULL" );
    }

    // Create download log table
    $log_table = $wpdb->prefix . 'cmi_download_log';
    $sql2 = "CREATE TABLE IF NOT EXISTS $log_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        report_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED DEFAULT 0,
        mobile VARCHAR(15) DEFAULT '',
        ip VARCHAR(45) NOT NULL,
        downloaded_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY report_id (report_id)
    ) $charset_collate;";
    dbDelta( $sql2 );
}

function cmi_pp_deactivate() {
    flush_rewrite_rules();
}

add_action( 'init', [ 'CMI_CPT', 'register' ] );
add_action( 'init', [ 'CMI_Guest_Access', 'init' ] );
add_action( 'init', 'cmi_pp_ensure_roles_and_caps' );
add_action( 'wp_enqueue_scripts', 'cmi_pp_enqueue_scripts' );
add_action( 'admin_enqueue_scripts', 'cmi_pp_admin_scripts' );

function cmi_pp_ensure_roles_and_caps() {
    $mp_role  = get_role( 'medical_partner' );
    $doc_role = get_role( 'cmi_doctor' );
    
    $heal_needed = false;
    
    if ( $mp_role && ! $mp_role->has_cap( 'cmi_view_assignments' ) ) {
        $heal_needed = true;
    }
    if ( $doc_role && ! $doc_role->has_cap( 'cmi_view_assignments' ) ) {
        $heal_needed = true;
    }

    if ( $heal_needed ) {
        require_once CMI_PP_PATH . 'includes/class-roles.php';
        CMI_Roles::create_roles();
    }
}

// Initialize Home Testing components after plugins are loaded
add_action( 'plugins_loaded', 'cmi_pp_init_home_testing' );
function cmi_pp_init_home_testing() {
    if ( class_exists( 'WooCommerce' ) ) {
        new CMI_HT_Checkout();
        new CMI_HT_Partner_Workflow();
        new CMI_HT_Reschedule();
        new CMI_HT_Notifications();
        new CMI_HT_Shortcodes();
        // Store instance globally so CMI_Consultations::static_sync_doctor_user_to_cpt()
        // can delegate to it without re-instantiating (which would double-register hooks).
        global $cmi_consultations_instance;
        $cmi_consultations_instance = new CMI_Consultations();
    }
}

function cmi_pp_enqueue_scripts() {
    $plugin_url = CMI_PP_URL;
    $ajax_url   = admin_url( 'admin-ajax.php' );

    // Reverse proxy HTTPS check (e.g. Hostinger, Cloudflare)
    if ( is_ssl() || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
        $plugin_url = str_replace( 'http://', 'https://', $plugin_url );
        $ajax_url   = str_replace( 'http://', 'https://', $ajax_url );
    }

    wp_enqueue_style( 'cmi-pp-style', $plugin_url . 'assets/style.css', [], CMI_PP_VERSION );
    wp_enqueue_script( 'cmi-pp-script', $plugin_url . 'assets/script.js', ['jquery'], CMI_PP_VERSION, true );
    wp_localize_script( 'cmi-pp-script', 'cmiPP', [
        'ajaxurl'        => $ajax_url,
        'nonce'          => wp_create_nonce( 'cmi_pp_nonce' ),
        'isDoctor'       => is_user_logged_in() && CMI_Roles::is_doctor(),
        'sameDayBuffer'  => absint( get_option( 'cmi_same_day_buffer_minutes', 30 ) ),
    ]);
}

function cmi_pp_admin_scripts( $hook ) {
    $plugin_url = CMI_PP_URL;
    $ajax_url   = admin_url( 'admin-ajax.php' );

    // Reverse proxy HTTPS check (e.g. Hostinger, Cloudflare)
    if ( is_ssl() || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
        $plugin_url = str_replace( 'http://', 'https://', $plugin_url );
        $ajax_url   = str_replace( 'http://', 'https://', $ajax_url );
    }

    wp_enqueue_style( 'cmi-pp-admin-style', $plugin_url . 'assets/admin-style.css', [], CMI_PP_VERSION );
    wp_enqueue_script( 'cmi-ht-admin-script', $plugin_url . 'assets/admin.js', [ 'jquery' ], CMI_PP_VERSION, true );
    wp_localize_script( 'cmi-ht-admin-script', 'cmiHTAdmin', [
        'ajaxurl' => $ajax_url,
        'nonce'   => wp_create_nonce( 'cmi_ht_admin_nonce' ),
    ] );
}

add_action( 'init', 'cmi_pp_register_endpoints' );
function cmi_pp_register_endpoints() {
    add_rewrite_endpoint( 'patient-reports', EP_ROOT | EP_PAGES );
    add_rewrite_endpoint( 'patient-consultations', EP_ROOT | EP_PAGES );
    add_rewrite_endpoint( 'home-collections', EP_ROOT | EP_PAGES );
    add_rewrite_endpoint( 'family-members', EP_ROOT | EP_PAGES );
}

add_filter( 'woocommerce_get_query_vars', 'cmi_pp_add_query_vars' );
function cmi_pp_add_query_vars( $vars ) {
    $vars['patient-reports'] = 'patient-reports';
    $vars['patient-consultations'] = 'patient-consultations';
    $vars['home-collections'] = 'home-collections';
    $vars['family-members'] = 'family-members';
    return $vars;
}
