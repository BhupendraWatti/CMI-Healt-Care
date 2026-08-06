<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMI_Guest_Access {

    public static function init() {
        add_shortcode( 'cmi_download_report', [ __CLASS__, 'shortcode' ] );
        add_action( 'wp_ajax_nopriv_cmi_guest_send_otp',   [ __CLASS__, 'ajax_send_otp' ] );
        add_action( 'wp_ajax_cmi_guest_send_otp',          [ __CLASS__, 'ajax_send_otp' ] );
        add_action( 'wp_ajax_nopriv_cmi_guest_verify_otp', [ __CLASS__, 'ajax_verify_otp' ] );
        add_action( 'wp_ajax_cmi_guest_verify_otp',        [ __CLASS__, 'ajax_verify_otp' ] );
        add_action( 'wp_ajax_nopriv_cmi_guest_download',   [ __CLASS__, 'ajax_guest_download' ] );
        add_action( 'wp_ajax_cmi_guest_download',          [ __CLASS__, 'ajax_guest_download' ] );
        add_action( 'wp_ajax_nopriv_cmi_guest_email_access', [ __CLASS__, 'ajax_email_access' ] );
        add_action( 'wp_ajax_cmi_guest_email_access',        [ __CLASS__, 'ajax_email_access' ] );
        add_action( 'wp_ajax_nopriv_cmi_guest_verify_magic_token', [ __CLASS__, 'ajax_verify_magic_token' ] );
        add_action( 'wp_ajax_cmi_guest_verify_magic_token',        [ __CLASS__, 'ajax_verify_magic_token' ] );
        add_action( 'wp_ajax_nopriv_cmi_guest_uid_send_otp', [ __CLASS__, 'ajax_patient_id_send_otp' ] );
        add_action( 'wp_ajax_cmi_guest_uid_send_otp',        [ __CLASS__, 'ajax_patient_id_send_otp' ] );
        add_action( 'wp_ajax_nopriv_cmi_guest_uid_verify_otp', [ __CLASS__, 'ajax_patient_id_verify_otp' ] );
        add_action( 'wp_ajax_cmi_guest_uid_verify_otp',        [ __CLASS__, 'ajax_patient_id_verify_otp' ] );
        // Logged-in patient: email OTP before download
        add_action( 'wp_ajax_cmi_patient_send_email_otp',   [ __CLASS__, 'ajax_patient_send_email_otp' ] );
        add_action( 'wp_ajax_cmi_patient_verify_email_otp', [ __CLASS__, 'ajax_patient_verify_email_otp' ] );
    }

    public static function shortcode() {
        ob_start();
        include CMI_PP_PATH . 'templates/guest-download.php';
        return ob_get_clean();
    }

    public static function ajax_send_otp() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $mobile = preg_replace( '/[^0-9+]/', '', $_POST['mobile'] ?? '' );
        if ( strlen( $mobile ) < 10 ) {
            wp_send_json_error( [ 'message' => 'Please enter a valid 10-digit mobile number.' ] );
        }

        // Check if reports exist for this number
        $reports = CMI_CPT::get_patient_reports( $mobile, '', 'cmi_report' );
        if ( empty( $reports ) ) {
            $reports = CMI_CPT::get_patient_reports( $mobile, '', 'cmi_prescription' );
        }
        if ( empty( $reports ) ) {
            wp_send_json_error( [ 'message' => 'No reports found for this mobile number. Please check and try again, or contact CMI Healthcare.' ] );
        }

        $normalized = CMI_CPT::normalize_mobile( $mobile );

        // Lookup user by mobile
        $users = get_users([
            'meta_query' => [
                'relation' => 'OR',
                [ 'key' => 'billing_phone', 'value' => $normalized ],
                [ 'key' => '_cmi_mobile',    'value' => $normalized ]
            ],
            'number' => 1
        ]);

        $email = '';
        if ( ! empty( $users ) ) {
            $email = $users[0]->user_email;
        } else {
            // Get from post meta of the first report
            $email = get_post_meta( $reports[0]->ID, '_cmi_patient_email', true );
        }

        if ( ! $email || ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'No registered email address found associated with this mobile number. Please contact CMI Healthcare.' ] );
        }

        $otp = CMI_OTP::generate( $email );

        // Send OTP via Email
        $subject = 'Your CMI Healthcare Access OTP';
        $body    = "Dear Patient,\n\nYour one-time password (OTP) to access your medical reports is: {$otp}\n\nValid for 10 minutes.";
        $sent = wp_mail( $email, $subject, $body );

        if ( ! $sent ) {
            wp_send_json_error( [ 'message' => 'Could not send verification email. Please try again.' ] );
        }

        $parts = explode( '@', $email );
        $masked = substr( $parts[0], 0, 2 ) . '***@' . $parts[1];

        wp_send_json_success( [ 
            'message' => 'OTP sent to your registered email address: ' . $masked,
            'email'   => $email
        ] );
    }

    public static function ajax_verify_otp() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $mobile = preg_replace( '/[^0-9+]/', '', $_POST['mobile'] ?? '' );
        $otp    = sanitize_text_field( $_POST['otp'] ?? '' );

        $normalized = CMI_CPT::normalize_mobile( $mobile );

        // Lookup user by mobile
        $users = get_users([
            'meta_query' => [
                'relation' => 'OR',
                [ 'key' => 'billing_phone', 'value' => $normalized ],
                [ 'key' => '_cmi_mobile',    'value' => $normalized ]
            ],
            'number' => 1
        ]);

        $email = '';
        if ( ! empty( $users ) ) {
            $email = $users[0]->user_email;
        } else {
            // Lookup post reports
            $reports = CMI_CPT::get_patient_reports( $mobile, '', 'cmi_report' );
            if ( empty( $reports ) ) {
                $reports = CMI_CPT::get_patient_reports( $mobile, '', 'cmi_prescription' );
            }
            if ( ! empty( $reports ) ) {
                $email = get_post_meta( $reports[0]->ID, '_cmi_patient_email', true );
            }
        }

        if ( ! $email || ! CMI_OTP::verify( $email, $otp ) ) {
            wp_send_json_error( [ 'message' => 'Invalid or expired OTP. Please try again.' ] );
        }

        // OTP verified – return reports list
        $reports = CMI_CPT::get_patient_reports( $mobile, '', 'cmi_report' );
        $rxs     = CMI_CPT::get_patient_reports( $mobile, '', 'cmi_prescription' );

        $list = [];
        foreach ( $reports as $r ) {
            $list[] = self::format_report_row( $r, 'mobile', $mobile );
        }
        foreach ( $rxs as $r ) {
            $list[] = self::format_report_row( $r, 'mobile', $mobile );
        }
        $list = array_values( array_filter( $list ) );

        if ( empty( $list ) ) {
            wp_send_json_error( [ 'message' => 'No reports found.' ] );
        }

        wp_send_json_success( [ 'reports' => $list ] );
    }

    private static function format_report_row( $post, $identity_type, $identity_value ) {
        $claim = CMI_Security::identity_claim( $identity_type, $identity_value );
        if ( ! $claim || ! CMI_Security::identity_can_access_report( $post->ID, $claim ) ) {
            return null;
        }

        $token   = wp_generate_password( 32, false );
        $expires = time() + 600; // 10 min
        set_transient( 'cmi_gdl_' . $token, [
            'report_id'      => $post->ID,
            'identity_type'  => $claim['identity_type'],
            'identity_value' => $claim['identity_value'],
            'expires'        => $expires,
        ], 600 );

        $terms = wp_get_post_terms( $post->ID, 'cmi_report_type', [ 'fields' => 'names' ] );
        $type  = ! empty( $terms ) ? $terms[0] : ( $post->post_type === 'cmi_prescription' ? 'Prescription' : 'Report' );

        return [
            'id'    => $post->ID,
            'title' => get_the_title( $post ),
            'type'  => $type,
            'date'  => date('d M Y', strtotime( $post->post_date ) ),
            'token' => $token,
        ];
    }

    public static function ajax_guest_download() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $token = sanitize_text_field( $_POST['token'] ?? '' );
        $data  = get_transient( 'cmi_gdl_' . $token );

        if ( ! $data || time() > $data['expires'] ) {
            wp_send_json_error( [ 'message' => 'Download link expired. Please verify OTP again.' ] );
        }

        if ( ! CMI_Security::identity_can_access_report( $data['report_id'], $data ) ) {
            delete_transient( 'cmi_gdl_' . $token );
            wp_send_json_error( [ 'message' => 'Access denied.' ] );
        }

        // Generate a final signed download URL
        $url = CMI_Download::generate_link( $data['report_id'], 'guest', $data );
        delete_transient( 'cmi_gdl_' . $token );
        if ( empty( $url ) ) {
            wp_send_json_error( [ 'message' => 'Access denied.' ] );
        }

        wp_send_json_success( [ 'url' => $url ] );
    }

    public static function ajax_email_access() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $email = sanitize_email( $_POST['email'] ?? '' );
        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'Please enter a valid email address.' ] );
        }

        // Check rate limit: 3 link requests per email per hour
        $rate_key = 'cmi_rate_magic_' . md5( $email );
        $requests = (int) get_transient( $rate_key );
        if ( $requests >= 3 ) {
            wp_send_json_error( [ 'message' => 'Too many requests. Please check your email or try again after 1 hour.' ] );
        }
        set_transient( $rate_key, $requests + 1, HOUR_IN_SECONDS );

        // Check if reports exist for this email
        $reports = CMI_CPT::get_patient_reports( '', '', 'cmi_report', $email );
        $rxs     = CMI_CPT::get_patient_reports( '', '', 'cmi_prescription', $email );

        if ( empty( $reports ) && empty( $rxs ) ) {
            wp_send_json_error( [ 'message' => 'No reports found for this email address. Please check and try again, or contact CMI Healthcare.' ] );
        }

        // Generate magic token
        $token = wp_generate_password( 32, false );
        set_transient( 'cmi_magic_' . $token, $email, 15 * MINUTE_IN_SECONDS ); // Valid for 15 minutes

        // Get redirect URL from post request
        $redirect_url = CMI_Security::local_redirect_url( $_POST['redirect_url'] ?? home_url( '/' ) );
        $magic_link   = add_query_arg( 'cmi_magic_token', $token, $redirect_url );

        $subject = 'Secure Access Link for CMI Healthcare Reports';
        $body    = "Dear Patient,\n\nYou requested access to your reports on CMI Healthcare. Click the link below to view and download them:\n\n" .
                   "{$magic_link}\n\n" .
                   "This link is valid for 15 minutes and can only be used once.\n\n" .
                   "If you did not request this, please ignore this email.\n\n" .
                   "Regards,\nCMI Healthcare Team";

        $sent = wp_mail( $email, $subject, $body );

        if ( ! $sent ) {
            wp_send_json_error( [ 'message' => 'Could not send access link. Please try again later.' ] );
        }

        wp_send_json_success( [ 'message' => 'A secure access link has been sent to your email. Please check your inbox (and spam folder).' ] );
    }

    public static function ajax_verify_magic_token() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $token = sanitize_text_field( $_POST['token'] ?? '' );
        if ( empty( $token ) ) {
            wp_send_json_error( [ 'message' => 'Invalid token.' ] );
        }

        $email = get_transient( 'cmi_magic_' . $token );
        if ( ! $email ) {
            wp_send_json_error( [ 'message' => 'This access link has expired or is invalid.' ] );
        }

        // Delete token to make it single-use
        delete_transient( 'cmi_magic_' . $token );

        // Retrieve reports
        $reports = CMI_CPT::get_patient_reports( '', '', 'cmi_report', $email );
        $rxs     = CMI_CPT::get_patient_reports( '', '', 'cmi_prescription', $email );

        $list = [];
        foreach ( $reports as $r ) {
            $list[] = self::format_report_row( $r, 'email', $email );
        }
        foreach ( $rxs as $r ) {
            $list[] = self::format_report_row( $r, 'email', $email );
        }
        $list = array_values( array_filter( $list ) );

        if ( empty( $list ) ) {
            wp_send_json_error( [ 'message' => 'No reports found.' ] );
        }

        wp_send_json_success( [ 'reports' => $list, 'email' => $email ] );
    }

    // ── Logged-in patient: send OTP to their email before download ───────────

    public static function ajax_patient_send_email_otp() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'You must be logged in.' ] );
        }

        $user  = wp_get_current_user();
        $email = sanitize_email( $user->user_email );
        $report_id = absint( $_POST['report_id'] ?? 0 );

        if ( ! $report_id ) {
            wp_send_json_error( [ 'message' => 'Invalid report.' ] );
        }
        if ( ! CMI_Download::user_can_download( $report_id ) ) {
            wp_send_json_error( [ 'message' => 'Access denied.' ] );
        }

        // Generate OTP keyed by email
        $otp = CMI_OTP::generate( $email );

        // Send via email
        $subject = 'Your CMI Healthcare Report Download OTP';
        $body    = "Dear " . $user->display_name . ",\n\n" .
                   "Your one-time password (OTP) to download your medical report is:\n\n" .
                   "    {$otp}\n\n" .
                   "This OTP is valid for 10 minutes. Do not share it with anyone.\n\n" .
                   "If you did not request this, please ignore this email.\n\n" .
                   "Regards,\nCMI Healthcare Team";

        $sent = wp_mail( $email, $subject, $body );

        if ( ! $sent ) {
            wp_send_json_error( [ 'message' => 'Could not send OTP email. Please try again.' ] );
        }

        wp_send_json_success( [
            'message' => 'OTP sent to ' . $email . '. Please check your inbox (and spam folder).',
            'email'   => $email,
        ]);
    }

    public static function ajax_patient_verify_email_otp() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'You must be logged in.' ] );
        }

        $user      = wp_get_current_user();
        $email     = sanitize_email( $user->user_email );
        $otp       = sanitize_text_field( $_POST['otp'] ?? '' );
        $report_id = absint( $_POST['report_id'] ?? 0 );

        if ( ! $report_id || ! CMI_Download::user_can_download( $report_id ) ) {
            wp_send_json_error( [ 'message' => 'Access denied.' ] );
        }

        if ( ! CMI_OTP::verify( $email, $otp ) ) {
            wp_send_json_error( [ 'message' => 'Invalid or expired OTP. Please try again.' ] );
        }

        // Store verification status in transient (for backend check)
        set_transient( 'cmi_verified_email_' . $user->ID, true, 3600 ); // 1 hour

        // Store verification status in cookie (for frontend check)
        setcookie( 'cmi_email_verified', '1', time() + 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );

        // OTP verified – generate signed download URL
        $url = CMI_Download::generate_link( $report_id, 'patient_email_verified' );
        if ( empty( $url ) ) {
            wp_send_json_error( [ 'message' => 'Access denied.' ] );
        }
        wp_send_json_success( [ 'url' => $url ] );
    }

    public static function ajax_patient_id_send_otp() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $uid = sanitize_text_field( $_POST['uid'] ?? '' );
        if ( empty( $uid ) ) {
            wp_send_json_error( [ 'message' => 'Unique Patient ID is required.' ] );
        }

        // Lookup patient by UID
        $users = get_users([
            'meta_key'   => '_cmi_uid',
            'meta_value' => $uid,
            'number'     => 1,
        ]);

        $reports = CMI_CPT::get_patient_reports( '', $uid, 'cmi_report' );
        if ( empty( $reports ) ) {
            $reports = CMI_CPT::get_patient_reports( '', $uid, 'cmi_prescription' );
        }

        $mobile = '';
        $email  = '';

        if ( ! empty( $users ) ) {
            $user   = $users[0];
            $mobile = get_user_meta( $user->ID, 'billing_phone', true ) ?: get_user_meta( $user->ID, '_cmi_mobile', true );
            $email  = $user->user_email;
        } elseif ( ! empty( $reports ) ) {
            $mobile = get_post_meta( $reports[0]->ID, '_cmi_patient_mobile', true );
            $email  = get_post_meta( $reports[0]->ID, '_cmi_patient_email', true );
        }

        if ( empty( $mobile ) && empty( $email ) ) {
            wp_send_json_error( [ 'message' => 'No contact details found associated with this Patient ID. Please contact support.' ] );
        }

        $sent = false;
        $masked = '';
        $type = '';

        if ( $mobile ) {
            $otp  = CMI_OTP::generate( $mobile );
            $sent = CMI_OTP::send( $mobile, $otp );
            $masked = substr( $mobile, 0, 3 ) . '******' . substr( $mobile, -2 );
            $type = 'mobile';
        } elseif ( $email ) {
            $otp  = CMI_OTP::generate( $email );
            $subject = 'Your CMI Healthcare Access OTP';
            $body    = "Dear Patient,\n\nYour OTP to access reports is: {$otp}\n\nValid for 10 minutes.";
            $sent = wp_mail( $email, $subject, $body );
            $parts = explode('@', $email);
            $masked = substr($parts[0], 0, 2) . '***@' . $parts[1];
            $type = 'email';
        }

        if ( ! $sent ) {
            wp_send_json_error( [ 'message' => 'Could not send verification code. Please try again.' ] );
        }

        wp_send_json_success( [
            'message' => 'Verification code sent to registered ' . $type . ': ' . $masked,
            'type'    => $type,
            'uid'     => $uid,
        ] );
    }

    public static function ajax_patient_id_verify_otp() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $uid = sanitize_text_field( $_POST['uid'] ?? '' );
        $otp = sanitize_text_field( $_POST['otp'] ?? '' );

        if ( empty( $uid ) || empty( $otp ) ) {
            wp_send_json_error( [ 'message' => 'Patient ID and OTP are required.' ] );
        }

        // Look up registered contact details
        $users = get_users([
            'meta_key'   => '_cmi_uid',
            'meta_value' => $uid,
            'number'     => 1,
        ]);

        $reports = CMI_CPT::get_patient_reports( '', $uid, 'cmi_report' );
        if ( empty( $reports ) ) {
            $reports = CMI_CPT::get_patient_reports( '', $uid, 'cmi_prescription' );
        }

        $mobile = '';
        $email  = '';

        if ( ! empty( $users ) ) {
            $user   = $users[0];
            $mobile = get_user_meta( $user->ID, 'billing_phone', true ) ?: get_user_meta( $user->ID, '_cmi_mobile', true );
            $email  = $user->user_email;
        } elseif ( ! empty( $reports ) ) {
            $mobile = get_post_meta( $reports[0]->ID, '_cmi_patient_mobile', true );
            $email  = get_post_meta( $reports[0]->ID, '_cmi_patient_email', true );
        }

        $verified = false;

        if ( $mobile && CMI_OTP::verify( $mobile, $otp ) ) {
            $verified = true;
        } elseif ( $email && CMI_OTP::verify( $email, $otp ) ) {
            $verified = true;
        }

        if ( ! $verified ) {
            wp_send_json_error( [ 'message' => 'Invalid or expired verification code.' ] );
        }

        // Get reports by UID
        $reports = CMI_CPT::get_patient_reports( '', $uid, 'cmi_report' );
        $rxs     = CMI_CPT::get_patient_reports( '', $uid, 'cmi_prescription' );

        $list = [];
        foreach ( $reports as $r ) {
            $list[] = self::format_report_row( $r, 'uid', $uid );
        }
        foreach ( $rxs as $r ) {
            $list[] = self::format_report_row( $r, 'uid', $uid );
        }
        $list = array_values( array_filter( $list ) );

        if ( empty( $list ) ) {
            wp_send_json_error( [ 'message' => 'No reports found.' ] );
        }

        wp_send_json_success( [ 'reports' => $list ] );
    }
}
