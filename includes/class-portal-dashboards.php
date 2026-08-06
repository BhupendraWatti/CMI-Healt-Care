<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMI_Portal_Dashboards {

    public static function init() {
        add_shortcode( 'cmi_partner_portal', [ __CLASS__, 'partner_portal_shortcode' ] );
        add_shortcode( 'cmi_patient_portal', [ __CLASS__, 'patient_portal_shortcode' ] );

        // AJAX actions for Authentication
        add_action( 'wp_ajax_nopriv_cmi_portal_login',     [ __CLASS__, 'ajax_login' ] );
        add_action( 'wp_ajax_cmi_portal_login',            [ __CLASS__, 'ajax_login' ] );
        add_action( 'wp_ajax_nopriv_cmi_portal_register',  [ __CLASS__, 'ajax_register' ] );
        add_action( 'wp_ajax_cmi_portal_register',         [ __CLASS__, 'ajax_register' ] );

        add_action( 'wp_ajax_nopriv_cmi_mobile_direct_auth',[ __CLASS__, 'ajax_mobile_direct_auth' ] );
        add_action( 'wp_ajax_cmi_mobile_direct_auth',       [ __CLASS__, 'ajax_mobile_direct_auth' ] );

        // 2-Step Airtel DLT SMS OTP Portal Auth
        add_action( 'wp_ajax_nopriv_cmi_send_portal_otp',   [ __CLASS__, 'ajax_send_portal_otp' ] );
        add_action( 'wp_ajax_cmi_send_portal_otp',          [ __CLASS__, 'ajax_send_portal_otp' ] );
        add_action( 'wp_ajax_nopriv_cmi_verify_portal_otp', [ __CLASS__, 'ajax_verify_portal_otp' ] );
        add_action( 'wp_ajax_cmi_verify_portal_otp',        [ __CLASS__, 'ajax_verify_portal_otp' ] );

        add_action( 'template_redirect',                  [ __CLASS__, 'prevent_caching_on_portals' ] );
    }

    public static function partner_portal_shortcode() {
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['elementor-preview'] ) ) {
            return '<div class="cmi-portal-preview-mode"><p>' . esc_html__( '[CMI Partner Portal - Preview Mode]', 'cmi-partner-portal' ) . '</p></div>';
        }

        if ( ! is_user_logged_in() ) {
            return self::render_auth_form( 'partner' );
        }

        $user = wp_get_current_user();
        if ( CMI_Roles::is_pending( $user ) ) {
            return '<div class="cmi-portal-notice"><p>Your partner registration is pending CMI admin approval.</p>' .
                   '<p><a href="' . esc_url( wp_logout_url( get_permalink() ) ) . '" class="button">Log Out</a></p></div>';
        }

        if ( ! CMI_Roles::is_partner( $user ) ) {
            return '<div class="cmi-portal-notice"><p>You are logged in as a patient/subscriber. Please log out to access the Partner Portal.</p>' .
                   '<p><a href="' . esc_url( wp_logout_url( get_permalink() ) ) . '" class="button">Log Out</a></p></div>';
        }

        ob_start();
        self::render_partner_dashboard( $user );
        return ob_get_clean();
    }

    public static function patient_portal_shortcode() {
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['elementor-preview'] ) ) {
            return '<div class="cmi-portal-preview-mode"><p>' . esc_html__( '[CMI Patient Portal - Preview Mode]', 'cmi-partner-portal' ) . '</p></div>';
        }

        if ( ! is_user_logged_in() ) {
            return self::render_auth_form( 'patient' );
        }

        $user = wp_get_current_user();
        if ( CMI_Roles::is_partner( $user ) || CMI_Roles::is_pending( $user ) ) {
            return '<div class="cmi-portal-notice"><p>You are logged in as a partner. Please log out to access the Patient Portal.</p>' .
                   '<p><a href="' . wp_logout_url( get_permalink() ) . '" class="button">Log Out</a></p></div>';
        }

        // Redirect patients to WooCommerce My Account where the merged dashboard resides
        if ( ! headers_sent() ) {
            wp_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        } else {
            return '<script type="text/javascript">window.location.href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '";</script>' .
                   '<p>' . sprintf( __( 'Redirecting to your account dashboard... If not redirected, <a href="%s">click here</a>.', 'cmi-partner-portal' ), esc_url( wc_get_page_permalink( 'myaccount' ) ) ) . '</p>';
        }
    }

    private static function render_auth_form( $type ) {
        ob_start();
        include CMI_PP_PATH . 'templates/portal-auth.php';
        return ob_get_clean();
    }

    private static function render_partner_dashboard( $user ) {
        $partner_id = $user->ID;
        
        $reports = get_posts([
            'post_type'      => 'cmi_report',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'author'         => $partner_id,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $report_types = get_terms([ 'taxonomy' => 'cmi_report_type', 'hide_empty' => false ]);

        $prescriptions = [];
        if ( CMI_Roles::is_doctor( $user ) ) {
            $prescriptions = get_posts([
                'post_type'      => 'cmi_prescription',
                'post_status'    => 'publish',
                'posts_per_page' => 50,
                'author'         => $partner_id,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);
        }

        global $wpdb;
        $patients = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT 
                pm1.meta_value AS mobile, 
                pm2.meta_value AS uid, 
                pm3.meta_value AS name, 
                pm4.meta_value AS email,
                p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = '_cmi_patient_mobile'
             LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_cmi_patient_uid'
             LEFT JOIN {$wpdb->postmeta} pm3 ON pm3.post_id = p.ID AND pm3.meta_key = '_cmi_patient_name'
             LEFT JOIN {$wpdb->postmeta} pm4 ON pm4.post_id = p.ID AND pm4.meta_key = '_cmi_patient_email'
             WHERE p.post_type IN ('cmi_report','cmi_prescription')
             AND p.post_status = 'publish'
             AND p.post_author = %d
             ORDER BY p.post_date DESC",
            $partner_id
        ));

        $history = null;
        $patient_name = 'Patient';
        if ( ! empty( $_GET['view_history'] ) ) {
            $patient_query = sanitize_text_field( $_GET['view_history'] );
            $normalized_query = CMI_CPT::normalize_mobile( $patient_query );

            $meta_query = [ 'relation' => 'OR' ];
            if ( $normalized_query ) {
                $meta_query[] = [ 'key' => '_cmi_patient_mobile', 'value' => $normalized_query ];
            }
            if ( is_email( $patient_query ) ) {
                $meta_query[] = [ 'key' => '_cmi_patient_email', 'value' => $patient_query ];
            }
            $meta_query[] = [ 'key' => '_cmi_patient_uid', 'value' => $patient_query ];

            $history = get_posts([
                'post_type'      => [ 'cmi_report', 'cmi_prescription' ],
                'post_status'    => 'publish',
                'author'         => $partner_id,
                'meta_query'     => $meta_query,
                'posts_per_page' => 100,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);

            if ( ! empty( $history ) ) {
                foreach ( $history as $h ) {
                    $name = get_post_meta( $h->ID, '_cmi_patient_name', true );
                    if ( $name ) {
                        $patient_name = $name;
                        break;
                    }
                }
            }
        }

        include CMI_PP_PATH . 'templates/partner-dashboard.php';
    }

    private static function render_patient_dashboard( $user ) {
        $mobile = get_user_meta( $user->ID, '_cmi_mobile', true );
        $uid    = get_user_meta( $user->ID, '_cmi_uid', true );
        $email  = $user->user_email;

        $reports = CMI_CPT::get_patient_reports( $mobile, $uid, 'cmi_report', $email );
        $rxs     = CMI_CPT::get_patient_reports( $mobile, $uid, 'cmi_prescription', $email );

        include CMI_PP_PATH . 'templates/patient-dashboard.php';
    }

    // ── AJAX Handlers for Authentication ─────────────────────────────────────

    public static function ajax_login() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $email    = sanitize_email( $_POST['email'] ?? '' );
        $password = $_POST['password'] ?? '';

        if ( ! is_email( $email ) || empty( $password ) ) {
            wp_send_json_error( [ 'message' => 'Please enter a valid email and password.' ] );
            wp_die();
        }

        // Get user by email
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            wp_send_json_error( [ 'message' => 'Account not found with this email.' ] );
            wp_die();
        }

        // Authenticate – do NOT pass is_ssl() as secure_cookie; WordPress manages
        // cookie security internally. Passing is_ssl() here breaks AJAX-based login
        // on staging/production HTTPS because the secure cookie cannot be set
        // inside an AJAX response context reliably across all server configs.
        $auth_user = wp_authenticate( $user->user_login, $password );

        if ( is_wp_error( $auth_user ) ) {
            wp_send_json_error( [ 'message' => 'Incorrect password. Please try again.' ] );
            wp_die();
        }

        // Explicitly set auth cookie so session persists after AJAX response
        wp_set_current_user( $auth_user->ID );
        wp_set_auth_cookie( $auth_user->ID, true, is_ssl() );

        wp_send_json_success( [ 'message' => 'Login successful. Redirecting...' ] );
        wp_die();
    }

    public static function ajax_register() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $type  = sanitize_text_field( $_POST['type'] ?? 'patient' ); // 'partner' or 'patient'
        $name  = sanitize_text_field( $_POST['name'] ?? '' );
        $email = sanitize_email( $_POST['email'] ?? '' );
        $password = $_POST['password'] ?? '';
        $mobile = preg_replace( '/[^0-9+]/', '', $_POST['mobile'] ?? '' );

        if ( empty( $name ) || ! is_email( $email ) || strlen( $password ) < 6 || empty( $mobile ) ) {
            wp_send_json_error( [ 'message' => 'Please fill in all fields correctly. Password must be at least 6 characters.' ] );
            wp_die();
        }

        if ( email_exists( $email ) ) {
            wp_send_json_error( [ 'message' => 'An account with this email address already exists.' ] );
            wp_die();
        }

        // Generate a unique username based on email
        $username = sanitize_user( current( explode( '@', $email ) ) );
        if ( username_exists( $username ) ) {
            $username .= '_' . wp_generate_password( 4, false );
        }

        // Create User
        $user_id = wp_insert_user( [
            'user_login' => $username,
            'user_email' => $email,
            'user_pass'  => $password,
            'first_name' => $name,
            'display_name'=> $name,
            'role'       => 'subscriber' // default subscriber
        ] );

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( [ 'message' => $user_id->get_error_message() ] );
            wp_die();
        }

        // Save mobile number, sync billing_phone, and generate a unique patient ID
        $norm_mobile = CMI_CPT::normalize_mobile( $mobile );
        update_user_meta( $user_id, '_cmi_mobile', $norm_mobile );
        update_user_meta( $user_id, 'billing_phone', $norm_mobile );
        $uid = 'CMI' . strtoupper( wp_generate_password( 6, false ) );
        update_user_meta( $user_id, '_cmi_uid', $uid );

        // Trigger Welcome SMS attempt if mobile number exists
        if ( class_exists( 'CMI_SMS_Manager' ) && ! empty( $norm_mobile ) ) {
            CMI_SMS_Manager::maybe_send_welcome_sms( $user_id, $norm_mobile );
        }

        if ( $type === 'partner' ) {
            $partner_type = sanitize_text_field( $_POST['partner_type'] ?? 'medical_partner' );
            $partner_type = CMI_Security::requested_partner_type( $partner_type );
            CMI_Security::mark_pending_partner( $user_id, $partner_type, [
                '_cmi_org'     => sanitize_text_field( $_POST['org'] ?? '' ),
                '_cmi_license' => sanitize_text_field( $_POST['license'] ?? '' ),
            ] );

            // Send notification email to admin about new partner registration
            $admin_email = get_option( 'admin_email' );
            wp_mail(
                $admin_email,
                'New Partner Approval Required - CMI Healthcare',
                "A new partner has registered and requires admin approval.\n\n" .
                "Name: {$name}\n" .
                "Email: {$email}\n" .
                "Requested Role: {$partner_type}\n" .
                "Mobile: {$mobile}\n\n" .
                "Manage partners here: " . admin_url( 'admin.php?page=cmi-partner-approvals' )
            );
        }

        // Auto-login after registration.
        // Use wp_authenticate + wp_set_auth_cookie instead of wp_signon() to avoid
        // the secure-cookie staging/production HTTPS bug where AJAX cannot reliably
        // set Secure cookies and the user appears immediately logged out on reload.
        $auth_user = wp_authenticate( $username, $password );

        if ( is_wp_error( $auth_user ) ) {
            // Registration succeeded but auto-login failed — tell user to log in manually.
            wp_send_json_success( [ 'message' => 'Registration successful! Please log in with your credentials.' ] );
            wp_die();
        }

        // Explicitly set auth cookie so session survives the AJAX boundary
        wp_set_current_user( $auth_user->ID );
        wp_set_auth_cookie( $auth_user->ID, true, is_ssl() );

        wp_send_json_success( [ 'message' => 'Registration successful. Redirecting...' ] );
        wp_die();
    }

    /**
     * Mobile Direct Auth (Passwordless Login / Register without OTP until DLT template approval)
     *
     * - Existing user: logged in directly by mobile number — no password needed.
     * - New user: account auto-created with a random internal password. User never needs to know it.
     * - Welcome SMS is fired on both new registration and first-time login (if not already sent).
     */
    public static function ajax_mobile_direct_auth() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        wp_send_json_error( [
            'message' => __( 'Passwordless mobile login now requires OTP verification. Please request an OTP to continue.', 'cmi-partner-portal' ),
        ] );
        wp_die();

        $mobile       = preg_replace( '/[^0-9]/', '', $_POST['mobile'] ?? '' );
        $name         = sanitize_text_field( $_POST['name'] ?? '' );
        $type         = sanitize_text_field( $_POST['type'] ?? 'patient' );
        $partner_type = sanitize_text_field( $_POST['partner_type'] ?? 'medical_partner' );

        if ( strlen( $mobile ) < 10 ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a valid 10-digit mobile number.', 'cmi-partner-portal' ) ] );
            wp_die();
        }

        $clean_10    = substr( $mobile, -10 );
        $norm_mobile = CMI_SMS_Manager::format_mobile( $clean_10 );

        // Search for existing user by mobile number
        $user = null;
        $users_by_meta = get_users([
            'meta_query' => [
                'relation' => 'OR',
                [ 'key' => '_cmi_mobile',   'value' => $norm_mobile ],
                [ 'key' => '_cmi_mobile',   'value' => $clean_10 ],
                [ 'key' => 'billing_phone', 'value' => $norm_mobile ],
                [ 'key' => 'billing_phone', 'value' => $clean_10 ],
            ],
            'number' => 1
        ]);

        if ( ! empty( $users_by_meta ) ) {
            $user = $users_by_meta[0];
        } else {
            $user_by_login = get_user_by( 'login', 'user_' . $clean_10 );
            if ( $user_by_login ) {
                $user = $user_by_login;
            }
        }

        if ( $user ) {
            // Existing user: login directly by mobile — no password required (passwordless flow)
            wp_set_current_user( $user->ID );
            wp_set_auth_cookie( $user->ID, true, is_ssl() );

            // Update mobile meta in case it wasn't stored before
            update_user_meta( $user->ID, '_cmi_mobile', $norm_mobile );
            update_user_meta( $user->ID, 'billing_phone', $norm_mobile );

            // Dispatch Welcome SMS if not already sent previously
            if ( class_exists( 'CMI_SMS_Manager' ) ) {
                CMI_SMS_Manager::maybe_send_welcome_sms( $user->ID, $norm_mobile );
            }

            wp_send_json_success( [ 'message' => __( 'Login successful! Redirecting...', 'cmi-partner-portal' ) ] );
        }

        // ── New User Registration via Mobile Number ──────────────────────────
        $username   = 'user_' . $clean_10;
        $email      = $clean_10 . '@cmihealthcare.in';
        $first_name = ! empty( $name ) ? $name : ( 'User ' . substr( $clean_10, -4 ) );
        // Generate a secure random password the user never needs — auth is mobile-based (OTP later)
        $user_pass  = wp_generate_password( 16 );

        $user_id = wp_insert_user([
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $user_pass,
            'first_name'   => $first_name,
            'display_name' => $first_name,
            'role'         => 'subscriber'
        ]);

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( [ 'message' => $user_id->get_error_message() ] );
            wp_die();
        }

        update_user_meta( $user_id, '_cmi_mobile', $norm_mobile );
        update_user_meta( $user_id, 'billing_phone', $norm_mobile );
        $uid = 'CMI' . strtoupper( wp_generate_password( 6, false ) );
        update_user_meta( $user_id, '_cmi_uid', $uid );

        if ( $type === 'partner' ) {
            $allowed_types = [ 'medical_partner', 'cmi_doctor' ];
            if ( ! in_array( $partner_type, $allowed_types, true ) ) {
                $partner_type = 'medical_partner';
            }

            CMI_Security::mark_pending_partner( $user_id, $partner_type );
        }

        // Send Welcome SMS for new user registration
        if ( class_exists( 'CMI_SMS_Manager' ) ) {
            CMI_SMS_Manager::maybe_send_welcome_sms( $user_id, $norm_mobile );
        }

        // Log in new user immediately
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true, is_ssl() );

        wp_send_json_success( [ 'message' => __( 'Registration successful! Redirecting...', 'cmi-partner-portal' ) ] );
        wp_die();
    }

    /**
     * AJAX Handler: Send Portal Login / Registration OTP via Airtel IQ DLT SMS API
     */
    public static function ajax_send_portal_otp() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $mobile = preg_replace( '/[^0-9]/', '', $_POST['mobile'] ?? '' );
        if ( strlen( $mobile ) < 10 ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a valid 10-digit mobile number.', 'cmi-partner-portal' ) ] );
            wp_die();
        }

        $clean_10    = substr( $mobile, -10 );
        $norm_mobile = CMI_SMS_Manager::format_mobile( $clean_10 );

        if ( ! $norm_mobile ) {
            wp_send_json_error( [ 'message' => __( 'Invalid mobile number format. Please enter a valid 10-digit Indian mobile number.', 'cmi-partner-portal' ) ] );
            wp_die();
        }

        // Verify DLT template is configured before generating OTP
        $tmpl_id = get_option( 'cmi_dlt_tmpl_otp_access', '' );
        $dlt_msg = get_option( 'cmi_dlt_msg_otp_access', '' );

        if ( empty( $tmpl_id ) || empty( $dlt_msg ) ) {
            error_log( "CMI OTP BLOCKED [{$norm_mobile}]: otp_access template not configured. tmpl_id=" . ($tmpl_id ?: 'EMPTY') . " msg=" . (empty($dlt_msg) ? 'EMPTY' : 'OK') );
            wp_send_json_error( [ 'message' => __( 'OTP service is not configured. Please contact admin to set up DLT Template ID for otp_access in SMS Settings.', 'cmi-partner-portal' ) ] );
            wp_die();
        }

        // Generate 6-digit OTP and store in wp_cmi_otp table
        $otp = CMI_OTP::generate( $norm_mobile );

        // Send OTP via Airtel IQ DLT SMS API
        $sent = CMI_OTP::send( $norm_mobile, $otp );

        if ( $sent ) {
            wp_send_json_success( [ 'message' => sprintf( __( 'OTP sent to +91 %s via SMS. Valid for 10 minutes.', 'cmi-partner-portal' ), $clean_10 ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Failed to send OTP via Airtel SMS gateway. Please check your DLT Template ID and message in Admin > CMI Portal > SMS Settings, then try again.', 'cmi-partner-portal' ) ] );
        }
        wp_die();
    }

    /**
     * AJAX Handler: Verify Portal Auth OTP & Log in / Register User
     */
    public static function ajax_verify_portal_otp() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $mobile       = preg_replace( '/[^0-9]/', '', $_POST['mobile'] ?? '' );
        $otp          = sanitize_text_field( $_POST['otp'] ?? '' );
        $name         = sanitize_text_field( $_POST['name'] ?? '' );
        $type         = sanitize_text_field( $_POST['type'] ?? 'patient' );
        $partner_type = sanitize_text_field( $_POST['partner_type'] ?? 'medical_partner' );

        if ( strlen( $mobile ) < 10 || empty( $otp ) ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a valid mobile number and 6-digit OTP code.', 'cmi-partner-portal' ) ] );
            wp_die();
        }

        $clean_10    = substr( $mobile, -10 );
        $norm_mobile = CMI_SMS_Manager::format_mobile( $clean_10 );

        // Verify OTP from wp_cmi_otp table
        if ( ! CMI_OTP::verify( $norm_mobile, $otp ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid or expired OTP code. Please try again.', 'cmi-partner-portal' ) ] );
            wp_die();
        }

        // OTP Verified successfully! Lookup existing user
        $user = null;
        $users_by_meta = get_users([
            'meta_query' => [
                'relation' => 'OR',
                [ 'key' => '_cmi_mobile',   'value' => $norm_mobile ],
                [ 'key' => '_cmi_mobile',   'value' => $clean_10 ],
                [ 'key' => 'billing_phone', 'value' => $norm_mobile ],
                [ 'key' => 'billing_phone', 'value' => $clean_10 ],
            ],
            'number' => 1
        ]);

        if ( ! empty( $users_by_meta ) ) {
            $user = $users_by_meta[0];
        } else {
            $user_by_login = get_user_by( 'login', 'user_' . $clean_10 );
            if ( $user_by_login ) {
                $user = $user_by_login;
            }
        }

        if ( ! $user ) {
            // Auto-register new user
            $username   = 'user_' . $clean_10;
            $email      = $clean_10 . '@cmihealthcare.in';
            $first_name = ! empty( $name ) ? $name : ( 'User ' . substr( $clean_10, -4 ) );
            $user_pass  = wp_generate_password( 16 );

            $user_id = wp_insert_user([
                'user_login'   => $username,
                'user_email'   => $email,
                'user_pass'    => $user_pass,
                'first_name'   => $first_name,
                'display_name' => $first_name,
                'role'         => 'subscriber'
            ]);

            if ( is_wp_error( $user_id ) ) {
                wp_send_json_error( [ 'message' => $user_id->get_error_message() ] );
                wp_die();
            }

            update_user_meta( $user_id, '_cmi_mobile', $norm_mobile );
            update_user_meta( $user_id, 'billing_phone', $norm_mobile );
            $uid = 'CMI' . strtoupper( wp_generate_password( 6, false ) );
            update_user_meta( $user_id, '_cmi_uid', $uid );

            if ( $type === 'partner' ) {
                $allowed_types = [ 'medical_partner', 'cmi_doctor' ];
                if ( ! in_array( $partner_type, $allowed_types, true ) ) {
                    $partner_type = 'medical_partner';
                }

                CMI_Security::mark_pending_partner( $user_id, $partner_type );
            }

            // Dispatch Welcome SMS upon registration
            if ( class_exists( 'CMI_SMS_Manager' ) ) {
                CMI_SMS_Manager::maybe_send_welcome_sms( $user_id, $norm_mobile );
            }

            $user = get_userdata( $user_id );
        } else {
            // Existing user: ensure mobile meta is set
            update_user_meta( $user->ID, '_cmi_mobile', $norm_mobile );
            update_user_meta( $user->ID, 'billing_phone', $norm_mobile );
            if ( class_exists( 'CMI_SMS_Manager' ) ) {
                CMI_SMS_Manager::maybe_send_welcome_sms( $user->ID, $norm_mobile );
            }
        }

        // Set Auth Cookie
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true, is_ssl() );

        wp_send_json_success( [ 'message' => sprintf( __( 'OTP verified! Welcome %s. Redirecting...', 'cmi-partner-portal' ), $user->display_name ) ] );
        wp_die();
    }

    public static function prevent_caching_on_portals() {
        global $post;
        $disable = false;
        if ( is_a( $post, 'WP_Post' ) ) {
            if ( 
                has_shortcode( $post->post_content, 'cmi_partner_portal' ) || 
                has_shortcode( $post->post_content, 'cmi_patient_portal' ) ||
                has_shortcode( $post->post_content, 'cmi_doctor_consultation' )
            ) {
                $disable = true;
            }
        }
        if ( function_exists( 'is_account_page' ) && is_account_page() ) {
            $disable = true;
        }

        if ( $disable ) {
            nocache_headers();
            if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                define( 'DONOTCACHEPAGE', true );
            }
        }
    }
}

CMI_Portal_Dashboards::init();
