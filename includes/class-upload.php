<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMI_Upload {

    public static function init() {
        add_action( 'wp_ajax_cmi_upload_report',        [ __CLASS__, 'handle_upload' ] );
        add_action( 'wp_ajax_cmi_upload_prescription',  [ __CLASS__, 'handle_prescription' ] );
    }

    public static function handle_upload() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        if ( ! is_user_logged_in() || ! current_user_can( 'cmi_upload_report' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $mobile = isset( $_POST['patient_mobile'] ) ? preg_replace( '/[^0-9+]/', '', $_POST['patient_mobile'] ) : '';
        $email  = isset( $_POST['patient_email'] )  ? sanitize_email( $_POST['patient_email'] ) : '';
        $uid    = isset( $_POST['patient_uid'] )    ? sanitize_text_field( $_POST['patient_uid'] ) : '';
        $name   = isset( $_POST['patient_name'] )   ? sanitize_text_field( $_POST['patient_name'] ) : '';
        $notes  = isset( $_POST['notes'] )           ? sanitize_textarea_field( $_POST['notes'] ) : '';
        $type   = isset( $_POST['report_type_id'] )  ? absint( $_POST['report_type_id'] ) : 0;

        if ( empty( $mobile ) && empty( $email ) && empty( $uid ) ) {
            wp_send_json_error( [ 'message' => 'Patient mobile number, email, or unique ID is required.' ] );
        }

        if ( empty( $_FILES['report_file'] ) || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => 'Please select a valid file to upload.' ] );
        }

        $file = $_FILES['report_file'];

        // Max 10 MB
        if ( $file['size'] > 10 * 1024 * 1024 ) {
            wp_send_json_error( [ 'message' => 'File size must be under 10 MB.' ] );
        }

        $result = CMI_CPT::save_report([
            'patient_mobile' => $mobile,
            'patient_email'  => $email,
            'patient_uid'    => $uid,
            'patient_name'   => $name,
            'report_type_id' => $type,
            'file_tmp'       => $file['tmp_name'],
            'file_name'      => $file['name'],
            'file_type'      => $file['type'],
            'notes'          => $notes,
            'uploaded_by'    => get_current_user_id(),
            'post_type'      => 'cmi_report',
        ]);

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'message' => 'Report uploaded successfully.', 'report_id' => $result ] );
    }

    public static function handle_prescription() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        if ( ! is_user_logged_in() || ! current_user_can( 'cmi_upload_prescription' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $mobile = isset( $_POST['patient_mobile'] ) ? preg_replace( '/[^0-9+]/', '', $_POST['patient_mobile'] ) : '';
        $email  = isset( $_POST['patient_email'] )  ? sanitize_email( $_POST['patient_email'] ) : '';
        $uid    = isset( $_POST['patient_uid'] )    ? sanitize_text_field( $_POST['patient_uid'] ) : '';
        $name   = isset( $_POST['patient_name'] )   ? sanitize_text_field( $_POST['patient_name'] ) : '';
        $notes  = isset( $_POST['notes'] )           ? sanitize_textarea_field( $_POST['notes'] ) : '';

        if ( empty( $mobile ) && empty( $email ) && empty( $uid ) ) {
            wp_send_json_error( [ 'message' => 'Patient mobile number, email, or unique ID is required.' ] );
        }

        if ( empty( $_FILES['report_file'] ) || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => 'Please select a valid file to upload.' ] );
        }

        $file = $_FILES['report_file'];

        $result = CMI_CPT::save_report([
            'patient_mobile' => $mobile,
            'patient_email'  => $email,
            'patient_uid'    => $uid,
            'patient_name'   => $name,
            'file_tmp'       => $file['tmp_name'],
            'file_name'      => $file['name'],
            'file_type'      => $file['type'],
            'notes'          => $notes,
            'uploaded_by'    => get_current_user_id(),
            'post_type'      => 'cmi_prescription',
        ]);

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'message' => 'Prescription uploaded successfully.', 'report_id' => $result ] );
    }
}

CMI_Upload::init();
