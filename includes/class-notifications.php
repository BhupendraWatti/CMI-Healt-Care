<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CMI_HT_Notifications {

    public function __construct() {
        // Direct workflow hooks schedule background cron events
        add_action( 'cmi_testing_partner_assigned', [ $this, 'schedule_partner_assigned' ], 10, 3 );
        add_action( 'cmi_testing_assignment_accepted', [ $this, 'schedule_admin_accepted' ], 10, 2 );
        add_action( 'cmi_testing_assignment_accepted', [ $this, 'schedule_patient_accepted' ], 10, 2 );
        add_action( 'cmi_testing_assignment_rejected', [ $this, 'schedule_admin_rejected' ], 10, 3 );
        add_action( 'cmi_testing_assignment_revoked', [ $this, 'schedule_partner_revoked' ], 10, 3 );
        add_action( 'cmi_testing_report_uploaded', [ $this, 'schedule_patient_report_ready' ], 10, 2 );
        add_action( 'cmi_testing_reschedule_requested', [ $this, 'schedule_admin_reschedule' ], 10, 3 );
        add_action( 'cmi_testing_reschedule_requested', [ $this, 'schedule_partner_reschedule_requested' ], 10, 3 );
        add_action( 'cmi_testing_reschedule_approved', [ $this, 'schedule_partner_reschedule_approved' ], 10, 3 );
        add_action( 'cmi_testing_report_corrected', [ $this, 'notify_report_corrected' ], 10, 3 );

        // Background Cron handlers
        add_action( 'cmi_deferred_notify_partner_assigned', [ $this, 'notify_partner_assigned' ], 10, 3 );
        add_action( 'cmi_deferred_notify_admin_accepted', [ $this, 'notify_admin_accepted' ], 10, 2 );
        add_action( 'cmi_deferred_notify_patient_accepted', [ $this, 'notify_patient_accepted' ], 10, 2 );
        add_action( 'cmi_deferred_notify_admin_rejected', [ $this, 'notify_admin_rejected' ], 10, 3 );
        add_action( 'cmi_deferred_notify_partner_revoked', [ $this, 'notify_partner_revoked' ], 10, 3 );
        add_action( 'cmi_deferred_notify_patient_report_ready', [ $this, 'notify_patient_report_ready' ], 10, 2 );
        add_action( 'cmi_deferred_notify_admin_reschedule', [ $this, 'notify_admin_reschedule' ], 10, 3 );
        add_action( 'cmi_deferred_notify_partner_reschedule_requested', [ $this, 'notify_partner_reschedule_requested' ], 10, 3 );
        add_action( 'cmi_deferred_notify_partner_reschedule_approved', [ $this, 'notify_partner_reschedule_approved' ], 10, 3 );

        // Doctor Consultation hooks
        add_action( 'cmi_consultation_requested', [ $this, 'schedule_consultation_requested' ], 10, 1 );
        add_action( 'cmi_consultation_assigned', [ $this, 'schedule_consultation_assigned' ], 10, 2 );
        add_action( 'cmi_consultation_scheduled', [ $this, 'schedule_consultation_scheduled' ], 10, 1 );
        add_action( 'cmi_consultation_completed', [ $this, 'schedule_consultation_completed' ], 10, 1 );
        add_action( 'cmi_consultation_cancelled', [ $this, 'schedule_consultation_cancelled' ], 10, 1 );
        add_action( 'cmi_consultation_missed', [ $this, 'schedule_consultation_missed' ], 10, 1 );
        add_action( 'cmi_consultation_rescheduled_by_admin', [ $this, 'schedule_consultation_rescheduled_by_admin' ], 10, 1 );

        // Background Cron handlers for consultations
        add_action( 'cmi_deferred_notify_consultation_requested', [ $this, 'notify_consultation_requested' ], 10, 1 );
        add_action( 'cmi_deferred_notify_consultation_assigned', [ $this, 'notify_consultation_assigned' ], 10, 2 );
        add_action( 'cmi_deferred_notify_consultation_scheduled', [ $this, 'notify_consultation_scheduled' ], 10, 1 );
        add_action( 'cmi_deferred_notify_consultation_completed', [ $this, 'notify_consultation_completed' ], 10, 1 );
        add_action( 'cmi_deferred_notify_consultation_cancelled', [ $this, 'notify_consultation_cancelled' ], 10, 1 );
        add_action( 'cmi_deferred_notify_consultation_missed', [ $this, 'notify_consultation_missed' ], 10, 1 );
        add_action( 'cmi_deferred_notify_consultation_rescheduled_by_admin', [ $this, 'notify_consultation_rescheduled_by_admin' ], 10, 1 );
        add_action( 'cmi_consultation_needs_reschedule', [ $this, 'schedule_consultation_needs_reschedule' ], 10, 1 );
        add_action( 'cmi_deferred_notify_consultation_needs_reschedule', [ $this, 'notify_consultation_needs_reschedule' ], 10, 1 );
 
        // Cron action for generic deferred email sending
        add_action( 'cmi_send_deferred_email_cron', [ $this, 'send_deferred_email_cron_handler' ], 10, 4 );
    }

    public function schedule_partner_assigned( $id, $partner_id, $is_rescheduled = false ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_partner_assigned', [ $id, $partner_id, $is_rescheduled ] );
    }

    public function schedule_admin_accepted( $id, $partner_id ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_admin_accepted', [ $id, $partner_id ] );
    }

    public function schedule_patient_accepted( $id, $partner_id ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_patient_accepted', [ $id, $partner_id ] );
    }

    public function schedule_admin_rejected( $id, $partner_id, $reason ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_admin_rejected', [ $id, $partner_id, $reason ] );
    }

    public function schedule_partner_revoked( $id, $partner_id, $reason ) {
        // Send notifications directly to bypass slow cron loopbacks and ensure immediate delivery
        $this->notify_partner_revoked( $id, $partner_id, $reason );
        $this->notify_patient_revoked( $id, $partner_id, $reason );
    }


    public function schedule_patient_report_ready( $id, $file_path ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_patient_report_ready', [ $id, $file_path ] );
    }

    public function schedule_admin_reschedule( $id, $date, $slot ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_admin_reschedule', [ $id, $date, $slot ] );
    }

    public function schedule_partner_reschedule_requested( $id, $date, $slot ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_partner_reschedule_requested', [ $id, $date, $slot ] );
    }

    public function schedule_partner_reschedule_approved( $id, $previous_partner_id = 0, $row_array = null ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_partner_reschedule_approved', [ $id, $previous_partner_id, $row_array ] );
    }

    /**
     * Shared HTML email wrapper.
     */
    public function get_html_email_template( $title, $body_content ) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f7fafc; color: #2d3748; margin: 0; padding: 0; }
                .email-wrapper { width: 100%; background-color: #f7fafc; padding: 30px 0; }
                .email-content { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.02); }
                .email-header { background-color: #1a4f8a; padding: 25px; text-align: center; color: #ffffff; }
                .email-header h1 { margin: 0; font-size: 22px; font-weight: 700; }
                .email-body { padding: 30px 25px; line-height: 1.6; font-size: 15px; }
                .email-footer { background-color: #edf2f7; padding: 15px 25px; text-align: center; font-size: 12px; color: #718096; border-top: 1px solid #e2e8f0; }
                .button { display: inline-block; padding: 10px 20px; background-color: #1a4f8a; color: #ffffff !important; text-decoration: none; border-radius: 4px; font-weight: bold; margin-top: 15px; }
                .details-table { width: 100%; border-collapse: collapse; margin-top: 20px; margin-bottom: 20px; }
                .details-table td { padding: 10px; border-bottom: 1px solid #edf2f7; font-size: 14px; }
                .details-table td.label { font-weight: bold; color: #4a5568; width: 35%; }
            </style>
        </head>
        <body>
            <div class="email-wrapper">
                <div class="email-content">
                    <div class="email-header">
                        <h1><?php echo esc_html( $title ); ?></h1>
                    </div>
                    <div class="email-body">
                        <?php echo $body_content; ?>
                    </div>
                    <div class="email-footer">
                        &copy; <?php echo date('Y'); ?> CMI Healthcare. All rights reserved.
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Send HTML email to partner notifying them of a new assignment.
     */
    public function notify_partner_assigned( $id, $partner_id, $is_rescheduled = false ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $partner = get_userdata( $partner_id );
        if ( ! $partner ) return;

        // Fetch patient details from Order
        $order = wc_get_order( $row->order_id );
        if ( ! $order ) return;

        $patient_name   = $order->get_meta( '_cmi_patient_name' ) ?: ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $patient_phone  = $order->get_meta( '_cmi_patient_mobile' ) ?: $order->get_billing_phone();
        $patient_gender = $order->get_meta( '_cmi_patient_gender' ) ?: 'Unspecified';
        $patient_dob    = $order->get_meta( '_cmi_patient_dob' ) ?: '—';
        $patient_rel    = $order->get_meta( '_cmi_patient_relationship' ) ?: 'Self';

        $partner_name = $partner->display_name;
        $note = $is_rescheduled 
            ? sprintf( __( 'Home collection rescheduled and assigned to partner: %s.', 'cmi-partner-portal' ), $partner_name )
            : sprintf( __( 'Home collection assigned to partner: %s.', 'cmi-partner-portal' ), $partner_name );
        $order->add_order_note( $note );

        $to = $partner->user_email;
        $subject = $is_rescheduled
            ? sprintf( __( 'Rescheduled Home Collection Job Assigned for %s: #%d', 'cmi-home-testing' ), $patient_name, $row->order_id )
            : sprintf( __( 'New Home Collection Job Assigned for %s: #%d', 'cmi-home-testing' ), $patient_name, $row->order_id );

        $address = str_replace('<br/>', ', ', $order->get_formatted_shipping_address());
        if ( empty( $address ) ) {
            $address = str_replace('<br/>', ', ', $order->get_formatted_billing_address());
        }

        $intro_text = $is_rescheduled
            ? __( 'You have been assigned a rescheduled home collection job. Please review the details below:', 'cmi-home-testing' )
            : __( 'You have been assigned a new medical home collection job. Please review the details below:', 'cmi-home-testing' );

        $body_content = sprintf(
            __( "<p>Hello %s,</p>
            <p>%s</p>
            <table class='details-table'>
                <tr><td class='label'>Order ID</td><td>#%d</td></tr>
                <tr><td class='label'>Patient Name</td><td>%s</td></tr>
                <tr><td class='label'>Relationship</td><td>%s</td></tr>
                <tr><td class='label'>Gender / DOB</td><td>%s / %s</td></tr>
                <tr><td class='label'>Phone</td><td>%s</td></tr>
                <tr><td class='label'>Collection Address</td><td>%s</td></tr>
                <tr><td class='label'>Scheduled Date</td><td>%s</td></tr>
                <tr><td class='label'>Time Slot</td><td>%s</td></tr>
            </table>
            <p style='text-align: center;'>
                <a href='%s' class='button'>Go to Partner Portal</a>
            </p>", 'cmi-home-testing' ),
            esc_html( $partner->display_name ),
            $intro_text,
            $row->order_id,
            esc_html( $patient_name ),
            esc_html( $patient_rel ),
            esc_html( $patient_gender ),
            esc_html( $patient_dob ),
            esc_html( $patient_phone ),
            wp_kses_post( $address ),
            esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->collection_date ) ) ),
            esc_html( $row->collection_time_slot ),
            esc_url( home_url( '/partner-dashboard/' ) )
        );

        $email_title = $is_rescheduled ? __( 'Rescheduled Home Collection Job', 'cmi-home-testing' ) : __( 'New Home Collection Job', 'cmi-home-testing' );
        $html_message = $this->get_html_email_template( $email_title, $body_content );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $to, $subject, $html_message, $headers );

        // Transactional SMS trigger to Partner
        $partner_phone = get_user_meta( $partner_id, '_cmi_mobile', true ) ?: get_user_meta( $partner_id, 'billing_phone', true );
        if ( class_exists( 'CMI_SMS_Manager' ) && ! empty( $partner_phone ) ) {
            CMI_SMS_Manager::send_event_sms( 'partner_assigned', $partner_phone, [
                'partner_name' => $partner->display_name,
                'patient_name' => $patient_name,
                'order_id'     => $row->order_id,
                'date'         => $row->collection_date,
                'slot'         => $row->collection_time_slot
            ] );
        }
    }

    /**
     * Notify Admin that partner accepted.
     */
    public function notify_admin_accepted( $id, $partner_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $order = wc_get_order( $row->order_id );
        if ( ! $order ) return;

        $patient_name = $order->get_meta( '_cmi_patient_name' ) ?: ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        $order->add_order_note( __( 'Home collection assignment accepted by partner.', 'cmi-partner-portal' ) );

        $partner = get_userdata( $partner_id );
        $partner_name = $partner ? $partner->display_name : 'Partner';
        $partner_org = $partner ? get_user_meta( $partner_id, '_cmi_org', true ) : '';
        $partner_display = ! empty( $partner_org ) ? $partner_org : $partner_name;

        $to = get_option( 'admin_email' );
        $subject = sprintf( __( 'Partner Accepted Job for %s: #%d', 'cmi-home-testing' ), $patient_name, $row->order_id );

        $body_content = sprintf(
            __( "<p>Admin,</p>
            <p>Partner <strong>%s</strong> has accepted the home collection job for <strong>%s</strong> (Order <strong>#%d</strong>).</p>
            <table class='details-table'>
                <tr><td class='label'>Order ID</td><td>#%d</td></tr>
                <tr><td class='label'>Patient Name</td><td>%s</td></tr>
                <tr><td class='label'>Partner</td><td>%s</td></tr>
                <tr><td class='label'>Collection Date</td><td>%s</td></tr>
                <tr><td class='label'>Time Slot</td><td>%s</td></tr>
            </table>", 'cmi-home-testing' ),
            esc_html( $partner_display ),
            esc_html( $patient_name ),
            $row->order_id,
            $row->order_id,
            esc_html( $patient_name ),
            esc_html( $partner_display ),
            esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->collection_date ) ) ),
            esc_html( $row->collection_time_slot )
        );

        $html_message = $this->get_html_email_template( __( 'Partner Accepted Assignment', 'cmi-home-testing' ), $body_content );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $to, $subject, $html_message, $headers );
    }

    /**
     * Notify Patient that the partner has accepted and confirmed their assignment.
     */
    public function notify_patient_accepted( $id, $partner_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $order = wc_get_order( $row->order_id );
        if ( ! $order ) return;

        $patient_name = $order->get_meta( '_cmi_patient_name' ) ?: ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        $partner = get_userdata( $partner_id );
        $partner_name = $partner ? $partner->display_name : '';
        $partner_org = $partner ? get_user_meta( $partner_id, '_cmi_org', true ) : '';
        $partner_display = ! empty( $partner_org ) ? $partner_org : $partner_name;
        $partner_phone = $partner ? get_user_meta( $partner_id, '_cmi_mobile', true ) : '';

        $to = $order->get_billing_email();
        $subject = sprintf( __( 'Home Collection Confirmed for %s: Order #%d', 'cmi-home-testing' ), $patient_name, $row->order_id );

        $body_content = sprintf(
            __( "<p>Hello %s,</p>
            <p>The home collection appointment for <strong>%s</strong> (Order <strong>#%d</strong>) has been confirmed. A medical partner has accepted the assignment and will visit the scheduled collection address.</p>
            <h3>Assigned Partner Details:</h3>
            <table class='details-table'>
                <tr><td class='label'>Assigned Partner</td><td>%s</td></tr>
                <tr><td class='label'>Phone Number</td><td>%s</td></tr>
                <tr><td class='label'>Schedule Date</td><td>%s</td></tr>
                <tr><td class='label'>Time Slot</td><td>%s</td></tr>
            </table>
            <p>Please ensure the patient is available at the collection address during the chosen time slot. If you need to reschedule, you can do so from your account dashboard.</p>
            <p style='text-align: center;'>
                <a href='%s' class='button'>View Dashboard</a>
            </p>", 'cmi-home-testing' ),
            esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            esc_html( $patient_name ),
            $row->order_id,
            esc_html( $partner_display ),
            esc_html( $partner_phone ? : '-' ),
            esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->collection_date ) ) ),
            esc_html( $row->collection_time_slot ),
            esc_url( wc_get_page_permalink( 'myaccount' ) )
        );

        $html_message = $this->get_html_email_template( __( 'Home Collection Confirmed', 'cmi-home-testing' ), $body_content );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $to, $subject, $html_message, $headers );

        // Transactional SMS trigger to Patient
        $patient_mobile = $order->get_meta( '_cmi_patient_mobile' ) ?: $order->get_billing_phone();
        if ( class_exists( 'CMI_SMS_Manager' ) && ! empty( $patient_mobile ) ) {
            CMI_SMS_Manager::send_event_sms( 'partner_accepted', $patient_mobile, [
                'name'     => $patient_name,
                'order_id' => $row->order_id,
                'date'     => $row->collection_date,
                'slot'     => $row->collection_time_slot,
                'partner'  => $partner_display
            ] );
        }
    }

    /**
     * Notify Admin that partner rejected the assignment.
     */
    public function notify_admin_rejected( $id, $partner_id, $reason ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $order = wc_get_order( $row->order_id );
        if ( ! $order ) return;

        $patient_name = $order->get_meta( '_cmi_patient_name' ) ?: ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        $order->add_order_note( sprintf( __( 'Home collection assignment rejected by partner. Reason: %s', 'cmi-partner-portal' ), $reason ) );

        $partner = get_userdata( $partner_id );
        $partner_name = $partner ? $partner->display_name : 'Partner';
        $partner_org = $partner ? get_user_meta( $partner_id, '_cmi_org', true ) : '';
        $partner_display = ! empty( $partner_org ) ? $partner_org : $partner_name;

        $to = get_option( 'admin_email' );
        $subject = sprintf( __( 'Job Assignment for %s Rejected: Order #%d', 'cmi-home-testing' ), $patient_name, $row->order_id );

        $body_content = sprintf(
            __( "<p>Admin,</p>
            <p>Partner <strong>%s</strong> has rejected the collection assignment for <strong>%s</strong> (Order <strong>#%d</strong>).</p>
            <table class='details-table'>
                <tr><td class='label'>Order ID</td><td>#%d</td></tr>
                <tr><td class='label'>Patient Name</td><td>%s</td></tr>
                <tr><td class='label'>Partner</td><td>%s</td></tr>
                <tr><td class='label'>Rejection Reason</td><td>%s</td></tr>
            </table>
            <p>The order has been returned to the pending assignment queue.</p>", 'cmi-home-testing' ),
            esc_html( $partner_display ),
            $row->order_id,
            $row->order_id,
            esc_html( $patient_name ),
            esc_html( $partner_display ),
            esc_html( $reason )
        );

        $html_message = $this->get_html_email_template( __( 'Job Assignment Rejected', 'cmi-home-testing' ), $body_content );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $to, $subject, $html_message, $headers );
    }

    /**
     * Notify Admin that partner revoked acceptance.
     */
    public function notify_partner_revoked( $id, $partner_id, $reason ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $order = wc_get_order( $row->order_id );
        if ( ! $order ) return;

        $patient_name = $order->get_meta( '_cmi_patient_name' ) ?: ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        $order->add_order_note( sprintf( __( 'Home collection acceptance revoked by partner. Reason: %s. Job returned to pending queue.', 'cmi-partner-portal' ), $reason ) );

        $partner = get_userdata( $partner_id );
        $partner_name = $partner ? $partner->display_name : 'Partner';
        $partner_org = $partner ? get_user_meta( $partner_id, '_cmi_org', true ) : '';
        $partner_display = ! empty( $partner_org ) ? $partner_org : $partner_name;

        $to = get_option( 'admin_email' );
        $subject = sprintf( __( 'Job Assignment for %s Revoked: Order #%d', 'cmi-home-testing' ), $patient_name, $row->order_id );

        $body_content = sprintf(
            __( "<p>Admin,</p>
            <p>Partner <strong>%s</strong> has revoked their acceptance of the collection assignment for <strong>%s</strong> (Order <strong>#%d</strong>).</p>
            <table class='details-table'>
                <tr><td class='label'>Order ID</td><td>#%d</td></tr>
                <tr><td class='label'>Patient Name</td><td>%s</td></tr>
                <tr><td class='label'>Partner</td><td>%s</td></tr>
                <tr><td class='label'>Revocation Reason</td><td>%s</td></tr>
            </table>
            <p>The order has been returned to the pending assignment queue so you can select another partner.</p>", 'cmi-home-testing' ),
            esc_html( $partner_display ),
            $row->order_id,
            $row->order_id,
            esc_html( $patient_name ),
            esc_html( $partner_display ),
            esc_html( $reason )
        );

        $html_message = $this->get_html_email_template( __( 'Job Assignment Revoked', 'cmi-home-testing' ), $body_content );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $to, $subject, $html_message, $headers );
    }

    /**
     * Send email to patient when report is uploaded.
     */
    public function notify_patient_report_ready( $id, $file_path ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $order = wc_get_order( $row->order_id );
        if ( ! $order ) return;

        $patient_name = $order->get_meta( '_cmi_patient_name' ) ?: ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        $to = $order->get_billing_email();

        $items = $order->get_items();
        $product_names = [];
        foreach ( $items as $item ) {
            $product_names[] = $item->get_name();
        }
        $test_name = ! empty( $product_names ) ? implode( ', ', $product_names ) : __( 'Test', 'cmi-home-testing' );

        $subject = sprintf( __( 'Report Ready for %s (%s): Order #%d', 'cmi-home-testing' ), $patient_name, $test_name, $row->order_id );

        $body_content = sprintf(
            __( "<p>Hello %s,</p>
            <p>The medical report for <strong>%s</strong> regarding the <strong>%s</strong> (Order <strong>#%d</strong>) has been uploaded and is ready for download.</p>
            <p>You can view and download the report directly from your account dashboard under WooCommerce My Account > Orders tab, or by visiting our Guest Report Download page.</p>
            <p style='text-align: center;'>
                <a href='%s' class='button'>Go to Dashboard</a>
            </p>", 'cmi-home-testing' ),
            esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            esc_html( $patient_name ),
            esc_html( $test_name ),
            $row->order_id,
            esc_url( wc_get_page_permalink( 'myaccount' ) )
        );

        $html_message = $this->get_html_email_template( __( 'Report Ready for Download', 'cmi-home-testing' ), $body_content );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $to, $subject, $html_message, $headers );

        // Transactional SMS trigger to Patient
        $patient_mobile = $order->get_meta( '_cmi_patient_mobile' ) ?: $order->get_billing_phone();
        if ( class_exists( 'CMI_SMS_Manager' ) && ! empty( $patient_mobile ) ) {
            CMI_SMS_Manager::send_event_sms( 'report_uploaded', $patient_mobile, [
                'name'     => $patient_name,
                'order_id' => $row->order_id,
                'test'     => $test_name
            ] );
        }
    }

    /**
     * Notify Patient and Admin that a corrected report has been uploaded.
     */
    public function notify_report_corrected( $order_id, $new_post_id, $old_post_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $patient_name = $order->get_meta( '_cmi_patient_name' ) ?: ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        $partner_id = get_current_user_id();

        $items = $order->get_items();
        $product_names = [];
        foreach ( $items as $item ) {
            $product_names[] = $item->get_name();
        }
        $test_name = ! empty( $product_names ) ? implode( ', ', $product_names ) : __( 'Test', 'cmi-home-testing' );

        // 1. Notify Patient
        $to_patient = $order->get_billing_email();
        if ( ! empty( $to_patient ) ) {
            $subject_patient = sprintf( __( 'Corrected %s Report Uploaded for %s: Order #%d', 'cmi-home-testing' ), $test_name, $patient_name, $order_id );
            
            $body_patient = sprintf(
                __( "<p>Hello %s,</p>
                <p>A corrected report for <strong>%s</strong> (Order <strong>#%d</strong>) has been uploaded for the patient <strong>%s</strong>.</p>
                <p style='color:#e53e3e; font-weight:600; background:#fff5f5; padding:12px; border-left:4px solid #e53e3e; border-radius:4px;'>
                    A corrected report has been uploaded. The previous report has been replaced and should be discarded for privacy and accuracy reasons.
                </p>
                <p>You can view and download the corrected report directly from your account dashboard.</p>
                <p style='text-align: center;'>
                    <a href='%s' class='button'>Go to Dashboard</a>
                </p>", 'cmi-home-testing' ),
                esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                esc_html( $test_name ),
                $order_id,
                esc_html( $patient_name ),
                esc_url( wc_get_page_permalink( 'myaccount' ) )
            );

            $html_message_patient = $this->get_html_email_template( __( 'Corrected Report Uploaded', 'cmi-home-testing' ), $body_patient );
            $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
            wp_mail( $to_patient, $subject_patient, $html_message_patient, $headers );
        }

        // 2. Notify Admin
        $to_admin = get_option( 'admin_email' );
        if ( ! empty( $to_admin ) ) {
            $partner = get_userdata( $partner_id );
            $partner_name = $partner ? $partner->display_name : 'Partner';
            $partner_org = $partner ? get_user_meta( $partner_id, '_cmi_org', true ) : '';
            $partner_display = ! empty( $partner_org ) ? $partner_org : $partner_name;

            $subject_admin = sprintf( __( 'Corrected Report for %s Uploaded by Partner: Order #%d', 'cmi-home-testing' ), $patient_name, $order_id );

            $body_admin = sprintf(
                __( "<p>Admin,</p>
                <p>Partner <strong>%s</strong> has uploaded a corrected report for Order <strong>#%d</strong> (Patient: <strong>%s</strong>).</p>
                <table class='details-table'>
                    <tr><td class='label'>Order ID</td><td>#%d</td></tr>
                    <tr><td class='label'>Patient Name</td><td>%s</td></tr>
                    <tr><td class='label'>Partner</td><td>%s</td></tr>
                    <tr><td class='label'>Previous Report Post ID</td><td>%d (Marked Inactive/Draft)</td></tr>
                    <tr><td class='label'>New Active Report Post ID</td><td>%d</td></tr>
                </table>", 'cmi-home-testing' ),
                esc_html( $partner_display ),
                $order_id,
                esc_html( $patient_name ),
                $order_id,
                esc_html( $patient_name ),
                esc_html( $partner_display ),
                $old_post_id,
                $new_post_id
            );

            $html_message_admin = $this->get_html_email_template( __( 'Corrected Report Uploaded', 'cmi-home-testing' ), $body_admin );
            $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
            wp_mail( $to_admin, $subject_admin, $html_message_admin, $headers );
        }
    }

    /**
     * Notify Admin of reschedule request.
     */
    public function notify_admin_reschedule( $id, $date, $slot ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $order = wc_get_order( $row->order_id );
        if ( ! $order ) return;

        $patient_name = $order->get_meta( '_cmi_patient_name' ) ?: ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        $to = get_option( 'admin_email' );
        $subject = sprintf( __( 'Reschedule Request for %s: Order #%d', 'cmi-home-testing' ), $patient_name, $row->order_id );

        $body_content = sprintf(
            __( "<p>Admin,</p>
            <p>Patient <strong>%s</strong> (Booked under Order <strong>#%d</strong>) has requested to reschedule the home collection.</p>
            <table class='details-table'>
                <tr><td class='label'>Order ID</td><td>#%d</td></tr>
                <tr><td class='label'>Patient Name</td><td>%s</td></tr>
                <tr><td class='label'>Requested Date</td><td>%s</td></tr>
                <tr><td class='label'>Requested Slot</td><td>%s</td></tr>
            </table>
            <p style='text-align: center;'>
                <a href='%s' class='button'>Manage Reschedules</a>
            </p>", 'cmi-home-testing' ),
            esc_html( $patient_name ),
            $row->order_id,
            $row->order_id,
            esc_html( $patient_name ),
            esc_html( date_i18n( get_option( 'date_format' ), strtotime( $date ) ) ),
            esc_html( $slot ),
            esc_url( admin_url( 'admin.php?page=cmi-home-testing-assignments' ) )
        );

        $html_message = $this->get_html_email_template( __( 'Reschedule Request Received', 'cmi-home-testing' ), $body_content );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $to, $subject, $html_message, $headers );
    }

    /**
     * Notify assigned partner that patient requested a reschedule.
     */
    public function notify_partner_reschedule_requested( $id, $date, $slot ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row || ! $row->partner_id ) return;

        $partner = get_userdata( $row->partner_id );
        if ( ! $partner ) return;

        $order = wc_get_order( $row->order_id );
        if ( ! $order ) return;

        $patient_name = $order->get_meta( '_cmi_patient_name' ) ?: ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        $to = $partner->user_email;
        $subject = sprintf( __( 'Reschedule Requested for %s: Order #%d', 'cmi-home-testing' ), $patient_name, $row->order_id );

        $body_content = sprintf(
            __( "<p>Hello %s,</p>
            <p>The patient <strong>%s</strong> has requested a reschedule for their home collection under Order <strong>#%d</strong>.</p>
            <p>This request is currently pending admin approval. Below are the requested rescheduling details:</p>
            <table class='details-table'>
                <tr><td class='label'>Order ID</td><td>#%d</td></tr>
                <tr><td class='label'>Patient Name</td><td>%s</td></tr>
                <tr><td class='label'>Requested Date</td><td>%s</td></tr>
                <tr><td class='label'>Requested Slot</td><td>%s</td></tr>
            </table>
            <p>Please hold on this collection until the admin approves or denies this request.</p>", 'cmi-home-testing' ),
            esc_html( $partner->display_name ),
            esc_html( $patient_name ),
            $row->order_id,
            $row->order_id,
            esc_html( $patient_name ),
            esc_html( date_i18n( get_option( 'date_format' ), strtotime( $date ) ) ),
            esc_html( $slot )
        );

        $html_message = $this->get_html_email_template( __( 'Reschedule Requested by Patient', 'cmi-home-testing' ), $body_content );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $to, $subject, $html_message, $headers );

        // Transactional SMS trigger to Patient
        $patient_mobile = $order->get_meta( '_cmi_patient_mobile' ) ?: $order->get_billing_phone();
        if ( class_exists( 'CMI_SMS_Manager' ) && ! empty( $patient_mobile ) ) {
            CMI_SMS_Manager::send_event_sms( 'reschedule_requested', $patient_mobile, [
                'name'     => $patient_name,
                'order_id' => $row->order_id,
                'date'     => $date,
                'slot'     => $slot,
            ] );
        }
    }

    /**
     * Notify previously assigned partner that the reschedule was approved.
     */
    public function notify_partner_reschedule_approved( $id, $previous_partner_id = 0, $row_array = null ) {
        if ( ! $previous_partner_id ) return;

        $partner = get_userdata( $previous_partner_id );
        if ( ! $partner ) return;

        // Convert array back to object if passed from cron
        $row = is_array( $row_array ) ? (object) $row_array : $row_array;

        $order = wc_get_order( $row->order_id );
        if ( ! $order ) return;

        $patient_name = $order->get_meta( '_cmi_patient_name' ) ?: ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';
        $current_partner_id = $wpdb->get_var( $wpdb->prepare( "SELECT partner_id FROM $table WHERE id = %d", $id ) );
        $is_still_assigned = ( intval( $current_partner_id ) === intval( $previous_partner_id ) && $previous_partner_id > 0 );

        $to = $partner->user_email;

        if ( $is_still_assigned ) {
            $subject = sprintf( __( 'Job Rescheduled (Assigned) for %s: Order #%d', 'cmi-home-testing' ), $patient_name, $row->order_id );
            $body_content = sprintf(
                __( "<p>Hello %s,</p>
                <p>The home collection appointment for <strong>%s</strong> (Order <strong>#%d</strong>) has been rescheduled by the admin. You remain assigned to this job.</p>
                <p>Below are the updated schedule details:</p>
                <table class='details-table'>
                    <tr><td class='label'>Order ID</td><td>#%d</td></tr>
                    <tr><td class='label'>Patient Name</td><td>%s</td></tr>
                    <tr><td class='label'>New Scheduled Date</td><td>%s</td></tr>
                    <tr><td class='label'>New Time Slot</td><td>%s</td></tr>
                </table>
                <p>Please review these details on your Partner Portal dashboard.</p>", 'cmi-home-testing' ),
                esc_html( $partner->display_name ),
                esc_html( $patient_name ),
                $row->order_id,
                $row->order_id,
                esc_html( $patient_name ),
                esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->reschedule_date ) ) ),
                esc_html( $row->reschedule_time_slot )
            );
        } else {
            $subject = sprintf( __( 'Job Rescheduled and Unassigned for %s: Order #%d', 'cmi-home-testing' ), $patient_name, $row->order_id );
            $body_content = sprintf(
                __( "<p>Hello %s,</p>
                <p>The home collection appointment for <strong>%s</strong> (Order <strong>#%d</strong>) has been rescheduled by the admin. Because of the schedule change, the job has been returned to the pending queue, and you are no longer assigned to it.</p>
                <p>Below are the details of the rescheduled request for your reference:</p>
                <table class='details-table'>
                    <tr><td class='label'>Order ID</td><td>#%d</td></tr>
                    <tr><td class='label'>Patient Name</td><td>%s</td></tr>
                    <tr><td class='label'>New Scheduled Date</td><td>%s</td></tr>
                    <tr><td class='label'>New Time Slot</td><td>%s</td></tr>
                </table>
                <p>You can view currently available assignments on your Partner Portal dashboard if you wish to accept this or other jobs.</p>", 'cmi-home-testing' ),
                esc_html( $partner->display_name ),
                esc_html( $patient_name ),
                $row->order_id,
                $row->order_id,
                esc_html( $patient_name ),
                esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->reschedule_date ) ) ),
                esc_html( $row->reschedule_time_slot )
            );
        }

        $html_message = $this->get_html_email_template( __( 'Collection Rescheduled', 'cmi-home-testing' ), $body_content );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $to, $subject, $html_message, $headers );

        // Transactional SMS trigger to Patient
        $patient_mobile = $order->get_meta( '_cmi_patient_mobile' ) ?: $order->get_billing_phone();
        if ( class_exists( 'CMI_SMS_Manager' ) && ! empty( $patient_mobile ) ) {
            CMI_SMS_Manager::send_event_sms( 'reschedule_approved', $patient_mobile, [
                'name'     => $patient_name,
                'order_id' => $row->order_id,
                'date'     => isset( $row->reschedule_date ) ? $row->reschedule_date : $row->collection_date,
                'slot'     => isset( $row->reschedule_time_slot ) ? $row->reschedule_time_slot : $row->collection_time_slot,
            ] );
        }
    }

    /**
     * Notify Patient that the partner has revoked their acceptance.
     */
    public function notify_patient_revoked( $id, $partner_id, $reason ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $order = wc_get_order( $row->order_id );
        if ( ! $order ) return;

        $to = $order->get_billing_email();
        if ( empty( $to ) ) return;

        $patient_name = $order->get_meta( '_cmi_patient_name' ) ?: ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $subject = sprintf( __( 'Update on Home Collection Appointment for %s: Order #%d', 'cmi-home-testing' ), $patient_name, $row->order_id );

        $body_content = sprintf(
            __( "<p>Hello %s,</p>
            <p>We wanted to inform you that there has been a change regarding the home collection appointment for <strong>%s</strong> (Order <strong>#%d</strong>).</p>
            <p>The assigned healthcare partner is currently unavailable to perform the collection. Our team is actively assigning a new partner to your request.</p>
            <p>The scheduled collection slot remains: <strong>%s</strong> at <strong>%s</strong>.</p>
            <p>We will notify you with the new partner details as soon as they are confirmed.</p>
            <p style='text-align: center;'>
                <a href='%s' class='button'>View Dashboard</a>
            </p>", 'cmi-home-testing' ),
            esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            esc_html( $patient_name ),
            $row->order_id,
            esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->collection_date ) ) ),
            esc_html( $row->collection_time_slot ),
            esc_url( wc_get_page_permalink( 'myaccount' ) )
        );

        $html_message = $this->get_html_email_template( __( 'Collection Appointment Update', 'cmi-home-testing' ), $body_content );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $to, $subject, $html_message, $headers );
    }

    /**
     * Defer generic email sending using WordPress single-shot background cron jobs.
     */
    public function send_email( $to, $subject, $message, $headers ) {
        wp_schedule_single_event( time(), 'cmi_send_deferred_email_cron', [ $to, $subject, $message, $headers ] );
    }

    /**
     * Cron callback handler to perform actual synchronous email delivery.
     */
    public function send_deferred_email_cron_handler( $to, $subject, $message, $headers ) {
        wp_mail( $to, $subject, $message, $headers );
    }

    /**
     * Doctor Consultation scheduling methods
     */
    public function schedule_consultation_requested( $id ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_consultation_requested', [ $id ] );
    }

    public function schedule_consultation_assigned( $id, $doctor_id ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_consultation_assigned', [ $id, $doctor_id ] );
    }

    public function schedule_consultation_scheduled( $id ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_consultation_scheduled', [ $id ] );
    }

    public function schedule_consultation_completed( $id ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_consultation_completed', [ $id ] );
    }

    public function schedule_consultation_cancelled( $id ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_consultation_cancelled', [ $id ] );
    }

    public function schedule_consultation_needs_reschedule( $id ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_consultation_needs_reschedule', [ $id ] );
    }
 
    /**
     * Doctor Consultation notification handlers
     */
    public function notify_consultation_requested( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $user = get_userdata( $row->user_id );
        if ( ! $user ) return;

        $patient_email = $user->user_email;
        if ( empty( $patient_email ) ) return;

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        // 1. Send confirmation to Patient
        $subject_patient = sprintf( __( 'Doctor Consultation Request Received: #%d', 'cmi-partner-portal' ), $id );
        $body_patient = sprintf(
            __( "<p>Hello %s,</p>
            <p>We have received your doctor consultation request for <strong>%s</strong>. Our team is currently reviewing your request and assigning a doctor.</p>
            <table class='details-table'>
                <tr><td class='label'>Request ID</td><td>#%d</td></tr>
                <tr><td class='label'>Patient Name</td><td>%s</td></tr>
                <tr><td class='label'>Relationship</td><td>%s</td></tr>
                <tr><td class='label'>Consultation Type</td><td>%s</td></tr>
                <tr><td class='label'>Preferred Date</td><td>%s</td></tr>
                <tr><td class='label'>Preferred Slot</td><td>%s</td></tr>
                <tr><td class='label'>Symptoms</td><td>%s</td></tr>
            </table>
            <p>We will notify you by email as soon as a doctor is assigned to your consultation.</p>", 'cmi-partner-portal' ),
            esc_html( $user->display_name ),
            esc_html( $row->patient_name ),
            $id,
            esc_html( $row->patient_name ),
            esc_html( $row->patient_relationship ),
            esc_html( $row->consultation_type ),
            esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->preferred_date ) ) ),
            esc_html( $row->preferred_time_slot ),
            nl2br( esc_html( $row->symptoms ) )
        );

        $html_message_patient = $this->get_html_email_template( __( 'Consultation Request Received', 'cmi-partner-portal' ), $body_patient );
        wp_mail( $patient_email, $subject_patient, $html_message_patient, $headers );

        // 2. Send notification to Admin
        $admin_email = get_option( 'admin_email' );
        if ( ! empty( $admin_email ) ) {
            $subject_admin = sprintf( __( 'New Doctor Consultation Request - ID: #%d', 'cmi-partner-portal' ), $id );
            $body_admin = sprintf(
                __( "<p>Hello Admin,</p>
                <p>A new doctor consultation request has been submitted by <strong>%s</strong>.</p>
                <table class='details-table'>
                    <tr><td class='label'>Request ID</td><td>#%d</td></tr>
                    <tr><td class='label'>Booked By</td><td>%s (%s)</td></tr>
                    <tr><td class='label'>Patient Name</td><td>%s</td></tr>
                    <tr><td class='label'>Relationship</td><td>%s</td></tr>
                    <tr><td class='label'>Consultation Type</td><td>%s</td></tr>
                    <tr><td class='label'>Preferred Date</td><td>%s</td></tr>
                    <tr><td class='label'>Preferred Slot</td><td>%s</td></tr>
                    <tr><td class='label'>Symptoms</td><td>%s</td></tr>
                </table>
                <p style='text-align: center;'>
                    <a href='%s' class='button'>Manage Consultations</a>
                </p>", 'cmi-partner-portal' ),
                esc_html( $user->display_name ),
                $id,
                esc_html( $user->display_name ),
                esc_html( $user->user_email ),
                esc_html( $row->patient_name ),
                esc_html( $row->patient_relationship ),
                esc_html( $row->consultation_type ),
                esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->preferred_date ) ) ),
                esc_html( $row->preferred_time_slot ),
                nl2br( esc_html( $row->symptoms ) ),
                esc_url( admin_url( 'admin.php?page=cmi-consultations' ) )
            );

            $html_message_admin = $this->get_html_email_template( __( 'New Consultation Request', 'cmi-partner-portal' ), $body_admin );
            wp_mail( $admin_email, $subject_admin, $html_message_admin, $headers );
        }

        // Transactional SMS trigger to Patient
        if ( class_exists( 'CMI_SMS_Manager' ) && ! empty( $row->patient_mobile ) ) {
            CMI_SMS_Manager::send_event_sms( 'consultation_requested', $row->patient_mobile, [
                'name' => $row->patient_name,
                'id'   => $id,
                'date' => $row->preferred_date,
                'slot' => $row->preferred_time_slot
            ] );
        }
    }

    public function notify_consultation_assigned( $id, $doctor_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $doctor = get_userdata( $doctor_id );
        if ( ! $doctor ) return;

        $user = get_userdata( $row->user_id );
        if ( ! $user ) return;

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        // 1. Send email to Doctor
        $to_doctor = $doctor->user_email;
        if ( ! empty( $to_doctor ) ) {
            $subject_doctor = sprintf( __( 'New Consultation Job Assigned: #%d', 'cmi-partner-portal' ), $id );
            $body_doctor = sprintf(
                __( "<p>Hello Dr. %s,</p>
                <p>You have been assigned to a new doctor consultation request. Please review the details below:</p>
                <table class='details-table'>
                    <tr><td class='label'>Request ID</td><td>#%d</td></tr>
                    <tr><td class='label'>Patient Name</td><td>%s</td></tr>
                    <tr><td class='label'>Gender / DOB</td><td>%s / %s</td></tr>
                    <tr><td class='label'>Contact Phone</td><td>%s</td></tr>
                    <tr><td class='label'>Consultation Type</td><td>%s</td></tr>
                    <tr><td class='label'>Preferred Date</td><td>%s</td></tr>
                    <tr><td class='label'>Preferred Slot</td><td>%s</td></tr>
                    <tr><td class='label'>Symptoms</td><td>%s</td></tr>
                </table>
                <p>Please log in to the partner portal to mark this consultation as \"Scheduled\" or upload a prescription when completed.</p>
                <p style='text-align: center;'>
                    <a href='%s' class='button'>Go to Portal</a>
                </p>", 'cmi-partner-portal' ),
                esc_html( $doctor->display_name ),
                $id,
                esc_html( $row->patient_name ),
                esc_html( $row->patient_gender ),
                esc_html( $row->patient_dob ),
                esc_html( $row->patient_mobile ),
                esc_html( $row->consultation_type ),
                esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->preferred_date ) ) ),
                esc_html( $row->preferred_time_slot ),
                nl2br( esc_html( $row->symptoms ) ),
                esc_url( home_url( '/partner-dashboard/' ) )
            );

            $html_message_doctor = $this->get_html_email_template( __( 'Consultation Job Assigned', 'cmi-partner-portal' ), $body_doctor );
            wp_mail( $to_doctor, $subject_doctor, $html_message_doctor, $headers );
        }

        // 2. Send email to Patient
        $to_patient = $user->user_email;
        if ( ! empty( $to_patient ) ) {
            $subject_patient = sprintf( __( 'Doctor Assigned for Your Consultation: #%d', 'cmi-partner-portal' ), $id );
            $body_patient = sprintf(
                __( "<p>Hello %s,</p>
                <p>We are pleased to inform you that <strong>Dr. %s</strong> has been assigned to your consultation request for <strong>%s</strong>.</p>
                <table class='details-table'>
                    <tr><td class='label'>Request ID</td><td>#%d</td></tr>
                    <tr><td class='label'>Assigned Doctor</td><td>Dr. %s</td></tr>
                    <tr><td class='label'>Consultation Type</td><td>%s</td></tr>
                    <tr><td class='label'>Preferred Date</td><td>%s</td></tr>
                    <tr><td class='label'>Preferred Slot</td><td>%s</td></tr>
                </table>
                <p>Dr. %s will contact you shortly to conduct the consultation. You can track the status in your dashboard.</p>
                <p style='text-align: center;'>
                    <a href='%s' class='button'>View Dashboard</a>
                </p>", 'cmi-partner-portal' ),
                esc_html( $user->display_name ),
                esc_html( $doctor->display_name ),
                esc_html( $row->patient_name ),
                $id,
                esc_html( $doctor->display_name ),
                esc_html( $row->consultation_type ),
                esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->preferred_date ) ) ),
                esc_html( $row->preferred_time_slot ),
                esc_html( $doctor->display_name ),
                esc_url( wc_get_page_permalink( 'myaccount' ) )
            );

            $html_message_patient = $this->get_html_email_template( __( 'Doctor Assigned', 'cmi-partner-portal' ), $body_patient );
            wp_mail( $to_patient, $subject_patient, $html_message_patient, $headers );
        }

        // Transactional SMS trigger to Doctor & Patient
        $doctor_phone = get_user_meta( $doctor_id, '_cmi_mobile', true ) ?: get_user_meta( $doctor_id, 'billing_phone', true );
        if ( class_exists( 'CMI_SMS_Manager' ) && ! empty( $doctor_phone ) ) {
            CMI_SMS_Manager::send_event_sms( 'consultation_assigned', $doctor_phone, [
                'doctor_name'  => $doctor->display_name,
                'patient_name' => $row->patient_name,
                'id'           => $id,
                'date'         => $row->preferred_date,
                'slot'         => $row->preferred_time_slot
            ] );
        }
        if ( class_exists( 'CMI_SMS_Manager' ) && ! empty( $row->patient_mobile ) ) {
            CMI_SMS_Manager::send_event_sms( 'consultation_scheduled', $row->patient_mobile, [
                'name'   => $row->patient_name,
                'id'     => $id,
                'doctor' => $doctor->display_name,
                'date'   => $row->preferred_date,
                'slot'   => $row->preferred_time_slot
            ] );
        }
    }

    public function notify_consultation_scheduled( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $user = get_userdata( $row->user_id );
        if ( ! $user ) return;

        $doctor = $row->doctor_id ? get_userdata( $row->doctor_id ) : null;
        $doctor_name = $doctor ? $doctor->display_name : 'Assigned Doctor';

        $to = $user->user_email;
        if ( empty( $to ) ) return;

        $subject = sprintf( __( 'Your Doctor Consultation is Scheduled: #%d', 'cmi-partner-portal' ), $id );
        $body = sprintf(
            __( "<p>Hello %s,</p>
            <p>Your doctor consultation request for <strong>%s</strong> has been scheduled successfully.</p>
            <table class='details-table'>
                <tr><td class='label'>Request ID</td><td>#%d</td></tr>
                <tr><td class='label'>Doctor</td><td>Dr. %s</td></tr>
                <tr><td class='label'>Consultation Type</td><td>%s</td></tr>
                <tr><td class='label'>Date</td><td>%s</td></tr>
                <tr><td class='label'>Time Slot</td><td>%s</td></tr>
            </table>
            <p>Dr. %s will connect with you during the scheduled slot. If you have any questions, please contact our support team.</p>
            <p style='text-align: center;'>
                <a href='%s' class='button'>View Dashboard</a>
            </p>", 'cmi-partner-portal' ),
            esc_html( $user->display_name ),
            esc_html( $row->patient_name ),
            $id,
            esc_html( $doctor_name ),
            esc_html( $row->consultation_type ),
            esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->preferred_date ) ) ),
            esc_html( $row->preferred_time_slot ),
            esc_html( $doctor_name ),
            esc_url( wc_get_page_permalink( 'myaccount' ) )
        );

        $html_message = $this->get_html_email_template( __( 'Consultation Scheduled', 'cmi-partner-portal' ), $body );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $to, $subject, $html_message, $headers );

        // Transactional SMS trigger to Patient
        if ( class_exists( 'CMI_SMS_Manager' ) && ! empty( $row->patient_mobile ) ) {
            CMI_SMS_Manager::send_event_sms( 'consultation_scheduled', $row->patient_mobile, [
                'name'   => $row->patient_name,
                'id'     => $id,
                'doctor' => $doctor_name,
                'date'   => $row->preferred_date,
                'slot'   => $row->preferred_time_slot
            ] );
        }
    }

    public function notify_consultation_completed( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $user = get_userdata( $row->user_id );
        if ( ! $user ) return;

        $doctor = $row->doctor_id ? get_userdata( $row->doctor_id ) : null;
        $doctor_name = $doctor ? $doctor->display_name : 'Assigned Doctor';

        $to = $user->user_email;
        if ( empty( $to ) ) return;

        $download_url = '';
        if ( $row->prescription_id && class_exists( 'CMI_Download' ) ) {
            $download_url = CMI_Download::generate_link( $row->prescription_id );
        }
        if ( empty( $download_url ) && ! empty( $row->prescription_file ) ) {
            $download_url = content_url( 'cmi-secure-reports/' . $row->prescription_file );
        }

        $subject = sprintf( __( 'Consultation Prescription & Report Ready: #%d', 'cmi-partner-portal' ), $id );
        $body = sprintf(
            __( "<p>Hello %s,</p>
            <p>Your doctor consultation request for <strong>%s</strong> has been completed by <strong>Dr. %s</strong>.</p>
            <p>Your prescription / consultation report has been uploaded securely and is now ready for download.</p>
            <table class='details-table'>
                <tr><td class='label'>Request ID</td><td>#%d</td></tr>
                <tr><td class='label'>Doctor</td><td>Dr. %s</td></tr>
                <tr><td class='label'>Consultation Type</td><td>%s</td></tr>
                <tr><td class='label'>Completed Date</td><td>%s</td></tr>
                <tr><td class='label'>Doctor Advice / Notes</td><td>%s</td></tr>
            </table>
            <p style='text-align: center;'>
                <a href='%s' class='button' target='_blank'>Download Prescription PDF</a>
            </p>", 'cmi-partner-portal' ),
            esc_html( $user->display_name ),
            esc_html( $row->patient_name ),
            esc_html( $doctor_name ),
            $id,
            esc_html( $doctor_name ),
            esc_html( $row->consultation_type ),
            esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->updated_at ) ) ),
            ! empty( $row->prescription_notes ) ? nl2br( esc_html( $row->prescription_notes ) ) : '—',
            esc_url( $download_url )
        );

        $html_message = $this->get_html_email_template( __( 'Consultation Prescription Ready', 'cmi-partner-portal' ), $body );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $to, $subject, $html_message, $headers );

        // Transactional SMS trigger to Patient
        if ( class_exists( 'CMI_SMS_Manager' ) && ! empty( $row->patient_mobile ) ) {
            CMI_SMS_Manager::send_event_sms( 'prescription_ready', $row->patient_mobile, [
                'name'   => $row->patient_name,
                'id'     => $id,
                'doctor' => $doctor_name
            ] );
        }
    }

    public function notify_consultation_cancelled( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $user = get_userdata( $row->user_id );
        if ( ! $user ) return;

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        // 1. Send to Patient
        $to_patient = $user->user_email;
        if ( ! empty( $to_patient ) ) {
            $subject_patient = sprintf( __( 'Doctor Consultation Request Cancelled: #%d', 'cmi-partner-portal' ), $id );
            $body_patient = sprintf(
                __( "<p>Hello %s,</p>
                <p>We wanted to inform you that your doctor consultation request for <strong>%s</strong> (Request #<strong>%d</strong>) has been cancelled.</p>
                <p>If you did not request this cancellation or have any questions, please contact our support team immediately.</p>", 'cmi-partner-portal' ),
                esc_html( $user->display_name ),
                esc_html( $row->patient_name ),
                $id
            );

            $html_message_patient = $this->get_html_email_template( __( 'Consultation Request Cancelled', 'cmi-partner-portal' ), $body_patient );
            wp_mail( $to_patient, $subject_patient, $html_message_patient, $headers );
        }

        // 2. Send to Doctor if assigned
        if ( $row->doctor_id ) {
            $doctor = get_userdata( $row->doctor_id );
            if ( $doctor && ! empty( $doctor->user_email ) ) {
                $subject_doctor = sprintf( __( 'Assigned Consultation Job Cancelled: #%d', 'cmi-partner-portal' ), $id );
                $body_doctor = sprintf(
                    __( "<p>Hello Dr. %s,</p>
                    <p>This is to inform you that the consultation request for <strong>%s</strong> (Request #<strong>%d</strong>) to which you were assigned has been cancelled.</p>
                    <p>No further action is required for this request.</p>", 'cmi-partner-portal' ),
                    esc_html( $doctor->display_name ),
                    esc_html( $row->patient_name ),
                    $id
                );

                $html_message_doctor = $this->get_html_email_template( __( 'Consultation Cancelled', 'cmi-partner-portal' ), $body_doctor );
                wp_mail( $doctor->user_email, $subject_doctor, $html_message_doctor, $headers );
            }
        }
    }

    public function notify_consultation_needs_reschedule( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $user = get_userdata( $row->user_id );
        if ( ! $user ) return;

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $to_patient = $user->user_email;
        if ( ! empty( $to_patient ) ) {
            $subject_patient = sprintf( __( 'Your Doctor Consultation Request Needs Rescheduling: #%d', 'cmi-partner-portal' ), $id );
            
            $reschedule_url = function_exists( 'wc_get_endpoint_url' ) ? wc_get_endpoint_url( 'patient-reports', '', myaccount_url() ) : home_url();
            
            $body_patient = sprintf(
                __( "<p>Hello %s,</p>
                <p>We wanted to inform you that your doctor consultation request for <strong>%s</strong> (Request #<strong>%d</strong>) scheduled on <strong>%s</strong> at <strong>%s</strong> needs to be rescheduled.</p>
                <p>Please log in to your account and select another date and time slot for your consultation.</p>
                <p><a href='%s' style='display: inline-block; padding: 10px 20px; background-color: #1a4f8a; color: #fff; text-decoration: none; border-radius: 4px;'>Reschedule Consultation</a></p>", 'cmi-partner-portal' ),
                esc_html( $user->display_name ),
                esc_html( $row->patient_name ),
                $id,
                esc_html( $row->preferred_date ),
                esc_html( $row->preferred_time_slot ),
                esc_url( $reschedule_url )
            );

            $html_message_patient = $this->get_html_email_template( __( 'Consultation Reschedule Required', 'cmi-partner-portal' ), $body_patient );
            wp_mail( $to_patient, $subject_patient, $html_message_patient, $headers );
        }
    }

    public function schedule_consultation_missed( $id ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_consultation_missed', [ $id ] );
    }

    public function schedule_consultation_rescheduled_by_admin( $id ) {
        wp_schedule_single_event( time(), 'cmi_deferred_notify_consultation_rescheduled_by_admin', [ $id ] );
    }

    public function notify_consultation_missed( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $user = get_userdata( $row->user_id );
        if ( ! $user ) return;

        $to = $user->user_email;
        if ( empty( $to ) ) return;

        $subject = sprintf( __( 'Consultation Session Expired - Action Required: #%d', 'cmi-partner-portal' ), $id );
        
        $timestamp = strtotime( $row->preferred_date );
        $formatted_date = date_i18n( 'l, F j, Y', $timestamp );

        $body = sprintf(
            __( "<p>Dear %s,</p>
            <p>We are writing to inform you that your consultation session scheduled for <strong>%s</strong> at <strong>%s</strong> has expired as the slot time has passed.</p>
            <p>A member of our care coordination team will contact you shortly to assist in scheduling a new appointment. If you prefer, you may also request a new schedule directly from your account dashboard.</p>
            <p>We look forward to connecting you with your physician soon.</p>
            <p>Best regards,<br>The CMI Healthcare Team</p>", 'cmi-partner-portal' ),
            esc_html( $row->patient_name ),
            esc_html( $formatted_date ),
            esc_html( $row->preferred_time_slot )
        );

        $html_message = $this->get_html_email_template( __( 'Consultation Session Expired', 'cmi-partner-portal' ), $body );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $to, $subject, $html_message, $headers );

        // Transactional SMS trigger to Patient
        if ( class_exists( 'CMI_SMS_Manager' ) && ! empty( $row->patient_mobile ) ) {
            CMI_SMS_Manager::send_event_sms( 'consultation_missed', $row->patient_mobile, [
                'name' => $row->patient_name,
                'id'   => $id,
                'date' => $row->preferred_date,
                'slot' => $row->preferred_time_slot
            ] );
        }
    }

    public function notify_consultation_rescheduled_by_admin( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return;

        $user = get_userdata( $row->user_id );
        if ( ! $user ) return;

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        // 1. Send email to Patient
        $to_patient = $user->user_email;
        if ( ! empty( $to_patient ) ) {
            $subject_patient = sprintf( __( 'Update: Your Doctor Consultation Has Been Rescheduled (ID: #%d)', 'cmi-partner-portal' ), $id );
            $formatted_date = date_i18n( get_option( 'date_format' ), strtotime( $row->preferred_date ) );
            $body_patient = sprintf(
                __( "<p>Dear %s,</p>
                <p>Please be advised that your doctor consultation appointment (ID: #<strong>%d</strong>) for <strong>%s</strong> has been rescheduled by our care coordination team.</p>
                <p>The updated schedule details are as follows:</p>
                <table class='details-table'>
                    <tr><td class='label'>Consultation ID</td><td>#%d</td></tr>
                    <tr><td class='label'>Rescheduled Date</td><td>%s</td></tr>
                    <tr><td class='label'>New Time Slot</td><td>%s</td></tr>
                </table>
                <p>You can access your patient dashboard to review the updated details or join your video consultation at the scheduled time.</p>
                <p>Warm regards,<br>The CMI Healthcare Team</p>", 'cmi-partner-portal' ),
                esc_html( $user->display_name ),
                $id,
                esc_html( $row->patient_name ),
                $id,
                esc_html( $formatted_date ),
                esc_html( $row->preferred_time_slot )
            );

            $html_message_patient = $this->get_html_email_template( __( 'Consultation Rescheduled by Admin', 'cmi-partner-portal' ), $body_patient );
            wp_mail( $to_patient, $subject_patient, $html_message_patient, $headers );

            // Transactional SMS trigger to Patient
            if ( class_exists( 'CMI_SMS_Manager' ) && ! empty( $row->patient_mobile ) ) {
                CMI_SMS_Manager::send_event_sms( 'consultation_rescheduled', $row->patient_mobile, [
                    'name' => $row->patient_name,
                    'id'   => $id,
                    'date' => $row->preferred_date,
                    'slot' => $row->preferred_time_slot
                ] );
            }
        }

        // 2. Send email to Doctor
        if ( $row->doctor_id ) {
            $doctor = get_userdata( $row->doctor_id );
            if ( $doctor && ! empty( $doctor->user_email ) ) {
                $subject_doctor = sprintf( __( 'Notification: Assigned Consultation Rescheduled (ID: #%d)', 'cmi-partner-portal' ), $id );
                $formatted_date = date_i18n( get_option( 'date_format' ), strtotime( $row->preferred_date ) );
                $body_doctor = sprintf(
                    __( "<p>Dear Dr. %s,</p>
                    <p>This is to inform you that the assigned consultation for <strong>%s</strong> (ID: #<strong>%d</strong>) has been rescheduled by the clinical administration.</p>
                    <p>Please note the updated appointment slot details:</p>
                    <table class='details-table'>
                        <tr><td class='label'>Consultation ID</td><td>#%d</td></tr>
                        <tr><td class='label'>Patient Name</td><td>%s</td></tr>
                        <tr><td class='label'>Rescheduled Date</td><td>%s</td></tr>
                        <tr><td class='label'>New Time Slot</td><td>%s</td></tr>
                    </table>
                    <p>You can review this schedule and manage the session directly from your doctor dashboard.</p>
                    <p>Best regards,<br>Clinical Administration Team</p>", 'cmi-partner-portal' ),
                    esc_html( $doctor->display_name ),
                    esc_html( $row->patient_name ),
                    $id,
                    $id,
                    esc_html( $row->patient_name ),
                    esc_html( $formatted_date ),
                    esc_html( $row->preferred_time_slot )
                );

                $html_message_doctor = $this->get_html_email_template( __( 'Consultation Rescheduled', 'cmi-partner-portal' ), $body_doctor );
                wp_mail( $doctor->user_email, $subject_doctor, $html_message_doctor, $headers );
            }
        }
    }
}
