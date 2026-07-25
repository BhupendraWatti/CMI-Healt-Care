<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMI_Download {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'handle_download_request' ] );
        add_action( 'wp_ajax_cmi_get_download_link',        [ __CLASS__, 'ajax_get_link' ] );
        add_action( 'wp_ajax_nopriv_cmi_get_download_link', [ __CLASS__, 'ajax_get_link' ] );
    }

    /**
     * Generate a signed download URL valid for 30 minutes.
     */
    public static function generate_link( $report_id, $context = 'user' ) {
        $token   = wp_generate_password( 32, false );
        $expires = time() + ( 30 * 60 ); // 30 minutes

        $transient_data = [
            'expires'   => $expires,
            'context'   => $context,
            'user_id'   => get_current_user_id(),
        ];

        if ( is_numeric( $report_id ) ) {
            $transient_data['report_id'] = absint( $report_id );
        } else {
            $transient_data['file_name'] = sanitize_file_name( $report_id );
        }

        set_transient( 'cmi_dl_' . $token, $transient_data, 30 * 60 );

        return add_query_arg([
            'cmi_download' => '1',
            'token'        => $token,
        ], home_url('/'));
    }

    /**
     * Intercept download requests.
     */
    public static function handle_download_request() {
        if ( empty( $_GET['cmi_download'] ) || empty( $_GET['token'] ) ) return;

        $token = sanitize_text_field( $_GET['token'] );
        $data  = get_transient( 'cmi_dl_' . $token );

        if ( ! $data || time() > $data['expires'] ) {
            wp_die( 'This download link has expired. Please request a new one.', 'Link Expired', [ 'response' => 403 ] );
        }

        $file_name = '';
        $report_id = 0;

        if ( ! empty( $data['report_id'] ) ) {
            $report_id = absint( $data['report_id'] );
            $post      = get_post( $report_id );

            if ( ! $post || ! in_array( $post->post_type, [ 'cmi_report', 'cmi_prescription' ] ) ) {
                wp_die( 'Report not found.', 'Not Found', [ 'response' => 404 ] );
            }
            $file_name = get_post_meta( $report_id, '_cmi_file_name', true );
        } elseif ( ! empty( $data['file_name'] ) ) {
            // Check access: only admins or logged-in users with correct caps can download arbitrary filenames
            $user = wp_get_current_user();
            $is_authorized = false;
            if ( $user && $user->ID ) {
                if ( in_array( 'administrator', (array) $user->roles ) || current_user_can( 'manage_options' ) || current_user_can( 'cmi_manage_reports' ) || current_user_can( 'cmi_upload_report' ) ) {
                    $is_authorized = true;
                }
            }
            if ( ! $is_authorized ) {
                wp_die( 'Access denied.', 'Forbidden', [ 'response' => 403 ] );
            }
            $file_name = sanitize_file_name( $data['file_name'] );
        }

        if ( empty( $file_name ) ) {
            wp_die( 'Invalid file.', 'Error', [ 'response' => 400 ] );
        }

        $file_path = CMI_PP_UPLOAD_DIR . '/' . $file_name;

        if ( ! file_exists( $file_path ) ) {
            wp_die( 'Report file not found on server.', 'File Missing', [ 'response' => 404 ] );
        }

        // Log download
        if ( $report_id ) {
            self::log_download( $report_id, $data['user_id'] );
        }

        // Delete token (one-time use)
        delete_transient( 'cmi_dl_' . $token );

        // Stream file
        $ext  = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
        $mime_map = [
            'pdf'  => 'application/pdf',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
        ];
        $mime = isset( $mime_map[ $ext ] ) ? $mime_map[ $ext ] : 'application/octet-stream';

        header( 'Content-Type: ' . $mime );
        header( 'Content-Disposition: attachment; filename="' . basename( $file_name ) . '"' );
        header( 'Content-Length: ' . filesize( $file_path ) );
        header( 'Cache-Control: no-store, no-cache, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $file_path );
        exit;
    }

    public static function ajax_get_link() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $report_id = absint( $_POST['report_id'] ?? 0 );
        if ( ! $report_id ) wp_send_json_error( [ 'message' => 'Invalid report.' ] );

        // Verify current user can access this report
        if ( ! self::user_can_download( $report_id ) ) {
            wp_send_json_error( [ 'message' => 'Access denied.' ] );
        }

        $link = self::generate_link( $report_id );
        wp_send_json_success( [ 'url' => $link ] );
    }

    public static function user_can_download( $report_id ) {
        $post = get_post( $report_id );
        if ( ! $post ) return false;

        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) return false;

        // Admin (manage_options / administrator role) or partner can always access
        if ( in_array( 'administrator', (array) $user->roles ) || current_user_can( 'manage_options' ) || current_user_can( 'cmi_manage_reports' ) || current_user_can( 'cmi_upload_report' ) ) {
            return true;
        }

        // Prevent patients from accessing draft/replaced reports
        if ( $post->post_status !== 'publish' ) {
            return false;
        }

        $mobile = get_user_meta( $user->ID, 'billing_phone', true );
        if ( ! $mobile ) $mobile = get_user_meta( $user->ID, '_cmi_mobile', true );
        $email  = $user->user_email;

        $report_mobile = get_post_meta( $report_id, '_cmi_patient_mobile', true );
        $report_email  = get_post_meta( $report_id, '_cmi_patient_email', true );
        $report_uid    = get_post_meta( $report_id, '_cmi_patient_uid',    true );
        $user_uid      = get_user_meta( $user->ID, '_cmi_uid', true );

        if ( $mobile && $report_mobile && CMI_CPT::normalize_mobile( $mobile ) === CMI_CPT::normalize_mobile( $report_mobile ) ) return true;
        if ( $email && $report_email && strcasecmp( $email, $report_email ) === 0 ) return true;
        if ( $user_uid && $report_uid && $user_uid === $report_uid ) return true;

        return false;
    }

    private static function log_download( $report_id, $user_id = 0 ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'cmi_download_log', [
            'report_id'     => $report_id,
            'user_id'       => $user_id,
            'ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
            'downloaded_at' => current_time('mysql'),
        ]);
    }
}

CMI_Download::init();
