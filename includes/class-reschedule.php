<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CMI_HT_Reschedule {

    public function __construct() {
        // Patient requests reschedule
        add_action( 'wp_ajax_cmi_ht_request_reschedule', [ $this, 'ajax_request_reschedule' ] );

        // Admin actions
        add_action( 'wp_ajax_cmi_ht_approve_reschedule', [ $this, 'ajax_approve_reschedule' ] );
        add_action( 'wp_ajax_cmi_ht_deny_reschedule', [ $this, 'ajax_deny_reschedule' ] );
    }

    /**
     * Patient requests rescheduling for their home collection appointment.
     */
    public function ajax_request_reschedule() {
        if ( ! check_ajax_referer( 'cmi_pp_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Security check failed. Please refresh the page and try again.', 'cmi-home-testing' ) ] );
            wp_die();
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Please log in to request rescheduling.', 'cmi-home-testing' ) ] );
            wp_die();
        }

        $id   = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $date = isset( $_POST['reschedule_date'] ) ? sanitize_text_field( $_POST['reschedule_date'] ) : '';
        $slot = isset( $_POST['reschedule_time_slot'] ) ? sanitize_text_field( $_POST['reschedule_time_slot'] ) : '';

        if ( ! $id || empty( $date ) || empty( $slot ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'All rescheduling fields are required.', 'cmi-home-testing' ) ] );
            wp_die();
        }

        // Validate date and slot (same-day reschedule check with timezone-aware buffer)
        $today = current_time( 'Y-m-d' );
        if ( $date < $today ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Reschedule date cannot be in the past.', 'cmi-home-testing' ) ] );
            wp_die();
        }

        if ( $date === $today ) {
            $parts = explode( '-', $slot );
            $start_str = ! empty( $parts ) ? trim( $parts[0] ) : '';
            $buffer_minutes = absint( get_option( 'cmi_same_day_buffer_minutes', 30 ) );
            $buffer_seconds = $buffer_minutes * 60;
            if ( $start_str ) {
                try {
                    $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( get_option( 'timezone_string' ) ?: 'UTC' );
                    $slot_start_time = new DateTime( $date . ' ' . $start_str, $timezone );
                    $slot_start_timestamp = $slot_start_time->getTimestamp();
                    
                    $current_time = new DateTime( 'now', $timezone );
                    $current_timestamp = $current_time->getTimestamp();
                    
                    $buffer_later = $current_timestamp + $buffer_seconds;
                    if ( $slot_start_timestamp < $buffer_later ) {
                        /* translators: %d: number of minutes */
                        wp_send_json_error( [ 'message' => sprintf( esc_html__( 'Same-day collection reschedules require a minimum of %d minutes lead time. Please select a later time slot or a future date.', 'cmi-home-testing' ), $buffer_minutes ) ] );
                        wp_die();
                    }
                } catch ( Exception $e ) {
                    // Fallback
                    $slot_start_timestamp = strtotime( $today . ' ' . $start_str );
                    $current_timestamp = current_time( 'timestamp' );
                    if ( $slot_start_timestamp < ( $current_timestamp + $buffer_seconds ) ) {
                        /* translators: %d: number of minutes */
                        wp_send_json_error( [ 'message' => sprintf( esc_html__( 'Same-day collection reschedules require a minimum of %d minutes lead time. Please select a later time slot or a future date.', 'cmi-home-testing' ), $buffer_minutes ) ] );
                        wp_die();
                    }
                }
            } else {
                wp_send_json_error( [ 'message' => esc_html__( 'Invalid time slot format.', 'cmi-home-testing' ) ] );
                wp_die();
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';

        // Check if the order belongs to this customer
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Appointment record not found.', 'cmi-home-testing' ) ] );
            wp_die();
        }

        $order = wc_get_order( $row->order_id );
        if ( ! $order || $order->get_customer_id() !== $user_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized request.', 'cmi-home-testing' ) ] );
            wp_die();
        }

        // Save reschedule request (Admin must approve)
        $update = $wpdb->update(
            $table,
            [
                'reschedule_date'      => $date,
                'reschedule_time_slot' => $slot,
                'reschedule_status'    => 'pending',
                'updated_at'           => current_time( 'mysql' )
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $update !== false ) {
            $order = wc_get_order( $row->order_id );
            if ( $order ) {
                $order->add_order_note( sprintf( __( 'Patient requested rescheduling to %s (%s). Pending admin approval.', 'cmi-home-testing' ), $date, $slot ) );
            }
            do_action( 'cmi_testing_reschedule_requested', $id, $date, $slot );
            wp_send_json_success( [ 'message' => esc_html__( 'Reschedule request submitted for admin approval.', 'cmi-home-testing' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Database write failed.', 'cmi-home-testing' ) ] );
        }
        wp_die();
    }

    /**
     * Admin approves reschedule request.
     */
    public function ajax_approve_reschedule() {
        check_ajax_referer( 'cmi_ht_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'cmi_manage_reschedules' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-home-testing' ) ] );
            wp_die();
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid request ID.', 'cmi-home-testing' ) ] );
            wp_die();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND reschedule_status = 'pending'", $id ) );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'No pending reschedule request found for this ID.', 'cmi-home-testing' ) ] );
            wp_die();
        }

        $new_status = ! empty( $row->partner_id ) ? 'rescheduled' : 'pending_assignment';
        $update = $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET collection_date = %s, collection_time_slot = %s, status = %s, reschedule_status = 'approved', reschedule_date = NULL, reschedule_time_slot = NULL, updated_at = %s WHERE id = %d AND reschedule_status = 'pending'",
            $row->reschedule_date,
            $row->reschedule_time_slot,
            $new_status,
            current_time( 'mysql' ),
            $id
        ) );

        if ( $update !== false ) {
            $order = wc_get_order( $row->order_id );
            if ( $order ) {
                $order->update_meta_data( '_cmi_collection_date', $row->reschedule_date );
                $order->update_meta_data( '_cmi_collection_time_slot', $row->reschedule_time_slot );
                $order->add_order_note( sprintf( __( 'Admin approved rescheduling request. New Date: %s, Slot: %s. Status updated to Rescheduled.', 'cmi-home-testing' ), $row->reschedule_date, $row->reschedule_time_slot ) );
                $order->save();
            }

            do_action( 'cmi_testing_reschedule_approved', $id, $row->partner_id, $row );
            wp_send_json_success( [ 'message' => esc_html__( 'Reschedule approved. Status updated to Rescheduled.', 'cmi-home-testing' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Database write failed.', 'cmi-home-testing' ) ] );
        }
        wp_die();
    }

    /**
     * Admin denies reschedule request.
     */
    public function ajax_deny_reschedule() {
        check_ajax_referer( 'cmi_ht_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'cmi_manage_reschedules' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-home-testing' ) ] );
            wp_die();
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid request ID.', 'cmi-home-testing' ) ] );
            wp_die();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND reschedule_status = 'pending'", $id ) );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'No pending reschedule request found.', 'cmi-home-testing' ) ] );
            wp_die();
        }

        $update = $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET reschedule_status = 'rejected', reschedule_date = NULL, reschedule_time_slot = NULL, updated_at = %s WHERE id = %d AND reschedule_status = 'pending'",
            current_time( 'mysql' ),
            $id
        ) );

        if ( $update !== false ) {
            $order = wc_get_order( $row->order_id );
            if ( $order ) {
                $order->add_order_note( __( 'Admin denied rescheduling request.', 'cmi-home-testing' ) );
            }
            do_action( 'cmi_testing_reschedule_denied', $id );
            wp_send_json_success( [ 'message' => esc_html__( 'Reschedule request denied.', 'cmi-home-testing' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Database write failed.', 'cmi-home-testing' ) ] );
        }
        wp_die();
    }
}
