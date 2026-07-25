<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CMI_HT_Partner_Workflow {

    public function __construct() {
        // Ajax actions for accepting and rejecting assignments
        add_action( 'wp_ajax_cmi_ht_partner_accept', [ $this, 'ajax_accept_job' ] );
        add_action( 'wp_ajax_cmi_ht_partner_reject', [ $this, 'ajax_reject_job' ] );
        add_action( 'wp_ajax_cmi_ht_partner_revoke', [ $this, 'ajax_revoke_job' ] );

        // Ajax upload of report PDF
        add_action( 'wp_ajax_cmi_ht_upload_report', [ $this, 'ajax_upload_report' ] );

        // Ajax partner profile update
        add_action( 'wp_ajax_cmi_ht_update_partner_profile', [ $this, 'ajax_update_profile' ] );
    }

    /**
     * Partner accepts assignment.
     */
    public function ajax_accept_job() {
        $this->verify_partner_ajax_request();

        $user_id = get_current_user_id();
        if ( ! $user_id || ! current_user_can( 'cmi_view_assignments' ) ) {
            $user = wp_get_current_user();
            $roles = $user ? implode( ',', (array) $user->roles ) : 'none';
            wp_send_json_error( [ 
                'message' => sprintf(
                    esc_html__( 'Unauthorized access. (User ID: %d, Roles: %s, Cap: %s)', 'cmi-home-testing' ),
                    $user_id,
                    $roles,
                    current_user_can( 'cmi_view_assignments' ) ? 'yes' : 'no'
                )
            ] );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid job ID.', 'cmi-home-testing' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';

        // Verify assignment belongs to this partner
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND partner_id = %d", $id, $user_id ) );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Job record not found or not assigned to you.', 'cmi-home-testing' ) ] );
        }

        $update = $wpdb->update(
            $table,
            [
                'status'     => 'accepted',
                'updated_at' => current_time( 'mysql' )
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( $update !== false ) {
            do_action( 'cmi_testing_assignment_accepted', $id, $user_id );
            wp_send_json_success( [ 'message' => esc_html__( 'Job accepted successfully.', 'cmi-home-testing' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Database update failed.', 'cmi-home-testing' ) ] );
        }
    }

    /**
     * Partner rejects assignment (requires rejection reason).
     */
    public function ajax_reject_job() {
        $this->verify_partner_ajax_request();

        $user_id = get_current_user_id();
        if ( ! $user_id || ! current_user_can( 'cmi_view_assignments' ) ) {
            $user = wp_get_current_user();
            $roles = $user ? implode( ',', (array) $user->roles ) : 'none';
            wp_send_json_error( [ 
                'message' => sprintf(
                    esc_html__( 'Unauthorized access. (User ID: %d, Roles: %s, Cap: %s)', 'cmi-home-testing' ),
                    $user_id,
                    $roles,
                    current_user_can( 'cmi_view_assignments' ) ? 'yes' : 'no'
                )
            ] );
        }

        $id     = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( $_POST['reason'] ) : '';

        if ( ! $id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid job ID.', 'cmi-home-testing' ) ] );
        }

        if ( empty( $reason ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Rejection reason is required.', 'cmi-home-testing' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';

        // Verify assignment belongs to this partner
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND partner_id = %d", $id, $user_id ) );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Job record not found or not assigned to you.', 'cmi-home-testing' ) ] );
        }

        // Return status back to 'pending_assignment' and clear partner_id so admin can reassign
        $update = $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET partner_id = NULL, status = 'pending_assignment', rejection_reason = %s, updated_at = %s WHERE id = %d",
            $reason,
            current_time( 'mysql' ),
            $id
        ) );

        if ( $update !== false ) {
            do_action( 'cmi_testing_assignment_rejected', $id, $user_id, $reason );
            wp_send_json_success( [ 'message' => esc_html__( 'Job assignment rejected and returned to queue.', 'cmi-home-testing' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Database update failed.', 'cmi-home-testing' ) ] );
        }
    }

    /**
     * Partner revokes accepted assignment.
     */
    public function ajax_revoke_job() {
        $this->verify_partner_ajax_request();

        $user_id = get_current_user_id();
        if ( ! $user_id || ! current_user_can( 'cmi_view_assignments' ) ) {
            $user = wp_get_current_user();
            $roles = $user ? implode( ',', (array) $user->roles ) : 'none';
            wp_send_json_error( [ 
                'message' => sprintf(
                    esc_html__( 'Unauthorized access. (User ID: %d, Roles: %s, Cap: %s)', 'cmi-home-testing' ),
                    $user_id,
                    $roles,
                    current_user_can( 'cmi_view_assignments' ) ? 'yes' : 'no'
                )
            ] );
        }

        $id     = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( $_POST['reason'] ) : '';

        if ( ! $id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid job ID.', 'cmi-home-testing' ) ] );
        }

        if ( empty( $reason ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Reason for revocation is required.', 'cmi-home-testing' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';

        // Verify assignment belongs to this partner and is currently accepted or rescheduled
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND partner_id = %d AND status IN ('accepted', 'rescheduled')", $id, $user_id ) );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Job record not found or cannot be revoked.', 'cmi-home-testing' ) ] );
        }

        // Return status back to 'pending_assignment' and clear partner_id so admin can reassign
        $update = $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET partner_id = NULL, status = 'pending_assignment', rejection_reason = %s, updated_at = %s WHERE id = %d AND status IN ('accepted', 'rescheduled')",
            sprintf( '[Revoked] %s', $reason ),
            current_time( 'mysql' ),
            $id
        ) );

        if ( $update !== false ) {
            do_action( 'cmi_testing_assignment_revoked', $id, $user_id, $reason );
            wp_send_json_success( [ 'message' => esc_html__( 'Job acceptance successfully revoked and returned to queue.', 'cmi-home-testing' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Database update failed.', 'cmi-home-testing' ) ] );
        }
    }

    /**
     * Upload collection report PDF securely (automating CMI Partner Portal integration).
     */
    public function ajax_upload_report() {
        $this->verify_partner_ajax_request();

        $user_id = get_current_user_id();
        if ( ! $user_id || ! current_user_can( 'cmi_view_assignments' ) ) {
            $user = wp_get_current_user();
            $roles = $user ? implode( ',', (array) $user->roles ) : 'none';
            wp_send_json_error( [ 
                'message' => sprintf(
                    esc_html__( 'Unauthorized access. (User ID: %d, Roles: %s, Cap: %s)', 'cmi-home-testing' ),
                    $user_id,
                    $roles,
                    current_user_can( 'cmi_view_assignments' ) ? 'yes' : 'no'
                )
            ] );
        }

        $id             = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $report_type_id = isset( $_POST['report_type_id'] ) ? intval( $_POST['report_type_id'] ) : 0;
        $notes          = isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : '';

        if ( ! $id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid job ID.', 'cmi-home-testing' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';

        // Verify assignment belongs to this partner and is accepted, rescheduled, or completed
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND partner_id = %d AND status IN ('accepted', 'rescheduled', 'completed')", $id, $user_id ) );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Job record not found or not in valid status for report upload.', 'cmi-partner-portal' ) ] );
        }

        if ( empty( $_FILES['report_file'] ) || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Please select a valid report PDF file.', 'cmi-home-testing' ) ] );
        }

        $file = $_FILES['report_file'];

        // File validation (must be PDF)
        $file_type = wp_check_filetype( $file['name'] );
        if ( $file_type['ext'] !== 'pdf' || $file_type['type'] !== 'application/pdf' ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Only PDF report files are allowed.', 'cmi-home-testing' ) ] );
        }

        // Fetch Patient details from Order (HPOS compatible)
        $order = wc_get_order( $row->order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => esc_html__( 'WooCommerce order data not found.', 'cmi-home-testing' ) ] );
        }

        // Auto-complete WooCommerce order since report has been uploaded
        $order->update_status( 'completed', esc_html__( 'Collection completed and report uploaded by partner.', 'cmi-home-testing' ) );

        $patient_name  = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $patient_phone = $order->get_billing_phone();
        $patient_email = $order->get_billing_email();
        
        // Fetch patient unique ID if stored
        $patient_uid   = get_user_meta( $order->get_customer_id(), '_cmi_patient_uid', true );

        $secure_filename = '';

        // Integration with CMI CPT handler in sibling plugin
        if ( class_exists( 'CMI_CPT' ) ) {
            // Save report via sibling API (stores in cmi-secure-reports and registers post)
            $result = CMI_CPT::save_report([
                'patient_mobile' => $patient_phone,
                'patient_email'  => $patient_email,
                'patient_uid'    => $patient_uid,
                'patient_name'   => $patient_name,
                'report_type_id' => $report_type_id,
                'file_tmp'       => $file['tmp_name'],
                'file_name'      => $file['name'],
                'file_type'      => $file['type'],
                'notes'          => $notes,
                'uploaded_by'    => $user_id,
                'post_type'      => 'cmi_report',
            ]);

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ] );
            }

            // Retrieve the unique filename generated by the companion plugin
            $secure_filename = get_post_meta( $result, '_cmi_file_name', true );
        } else {
            // Standalone Fallback Upload Handler (If sibling CPT is not active)
            $secure_dir = WP_CONTENT_DIR . '/cmi-secure-reports';
            if ( ! file_exists( $secure_dir ) ) {
                wp_mkdir_p( $secure_dir );
                file_put_contents( $secure_dir . '/.htaccess', "Options -Indexes\nDeny from all\n" );
                file_put_contents( $secure_dir . '/index.php', '<?php // silence' );
            }

            $secure_filename = 'report_' . $row->order_id . '_' . time() . '.pdf';
            $destination = $secure_dir . '/' . $secure_filename;

            if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Failed to save uploaded file to disk.', 'cmi-home-testing' ) ] );
            }
        }

        // Fetch old report details if re-uploading to version control it
        $old_report_pdf = $row->report_pdf;
        $old_post_id = 0;
        if ( ! empty( $old_report_pdf ) ) {
            $old_post_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cmi_file_name' AND meta_value = %s LIMIT 1",
                $old_report_pdf
            ) );
        }

        // Save filename/relative path in custom DB table and complete job
        $update = $wpdb->update(
            $table,
            [
                'status'     => 'completed',
                'report_pdf' => $secure_filename,
                'updated_at' => current_time( 'mysql' )
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $update !== false ) {
            $order = wc_get_order( $row->order_id );
            if ( $order ) {
                $order->add_order_note( __( 'Test report PDF successfully uploaded by partner. Home collection completed.', 'cmi-partner-portal' ) );
            }

            // Version control and audit trail if this is a replacement/reupload
            if ( $old_post_id && $result && $old_post_id !== $result ) {
                // 1. Mark previous report as draft (inactive but retained in DB)
                wp_update_post([
                    'ID'          => $old_post_id,
                    'post_status' => 'draft'
                ]);

                // 2. Write metadata links for audit trail
                update_post_meta( $result, '_cmi_replaces_report', $old_post_id );
                update_post_meta( $old_post_id, '_cmi_replaced_by', $result );

                // 3. Trigger corrected report action (notifies patient and admin)
                do_action( 'cmi_testing_report_corrected', $row->order_id, $result, $old_post_id );
            } else {
                // Standard initial upload
                do_action( 'cmi_testing_report_uploaded', $id, WP_CONTENT_DIR . '/cmi-secure-reports/' . $secure_filename );
            }

            wp_send_json_success( [ 'message' => esc_html__( 'Report PDF uploaded and integrated successfully.', 'cmi-partner-portal' ) ] );
        } else {
            // Clean up file if db write failed
            @unlink( WP_CONTENT_DIR . '/cmi-secure-reports/' . $secure_filename );
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to update database record.', 'cmi-partner-portal' ) ] );
        }
    }

    /**
     * Update partner profile details.
     */
    public function ajax_update_profile() {
        $this->verify_partner_ajax_request();

        $user_id = get_current_user_id();
        $is_doctor = in_array( 'cmi_doctor', (array) wp_get_current_user()->roles );
        if ( ! $user_id || ( ! current_user_can( 'cmi_view_assignments' ) && ! $is_doctor && ! current_user_can( 'manage_options' ) ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-home-testing' ) ] );
        }

        $display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( $_POST['display_name'] ) : '';
        $org          = isset( $_POST['org'] ) ? sanitize_text_field( $_POST['org'] ) : '';
        $mobile       = isset( $_POST['mobile'] ) ? preg_replace( '/[^0-9+]/', '', $_POST['mobile'] ) : '';
        $license      = isset( $_POST['license'] ) ? sanitize_text_field( $_POST['license'] ) : '';

        if ( empty( $display_name ) || empty( $mobile ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Name and Mobile fields are required.', 'cmi-home-testing' ) ] );
        }

        // Update user display name and metadata
        $result = wp_update_user( [
            'ID'           => $user_id,
            'display_name' => $display_name,
            'first_name'   => $display_name,
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        update_user_meta( $user_id, '_cmi_org', $org );
        update_user_meta( $user_id, '_cmi_mobile', $mobile );
        update_user_meta( $user_id, '_cmi_license', $license );

        if ( in_array( 'cmi_doctor', (array) wp_get_current_user()->roles ) ) {
            $specialty = isset( $_POST['specialty'] ) ? sanitize_text_field( $_POST['specialty'] ) : '';
            $fee       = isset( $_POST['consultation_fee'] ) ? sanitize_text_field( $_POST['consultation_fee'] ) : '';
            update_user_meta( $user_id, '_cmi_specialty', $specialty );
            update_user_meta( $user_id, '_cmi_consultation_fee', $fee );

            // Save biography description
            wp_update_user( [
                'ID'          => $user_id,
                'description' => sanitize_textarea_field( $_POST['description'] ?? '' ),
            ] );
        }

        wp_send_json_success( [ 'message' => esc_html__( 'Profile updated successfully.', 'cmi-home-testing' ) ] );
    }

    /**
     * Detect report type based on order product names.
     */
    public static function detect_report_type_by_order_items( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return '';
        }

        $items = $order->get_items();
        foreach ( $items as $item ) {
            $product_name = trim( strip_tags( $item->get_name() ) );
            if ( empty( $product_name ) ) {
                continue;
            }

            // Ensure taxonomy exists
            if ( ! taxonomy_exists( 'cmi_report_type' ) ) {
                continue;
            }

            // Check if the term already exists
            $term = get_term_by( 'name', $product_name, 'cmi_report_type' );
            if ( $term ) {
                return $term->term_id;
            }

            // If it doesn't exist, create it automatically
            $inserted = wp_insert_term( $product_name, 'cmi_report_type' );
            if ( ! is_wp_error( $inserted ) ) {
                return intval( $inserted['term_id'] );
            }
        }

        // Fallback: return "Other"
        if ( taxonomy_exists( 'cmi_report_type' ) ) {
            $term = get_term_by( 'name', 'Other', 'cmi_report_type' );
            if ( $term ) {
                return $term->term_id;
            }
        }

        return '';
    }

    /**
     * Verify partner AJAX request nonce.
     */
    private function verify_partner_ajax_request() {
        if ( ! check_ajax_referer( 'cmi_pp_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Security check failed. Please refresh the page and try again.', 'cmi-partner-portal' ) ] );
        }
    }
}
