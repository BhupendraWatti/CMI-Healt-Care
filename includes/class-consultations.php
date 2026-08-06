<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CMI_Consultations {

    public function __construct() {
        // Ensure new table and columns are created on-the-fly
        if ( class_exists( 'CMI_HT_DB' ) ) {
            global $wpdb;
            $avail_table = $wpdb->prefix . 'cmi_doctor_availability';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$avail_table'" ) !== $avail_table ) {
                CMI_HT_DB::create_tables();
            }
        }

        // Enqueue scripts & styles
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_consultation_assets' ] );

        // Shortcodes
        add_shortcode( 'cmi_doctor_consultation', [ $this, 'render_patient_consultation_portal' ] );

        // AJAX handlers for patients
        add_action( 'wp_ajax_cmi_submit_consultation', [ $this, 'ajax_submit_consultation' ] );
        add_action( 'wp_ajax_cmi_cancel_consultation', [ $this, 'ajax_cancel_consultation' ] );
        add_action( 'wp_ajax_cmi_get_available_slots', [ $this, 'ajax_get_available_slots' ] );

        // AJAX handlers for admin
        add_action( 'wp_ajax_cmi_admin_assign_doctor', [ $this, 'ajax_admin_assign_doctor' ] );
        add_action( 'wp_ajax_cmi_admin_update_consultation_status', [ $this, 'ajax_admin_update_consultation_status' ] );
        add_action( 'wp_ajax_cmi_admin_update_consultation_schedule', [ $this, 'ajax_admin_update_consultation_schedule' ] );

        // AJAX handlers for doctor
        add_action( 'wp_ajax_cmi_doctor_upload_prescription', [ $this, 'ajax_doctor_upload_prescription' ] );
        add_action( 'wp_ajax_cmi_doctor_update_status', [ $this, 'ajax_doctor_update_status' ] );
        add_action( 'wp_ajax_cmi_save_doctor_availability', [ $this, 'ajax_save_doctor_availability' ] );
        add_action( 'wp_ajax_cmi_delete_doctor_availability', [ $this, 'ajax_delete_doctor_availability' ] );
        add_action( 'wp_ajax_cmi_save_doctor_exception', [ $this, 'ajax_save_doctor_exception' ] );
        add_action( 'wp_ajax_cmi_delete_doctor_exception', [ $this, 'ajax_delete_doctor_exception' ] );
        add_action( 'wp_ajax_cmi_update_meeting_status', [ $this, 'ajax_update_meeting_status' ] );
        add_action( 'wp_ajax_cmi_validate_meeting_access', [ $this, 'ajax_validate_meeting_access' ] );
        add_action( 'wp_ajax_cmi_request_admin_reschedule', [ $this, 'ajax_request_admin_reschedule' ] );

        // Profile sync actions
        add_action( 'profile_update', [ $this, 'sync_doctor_profile_to_cpt' ], 10, 2 );
        add_action( 'added_user_meta', [ $this, 'sync_doctor_user_meta_to_cpt' ], 10, 4 );
        add_action( 'updated_user_meta', [ $this, 'sync_doctor_user_meta_to_cpt' ], 10, 4 );
        add_action( 'deleted_user_meta', [ $this, 'sync_doctor_user_meta_to_cpt' ], 10, 4 );

        // Automatically append booking widget to doctor CPT
        add_filter( 'the_content', [ $this, 'append_booking_widget_to_doctor_cpt' ] );

        // Pre-meeting 5-minute automated SMS reminder hooks
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
        add_action( 'init', [ $this, 'schedule_meeting_reminder_cron' ] );
        add_action( 'cmi_check_upcoming_meeting_reminders_cron', [ $this, 'dispatch_upcoming_meeting_reminders' ] );

        // Admin Menus
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
    }

    /**
     * Enqueue assets for consultation pages.
     */
    public function enqueue_consultation_assets() {
        // We will inline styles and scripts inside shortcodes/templates for modularity,
        // but this method is kept in case global enqueues are needed in the future.
    }

    private function get_available_consultation_payment( $user_id, $product_id ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return new WP_Error( 'woocommerce_missing', esc_html__( 'WooCommerce is required for paid consultations.', 'cmi-partner-portal' ) );
        }

        global $wpdb;
        $consult_table = $wpdb->prefix . 'cmi_consultations';
        $paid_orders = wc_get_orders( [
            'customer_id' => $user_id,
            'status'      => [ 'processing', 'completed' ],
            'limit'       => 20,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'return'      => 'objects',
        ] );

        foreach ( $paid_orders as $wc_order ) {
            foreach ( $wc_order->get_items() as $item ) {
                $matches_product = absint( $item->get_product_id() ) === $product_id || absint( $item->get_variation_id() ) === $product_id;
                if ( ! $matches_product ) {
                    continue;
                }
                if ( $item->get_meta( '_cmi_consultation_consumed', true ) ) {
                    continue;
                }
                $already_consumed = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $consult_table WHERE order_item_id = %d LIMIT 1",
                    $item->get_id()
                ) );
                if ( $already_consumed ) {
                    continue;
                }

                return [
                    'order'         => $wc_order,
                    'item'          => $item,
                    'order_id'      => $wc_order->get_id(),
                    'order_item_id' => $item->get_id(),
                ];
            }
        }

        return new WP_Error( 'consultation_payment_required', esc_html__( 'A paid, unused consultation order is required before submitting a request. Please complete your purchase first.', 'cmi-partner-portal' ) );
    }

    private function mark_consultation_payment_consumed( $payment, $consultation_id ) {
        if ( empty( $payment['item'] ) || empty( $payment['order'] ) ) {
            return;
        }

        $payment['item']->update_meta_data( '_cmi_consultation_consumed', 'yes' );
        $payment['item']->update_meta_data( '_cmi_consultation_id', absint( $consultation_id ) );
        $payment['item']->save();
        $payment['order']->add_order_note( sprintf(
            /* translators: %d = consultation id */
            esc_html__( 'Consultation payment consumed by CMI consultation #%d.', 'cmi-partner-portal' ),
            absint( $consultation_id )
        ) );
    }

    private static function jitsi_requires_jwt() {
        return 'no' !== get_option( 'cmi_jitsi_require_jwt', 'yes' );
    }

    private function parse_consultation_slot( $date, $slot ) {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || false === strtotime( $date ) ) {
            return false;
        }
        if ( ! preg_match( '/^(.+?)\s*-\s*(.+)$/', $slot, $matches ) ) {
            return false;
        }

        $start = date( 'H:i:s', strtotime( $matches[1] ) );
        $end   = date( 'H:i:s', strtotime( $matches[2] ) );
        if ( ! $start || ! $end || $start >= $end ) {
            return false;
        }

        return [
            'start' => $start,
            'end'   => $end,
        ];
    }

    private function is_consultation_slot_bookable( $doctor_id, $date, $slot, $exclude_consultation_id = 0 ) {
        $doctor_id = absint( $doctor_id );
        $range = $this->parse_consultation_slot( $date, $slot );
        if ( ! $doctor_id || ! $range ) {
            return false;
        }

        $today = current_time( 'Y-m-d' );
        if ( $date < $today ) {
            return false;
        }

        global $wpdb;
        $avail_table      = $wpdb->prefix . 'cmi_doctor_availability';
        $exceptions_table = $wpdb->prefix . 'cmi_doctor_exceptions';
        $consult_table    = $wpdb->prefix . 'cmi_consultations';
        $day_of_week      = date( 'l', strtotime( $date ) );

        $exceptions = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $exceptions_table WHERE doctor_id = %d AND %s BETWEEN start_date AND end_date",
            $doctor_id,
            $date
        ) );

        $override = null;
        foreach ( (array) $exceptions as $ex ) {
            if ( in_array( $ex->type, [ 'leave', 'holiday', 'emergency' ], true ) ) {
                if ( empty( $ex->start_time ) || empty( $ex->end_time ) ) {
                    return false;
                }
                if ( $range['start'] < $ex->end_time && $range['end'] > $ex->start_time ) {
                    return false;
                }
            } elseif ( 'override' === $ex->type && ! empty( $ex->start_time ) && ! empty( $ex->end_time ) ) {
                $override = $ex;
            }
        }

        if ( $override ) {
            $inside_working_hours = $range['start'] >= $override->start_time && $range['end'] <= $override->end_time;
        } else {
            $inside_working_hours = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $avail_table
                 WHERE doctor_id = %d AND day = %s AND status = 'active'
                   AND start_time <= %s AND end_time >= %s
                 LIMIT 1",
                $doctor_id,
                $day_of_week,
                $range['start'],
                $range['end']
            ) );
        }

        if ( ! $inside_working_hours && 'yes' !== get_option( 'cmi_allow_default_doctor_slots', 'no' ) ) {
            return false;
        }

        $conflict = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $consult_table
             WHERE doctor_id = %d
               AND preferred_date = %s
               AND preferred_time_slot = %s
               AND status NOT IN ('cancelled')
               AND id != %d
             LIMIT 1",
            $doctor_id,
            $date,
            $slot,
            absint( $exclude_consultation_id )
        ) );

        return empty( $conflict );
    }

    /**
     * Admin menu registration.
     */
    public function register_admin_menu() {
        add_submenu_page(
            'cmi-portal',
            __( 'Doctor Consultations', 'cmi-partner-portal' ),
            __( 'Consultations', 'cmi-partner-portal' ),
            'manage_options',
            'cmi-consultations',
            [ $this, 'render_admin_consultations_page' ]
        );
    }

    /**
     * Render the admin consultations page.
     */
    public function render_admin_consultations_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cmi-partner-portal' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        // Lazy load / pagination
        $limit = 20;
        $paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $offset = ( $paged - 1 ) * $limit;

        $total_items = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        $num_pages = ceil( $total_items / $limit );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ) );

        $doctors = get_users( [ 'role' => 'cmi_doctor' ] );

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Doctor Consultations', 'cmi-partner-portal' ); ?></h1>
            <hr class="wp-header-end">

            <style>
            .cmi-admin-select-custom {
                padding: 4px 28px 4px 8px !important;
                font-size: 12px !important;
                border-radius: 4px !important;
                height: 28px !important;
                line-height: 18px !important;
                max-width: 160px !important;
                min-width: 130px !important;
                background-position: right 8px center !important;
                vertical-align: middle !important;
            }
            </style>

            <table class="wp-list-table widefat fixed striped table-view-list entries" style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th style="width: 60px;"><?php esc_html_e( 'ID', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Requested By', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Patient Details', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Symptoms / Reason', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Preferred Schedule', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Assigned Doctor', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Prescription / Note', 'cmi-partner-portal' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $results ) ) : ?>
                        <tr>
                            <td colspan="9"><?php esc_html_e( 'No consultations found.', 'cmi-partner-portal' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $results as $row ) :
                            $user = get_userdata( $row->user_id );
                            $booked_by_name = $user ? $user->display_name : esc_html__( 'Guest / Deleted', 'cmi-partner-portal' );
                            ?>
                            <tr data-id="<?php echo esc_attr( $row->id ); ?>">
                                <td><strong>#<?php echo esc_html( $row->id ); ?></strong></td>
                                <td>
                                    <?php echo esc_html( $booked_by_name ); ?><br>
                                    <span class="description"><?php echo $user ? esc_html( $user->user_email ) : ''; ?></span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html( $row->patient_name ); ?></strong><br>
                                    <span class="description">
                                        <?php printf( __( 'Relation: %s | %s | DOB: %s', 'cmi-partner-portal' ), esc_html( $row->patient_relationship ), esc_html( $row->patient_gender ), esc_html( $row->patient_dob ) ); ?>
                                    </span><br>
                                    <span class="description"><?php echo esc_html( $row->patient_mobile ); ?></span>
                                </td>
                                <td><span class="badge" style="background:#eef4ff; color:#1a4f8a; padding:3px 8px; border-radius:4px; font-weight:600;"><?php echo esc_html( $row->consultation_type ); ?></span></td>
                                <td><p style="margin:0; font-size:12px; max-width:200px; word-break:break-word; white-space:normal; line-height:1.4;" title="<?php echo esc_attr( $row->symptoms ); ?>"><?php echo esc_html( $row->symptoms ); ?></p></td>
                                <td>
                                    <div class="cmi-admin-edit-schedule-container" data-id="<?php echo esc_attr( $row->id ); ?>">
                                        <input type="date" class="cmi-admin-edit-date" data-id="<?php echo esc_attr( $row->id ); ?>" value="<?php echo esc_attr( $row->preferred_date ); ?>" style="width:130px; font-size:12px; padding:3px; display:block; margin-bottom:4px;">
                                        <input type="text" class="cmi-admin-edit-slot" data-id="<?php echo esc_attr( $row->id ); ?>" value="<?php echo esc_attr( $row->preferred_time_slot ); ?>" style="width:130px; font-size:12px; padding:3px; display:block;" placeholder="e.g. 03:00 PM - 03:30 PM">
                                        <button type="button" class="button button-small cmi-admin-save-schedule" data-id="<?php echo esc_attr( $row->id ); ?>" style="margin-top:4px; font-size:10px; padding:0 6px; height:22px; line-height:20px;"><?php esc_html_e( 'Save Schedule', 'cmi-partner-portal' ); ?></button>
                                    </div>
                                </td>
                                <td>
                                    <select class="cmi-admin-consult-status cmi-admin-select-custom" data-id="<?php echo esc_attr( $row->id ); ?>">
                                        <option value="requested" <?php selected( $row->status, 'requested' ); ?>><?php esc_html_e( 'Requested', 'cmi-partner-portal' ); ?></option>
                                        <option value="assigned" <?php selected( $row->status, 'assigned' ); ?>><?php esc_html_e( 'Assigned', 'cmi-partner-portal' ); ?></option>
                                        <option value="scheduled" <?php selected( $row->status, 'scheduled' ); ?>><?php esc_html_e( 'Scheduled', 'cmi-partner-portal' ); ?></option>
                                        <option value="in_progress" <?php selected( $row->status, 'in_progress' ); ?>><?php esc_html_e( 'In Progress', 'cmi-partner-portal' ); ?></option>
                                        <option value="awaiting_prescription" <?php selected( $row->status, 'awaiting_prescription' ); ?>><?php esc_html_e( 'Awaiting Prescription', 'cmi-partner-portal' ); ?></option>
                                        <option value="needs_reschedule" <?php selected( $row->status, 'needs_reschedule' ); ?>><?php esc_html_e( 'Needs Reschedule', 'cmi-partner-portal' ); ?></option>
                                        <option value="rescheduled" <?php selected( $row->status, 'rescheduled' ); ?>><?php esc_html_e( 'Rescheduled', 'cmi-partner-portal' ); ?></option>
                                        <option value="completed" <?php selected( $row->status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'cmi-partner-portal' ); ?></option>
                                        <option value="cancelled" <?php selected( $row->status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'cmi-partner-portal' ); ?></option>
                                    </select>
                                </td>
                                <td>
                                    <?php if ( $row->status === 'completed' || $row->status === 'cancelled' ) : ?>
                                        <?php
                                        $doc = get_userdata( $row->doctor_id );
                                        echo esc_html( $doc ? $doc->display_name : '-' );
                                        ?>
                                    <?php else : ?>
                                        <select class="cmi-admin-consult-doctor cmi-admin-select-custom" data-id="<?php echo esc_attr( $row->id ); ?>">
                                            <option value=""><?php esc_html_e( 'Assign Doctor', 'cmi-partner-portal' ); ?></option>
                                            <?php foreach ( $doctors as $doc ) : ?>
                                                <option value="<?php echo esc_attr( $doc->ID ); ?>" <?php selected( $row->doctor_id, $doc->ID ); ?>>
                                                    <?php echo esc_html( $doc->display_name ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( $row->prescription_id ) : 
                                        $dl_url = CMI_Download::generate_link( $row->prescription_id, 'admin' );
                                        ?>
                                        <a href="<?php echo esc_url( $dl_url ); ?>" class="button button-small" target="_blank"><?php esc_html_e( 'View PDF', 'cmi-partner-portal' ); ?></a>
                                    <?php else : ?>
                                        <span class="description">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ( $num_pages > 1 ) : ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php printf( _n( '%s item', '%s items', $total_items, 'cmi-partner-portal' ), number_format_i18n( $total_items ) ); ?></span>
                        <span class="pagination-links">
                            <?php if ( $paged > 1 ) : ?>
                                <a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>">&lsaquo;</a>
                            <?php endif; ?>
                            <span class="paging-input">
                                <span class="current-page"><?php echo $paged; ?></span> <?php esc_html_e( 'of', 'cmi-partner-portal' ); ?> <span class="total-pages"><?php echo $num_pages; ?></span>
                            </span>
                            <?php if ( $paged < $num_pages ) : ?>
                                <a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>">&rsaquo;</a>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Jitsi JWT Configuration -->
        <?php
        // Handle settings save
        if (
            isset( $_POST['cmi_jitsi_save'] ) &&
            check_admin_referer( 'cmi_jitsi_settings_save', 'cmi_jitsi_nonce' )
        ) {
            update_option( 'cmi_jitsi_domain',         sanitize_text_field( $_POST['cmi_jitsi_domain'] ?? '8x8.vc' ) );
            update_option( 'cmi_jitsi_app_id',         sanitize_text_field( $_POST['cmi_jitsi_app_id'] ?? '' ) );
            update_option( 'cmi_jitsi_api_key_id',     sanitize_text_field( $_POST['cmi_jitsi_api_key_id'] ?? '' ) );
            update_option( 'cmi_jitsi_private_key',     sanitize_textarea_field( $_POST['cmi_jitsi_private_key'] ?? '' ) );
            update_option( 'cmi_jitsi_app_secret',     sanitize_text_field( $_POST['cmi_jitsi_app_secret'] ?? '' ) );
            update_option( 'cmi_consultation_product_id', absint( $_POST['cmi_consultation_product_id'] ?? 0 ) );
            update_option( 'cmi_same_day_buffer_minutes', absint( $_POST['cmi_same_day_buffer_minutes'] ?? 30 ) );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Jitsi settings saved.', 'cmi-partner-portal' ) . '</p></div>';
        }
        ?>
        <div style="margin-top:32px; background:#fff; border:1px solid #c3c4c7; border-radius:6px; padding:24px;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Jitsi JWT & JaaS (8x8) Settings', 'cmi-partner-portal' ); ?></h2>
            <p style="color:#646970; font-size:13px; margin-top:0;">
                <?php esc_html_e( 'Configure JWT authentication for Jitsi meetings. Supports Jitsi as a Service (JaaS - 8x8.vc) with PKI (RSA/EC) private keys, self-hosted Jitsi (TOKEN_BASED_AUTH=1 with HS256/RS256), or open mode. Prevents 5-minute meeting timeouts.', 'cmi-partner-portal' ); ?>
            </p>
            <form method="post" action="">
                <?php wp_nonce_field( 'cmi_jitsi_settings_save', 'cmi_jitsi_nonce' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cmi_jitsi_domain"><?php esc_html_e( 'Jitsi Domain', 'cmi-partner-portal' ); ?></label></th>
                        <td>
                            <input type="text" id="cmi_jitsi_domain" name="cmi_jitsi_domain" class="regular-text"
                                   value="<?php echo esc_attr( get_option( 'cmi_jitsi_domain', '8x8.vc' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Use 8x8.vc for JaaS, or meet.jit.si or your self-hosted domain.', 'cmi-partner-portal' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cmi_jitsi_app_id"><?php esc_html_e( 'App ID / Tenant ID', 'cmi-partner-portal' ); ?></label></th>
                        <td>
                            <input type="text" id="cmi_jitsi_app_id" name="cmi_jitsi_app_id" class="regular-text"
                                   value="<?php echo esc_attr( get_option( 'cmi_jitsi_app_id', '' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Your 8x8 JaaS App ID / Tenant ID (e.g. vpaas-magic-cookie-39e1af0a96c84828829ecf9a61907db3).', 'cmi-partner-portal' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cmi_jitsi_api_key_id"><?php esc_html_e( 'API Key ID (Header kid)', 'cmi-partner-portal' ); ?></label></th>
                        <td>
                            <input type="text" id="cmi_jitsi_api_key_id" name="cmi_jitsi_api_key_id" class="regular-text"
                                   value="<?php echo esc_attr( get_option( 'cmi_jitsi_api_key_id', '' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'The Key ID from your 8x8 JaaS console (used as JWT header kid). Leave empty to use App ID as kid.', 'cmi-partner-portal' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cmi_jitsi_private_key"><?php esc_html_e( 'JaaS Private Key (.pk / .pem)', 'cmi-partner-portal' ); ?></label></th>
                        <td>
                            <textarea id="cmi_jitsi_private_key" name="cmi_jitsi_private_key" rows="6" class="large-text code" placeholder="Paste your -----BEGIN PRIVATE KEY----- PEM content here OR enter server file path (e.g. C:\Keys\jitsi.pk)"><?php echo esc_textarea( get_option( 'cmi_jitsi_private_key', '' ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Paste your RSA or EC Private Key PEM text OR enter the full server file path to your .pk/.pem key file.', 'cmi-partner-portal' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cmi_jitsi_app_secret"><?php esc_html_e( 'App Secret (Legacy HS256)', 'cmi-partner-portal' ); ?></label></th>
                        <td>
                            <input type="password" id="cmi_jitsi_app_secret" name="cmi_jitsi_app_secret" class="regular-text"
                                   value="<?php echo esc_attr( get_option( 'cmi_jitsi_app_secret', '' ) ); ?>" autocomplete="new-password">
                            <p class="description"><?php esc_html_e( 'Legacy HS256 secret for self-hosted Jitsi. Ignored if a JaaS Private Key is provided above.', 'cmi-partner-portal' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cmi_consultation_product_id"><?php esc_html_e( 'Consultation Product ID', 'cmi-partner-portal' ); ?></label></th>
                        <td>
                            <input type="number" id="cmi_consultation_product_id" name="cmi_consultation_product_id" class="small-text" min="0"
                                   value="<?php echo esc_attr( get_option( 'cmi_consultation_product_id', 0 ) ); ?>">
                            <p class="description"><?php esc_html_e( 'WooCommerce product ID required to submit a consultation. Set to 0 to disable the payment gate.', 'cmi-partner-portal' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cmi_same_day_buffer_minutes"><?php esc_html_e( 'Same-day Booking Buffer (Minutes)', 'cmi-partner-portal' ); ?></label></th>
                        <td>
                            <input type="number" id="cmi_same_day_buffer_minutes" name="cmi_same_day_buffer_minutes" class="small-text" min="0"
                                   value="<?php echo esc_attr( get_option( 'cmi_same_day_buffer_minutes', 30 ) ); ?>">
                            <p class="description"><?php esc_html_e( 'The minimum number of minutes in the future a same-day slot must be before it can be booked (defaults to 30 minutes). Set to 0 for instant booking.', 'cmi-partner-portal' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="cmi_jitsi_save" class="button button-primary"><?php esc_html_e( 'Save Jitsi Settings', 'cmi-partner-portal' ); ?></button>
                </p>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.cmi-admin-consult-doctor').on('change', function() {
                var consultId = $(this).data('id');
                var doctorId = $(this).val();
                if (!consultId) return;

                $.post(ajaxurl, {
                    action: 'cmi_admin_assign_doctor',
                    id: consultId,
                    doctor_id: doctorId,
                    nonce: '<?php echo wp_create_nonce("cmi_ht_admin_nonce"); ?>'
                }, function(res) {
                    if (res.success) {
                        alert(res.data.message);
                        location.reload();
                    } else {
                        alert(res.data.message || 'Failed to assign doctor.');
                    }
                });
            });

            $('.cmi-admin-consult-status').on('change', function() {
                var consultId = $(this).data('id');
                var status = $(this).val();
                if (!consultId) return;

                $.post(ajaxurl, {
                    action: 'cmi_admin_update_consultation_status',
                    id: consultId,
                    status: status,
                    nonce: '<?php echo wp_create_nonce("cmi_ht_admin_nonce"); ?>'
                }, function(res) {
                    if (res.success) {
                        alert(res.data.message);
                        location.reload();
                    } else {
                        alert(res.data.message || 'Failed to update status.');
                    }
                });
            });

            $('.cmi-admin-save-schedule').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var id = btn.data('id');
                var container = btn.closest('.cmi-admin-edit-schedule-container');
                var date = container.find('.cmi-admin-edit-date').val();
                var slot = container.find('.cmi-admin-edit-slot').val();

                if (!date || !slot) {
                    alert('Both Date and Time Slot are required.');
                    return;
                }

                btn.prop('disabled', true).text('Saving...');

                $.post(ajaxurl, {
                    action: 'cmi_admin_update_consultation_schedule',
                    id: id,
                    date: date,
                    slot: slot,
                    nonce: '<?php echo wp_create_nonce("cmi_ht_admin_nonce"); ?>'
                }, function(res) {
                    if (res.success) {
                        alert(res.data.message);
                        location.reload();
                    } else {
                        alert(res.data.message || 'Failed to update schedule.');
                        btn.prop('disabled', false).text('Save Schedule');
                    }
                }).fail(function() {
                    alert('Connection error.');
                    btn.prop('disabled', false).text('Save Schedule');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render the Patient booking form and consultation history tab/page.
     */
    public function render_patient_consultation_portal( $atts = [] ) {
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return '<div class="cmi-portal-preview-mode"><p>' . esc_html__( '[CMI Consultation Booking - Preview Mode]', 'cmi-partner-portal' ) . '</p></div>';
        }

        $atts = shortcode_atts( [
            'doctor_id' => 0,
        ], $atts, 'cmi_doctor_consultation' );

        // If no doctor_id is passed, check if we are on a single post of CPT 'doctor'
        if ( ! $atts['doctor_id'] && is_singular( 'doctor' ) ) {
            $doctor_post_id = get_the_ID();
            $atts['doctor_id'] = get_post_meta( $doctor_post_id, '_cmi_doctor_user_id', true );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return '<div class="cmi-login-required-msg"><p>' . sprintf( __( 'Please <a href="%s">log in to your account</a> to request a doctor consultation.', 'cmi-partner-portal' ), esc_url( wc_get_page_permalink( 'myaccount' ) ) ) . '</p></div>';
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        // Fetch history
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY id DESC",
            $user_id
        ) );

        $members = CMI_HT_DB::get_user_members( $user_id );

        ob_start();
        ?>
        <!-- Shadcn UI inspired premium styling -->
        <style>
        .cmi-shadcn-wrapper {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #09090b;
        }
        .cmi-shadcn-layout {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 32px;
            margin-bottom: 40px;
        }
        @media (max-width: 768px) {
            .cmi-shadcn-layout {
                grid-template-columns: 1fr;
                gap: 24px;
            }
        }
        .cmi-shadcn-card {
            background: #ffffff;
            border: 1px solid #e4e4e7;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05), 0 1px 2px -1px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .cmi-shadcn-title {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            color: #09090b;
            letter-spacing: -0.025em;
        }
        .cmi-shadcn-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #09090b;
            margin-bottom: 6px;
        }
        .cmi-shadcn-input, .cmi-shadcn-select, .cmi-shadcn-textarea {
            width: 100%;
            padding: 8px 12px;
            font-size: 14px;
            color: #09090b;
            background-color: #ffffff;
            border: 1px solid #e4e4e7;
            border-radius: 6px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            box-sizing: border-box;
        }
        .cmi-shadcn-input:focus, .cmi-shadcn-select:focus, .cmi-shadcn-textarea:focus {
            outline: none;
            border-color: #09090b;
            box-shadow: 0 0 0 1px #09090b;
        }
        .cmi-shadcn-btn-primary {
            background-color: #18181b;
            color: #fafafa;
            border: none;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.15s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }
        .cmi-shadcn-btn-primary:hover:not(:disabled) {
            background-color: #27272a;
        }
        .cmi-shadcn-btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .cmi-shadcn-btn-outline {
            background-color: #ffffff;
            color: #18181b;
            border: 1px solid #e4e4e7;
            padding: 8px 14px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.15s ease, border-color 0.15s ease;
        }
        .cmi-shadcn-btn-outline:hover:not(:disabled) {
            background-color: #f4f4f5;
            border-color: #d4d4d8;
        }
        .cmi-shadcn-btn-outline:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .cmi-shadcn-btn-destructive {
            background-color: #ffffff;
            color: #ef4444;
            border: 1px solid #fee2e2;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.15s ease, border-color 0.15s ease;
        }
        .cmi-shadcn-btn-destructive:hover:not(:disabled) {
            background-color: #fef2f2;
            border-color: #fca5a5;
        }
        .cmi-shadcn-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            border: 1px solid transparent;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .cmi-shadcn-badge-completed {
            background-color: #f0fdf4;
            color: #166534;
            border-color: #bbf7d0;
        }
        .cmi-shadcn-badge-assigned, .cmi-shadcn-badge-scheduled {
            background-color: #eff6ff;
            color: #1e40af;
            border-color: #bfdbfe;
        }
        .cmi-shadcn-badge-requested {
            background-color: #f4f4f5;
            color: #3f3f46;
            border-color: #e4e4e7;
        }
        .cmi-shadcn-badge-cancelled {
            background-color: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }
        .cmi-shadcn-form-row {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .cmi-shadcn-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .cmi-shadcn-inline-card {
            background: #fafafa;
            border: 1px solid #e4e4e7;
            border-radius: 6px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .cmi-shadcn-history-container {
            max-height: 520px;
            overflow-y: auto;
            border: 1px solid #e4e4e7;
            border-radius: 8px;
            background: #ffffff;
        }
        .cmi-shadcn-history-item {
            padding: 16px 20px;
            border-bottom: 1px solid #f4f4f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background-color 0.15s ease;
        }
        .cmi-shadcn-history-item:hover {
            background-color: #fafafa;
        }
        .cmi-shadcn-history-item:last-child {
            border-bottom: none;
        }
        </style>

        <div class="cmi-consultation-portal-wrapper cmi-shadcn-wrapper">
            <div class="cmi-shadcn-layout">
                <!-- Request Form -->
                <div class="cmi-consultation-form-card cmi-shadcn-card">
                    <h3 class="cmi-shadcn-title"><?php esc_html_e( 'Request Doctor Consultation', 'cmi-partner-portal' ); ?></h3>
                    
                    <form id="cmi-consultation-booking-form" style="display: flex; flex-direction: column; gap: 16px;">
                        <!-- Patient Select -->
                        <div class="cmi-shadcn-form-row">
                            <label class="cmi-shadcn-label"><?php esc_html_e( 'Select Patient', 'cmi-partner-portal' ); ?> *</label>
                            <select name="patient_member_id" id="cmi_consult_patient_select" class="cmi-shadcn-select" required>
                                <?php
                                // Pre-scan members to determine if a Self record exists
                                // BEFORE rendering any options so the 'myself' fallback
                                // only appears when truly no Self DB record is present.
                                $has_self = false;
                                foreach ( $members as $member ) :
                                    if ( 'Self' === $member->relationship ) {
                                        $has_self = true;
                                        break; // found Self — no need to continue scan
                                    }
                                endforeach;

                                // If NO Self record exists, show 'Myself' as the first+selected option.
                                // This must come BEFORE looping other members so it is the
                                // browser default and the AJAX 'myself' path is triggered.
                                if ( ! $has_self ) : ?>
                                    <option value="myself" selected><?php esc_html_e( 'Myself (You)', 'cmi-partner-portal' ); ?></option>
                                <?php endif; ?>
                                <?php foreach ( $members as $member ) : ?>
                                    <option value="<?php echo esc_attr( intval( $member->id ) ); ?>">
                                        <?php echo esc_html( $member->name ); ?> (<?php echo esc_html( $member->relationship ); ?>)
                                    </option>
                                <?php endforeach; ?>
                                <option value="new"><?php esc_html_e( '+ Add New Family Member', 'cmi-partner-portal' ); ?></option>
                            </select>
                        </div>

                        <!-- Add Member Form Wrapper (Hidden by default) -->
                        <div id="cmi_consult_new_patient_wrapper" class="cmi-shadcn-inline-card" style="display:none;">
                            <h4 style="margin:0; font-size:14px; font-weight:600; color:#09090b;"><?php esc_html_e( 'Add Family Profile', 'cmi-partner-portal' ); ?></h4>
                            <div style="display:flex; flex-direction:column; gap:12px;">
                                <div class="cmi-shadcn-form-row">
                                    <label class="cmi-shadcn-label" style="font-size:12px;"><?php esc_html_e( 'Full Name', 'cmi-partner-portal' ); ?> *</label>
                                    <input type="text" id="cmi_new_consult_name" name="cmi_new_patient_name" class="cmi-shadcn-input">
                                </div>
                                <div class="cmi-shadcn-grid-2">
                                    <div class="cmi-shadcn-form-row">
                                        <label class="cmi-shadcn-label" style="font-size:12px;"><?php esc_html_e( 'Gender', 'cmi-partner-portal' ); ?> *</label>
                                        <select id="cmi_new_consult_gender" name="cmi_new_patient_gender" class="cmi-shadcn-select">
                                            <option value="Male"><?php esc_html_e( 'Male', 'cmi-partner-portal' ); ?></option>
                                            <option value="Female"><?php esc_html_e( 'Female', 'cmi-partner-portal' ); ?></option>
                                            <option value="Other"><?php esc_html_e( 'Other', 'cmi-partner-portal' ); ?></option>
                                        </select>
                                    </div>
                                    <div class="cmi-shadcn-form-row">
                                        <label class="cmi-shadcn-label" style="font-size:12px;"><?php esc_html_e( 'Date of Birth', 'cmi-partner-portal' ); ?> *</label>
                                        <input type="date" id="cmi_new_consult_dob" name="cmi_new_patient_dob" max="<?php echo date('Y-m-d'); ?>" class="cmi-shadcn-input">
                                    </div>
                                </div>
                                <div class="cmi-shadcn-grid-2">
                                    <div class="cmi-shadcn-form-row">
                                        <label class="cmi-shadcn-label" style="font-size:12px;"><?php esc_html_e( 'Relationship', 'cmi-partner-portal' ); ?> *</label>
                                        <select id="cmi_new_consult_relationship" name="cmi_new_patient_relationship" class="cmi-shadcn-select">
                                            <option value="Mother"><?php esc_html_e( 'Mother', 'cmi-partner-portal' ); ?></option>
                                            <option value="Father"><?php esc_html_e( 'Father', 'cmi-partner-portal' ); ?></option>
                                            <option value="Spouse"><?php esc_html_e( 'Spouse', 'cmi-partner-portal' ); ?></option>
                                            <option value="Child"><?php esc_html_e( 'Child', 'cmi-partner-portal' ); ?></option>
                                            <option value="Sibling"><?php esc_html_e( 'Sibling', 'cmi-partner-portal' ); ?></option>
                                            <option value="Other"><?php esc_html_e( 'Other', 'cmi-partner-portal' ); ?></option>
                                        </select>
                                    </div>
                                    <div class="cmi-shadcn-form-row">
                                        <label class="cmi-shadcn-label" style="font-size:12px;"><?php esc_html_e( 'Mobile Number', 'cmi-partner-portal' ); ?></label>
                                        <input type="tel" id="cmi_new_consult_mobile" name="cmi_new_patient_mobile" class="cmi-shadcn-input">
                                    </div>
                                </div>
                                <div style="display:flex; align-items:center; gap:10px; margin-top:4px;">
                                    <button type="button" id="cmi_save_consult_member_btn" class="cmi-shadcn-btn-outline"><?php esc_html_e( 'Save & Select Member', 'cmi-partner-portal' ); ?></button>
                                    <span id="cmi_save_consult_member_msg" style="font-size:12px; font-weight:600;"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Patient Mobile Number -->
                        <div class="cmi-shadcn-form-row">
                            <label class="cmi-shadcn-label"><?php esc_html_e( 'Mobile Number (For SMS & Meeting Updates)', 'cmi-partner-portal' ); ?> *</label>
                            <input type="tel" name="patient_mobile" id="cmi_consult_patient_mobile" class="cmi-shadcn-input" 
                                   value="<?php echo esc_attr( get_user_meta( get_current_user_id(), '_cmi_mobile', true ) ?: get_user_meta( get_current_user_id(), 'billing_phone', true ) ); ?>" 
                                   placeholder="10-digit mobile number" maxlength="15" required>
                        </div>

                        <!-- Doctor Select -->
                        <div class="cmi-shadcn-form-row">
                            <label class="cmi-shadcn-label"><?php esc_html_e( 'Preferred Doctor', 'cmi-partner-portal' ); ?> *</label>
                            <?php if ( ! empty( $atts['doctor_id'] ) ) : 
                                $doc = get_userdata( $atts['doctor_id'] );
                                if ( $doc ) :
                                    $specialty = get_user_meta( $doc->ID, '_cmi_specialty', true ) ?: esc_html__( 'General Physician', 'cmi-partner-portal' );
                                    $fee = get_user_meta( $doc->ID, '_cmi_consultation_fee', true ) ?: '500';
                                    ?>
                                    <input type="hidden" name="doctor_id" id="cmi_consult_doctor_select" value="<?php echo esc_attr( $doc->ID ); ?>">
                                    <div style="padding: 10px 12px; background: #f8fafc; border: 1px solid #e4e4e7; border-radius: 6px; font-weight: 500; font-size: 14px; color: #09090b;">
                                        <?php echo esc_html( $doc->display_name ); ?> (<?php echo esc_html( $specialty ); ?> - ₹<?php echo esc_html( $fee ); ?>)
                                    </div>
                                <?php endif; ?>
                            <?php else : ?>
                                <select name="doctor_id" id="cmi_consult_doctor_select" class="cmi-shadcn-select" required>
                                    <option value=""><?php esc_html_e( 'Select Preferred Doctor', 'cmi-partner-portal' ); ?></option>
                                    <?php
                                    $doctors_list = get_users( [ 'role' => 'cmi_doctor' ] );
                                    foreach ( $doctors_list as $doc ) :
                                        $specialty = get_user_meta( $doc->ID, '_cmi_specialty', true ) ?: esc_html__( 'General Physician', 'cmi-partner-portal' );
                                        $fee = get_user_meta( $doc->ID, '_cmi_consultation_fee', true ) ?: '500';
                                        ?>
                                        <option value="<?php echo esc_attr( $doc->ID ); ?>">
                                            <?php echo esc_html( $doc->display_name ); ?> (<?php echo esc_html( $specialty ); ?> - ₹<?php echo esc_html( $fee ); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>

                        <!-- Specialization Type -->
                        <div class="cmi-shadcn-form-row">
                            <label class="cmi-shadcn-label"><?php esc_html_e( 'Consultation Category', 'cmi-partner-portal' ); ?> *</label>
                            <select name="consultation_type" class="cmi-shadcn-select" required>
                                <option value="General Physician"><?php esc_html_e( 'General Physician', 'cmi-partner-portal' ); ?></option>
                                <option value="Pediatrician"><?php esc_html_e( 'Pediatrician (Children)', 'cmi-partner-portal' ); ?></option>
                                <option value="Dermatologist"><?php esc_html_e( 'Dermatologist (Skin)', 'cmi-partner-portal' ); ?></option>
                                <option value="Cardiologist"><?php esc_html_e( 'Cardiologist (Heart)', 'cmi-partner-portal' ); ?></option>
                                <option value="Gynecologist"><?php esc_html_e( 'Gynecologist (Women\'s Health)', 'cmi-partner-portal' ); ?></option>
                                <option value="Orthopedic"><?php esc_html_e( 'Orthopedic (Bones & Joints)', 'cmi-partner-portal' ); ?></option>
                                <option value="Other"><?php esc_html_e( 'Other Specialist', 'cmi-partner-portal' ); ?></option>
                            </select>
                        </div>

                        <!-- Schedule Date & Time -->
                        <div class="cmi-shadcn-grid-2">
                            <div class="cmi-shadcn-form-row">
                                <label class="cmi-shadcn-label"><?php esc_html_e( 'Preferred Date', 'cmi-partner-portal' ); ?> *</label>
                                <input type="date" name="preferred_date" min="<?php echo current_time('Y-m-d'); ?>" class="cmi-shadcn-input" required>
                            </div>
                            <div class="cmi-shadcn-form-row">
                                <label class="cmi-shadcn-label"><?php esc_html_e( 'Time Slot', 'cmi-partner-portal' ); ?> *</label>
                                <select name="preferred_time_slot" class="cmi-shadcn-select" required>
                                    <option value=""><?php esc_html_e( 'Select Date & Doctor First', 'cmi-partner-portal' ); ?></option>
                                </select>
                            </div>
                        </div>

                        <!-- Symptoms -->
                        <div class="cmi-shadcn-form-row">
                            <label class="cmi-shadcn-label"><?php esc_html_e( 'Describe Symptoms / Reason', 'cmi-partner-portal' ); ?> *</label>
                            <textarea name="symptoms" rows="4" class="cmi-shadcn-textarea" placeholder="<?php esc_attr_e( 'Please describe symptoms, history or reason for consulting a doctor...', 'cmi-partner-portal' ); ?>" required></textarea>
                        </div>

                        <div id="cmi-consult-booking-msg" style="display:none; padding:12px; border-radius:6px; font-size:14px; font-weight:500; border:1px solid transparent;"></div>

                        <button type="submit" id="cmi-submit-consultation-btn" class="cmi-shadcn-btn-primary"><?php esc_html_e( 'Submit Request', 'cmi-partner-portal' ); ?></button>
                    </form>
                </div>

                <!-- History List -->
                <div class="cmi-consultation-history-card cmi-shadcn-card">
                    <h3 class="cmi-shadcn-title"><?php esc_html_e( 'My Consultations History', 'cmi-partner-portal' ); ?></h3>
                    
                    <div class="cmi-shadcn-history-container">
                        <?php if ( empty( $results ) ) : ?>
                            <p style="text-align:center; padding: 40px; margin:0; color:#71717a; font-size:14px;"><?php esc_html_e( 'No consultation requests placed yet.', 'cmi-partner-portal' ); ?></p>
                        <?php else : ?>
                            <div style="display:flex; flex-direction:column;">
                                <?php foreach ( $results as $row ) :
                                     // Check if consultation time slot has passed
                                     $is_expired = false;
                                     $is_slot_over = false;
                                     if ( in_array( $row->status, [ 'assigned', 'scheduled', 'in_progress' ], true ) ) {
                                         $slot_parts = explode( '-', $row->preferred_time_slot );
                                         $end_str = ! empty( $slot_parts ) && isset( $slot_parts[1] ) ? trim( $slot_parts[1] ) : '';
                                         if ( $end_str ) {
                                             try {
                                                 $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Kolkata' );
                                                 if ( $timezone->getName() === 'UTC' || $timezone->getName() === '+00:00' ) {
                                                     $timezone = new DateTimeZone( 'Asia/Kolkata' );
                                                 }
                                                 $slot_end = new DateTime( $row->preferred_date . ' ' . $end_str, $timezone );
                                                 $current_time = new DateTime( 'now', $timezone );
                                                 if ( $current_time > $slot_end ) {
                                                     $is_slot_over = true;
                                                     if ( $row->status !== 'in_progress' ) {
                                                         $is_expired = true;
                                                     }
                                                 }
                                             } catch ( Exception $e ) {
                                                 // ignore
                                             }
                                         }
                                     }

                                     $badge_class = 'cmi-shadcn-badge-requested';
                                     $status_text = str_replace('_', ' ', $row->status);
                                     if ( $is_expired ) {
                                         $badge_class = 'cmi-shadcn-badge-cancelled';
                                         $status_text = esc_html__( 'Expired / Missed', 'cmi-partner-portal' );
                                     } elseif ( $row->status === 'in_progress' && $is_slot_over ) {
                                         $badge_class = 'cmi-shadcn-badge-completed';
                                         $status_text = esc_html__( 'Awaiting Prescription', 'cmi-partner-portal' );
                                     } elseif ( $row->status === 'completed' ) {
                                         $badge_class = 'cmi-shadcn-badge-completed';
                                     } elseif ( $row->status === 'assigned' || $row->status === 'scheduled' ) {
                                         $badge_class = 'cmi-shadcn-badge-assigned';
                                     } elseif ( $row->status === 'in_progress' ) {
                                         $badge_class = 'cmi-shadcn-badge-assigned';
                                     } elseif ( $row->status === 'cancelled' ) {
                                         $badge_class = 'cmi-shadcn-badge-cancelled';
                                     }
                                     ?>
                                     <div class="cmi-shadcn-history-item">
                                         <div style="display:flex; flex-direction:column; gap:4px;">
                                             <div style="display:flex; align-items:center; gap:8px;">
                                                 <strong style="font-size:15px; color:#09090b;"><?php echo esc_html( $row->patient_name ); ?></strong>
                                                 <span class="cmi-shadcn-badge <?php echo $badge_class; ?>"><?php echo esc_html( $status_text ); ?></span>
                                             </div>
                                             <div style="font-size:13px; color:#71717a; line-height:1.4;">
                                                 <span style="font-weight:500; color:#18181b;"><?php echo esc_html( $row->consultation_type ); ?></span> | 
                                                 <span><?php echo esc_html( date_i18n( get_option('date_format'), strtotime($row->preferred_date) ) ); ?> (<?php echo esc_html( $row->preferred_time_slot ); ?>)</span>
                                             </div>
                                             <?php if ( $row->doctor_id ) : 
                                                 $doc = get_userdata( $row->doctor_id );
                                                 ?>
                                                 <div style="font-size:12px; color:#71717a; margin-top:2px;">
                                                     <strong><?php esc_html_e( 'Doctor:', 'cmi-partner-portal' ); ?></strong> <?php echo $doc ? esc_html( $doc->display_name ) : esc_html__( 'Assigned Doctor', 'cmi-partner-portal' ); ?>
                                                 </div>
                                             <?php endif; ?>
                                         </div>
                                         <div>
                                             <?php if ( $is_expired ) : ?>
                                                 <div style="display:flex; flex-direction:column; gap:4px; align-items:flex-end;">
                                                     <button type="button" class="cmi-request-reschedule-btn cmi-shadcn-btn-outline" data-id="<?php echo esc_attr( $row->id ); ?>" style="font-size:11px; padding:3px 8px;"><?php esc_html_e( 'Request Reschedule', 'cmi-partner-portal' ); ?></button>
                                                 </div>
                                             <?php elseif ( $row->status === 'completed' && $row->prescription_id ) : 
                                                 $dl_link = CMI_Download::generate_link( $row->prescription_id );
                                                 ?>
                                                 <a href="<?php echo esc_url( $dl_link ); ?>" class="cmi-shadcn-btn-outline" target="_blank" style="text-decoration:none; display:inline-block; font-size:11px;"><?php esc_html_e( 'Prescription', 'cmi-partner-portal' ); ?></a>
                                             <?php elseif ( ( $row->status === 'scheduled' || $row->status === 'in_progress' ) && ! empty( $row->meeting_room_id ) ) : ?>
                                                 <?php if ( $row->status === 'in_progress' && $is_slot_over ) : ?>
                                                     <span style="font-size:12px; color:#71717a; font-weight: 500;"><?php esc_html_e( 'Call Ended', 'cmi-partner-portal' ); ?></span>
                                                 <?php else : ?>
                                                     <div style="display:flex; flex-direction:column; gap:4px; align-items:flex-end;">
                                                         <button type="button" class="cmi-join-video-btn cmi-shadcn-btn-primary" data-id="<?php echo esc_attr( $row->id ); ?>" style="font-size:11px; padding:3px 8px;"><?php esc_html_e( 'Join Call', 'cmi-partner-portal' ); ?></button>
                                                         <button type="button" class="cmi-cancel-consultation-btn cmi-shadcn-btn-destructive" data-id="<?php echo esc_attr( $row->id ); ?>" style="font-size:11px; padding:3px 8px;"><?php esc_html_e( 'Cancel', 'cmi-partner-portal' ); ?></button>
                                                     </div>
                                                 <?php endif; ?>
                                             <?php elseif ( in_array( $row->status, [ 'requested', 'assigned' ] ) ) : ?>
                                                 <button type="button" class="cmi-cancel-consultation-btn cmi-shadcn-btn-destructive" data-id="<?php echo esc_attr( $row->id ); ?>" style="font-size:11px;"><?php esc_html_e( 'Cancel', 'cmi-partner-portal' ); ?></button>
                                             <?php else : ?>
                                                 <span style="font-size:12px; color:#a1a1aa;">-</span>
                                             <?php endif; ?>
                                         </div>
                                     </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php self::render_jitsi_overlay_modal(); ?>

        <script>
        jQuery(document).ready(function($) {
            // Dynamic slots fetch based on selected doctor and date
            function fetchAvailableSlots() {
                var doctorId = $('#cmi_consult_doctor_select').val();
                var date = $('input[name="preferred_date"]').val();
                var slotSelect = $('select[name="preferred_time_slot"]');

                if (!doctorId || !date) {
                    slotSelect.html('<option value=""><?php esc_html_e( 'Select Date & Doctor First', 'cmi-partner-portal' ); ?></option>');
                    return;
                }

                slotSelect.prop('disabled', true).html('<option><?php esc_html_e( 'Loading slots...', 'cmi-partner-portal' ); ?></option>');

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: {
                        action: 'cmi_get_available_slots',
                        doctor_id: doctorId,
                        date: date,
                        nonce: '<?php echo wp_create_nonce("cmi_pp_nonce"); ?>'
                    },
                    success: function(response) {
                        slotSelect.prop('disabled', false);
                        if (response.success && response.data.slots && response.data.slots.length > 0) {
                            var html = '';
                            response.data.slots.forEach(function(slot) {
                                html += '<option value="' + slot + '">' + slot + '</option>';
                            });
                            slotSelect.html(html);
                        } else {
                            slotSelect.html('<option value=""><?php esc_html_e( 'No slots available for this day', 'cmi-partner-portal' ); ?></option>');
                        }
                    },
                    error: function() {
                        slotSelect.prop('disabled', false).html('<option value=""><?php esc_html_e( 'Error loading slots', 'cmi-partner-portal' ); ?></option>');
                    }
                });
            }

            $('#cmi_consult_doctor_select, input[name="preferred_date"]').on('change', fetchAvailableSlots);

            // Patient Dropdown Toggle Inline Form
            $('#cmi_consult_patient_select').on('change', function() {
                if ($(this).val() === 'new') {
                    $('#cmi_consult_new_patient_wrapper').slideDown();
                } else {
                    $('#cmi_consult_new_patient_wrapper').slideUp();
                }
            });

            // Save Family Member inline via companion AJAX
            $('#cmi_save_consult_member_btn').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var msg = $('#cmi_save_consult_member_msg');

                var name = $('#cmi_new_consult_name').val().trim();
                var gender = $('#cmi_new_consult_gender').val();
                var dob = $('#cmi_new_consult_dob').val();
                var relationship = $('#cmi_new_consult_relationship').val();
                var mobile = $('#cmi_new_consult_mobile').val().trim();

                if (!name || !dob) {
                    msg.css('color', '#ef4444').text('Name and DOB are required.');
                    return;
                }

                btn.prop('disabled', true).text('Saving...');
                msg.css('color', '#2563eb').text('Saving...');

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: {
                        action: 'cmi_add_family_member',
                        nonce: '<?php echo wp_create_nonce("cmi_pp_nonce"); ?>',
                        name: name,
                        gender: gender,
                        dob: dob,
                        relationship: relationship,
                        mobile: mobile
                    },
                    success: function(response) {
                        btn.prop('disabled', false).text('Save & Select Member');
                        if (response.success) {
                            msg.css('color', '#16a34a').text(response.data.message);
                            
                            var newOptionText = response.data.name + ' (' + response.data.relationship + ')';
                            var newOptionVal = response.data.member_id;
                            
                            // Append and select
                            $('#cmi_consult_patient_select').find('option[value="new"]').before('<option value="' + newOptionVal + '">' + newOptionText + '</option>');
                            $('#cmi_consult_patient_select').val(newOptionVal).trigger('change');
                            
                            // Clear inputs
                            $('#cmi_new_consult_name').val('');
                            $('#cmi_new_consult_dob').val('');
                            $('#cmi_new_consult_mobile').val('');
                            
                            $('#cmi_consult_new_patient_wrapper').slideUp();
                            msg.text('');
                        } else {
                            msg.css('color', '#ef4444').text(response.data.message || 'Failed to save member.');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Save & Select Member');
                        msg.css('color', '#ef4444').text('Connection error.');
                    }
                });
            });

            // Submit Consultation Form
            $('#cmi-consultation-booking-form').on('submit', function(e) {
                e.preventDefault();
                var form = $(this);
                var btn = $('#cmi-submit-consultation-btn');
                var msg = $('#cmi-consult-booking-msg');

                btn.prop('disabled', true).text('Submitting...');
                msg.hide();

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: form.serialize() + '&action=cmi_submit_consultation&nonce=<?php echo wp_create_nonce("cmi_pp_nonce"); ?>',
                    success: function(response) {
                        if (response.success) {
                            msg.css({'background':'#f0fdf4', 'border-color':'#bbf7d0', 'color':'#166534'}).text(response.data.message).show();
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            btn.prop('disabled', false).text('Submit Request');
                            msg.css({'background':'#fef2f2', 'border-color':'#fecaca', 'color':'#991b1b'}).text(response.data.message || 'Submission failed.').show();
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Submit Request');
                        msg.css({'background':'#fef2f2', 'border-color':'#fecaca', 'color':'#991b1b'}).text('Connection error. Please try again.').show();
                    }
                });
            });

            // Cancel Consultation
            $('.cmi-cancel-consultation-btn').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var id = btn.data('id');

                if (!confirm('Are you sure you want to cancel this consultation request?')) {
                    return;
                }

                btn.prop('disabled', true).text('Cancelling...');

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: {
                        action: 'cmi_cancel_consultation',
                        id: id,
                        nonce: '<?php echo wp_create_nonce("cmi_pp_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            btn.prop('disabled', false).text('Cancel');
                            alert(response.data.message || 'Cancellation failed.');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Cancel');
                        alert('Connection error.');
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Submit new consultation request to database.
     */
    public function ajax_submit_consultation() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Please log in to submit a consultation request.', 'cmi-partner-portal' ) ] );
        }

        // ── Payment integrity gate ────────────────────────────────────────────
        // If a consultation product ID is configured, verify the user has paid
        // before allowing a consultation record to be created.  This prevents
        // free consultation creation by any authenticated user who simply POSTs
        // to this AJAX endpoint without completing payment.
        //
        // Configuration: set option 'cmi_consultation_product_id' to the WC
        // product ID of the consultation product.  Leave empty to skip (e.g. for
        // complimentary consultations or internal doctor bookings).
        $consultation_product_id = absint( get_option( 'cmi_consultation_product_id', 0 ) );
        if ( $consultation_product_id <= 0 && 'yes' !== get_option( 'cmi_allow_free_consultations', 'no' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Consultation payment is not configured. Please contact CMI Healthcare.', 'cmi-partner-portal' ) ] );
        }

        $payment = null;
        if ( $consultation_product_id > 0 ) {
            $payment = $this->get_available_consultation_payment( $user_id, $consultation_product_id );
            if ( is_wp_error( $payment ) ) {
                wp_send_json_error( [ 'message' => $payment->get_error_message() ] );
            }
        }
        // ── End payment gate ─────────────────────────────────────────────────

        $member_id_raw     = isset( $_POST['patient_member_id'] ) ? sanitize_text_field( $_POST['patient_member_id'] ) : '';
        $consultation_type = isset( $_POST['consultation_type'] ) ? sanitize_text_field( $_POST['consultation_type'] ) : '';
        $symptoms          = isset( $_POST['symptoms'] ) ? sanitize_textarea_field( $_POST['symptoms'] ) : '';
        $preferred_date    = isset( $_POST['preferred_date'] ) ? sanitize_text_field( $_POST['preferred_date'] ) : '';
        $preferred_time    = isset( $_POST['preferred_time_slot'] ) ? sanitize_text_field( $_POST['preferred_time_slot'] ) : '';
        $doctor_id         = isset( $_POST['doctor_id'] ) ? intval( $_POST['doctor_id'] ) : 0;

        if ( empty( $member_id_raw ) || empty( $consultation_type ) || empty( $symptoms ) || empty( $preferred_date ) || empty( $preferred_time ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Please fill in all required fields.', 'cmi-partner-portal' ) ] );
        }

        // Validate date
        $today = current_time( 'Y-m-d' );
        if ( $preferred_date < $today ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Preferred date must be a future or present date.', 'cmi-partner-portal' ) ] );
        }

        // Configurable same-day booking buffer — must match the slot generation filter in ajax_get_available_slots()
        if ( $preferred_date === $today ) {
            $parts = explode( '-', $preferred_time );
            $start_str = ! empty( $parts ) ? trim( $parts[0] ) : '';
            if ( $start_str ) {
                $buffer_minutes = absint( get_option( 'cmi_same_day_buffer_minutes', 30 ) );
                $buffer_seconds = $buffer_minutes * 60;
                $buffer_label   = $buffer_minutes . ' ' . _n( 'minute', 'minutes', $buffer_minutes, 'cmi-partner-portal' );
                try {
                    $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( get_option( 'timezone_string' ) ?: 'UTC' );
                    if ( $timezone->getName() === 'UTC' || $timezone->getName() === '+00:00' ) {
                        $timezone = new DateTimeZone( 'Asia/Kolkata' );
                    }
                    $slot_start_time      = new DateTime( $preferred_date . ' ' . $start_str, $timezone );
                    $slot_start_timestamp = $slot_start_time->getTimestamp();
                    $current_time         = new DateTime( 'now', $timezone );
                    $current_timestamp    = $current_time->getTimestamp();
                    if ( $slot_start_timestamp < $current_timestamp + $buffer_seconds ) {
                        wp_send_json_error( [ 'message' => sprintf(
                            /* translators: %s = buffer duration e.g. "30 minutes" */
                            esc_html__( 'Same-day bookings require at least %s advance notice. Please choose a later slot.', 'cmi-partner-portal' ),
                            $buffer_label
                        ) ] );
                    }
                } catch ( Exception $e ) {
                    $slot_start_timestamp = strtotime( $today . ' ' . $start_str );
                    $current_timestamp    = current_time( 'timestamp' );
                    if ( $slot_start_timestamp < ( $current_timestamp + $buffer_seconds ) ) {
                        wp_send_json_error( [ 'message' => sprintf(
                            esc_html__( 'Same-day bookings require at least %s advance notice. Please choose a later slot.', 'cmi-partner-portal' ),
                            $buffer_label
                        ) ] );
                    }
                }
            }
        }

        if ( $doctor_id && ! $this->is_consultation_slot_bookable( $doctor_id, $preferred_date, $preferred_time ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'The selected doctor slot is no longer available. Please choose another slot.', 'cmi-partner-portal' ) ] );
        }

        if ( 'new' === $member_id_raw ) {
            // Save new family member to DB inline
            $name         = isset( $_POST['cmi_new_patient_name'] ) ? sanitize_text_field( $_POST['cmi_new_patient_name'] ) : '';
            $gender       = isset( $_POST['cmi_new_patient_gender'] ) ? sanitize_text_field( $_POST['cmi_new_patient_gender'] ) : '';
            $dob          = isset( $_POST['cmi_new_patient_dob'] ) ? sanitize_text_field( $_POST['cmi_new_patient_dob'] ) : '';
            $relationship = isset( $_POST['cmi_new_patient_relationship'] ) ? sanitize_text_field( $_POST['cmi_new_patient_relationship'] ) : '';
            $mobile       = isset( $_POST['cmi_new_patient_mobile'] ) ? sanitize_text_field( $_POST['cmi_new_patient_mobile'] ) : '';

            if ( empty( $name ) || empty( $gender ) || empty( $dob ) || empty( $relationship ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Please fill in all required fields for the new family member.', 'cmi-partner-portal' ) ] );
            }

            $new_member_id = CMI_HT_DB::add_member( $user_id, $name, $gender, $dob, $relationship, $mobile );
            if ( ! $new_member_id ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Failed to create new family member.', 'cmi-partner-portal' ) ] );
            }
            $member_id = $new_member_id;
        } elseif ( 'myself' === $member_id_raw ) {
            // Resolve 'myself' to the user's own 'Self' member record.
            // get_user_members() auto-creates a Self record if none exists.
            $self_members = CMI_HT_DB::get_user_members( $user_id );
            foreach ( $self_members as $m ) {
                if ( 'Self' === $m->relationship ) {
                    $member_id = intval( $m->id ); // Cast to int immediately
                    break;
                }
            }
            // Strict fallback: if no 'Self' relationship exists, refuse rather
            // than silently assigning an arbitrary family member.
            if ( ! isset( $member_id ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Could not find your personal profile. Please contact support.', 'cmi-partner-portal' ) ] );
            }
        } else {
            $member_id = intval( $member_id_raw );
        }

        // Fetch patient details
        $member = CMI_HT_DB::get_member( $member_id );
        if ( ! $member || intval( $member->user_id ) !== intval( $user_id ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid patient selection.', 'cmi-partner-portal' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        // ── Slot-locking transaction ─────────────────────────────────────────
        // We use START TRANSACTION + SELECT … FOR UPDATE so that concurrent
        // requests for the same slot block on the lock rather than racing past
        // the duplicate check.  The lock is released on COMMIT or ROLLBACK.
        $wpdb->query( 'START TRANSACTION' );

        // Double-booking guard: lock any existing active row for this
        // patient+date combination so no second request can slip past.
        $duplicate = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table
             WHERE user_id = %d
               AND patient_member_id = %d
               AND preferred_date = %s
               AND status NOT IN ('cancelled')
             LIMIT 1
             FOR UPDATE",
            $user_id,
            $member_id,
            $preferred_date
        ) );

        if ( $duplicate ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( [ 'message' => esc_html__( 'You have already requested a consultation for this patient on the selected date.', 'cmi-partner-portal' ) ] );
        }

        // Doctor slot-overbooking guard: if a doctor was pre-selected, verify
        // the slot is still free, locking any conflicting row to block races.
        if ( $doctor_id ) {
            $slot_taken = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table
                 WHERE doctor_id = %d
                   AND preferred_date = %s
                   AND preferred_time_slot = %s
                   AND status NOT IN ('cancelled')
                 LIMIT 1
                 FOR UPDATE",
                $doctor_id,
                $preferred_date,
                $preferred_time
            ) );

            if ( $slot_taken ) {
                $wpdb->query( 'ROLLBACK' );
                wp_send_json_error( [ 'message' => esc_html__( 'This time slot has just been booked by another patient. Please choose a different slot.', 'cmi-partner-portal' ) ] );
            }
        }

        // Resolve patient mobile number (POST field -> member record -> usermeta fallback)
        $posted_mobile  = isset( $_POST['patient_mobile'] ) ? sanitize_text_field( $_POST['patient_mobile'] ) : '';
        $patient_mobile = ! empty( $posted_mobile ) ? $posted_mobile
            : ( ! empty( $member->mobile ) ? $member->mobile
                : ( get_user_meta( $user_id, '_cmi_mobile', true ) ?: get_user_meta( $user_id, 'billing_phone', true ) ) );

        if ( ! empty( $patient_mobile ) && empty( get_user_meta( $user_id, '_cmi_mobile', true ) ) ) {
            update_user_meta( $user_id, '_cmi_mobile', $patient_mobile );
        }

        // Insert inside the open transaction.
        $result = $wpdb->insert(
            $table,
            [
                'order_id'             => $payment ? absint( $payment['order_id'] ) : null,
                'order_item_id'        => $payment ? absint( $payment['order_item_id'] ) : null,
                'user_id'              => $user_id,
                'patient_member_id'    => $member_id,
                'patient_name'         => $member->name,
                'patient_gender'       => $member->gender,
                'patient_dob'          => $member->dob,
                'patient_relationship' => $member->relationship,
                'patient_mobile'       => $patient_mobile,
                'consultation_type'    => $consultation_type,
                'symptoms'             => $symptoms,
                'preferred_date'       => $preferred_date,
                'preferred_time_slot'  => $preferred_time,
                'status'               => $doctor_id ? 'assigned' : 'requested',
                'doctor_id'            => $doctor_id ? $doctor_id : null,
                'created_at'           => current_time( 'mysql' ),
                'updated_at'           => current_time( 'mysql' )
            ],
            [ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $result ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to submit request. Please try again.', 'cmi-partner-portal' ) ] );
        }

        $consult_id = $wpdb->insert_id;
        $wpdb->query( 'COMMIT' );
        if ( $payment ) {
            $this->mark_consultation_payment_consumed( $payment, $consult_id );
        }
        // ── End transaction ──────────────────────────────────────────────────

        if ( $doctor_id ) {
            self::generate_jitsi_meeting_data( $consult_id );
            do_action( 'cmi_consultation_assigned', $consult_id, $doctor_id );
        } else {
            do_action( 'cmi_consultation_requested', $consult_id );
        }
        wp_send_json_success( [ 'message' => esc_html__( 'Your consultation request has been submitted successfully.', 'cmi-partner-portal' ) ] );
    }

    /**
     * Cancel a consultation request by patient.
     */
    public function ajax_cancel_consultation() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-partner-portal' ) ] );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid consultation ID.', 'cmi-partner-portal' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND user_id = %d", $id, $user_id ) );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Record not found.', 'cmi-partner-portal' ) ] );
        }

        if ( in_array( $row->status, [ 'completed', 'cancelled' ] ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Cannot cancel a completed or already cancelled consultation.', 'cmi-partner-portal' ) ] );
        }

        $update = $wpdb->update(
            $table,
            [ 'status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( $update !== false ) {
            do_action( 'cmi_consultation_cancelled', $id );
            wp_send_json_success( [ 'message' => esc_html__( 'Consultation cancelled successfully.', 'cmi-partner-portal' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to cancel consultation.', 'cmi-partner-portal' ) ] );
        }
    }

    /**
     * Assign Doctor to Consultation (Admin action).
     */
    public function ajax_admin_assign_doctor() {
        check_ajax_referer( 'cmi_ht_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-partner-portal' ) ] );
        }

        $id        = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $doctor_id = isset( $_POST['doctor_id'] ) ? intval( $_POST['doctor_id'] ) : 0;

        if ( ! $id || ! $doctor_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid parameter inputs.', 'cmi-partner-portal' ) ] );
        }

        // Verify doctor role
        $doctor = get_userdata( $doctor_id );
        if ( ! $doctor || ! in_array( 'cmi_doctor', $doctor->roles ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Assigned user must be a doctor.', 'cmi-partner-portal' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        // ── Slot-locking transaction ─────────────────────────────────────────
        // Lock the target consultation row first so no parallel admin request
        // can reassign it simultaneously, then verify the doctor has no
        // conflicting active booking at the same date/time before committing.
        $wpdb->query( 'START TRANSACTION' );

        // Lock the target consultation row.
        $consult = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, preferred_date, preferred_time_slot, status
             FROM $table
             WHERE id = %d
             LIMIT 1
             FOR UPDATE",
            $id
        ) );

        if ( ! $consult ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( [ 'message' => esc_html__( 'Consultation record not found.', 'cmi-partner-portal' ) ] );
        }

        if ( in_array( $consult->status, [ 'completed', 'cancelled' ], true ) ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( [ 'message' => esc_html__( 'Cannot reassign a completed or cancelled consultation.', 'cmi-partner-portal' ) ] );
        }

        // Doctor overlap guard: block assignment if this doctor already has a
        // non-cancelled booking at the same date + time slot (different consult).
        $overlap = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table
             WHERE doctor_id = %d
               AND preferred_date = %s
               AND preferred_time_slot = %s
               AND id != %d
               AND status NOT IN ('cancelled')
             LIMIT 1
             FOR UPDATE",
            $doctor_id,
            $consult->preferred_date,
            $consult->preferred_time_slot,
            $id
        ) );

        if ( $overlap ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( [ 'message' => esc_html__( 'This doctor already has an active consultation at the same date and time. Please assign a different doctor or time slot.', 'cmi-partner-portal' ) ] );
        }

        $update = $wpdb->update(
            $table,
            [
                'doctor_id'  => $doctor_id,
                'status'     => 'assigned',
                'updated_at' => current_time( 'mysql' )
            ],
            [ 'id' => $id ],
            [ '%d', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $update === false ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( [ 'message' => esc_html__( 'Database write failed.', 'cmi-partner-portal' ) ] );
        }

        $wpdb->query( 'COMMIT' );
        // ── End transaction ──────────────────────────────────────────────────

        self::generate_jitsi_meeting_data( $id );
        do_action( 'cmi_consultation_assigned', $id, $doctor_id );
        wp_send_json_success( [ 'message' => esc_html__( 'Doctor successfully assigned.', 'cmi-partner-portal' ) ] );
    }

    /**
     * Update consultation status (Admin action).
     */
    public function ajax_admin_update_consultation_status() {
        check_ajax_referer( 'cmi_ht_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-partner-portal' ) ] );
        }

        $id     = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $status = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : '';

        if ( ! $id || empty( $status ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid parameter inputs.', 'cmi-partner-portal' ) ] );
        }

        $allowed_statuses = [ 'requested', 'assigned', 'scheduled', 'in_progress', 'awaiting_prescription', 'rescheduled', 'completed', 'cancelled', 'needs_reschedule' ];

        if ( ! in_array( $status, $allowed_statuses ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid status.', 'cmi-partner-portal' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        $update = $wpdb->update(
            $table,
            [
                'status'     => $status,
                'updated_at' => current_time( 'mysql' )
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( $update !== false ) {
            if ( in_array( $status, [ 'assigned', 'scheduled' ] ) ) {
                self::generate_jitsi_meeting_data( $id );
            }
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
            if ( $row ) {
                // NOTE: 'assigned' status notification is fired by ajax_admin_assign_doctor().
                // Firing it here too would send duplicate emails to the doctor and patient.
                if ( 'scheduled' === $status ) {
                    do_action( 'cmi_consultation_scheduled', $id );
                } elseif ( 'assigned' === $status ) {
                    do_action( 'cmi_consultation_assigned', $id, $row->doctor_id );
                } elseif ( 'rescheduled' === $status ) {
                    do_action( 'cmi_consultation_rescheduled_by_admin', $id );
                } elseif ( 'completed' === $status ) {
                    do_action( 'cmi_consultation_completed', $id );
                } elseif ( 'cancelled' === $status ) {
                    do_action( 'cmi_consultation_cancelled', $id );
                }
            }
            wp_send_json_success( [ 'message' => esc_html__( 'Consultation status updated.', 'cmi-partner-portal' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Database update failed.', 'cmi-partner-portal' ) ] );
        }
    }

    /**
     * Update consultation date and time slot (Admin action).
     */
    public function ajax_admin_update_consultation_schedule() {
        check_ajax_referer( 'cmi_ht_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-partner-portal' ) ] );
        }

        $id   = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $date = isset( $_POST['date'] ) ? sanitize_text_field( $_POST['date'] ) : '';
        $slot = isset( $_POST['slot'] ) ? sanitize_text_field( $_POST['slot'] ) : '';

        if ( ! $id || empty( $date ) || empty( $slot ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid parameter inputs.', 'cmi-partner-portal' ) ] );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || false === strtotime( $date ) || $date < current_time( 'Y-m-d' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid consultation date.', 'cmi-partner-portal' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        // Fetch current status to see if it was needs_reschedule
        $current_row = $wpdb->get_row( $wpdb->prepare( "SELECT status, doctor_id FROM $table WHERE id = %d", $id ) );
        if ( ! $current_row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Consultation not found.', 'cmi-partner-portal' ) ] );
        }
        if ( $current_row->doctor_id && ! $this->is_consultation_slot_bookable( $current_row->doctor_id, $date, $slot, $id ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'The selected doctor slot is unavailable or conflicts with another consultation.', 'cmi-partner-portal' ) ] );
        }

        $update_data = [
            'preferred_date'      => $date,
            'preferred_time_slot' => $slot,
            'updated_at'          => current_time( 'mysql' )
        ];

        // If status was needs_reschedule, transition it back to scheduled/assigned automatically
        if ( $current_row->status === 'needs_reschedule' ) {
            $update_data['status'] = $current_row->doctor_id ? 'scheduled' : 'requested';
        }

        $update = $wpdb->update(
            $table,
            $update_data,
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $update !== false ) {
            // Re-generate Jitsi meeting room/URL if scheduled or assigned
            $new_status = isset( $update_data['status'] ) ? $update_data['status'] : $current_row->status;
            if ( in_array( $new_status, [ 'assigned', 'scheduled' ] ) ) {
                self::generate_jitsi_meeting_data( $id );
            }

            // Notify patient and doctor about the rescheduling
            do_action( 'cmi_consultation_rescheduled_by_admin', $id );

            wp_send_json_success( [ 'message' => esc_html__( 'Consultation schedule updated successfully.', 'cmi-partner-portal' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Database write failed.', 'cmi-partner-portal' ) ] );
        }
    }

    /**
     * Helper to generate unique Jitsi meeting room data if not already exists.
     */
    public static function generate_jitsi_meeting_data( $id, $row = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        if ( ! $row ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        }
        if ( ! $row || ! empty( $row->meeting_room_id ) ) {
            return;
        }

        $room_id = 'CMI-' . date( 'Y' ) . '-' . strtoupper( wp_generate_password( 8, false, false ) ) . '-' . strtoupper( wp_generate_password( 4, false, false ) );
        $jitsi_domain = get_option( 'cmi_jitsi_domain', 'meet.jit.si' );

        $wpdb->update(
            $table,
            [
                'meeting_room_id' => $room_id,
                'meeting_url'     => 'https://' . $jitsi_domain . '/' . $room_id,
                'updated_at'      => current_time( 'mysql' )
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Generate a signed Jitsi JWT token (HS256).
     *
     * Works with self-hosted Jitsi (TOKEN_BASED_AUTH=1, luajwtjitsi) and Jaas
     * (jaas.8x8.vc).  No PHP JWT library is required — uses only hash_hmac().
     *
     * wp_options required:
     *   cmi_jitsi_app_id     — JWT issuer / App ID (iss claim)
     *   cmi_jitsi_app_secret — HS256 shared secret (never sent to client)
     *   cmi_jitsi_domain     — Jitsi domain (sub claim)
     *
     * @param string $room_id       Meeting room identifier.
     * @param int    $user_id       WordPress user ID of the joining participant.
     * @param bool   $is_moderator  True → doctor (host), False → patient (guest).
     * @return string|false  Signed JWT string, or false when not configured.
     */
    /**
     * Generate a signed Jitsi JWT token (RS256 / ES256 for JaaS, or HS256 for self-hosted).
     *
     * Supports Jitsi as a Service (8x8.vc) using RSA / EC Private Keys (.pk / .pem)
     * and fallback to self-hosted Jitsi (TOKEN_BASED_AUTH=1, luajwtjitsi).
     * No composer dependencies required — uses native OpenSSL PHP functions.
     *
     * wp_options used:
     *   cmi_jitsi_domain      — Jitsi domain (e.g. 8x8.vc)
     *   cmi_jitsi_app_id      — App ID / Tenant ID (sub claim for JaaS)
     *   cmi_jitsi_api_key_id  — API Key ID (header kid claim for JaaS)
     *   cmi_jitsi_private_key — Private key PEM string or server file path (.pk/.pem)
     *   cmi_jitsi_app_secret  — Legacy HS256 shared secret
     *
     * @param string $room_id       Meeting room identifier.
     * @param int    $user_id       WordPress user ID of the joining participant.
     * @param bool   $is_moderator  True → doctor (host), False → patient (guest).
     * @return string|false  Signed JWT string, or false when not configured.
     */
    private static function generate_jitsi_jwt( $room_id, $user_id, $is_moderator ) {
        $app_id       = trim( get_option( 'cmi_jitsi_app_id', '' ) );
        $api_key_id   = trim( get_option( 'cmi_jitsi_api_key_id', '' ) );
        $priv_key_opt = trim( get_option( 'cmi_jitsi_private_key', '' ) );
        $app_secret   = trim( get_option( 'cmi_jitsi_app_secret', '' ) );
        $domain       = rtrim( get_option( 'cmi_jitsi_domain', '8x8.vc' ), '/' );

        $user  = get_userdata( $user_id );
        $name  = $user ? $user->display_name : ( $is_moderator ? 'Doctor' : 'Patient' );
        $email = $user ? $user->user_email   : '';

        $now = time();
        $ttl_minutes = max( 5, min( 60, absint( get_option( 'cmi_jitsi_token_ttl_minutes', 20 ) ) ) );
        $exp = $now + ( $ttl_minutes * MINUTE_IN_SECONDS );

        $b64url = function ( $data ) {
            return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
        };

        // ── 1. Try JaaS / PKI Authentication (RS256 or ES256) ───────────────────
        $pkey = null;
        if ( ! empty( $priv_key_opt ) ) {
            if ( strpos( $priv_key_opt, '-----BEGIN' ) !== false ) {
                $pkey = openssl_pkey_get_private( $priv_key_opt );
            } elseif ( file_exists( $priv_key_opt ) ) {
                $key_raw = file_get_contents( $priv_key_opt );
                if ( $key_raw !== false ) {
                    $pkey = openssl_pkey_get_private( $key_raw );
                }
            }
        }

        if ( $pkey ) {
            $key_details = openssl_pkey_get_details( $pkey );
            $alg = 'RS256';
            if ( isset( $key_details['type'] ) && $key_details['type'] === OPENSSL_KEYTYPE_EC ) {
                $alg = 'ES256';
            }

            $kid = ! empty( $api_key_id ) ? $api_key_id : $app_id;

            $header = [
                'alg' => $alg,
                'typ' => 'JWT',
                'kid' => $kid,
            ];

            // Payload structure according to official 8x8 JaaS specifications
            $payload = [
                'aud'     => 'jitsi',
                'iss'     => 'chat',
                'sub'     => $app_id,
                'room'    => $room_id,
                'exp'     => $exp,
                'nbf'     => $now - 5,
                'context' => [
                    'user' => [
                        'id'          => (string) $user_id,
                        'name'        => $name,
                        'email'       => $email,
                        'moderator'   => (bool) $is_moderator,
                        'affiliation' => $is_moderator ? 'owner' : 'member',
                        'avatar'      => '',
                    ],
                    'features' => [
                        'livestreaming' => (bool) $is_moderator,
                        'recording'     => (bool) $is_moderator,
                        'transcription' => (bool) $is_moderator,
                        'outbound-call' => false,
                    ],
                ],
            ];

            $header_enc    = $b64url( wp_json_encode( $header ) );
            $payload_enc   = $b64url( wp_json_encode( $payload ) );
            $signing_input = $header_enc . '.' . $payload_enc;

            $signature_bin = '';
            if ( $alg === 'RS256' ) {
                if ( ! openssl_sign( $signing_input, $signature_bin, $pkey, OPENSSL_ALGO_SHA256 ) ) {
                    return false;
                }
            } elseif ( $alg === 'ES256' ) {
                $der = '';
                if ( ! openssl_sign( $signing_input, $der, $pkey, OPENSSL_ALGO_SHA256 ) ) {
                    return false;
                }
                // Convert ASN.1 DER signature to raw R+S concatenation (64 bytes)
                $offset = 2;
                if ( ord( $der[1] ) & 0x80 ) {
                    $offset += ( ord( $der[1] ) & 0x7f );
                }
                $r_len = ord( $der[ $offset + 1 ] );
                $r     = substr( $der, $offset + 2, $r_len );
                $offset += 2 + $r_len;
                $s_len = ord( $der[ $offset + 1 ] );
                $s     = substr( $der, $offset + 2, $s_len );

                $r = ltrim( $r, "\x00" );
                $s = ltrim( $s, "\x00" );
                $r = str_pad( $r, 32, "\x00", STR_PAD_LEFT );
                $s = str_pad( $s, 32, "\x00", STR_PAD_LEFT );
                $signature_bin = $r . $s;
            }

            return $signing_input . '.' . $b64url( $signature_bin );
        }

        // ── 2. Fallback to Legacy HS256 Secret Authentication ──────────────────
        if ( ! empty( $app_id ) && ! empty( $app_secret ) ) {
            $header = [
                'alg' => 'HS256',
                'typ' => 'JWT',
                'kid' => $app_id . '/HS256',
            ];

            $payload = [
                'aud'     => 'jitsi',
                'iss'     => $app_id,
                'sub'     => $domain,
                'room'    => $room_id,
                'exp'     => $exp,
                'nbf'     => $now - 5,
                'context' => [
                    'user' => [
                        'id'          => (string) $user_id,
                        'name'        => $name,
                        'email'       => $email,
                        'moderator'   => (bool) $is_moderator,
                        'affiliation' => $is_moderator ? 'owner' : 'member',
                        'avatar'      => '',
                    ],
                    'features' => [
                        'livestreaming' => (bool) $is_moderator,
                        'recording'     => (bool) $is_moderator,
                        'transcription' => (bool) $is_moderator,
                        'outbound-call' => false,
                    ],
                ],
            ];

            $header_enc    = $b64url( wp_json_encode( $header ) );
            $payload_enc   = $b64url( wp_json_encode( $payload ) );
            $signing_input = $header_enc . '.' . $payload_enc;
            $signature     = $b64url( hash_hmac( 'sha256', $signing_input, $app_secret, true ) );

            return $signing_input . '.' . $signature;
        }

        // Fail closed when protected meeting mode is enabled.
        return false;
    }

    /**
     * Render the doctor consultations tab content inside partner portal.
     */
    public static function render_doctor_consultations_tab() {
        $doctor_id = get_current_user_id();
        $user      = wp_get_current_user();
        $is_doctor = in_array( 'cmi_doctor', (array) $user->roles );
        if ( ! $doctor_id || ( ! current_user_can( 'cmi_view_assignments' ) && ! $is_doctor ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE doctor_id = %d ORDER BY id DESC LIMIT 100",
            $doctor_id
        ) );

        ?>
        <div class="cmi-doctor-consultations-wrapper" style="font-family: inherit;">
            <h2 style="font-size:20px; font-weight:700; color:#1a4f8a; margin-bottom:15px;"><?php esc_html_e( 'Assigned Consultations', 'cmi-partner-portal' ); ?></h2>
            
            <div class="cmi-table-responsive">
                <table class="cmi-dashboard-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'ID', 'cmi-partner-portal' ); ?></th>
                            <th><?php esc_html_e( 'Patient Name', 'cmi-partner-portal' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'cmi-partner-portal' ); ?></th>
                            <th><?php esc_html_e( 'Schedule Details', 'cmi-partner-portal' ); ?></th>
                            <th><?php esc_html_e( 'Reason / Symptoms', 'cmi-partner-portal' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'cmi-partner-portal' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'cmi-partner-portal' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $results ) ) : ?>
                            <tr>
                                <td colspan="7" class="cmi-empty" style="text-align:center; padding:20px;"><?php esc_html_e( 'No doctor consultation jobs assigned.', 'cmi-partner-portal' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $results as $row ) : ?>
                                <tr data-id="<?php echo esc_attr( $row->id ); ?>">
                                    <td>#<?php echo esc_html( $row->id ); ?></td>
                                    <td>
                                        <strong><?php echo esc_html( $row->patient_name ); ?></strong><br>
                                        <span class="description" style="font-size:11px;"><?php printf( __( 'Gender: %s | DOB: %s', 'cmi-partner-portal' ), esc_html( $row->patient_gender ), esc_html( $row->patient_dob ) ); ?></span>
                                    </td>
                                    <td><span style="font-weight:600; color:#2b6cb0;"><?php echo esc_html( $row->consultation_type ); ?></span></td>
                                    <td>
                                        <strong><?php echo esc_html( date_i18n( get_option('date_format'), strtotime($row->preferred_date) ) ); ?></strong><br>
                                        <span class="description"><?php echo esc_html( $row->preferred_time_slot ); ?></span>
                                    </td>
                                    <td><p style="margin:0; font-size:12px; max-width:180px; word-break:break-word; white-space:normal; line-height:1.4;" title="<?php echo esc_attr( $row->symptoms ); ?>"><?php echo esc_html( $row->symptoms ); ?></p></td>
                                    <td>
                                        <?php 
                                        $is_slot_over = false;
                                        if ( in_array( $row->status, [ 'assigned', 'scheduled', 'in_progress' ], true ) ) {
                                            $slot_parts = explode( '-', $row->preferred_time_slot );
                                            $end_str = ! empty( $slot_parts ) && isset( $slot_parts[1] ) ? trim( $slot_parts[1] ) : '';
                                            if ( $end_str ) {
                                                try {
                                                    $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Kolkata' );
                                                    if ( $timezone->getName() === 'UTC' || $timezone->getName() === '+00:00' ) {
                                                        $timezone = new DateTimeZone( 'Asia/Kolkata' );
                                                    }
                                                    $slot_end = new DateTime( $row->preferred_date . ' ' . $end_str, $timezone );
                                                    $current_time = new DateTime( 'now', $timezone );
                                                    if ( $current_time > $slot_end ) {
                                                        $is_slot_over = true;
                                                    }
                                                } catch ( Exception $e ) {
                                                    // ignore
                                                }
                                            }
                                        }

                                        $display_status = $row->status;
                                        $status_class = str_replace( '_', '-', $row->status );
                                        if ( $row->status === 'in_progress' && $is_slot_over ) {
                                            $display_status = 'Awaiting Prescription';
                                            $status_class   = 'awaiting-prescription';
                                        } elseif ( ( $row->status === 'scheduled' || $row->status === 'assigned' ) && $is_slot_over ) {
                                            $display_status = 'Expired / Missed';
                                            $status_class   = 'expired-missed';
                                        }
                                        ?>
                                        <span class="cmi-badge cmi-status-<?php echo esc_attr( $status_class ); ?>">
                                            <?php echo esc_html( $display_status === 'in_progress' ? __( 'In Progress', 'cmi-partner-portal' ) : $display_status ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ( $row->status === 'assigned' && ! $is_slot_over ) : ?>
                                            <button class="button cmi-doc-accept-consult-btn" data-id="<?php echo esc_attr( $row->id ); ?>" style="font-size:11px; padding:4px 10px;"><?php esc_html_e( 'Mark Scheduled', 'cmi-partner-portal' ); ?></button>
                                            <button class="button cmi-doc-cancel-consult-btn" data-id="<?php echo esc_attr( $row->id ); ?>" style="font-size:11px; padding:4px 10px; background:#fff; color:#dc2626; border-color:#e5e7eb;"><?php esc_html_e( 'Cancel', 'cmi-partner-portal' ); ?></button>
                                        <?php elseif ( $row->status === 'assigned' && $is_slot_over ) : ?>
                                            <span style="color:#ef4444; font-size:11px; font-weight:600; display:block; margin-bottom:4px;"><?php esc_html_e( 'Expired (No Show)', 'cmi-partner-portal' ); ?></span>
                                            <button class="button cmi-doc-no-show-btn" data-id="<?php echo esc_attr( $row->id ); ?>" style="font-size:11px; padding:4px 10px; background:#dc2626; color:#fff; border:none;"><?php esc_html_e( 'Mark No Show', 'cmi-partner-portal' ); ?></button>
                                        <?php elseif ( $row->status === 'scheduled' || $row->status === 'in_progress' || $row->status === 'awaiting_prescription' ) : ?>
                                            <div style="display:flex; flex-direction:column; gap:4px;">
                                                <span style="font-size:11px;"><strong>Contact:</strong> <?php echo esc_html( $row->patient_mobile ); ?></span>
                                                <?php if ( ! empty( $row->meeting_room_id ) && ! $is_slot_over && $row->status !== 'awaiting_prescription' ) : ?>
                                                    <button class="button button-primary cmi-start-video-btn" data-id="<?php echo esc_attr( $row->id ); ?>" style="font-size:11px; padding:4px 10px; background:#1a4f8a; color:#fff; border:none; margin-bottom: 2px;"><?php esc_html_e( 'Start Video Call', 'cmi-partner-portal' ); ?></button>
                                                <?php endif; ?>
                                                <button class="button button-primary cmi-doc-complete-consult-btn" data-id="<?php echo esc_attr( $row->id ); ?>" data-patient="<?php echo esc_attr( $row->patient_name ); ?>" style="font-size:11px; padding:4px 10px; background:#22c55e; color:#fff; border:none; margin-bottom:2px;"><?php esc_html_e( 'Complete & Prescribe', 'cmi-partner-portal' ); ?></button>
                                                <button class="button cmi-doc-no-show-btn" data-id="<?php echo esc_attr( $row->id ); ?>" style="font-size:11px; padding:4px 10px; background:#dc2626; color:#fff; border:none; margin-bottom:2px;"><?php esc_html_e( 'Mark No Show', 'cmi-partner-portal' ); ?></button>
                                                <button class="button cmi-doc-done-direct-btn" data-id="<?php echo esc_attr( $row->id ); ?>" style="font-size:11px; padding:4px 10px; background:#4f46e5; color:#fff; border:none;"><?php esc_html_e( 'Mark Completed (No Pres.)', 'cmi-partner-portal' ); ?></button>
                                            </div>
                                        <?php elseif ( $row->status === 'completed' ) : ?>
                                            <span style="color:#10b981; font-weight:600; font-size:12px;"><?php esc_html_e( 'Completed', 'cmi-partner-portal' ); ?></span><br>
                                            <?php if ( $row->prescription_id ) : 
                                                $dl = CMI_Download::generate_link( $row->prescription_id );
                                                ?>
                                                <a href="<?php echo esc_url($dl); ?>" class="button" target="_blank" style="font-size:10px; padding:2px 6px; margin-top:3px; text-decoration:none; display:inline-block;"><?php esc_html_e( 'Prescription PDF', 'cmi-partner-portal' ); ?></a>
                                            <?php endif; ?>
                                        <?php elseif ( $row->status === 'needs_reschedule' ) : ?>
                                            <span style="color:#b45309; font-weight:600; font-size:12px;"><?php esc_html_e( 'Reschedule Needed', 'cmi-partner-portal' ); ?></span>
                                        <?php else : ?>
                                            <span class="description">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
 
            <!-- Complete/Upload Prescription Modal -->
            <div id="cmi-doc-prescription-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
                <div style="background:#fff; padding:25px; border-radius:10px; max-width:480px; width:100%; box-shadow:0 10px 25px rgba(0,0,0,0.1);">
                    <h3 style="margin-top:0; color:#1a4f8a; font-weight:700; margin-bottom:15px;"><?php esc_html_e( 'Complete Consultation', 'cmi-partner-portal' ); ?></h3>
                    
                    <form id="cmi-doc-prescription-form" enctype="multipart/form-data">
                        <input type="hidden" name="id" id="cmi-doc-consult-id">
                        
                        <div class="cmi-form-row" style="margin-bottom:12px;">
                            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Patient Name', 'cmi-partner-portal' ); ?></label>
                            <input type="text" id="cmi-doc-display-patient" disabled style="width:100%; padding:6px; border:1px solid #ddd; background:#f5f5f5; border-radius:4px;">
                        </div>
 
                        <div class="cmi-form-row" style="margin-bottom:12px;">
                            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Prescription / Consultation Report (PDF only)', 'cmi-partner-portal' ); ?> *</label>
                            <input type="file" name="prescription_file" accept="application/pdf" required style="width:100%;">
                        </div>
 
                        <div class="cmi-form-row" style="margin-bottom:15px;">
                            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Prescription Notes / Advice', 'cmi-partner-portal' ); ?></label>
                            <textarea name="notes" rows="4" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px;" placeholder="<?php esc_attr_e( 'Enter any notes, dosage details, or medical advice for the patient...', 'cmi-partner-portal' ); ?>"></textarea>
                        </div>
 
                        <div id="cmi-doc-pres-upload-msg" style="display:none; margin-bottom:12px; color:red; font-size:12px; font-weight:600;"></div>
 
                        <div style="display:flex; justify-content:flex-end; gap:10px;">
                            <button type="button" class="button cmi-doc-close-modal-btn" style="background:#fff; border:1px solid #ddd;"><?php esc_html_e( 'Cancel', 'cmi-partner-portal' ); ?></button>
                            <button type="submit" class="button button-primary" style="background:#22c55e; border:none; color:#fff;"><?php esc_html_e( 'Submit & Complete', 'cmi-partner-portal' ); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
 
        <?php self::render_jitsi_overlay_modal(); ?>

        <script>
        jQuery(document).ready(function($) {

            // Cache-busting reload — bypasses Cloudflare / Hostinger page cache
            function cmiDocReload() {
                var href = window.location.href;
                var cb = 'cb=' + Date.now();
                if (href.indexOf('cb=') > -1) {
                    href = href.replace(/cb=\d+/, cb);
                } else {
                    href = href + (href.indexOf('?') > -1 ? '&' : '?') + cb;
                }
                window.location.href = href;
            }

            // Cancel Action — use event delegation so it works inside hidden tabs
            $(document).on('click', '.cmi-doc-cancel-consult-btn', function(e) {
                e.preventDefault();
                var btn = $(this);
                var id = btn.data('id');

                if (!confirm('Are you sure you want to cancel this consultation assignment?')) {
                    return;
                }

                btn.prop('disabled', true).text('Cancelling...');

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: {
                        action: 'cmi_doctor_update_status',
                        id: id,
                        status: 'cancelled',
                        nonce: '<?php echo wp_create_nonce("cmi_pp_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            btn.text('Cancelled ✓');
                            setTimeout(function() { cmiDocReload(); }, 600);
                        } else {
                            btn.prop('disabled', false).text('Cancel');
                            alert(response.data.message || 'Action failed.');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Cancel');
                        alert('Connection error. Please try again.');
                    }
                });
            });

            // Mark Scheduled — use event delegation so it works inside hidden tabs
            $(document).on('click', '.cmi-doc-accept-consult-btn', function(e) {
                e.preventDefault();
                var btn = $(this);
                var id = btn.data('id');

                btn.prop('disabled', true).text('Scheduling...');

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: {
                        action: 'cmi_doctor_update_status',
                        id: id,
                        status: 'scheduled',
                        nonce: '<?php echo wp_create_nonce("cmi_pp_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            btn.text('Scheduled ✓').css('background', '#22c55e').css('color', '#fff');
                            setTimeout(function() { cmiDocReload(); }, 600);
                        } else {
                            btn.prop('disabled', false).text('Mark Scheduled');
                            alert(response.data.message || 'Action failed.');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Mark Scheduled');
                        alert('Connection error. Please try again.');
                    }
                });
            });

            // Open Upload Prescription Modal — use event delegation for robustness in hidden tabs
            $(document).on('click', '.cmi-doc-complete-consult-btn', function(e) {
                e.preventDefault();
                var btn = $(this);
                var id = btn.data('id');
                var name = btn.data('patient');

                $('#cmi-doc-consult-id').val(id);
                $('#cmi-doc-display-patient').val(name);
                $('#cmi-doc-prescription-modal').css('display', 'flex');
            });

            // Close Modal — use event delegation for robustness
            $(document).on('click', '.cmi-doc-close-modal-btn', function() {
                $('#cmi-doc-prescription-modal').hide();
                $('#cmi-doc-pres-upload-msg').hide();
                $('#cmi-doc-prescription-form')[0].reset();
            });

            // Mark No Show
            $(document).on('click', '.cmi-doc-no-show-btn', function(e) {
                e.preventDefault();
                var btn = $(this);
                var id = btn.data('id');

                if (!confirm('Are you sure you want to mark this patient as a No Show / Missed?')) {
                    return;
                }

                btn.prop('disabled', true).text('Updating...');

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: {
                        action: 'cmi_doctor_update_status',
                        id: id,
                        status: 'needs_reschedule',
                        nonce: '<?php echo wp_create_nonce("cmi_pp_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            btn.text('No Show ✓');
                            setTimeout(function() { cmiDocReload(); }, 600);
                        } else {
                            btn.prop('disabled', false).text('Mark No Show');
                            alert(response.data.message || 'Action failed.');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Mark No Show');
                        alert('Connection error. Please try again.');
                    }
                });
            });

            // Mark Done / Completed (No Prescription)
            $(document).on('click', '.cmi-doc-done-direct-btn', function(e) {
                e.preventDefault();
                var btn = $(this);
                var id = btn.data('id');

                if (!confirm('Are you sure you want to mark this consultation as completed without uploading a prescription?')) {
                    return;
                }

                btn.prop('disabled', true).text('Updating...');

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: {
                        action: 'cmi_doctor_update_status',
                        id: id,
                        status: 'completed',
                        nonce: '<?php echo wp_create_nonce("cmi_pp_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            btn.text('Completed ✓');
                            setTimeout(function() { cmiDocReload(); }, 600);
                        } else {
                            btn.prop('disabled', false).text('Mark Completed');
                            alert(response.data.message || 'Action failed.');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Mark Completed');
                        alert('Connection error. Please try again.');
                    }
                });
            });

            // Submit Prescription Form
            $('#cmi-doc-prescription-form').on('submit', function(e) {
                e.preventDefault();
                var form = $(this);
                var submitBtn = form.find('button[type="submit"]');
                var msg = $('#cmi-doc-pres-upload-msg');

                submitBtn.prop('disabled', true).text('Uploading...');
                msg.hide();

                var formData = new FormData(this);
                formData.append('action', 'cmi_doctor_upload_prescription');
                formData.append('nonce', '<?php echo wp_create_nonce("cmi_pp_nonce"); ?>');

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    success: function(response) {
                        if (response.success) {
                            submitBtn.text('Uploaded ✓').css('background', '#22c55e');
                            msg.hide();
                            setTimeout(function() { cmiDocReload(); }, 600);
                        } else {
                            submitBtn.prop('disabled', false).text('Submit & Complete');
                            msg.text(response.data.message || 'Failed to complete consultation.').show();
                        }
                    },
                    error: function() {
                        submitBtn.prop('disabled', false).text('Submit & Complete');
                        msg.text('Connection error. Please try again.').show();
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Doctor updates assignment status.
     */
    public function ajax_doctor_update_status() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $doctor_id = get_current_user_id();
        $curr_user = wp_get_current_user();
        $is_doctor = in_array( 'cmi_doctor', (array) $curr_user->roles );
        if ( ! $doctor_id || ( ! current_user_can( 'cmi_view_assignments' ) && ! $is_doctor ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-partner-portal' ) ] );
        }

        $id     = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $status = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : '';

        if ( ! $id || empty( $status ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid parameter inputs.', 'cmi-partner-portal' ) ] );
        }

        // Must be scheduled, cancelled, needs_reschedule (No Show), or completed
        if ( ! in_array( $status, [ 'scheduled', 'cancelled', 'needs_reschedule', 'completed' ], true ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized status transition.', 'cmi-partner-portal' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        // Verify assignment
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND doctor_id = %d", $id, $doctor_id ) );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Consultation record not found.', 'cmi-partner-portal' ) ] );
        }

        $update = $wpdb->update(
            $table,
            [
                'status'     => $status,
                'updated_at' => current_time( 'mysql' )
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( $update !== false ) {
            if ( 'scheduled' === $status ) {
                self::generate_jitsi_meeting_data( $id );
                do_action( 'cmi_consultation_scheduled', $id );
            } elseif ( 'needs_reschedule' === $status ) {
                do_action( 'cmi_consultation_missed', $id );
            } elseif ( 'completed' === $status ) {
                do_action( 'cmi_consultation_completed', $id );
            } else {
                do_action( 'cmi_consultation_cancelled', $id );
            }
            $msg = $status === 'scheduled' 
                ? esc_html__( 'Consultation marked as scheduled.', 'cmi-partner-portal' )
                : esc_html__( 'Consultation marked as cancelled.', 'cmi-partner-portal' );
            wp_send_json_success( [ 'message' => $msg ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Database write failed.', 'cmi-partner-portal' ) ] );
        }
    }

    /**
     * Doctor uploads prescription, adds advice, and completes the consultation.
     */
    public function ajax_doctor_upload_prescription() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $doctor_id = get_current_user_id();
        $curr_user = wp_get_current_user();
        $is_doctor = in_array( 'cmi_doctor', (array) $curr_user->roles );
        if ( ! $doctor_id || ( ! current_user_can( 'cmi_view_assignments' ) && ! $is_doctor ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-partner-portal' ) ] );
        }

        $id    = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : '';

        if ( ! $id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid parameter inputs.', 'cmi-partner-portal' ) ] );
        }

        if ( empty( $_FILES['prescription_file'] ) || $_FILES['prescription_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Please select a valid PDF prescription file.', 'cmi-partner-portal' ) ] );
        }

        $file = $_FILES['prescription_file'];

        $validation = CMI_Security::validate_uploaded_file( $file, [ 'pdf' ] );
        if ( is_wp_error( $validation ) ) {
            wp_send_json_error( [ 'message' => $validation->get_error_message() ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        // Verify assignment
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND doctor_id = %d", $id, $doctor_id ) );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Consultation record not found.', 'cmi-partner-portal' ) ] );
        }

        // Fetch patient email from user account
        $patient_email = '';
        $user = get_userdata( $row->user_id );
        if ( $user ) {
            $patient_email = $user->user_email;
        }

        // Register in companion CPT registry
        $secure_filename = '';
        $prescription_post_id = 0;

        if ( class_exists( 'CMI_CPT' ) ) {
            // Save prescription via CPT helper (will create post & secure upload file)
            $result = CMI_CPT::save_report( [
                'patient_mobile' => $row->patient_mobile,
                'patient_email'  => $patient_email,
                'patient_uid'    => get_user_meta( $row->user_id, '_cmi_uid', true ),
                'patient_name'   => $row->patient_name,
                'file_tmp'       => $file['tmp_name'],
                'file_name'      => $file['name'],
                'file_type'      => $validation['mime'],
                'notes'          => $notes,
                'uploaded_by'    => $doctor_id,
                'post_type'      => 'cmi_prescription',
            ] );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ] );
            }

            $prescription_post_id = intval( $result );
            $secure_filename = get_post_meta( $prescription_post_id, '_cmi_file_name', true );
        } else {
            // Standalone manual fallback
            $secure_dir = WP_CONTENT_DIR . '/cmi-secure-reports';
            if ( ! file_exists( $secure_dir ) ) {
                wp_mkdir_p( $secure_dir );
                file_put_contents( $secure_dir . '/.htaccess', "Options -Indexes\nDeny from all\n" );
                file_put_contents( $secure_dir . '/index.php', '<?php // silence' );
            }

            $secure_filename = 'prescription_consult_' . $id . '_' . time() . '.pdf';
            $destination = $secure_dir . '/' . $secure_filename;

            if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Failed to save upload on server disk.', 'cmi-partner-portal' ) ] );
            }
        }

        // Update consultation record to completed
        $update = $wpdb->update(
            $table,
            [
                'status'             => 'completed',
                'prescription_id'    => $prescription_post_id,
                'prescription_file'  => $secure_filename,
                'prescription_notes' => $notes,
                'updated_at'         => current_time( 'mysql' )
            ],
            [ 'id' => $id ],
            [ '%s', '%d', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $update !== false ) {
            do_action( 'cmi_consultation_completed', $id );
            wp_send_json_success( [ 'message' => esc_html__( 'Prescription successfully uploaded and consultation completed.', 'cmi-partner-portal' ) ] );
        } else {
            // clean up file
            if ( ! empty( $secure_filename ) ) {
                @unlink( WP_CONTENT_DIR . '/cmi-secure-reports/' . $secure_filename );
            }
            wp_send_json_error( [ 'message' => esc_html__( 'Database write failed.', 'cmi-partner-portal' ) ] );
        }
    }

    /**
     * AJAX handler: Save doctor availability rule.
     */
    public function ajax_save_doctor_availability() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $doctor_id = get_current_user_id();
        $curr_user = wp_get_current_user();
        $is_doctor = in_array( 'cmi_doctor', (array) $curr_user->roles );
        if ( current_user_can( 'manage_options' ) && isset( $_POST['target_doctor_id'] ) ) {
            $doctor_id = intval( $_POST['target_doctor_id'] );
        } elseif ( ! $doctor_id || ( ! current_user_can( 'cmi_view_assignments' ) && ! $is_doctor ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-partner-portal' ) ] );
        }

        $day           = isset( $_POST['day'] ) ? sanitize_text_field( $_POST['day'] ) : '';
        $start_time    = isset( $_POST['start_time'] ) ? sanitize_text_field( $_POST['start_time'] ) : '';
        $end_time      = isset( $_POST['end_time'] ) ? sanitize_text_field( $_POST['end_time'] ) : '';
        $slot_duration = isset( $_POST['slot_duration'] ) ? intval( $_POST['slot_duration'] ) : 30;

        if ( empty( $day ) || empty( $start_time ) || empty( $end_time ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'All fields are required.', 'cmi-partner-portal' ) ] );
        }

        $days = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ];
        if ( ! in_array( $day, $days ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid day selected.', 'cmi-partner-portal' ) ] );
        }

        $start_ts = strtotime( $start_time );
        $end_ts   = strtotime( $end_time );
        if ( ! $start_ts || ! $end_ts || $start_ts >= $end_ts ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Start time must be before end time.', 'cmi-partner-portal' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_doctor_availability';

        $overlap = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE doctor_id = %d AND day = %s AND (
                (start_time <= %s AND end_time > %s) OR
                (start_time < %s AND end_time >= %s) OR
                (start_time >= %s AND end_time <= %s)
            ) LIMIT 1",
            $doctor_id,
            $day,
            $start_time, $start_time,
            $end_time, $end_time,
            $start_time, $end_time
        ) );

        if ( $overlap ) {
            wp_send_json_error( [ 'message' => esc_html__( 'This slot overlaps with an existing availability window.', 'cmi-partner-portal' ) ] );
        }

        $result = $wpdb->insert(
            $table,
            [
                'doctor_id'     => $doctor_id,
                'day'           => $day,
                'start_time'    => date( 'H:i:s', $start_ts ),
                'end_time'      => date( 'H:i:s', $end_ts ),
                'slot_duration' => $slot_duration,
                'status'        => 'active',
                'created_at'    => current_time( 'mysql' ),
                'updated_at'    => current_time( 'mysql' )
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
        );

        if ( $result ) {
            $this->sync_doctor_user_to_cpt( $doctor_id );
            wp_send_json_success( [ 'message' => esc_html__( 'Availability window saved successfully.', 'cmi-partner-portal' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Database save failed.', 'cmi-partner-portal' ) ] );
        }
    }

    /**
     * AJAX handler: Delete doctor availability rule.
     */
    public function ajax_delete_doctor_availability() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $doctor_id = get_current_user_id();
        $curr_user = wp_get_current_user();
        $is_doctor = in_array( 'cmi_doctor', (array) $curr_user->roles );
        if ( current_user_can( 'manage_options' ) && isset( $_POST['target_doctor_id'] ) ) {
            $doctor_id = intval( $_POST['target_doctor_id'] );
        } elseif ( ! $doctor_id || ( ! current_user_can( 'cmi_view_assignments' ) && ! $is_doctor ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-partner-portal' ) ] );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid slot ID.', 'cmi-partner-portal' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_doctor_availability';

        // Retrieve rule before deleting
        $rule = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND doctor_id = %d", $id, $doctor_id ) );

        if ( $rule ) {
            // Find and reschedule active appointments overlapping this weekly slot
            $day_name = $rule->day;
            $start_time = $rule->start_time;
            $end_time = $rule->end_time;

            $consult_table = $wpdb->prefix . 'cmi_consultations';
            $today = current_time( 'Y-m-d' );
            $appointments = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $consult_table WHERE doctor_id = %d AND preferred_date >= %s AND status IN ('assigned', 'scheduled', 'requested', 'in_progress')",
                $doctor_id,
                $today
            ) );

            foreach ( $appointments as $appt ) {
                $appt_day = date( 'l', strtotime( $appt->preferred_date ) );
                if ( $appt_day === $day_name ) {
                    $parts = explode( '-', $appt->preferred_time_slot );
                    if ( count( $parts ) === 2 ) {
                        $appt_start_ts = strtotime( trim( $parts[0] ) );
                        $appt_end_ts   = strtotime( trim( $parts[1] ) );
                        $ex_start_ts   = strtotime( $start_time );
                        $ex_end_ts     = strtotime( $end_time );

                        if ( $appt_start_ts && $appt_end_ts && $ex_start_ts && $ex_end_ts ) {
                            if ( ( $appt_start_ts >= $ex_start_ts && $appt_start_ts < $ex_end_ts ) ||
                                 ( $appt_end_ts > $ex_start_ts && $appt_end_ts <= $ex_end_ts ) ||
                                 ( $appt_start_ts <= $ex_start_ts && $appt_end_ts >= $ex_end_ts ) ) {
                                
                                $wpdb->update(
                                    $consult_table,
                                    [ 'status' => 'needs_reschedule', 'updated_at' => current_time( 'mysql' ) ],
                                    [ 'id' => $appt->id ],
                                    [ '%s', '%s' ],
                                    [ '%d' ]
                                );
                                do_action( 'cmi_consultation_needs_reschedule', $appt->id );
                            }
                        }
                    }
                }
            }
        }

        $delete = $wpdb->delete(
            $table,
            [ 'id' => $id, 'doctor_id' => $doctor_id ],
            [ '%d', '%d' ]
        );

        if ( $delete !== false ) {
            $this->sync_doctor_user_to_cpt( $doctor_id );
            wp_send_json_success( [ 'message' => esc_html__( 'Slot deleted successfully.', 'cmi-partner-portal' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Database delete failed.', 'cmi-partner-portal' ) ] );
        }
    }


    /**
     * AJAX handler: Fetch available slots for patient booking portal.
     */
    public function ajax_get_available_slots() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $doctor_id = isset( $_POST['doctor_id'] ) ? intval( $_POST['doctor_id'] ) : 0;
        $date      = isset( $_POST['date'] ) ? sanitize_text_field( $_POST['date'] ) : '';

        if ( ! $doctor_id || empty( $date ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid request parameters.', 'cmi-partner-portal' ) ] );
        }

        $day_of_week = date( 'l', strtotime( $date ) );

        global $wpdb;
        $avail_table = $wpdb->prefix . 'cmi_doctor_availability';
        $exceptions_table = $wpdb->prefix . 'cmi_doctor_exceptions';
        $consult_table = $wpdb->prefix . 'cmi_consultations';

        // 1. Query exceptions for this date
        $exceptions = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $exceptions_table WHERE doctor_id = %d AND %s BETWEEN start_date AND end_date",
            $doctor_id,
            $date
        ) );

        $blocked_ranges = [];
        $override_rule = null;

        if ( ! empty( $exceptions ) ) {
            foreach ( $exceptions as $ex ) {
                if ( in_array( $ex->type, [ 'leave', 'holiday', 'emergency' ], true ) ) {
                    if ( empty( $ex->start_time ) || empty( $ex->end_time ) ) {
                        // Entire day is blocked, return empty list of slots
                        wp_send_json_success( [ 'slots' => [] ] );
                        return;
                    } else {
                        $blocked_ranges[] = [
                            'start' => strtotime( $ex->start_time ),
                            'end'   => strtotime( $ex->end_time )
                        ];
                    }
                } elseif ( 'override' === $ex->type ) {
                    // Specific Date Override
                    if ( ! empty( $ex->start_time ) && ! empty( $ex->end_time ) ) {
                        $override_rule = $ex;
                    }
                }
            }
        }

        $working_blocks = [];
        if ( $override_rule ) {
            // Level 4 Override: Use override hours instead of weekly rules
            $working_blocks[] = (object)[
                'start_time'    => $override_rule->start_time,
                'end_time'      => $override_rule->end_time,
                'slot_duration' => 30
            ];
        } else {
            // Level 5: Weekly Schedule
            $rules = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $avail_table WHERE doctor_id = %d AND day = %s AND status = 'active' ORDER BY start_time ASC",
                $doctor_id,
                $day_of_week
            ) );

            if ( ! empty( $rules ) ) {
                foreach ( $rules as $rule ) {
                    $working_blocks[] = (object)[
                        'start_time'    => $rule->start_time,
                        'end_time'      => $rule->end_time,
                        'slot_duration' => intval( $rule->slot_duration )
                    ];
                }
            }
        }

        // Generate sliced slots
        $slots = [];
        if ( ! empty( $working_blocks ) ) {
            foreach ( $working_blocks as $block ) {
                $start = strtotime( $block->start_time );
                $end   = strtotime( $block->end_time );
                $duration = intval( $block->slot_duration ) * 60;

                for ( $t = $start; $t + $duration <= $end; $t += $duration ) {
                    $s_start_time = $t;
                    $s_end_time   = $t + $duration;

                    // Check if this slot overlaps with any blocked ranges
                    $is_blocked = false;
                    foreach ( $blocked_ranges as $range ) {
                        if ( ( $s_start_time >= $range['start'] && $s_start_time < $range['end'] ) ||
                             ( $s_end_time > $range['start'] && $s_end_time <= $range['end'] ) ||
                             ( $s_start_time <= $range['start'] && $s_end_time >= $range['end'] ) ) {
                            $is_blocked = true;
                            break;
                        }
                    }
                    if ( $is_blocked ) {
                        continue;
                    }

                    $s_start = date( 'h:i A', $t );
                    $s_end   = date( 'h:i A', $t + $duration );
                    $slots[] = $s_start . ' - ' . $s_end;
                }
            }
        } else {
            if ( 'yes' !== get_option( 'cmi_allow_default_doctor_slots', 'no' ) ) {
                wp_send_json_success( [ 'slots' => [] ] );
                return;
            }

            // Default 30-minute fallback slots (equivalent to 2-hour blocks but sliced into 30 mins)
            $default_blocks = [
                [ '09:00:00', '13:00:00' ],
                [ '14:00:00', '18:00:00' ]
            ];
            foreach ( $default_blocks as $block ) {
                $start = strtotime( $block[0] );
                $end   = strtotime( $block[1] );
                $duration = 30 * 60;

                for ( $t = $start; $t + $duration <= $end; $t += $duration ) {
                    $s_start_time = $t;
                    $s_end_time   = $t + $duration;

                    // Check blocked ranges
                    $is_blocked = false;
                    foreach ( $blocked_ranges as $range ) {
                        if ( ( $s_start_time >= $range['start'] && $s_start_time < $range['end'] ) ||
                             ( $s_end_time > $range['start'] && $s_end_time <= $range['end'] ) ||
                             ( $s_start_time <= $range['start'] && $s_end_time >= $range['end'] ) ) {
                            $is_blocked = true;
                            break;
                        }
                    }
                    if ( $is_blocked ) {
                        continue;
                    }

                    $s_start = date( 'h:i A', $t );
                    $s_end   = date( 'h:i A', $t + $duration );
                    $slots[] = $s_start . ' - ' . $s_end;
                }
            }
        }

        $booked = $wpdb->get_col( $wpdb->prepare(
            "SELECT preferred_time_slot FROM $consult_table WHERE doctor_id = %d AND preferred_date = %s AND status != 'cancelled'",
            $doctor_id,
            $date
        ) );

        $today = current_time( 'Y-m-d' );
        $free_slots = [];
        if ( ! empty( $slots ) ) {
            foreach ( $slots as $slot ) {
                if ( ! in_array( $slot, $booked ) ) {
                    // Same-day check: must be at least configurable buffer minutes from now
                    if ( $date === $today ) {
                        $parts = explode( '-', $slot );
                        $start_str = ! empty( $parts ) ? trim( $parts[0] ) : '';
                        if ( $start_str ) {
                            $buffer_minutes = absint( get_option( 'cmi_same_day_buffer_minutes', 30 ) );
                            $buffer_seconds = $buffer_minutes * 60;
                            try {
                                $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( get_option( 'timezone_string' ) ?: 'UTC' );
                                if ( $timezone->getName() === 'UTC' || $timezone->getName() === '+00:00' ) {
                                    $timezone = new DateTimeZone( 'Asia/Kolkata' );
                                }
                                $slot_start_time = new DateTime( $date . ' ' . $start_str, $timezone );
                                $slot_start_timestamp = $slot_start_time->getTimestamp();
                                $current_time = new DateTime( 'now', $timezone );
                                $current_timestamp = $current_time->getTimestamp();
                                if ( $slot_start_timestamp < $current_timestamp + $buffer_seconds ) {
                                    continue; // Skip slot
                                }
                            } catch ( Exception $e ) {
                                $slot_start_timestamp = strtotime( $today . ' ' . $start_str );
                                $current_timestamp = current_time( 'timestamp' );
                                if ( $slot_start_timestamp < ( $current_timestamp + $buffer_seconds ) ) {
                                    continue;
                                }
                            }
                        }
                    }
                    $free_slots[] = $slot;
                }
            }
        }

        wp_send_json_success( [ 'slots' => $free_slots ] );
    }

    /**
     * AJAX handler: Validate meeting access before launching the Jitsi iframe.
     *
     * Security gate for two issues:
     *   1. Meeting link reuse   — rejects requests when status is not 'scheduled'
     *                             or 'in_progress' (e.g. completed, cancelled).
     *   2. Cross-patient access — rejects requests where the current user is
     *                             neither the assigned patient (user_id) nor the
     *                             assigned doctor (doctor_id).
     *
     * On success, returns only the room name — never the raw public URL.
     * The room name is therefore never present in the page HTML source.
     */
    public function ajax_validate_meeting_access() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'You must be logged in to join a consultation.', 'cmi-partner-portal' ) ] );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid consultation ID.', 'cmi-partner-portal' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        $is_admin = current_user_can( 'manage_options' );

        // User must be the assigned patient OR the assigned doctor (or an admin).
        // Fetch schedule and room metadata to enforce role permissions & early join guards.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table
             WHERE id = %d AND ( user_id = %d OR doctor_id = %d OR %d = 1 )
             LIMIT 1",
            $id,
            $user_id,
            $user_id,
            $is_admin ? 1 : 0
        ) );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Access Denied: You are not authorized to join this consultation room.', 'cmi-partner-portal' ) ] );
        }

        // Explicitly block joining if the doctor has already ended the call and is awaiting prescription.
        if ( $row->status === 'awaiting_prescription' ) {
            wp_send_json_error( [ 'message' => esc_html__( "This consultation has concluded. The doctor is currently preparing your prescription.", 'cmi-partner-portal' ) ] );
        }

        // Block joining when the consultation is no longer active.
        if ( ! in_array( $row->status, [ 'assigned', 'scheduled', 'in_progress' ], true ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'This consultation session is no longer active.', 'cmi-partner-portal' ) ] );
        }

        if ( empty( $row->meeting_room_id ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'The consultation room has not been initialized. Please contact support.', 'cmi-partner-portal' ) ] );
        }

        // ── JWT + role determination ───────────────────────────────────────────
        // The doctor (or admin) is the moderator (host); the patient is a participant (guest).
        $is_moderator = ( intval( $row->doctor_id ) === $user_id ) || $is_admin;

        // Check if the doctor is currently active in another Jitsi room (running late)
        $doctor_busy = false;
        if ( ! $is_moderator && $row->doctor_id ) {
            $busy_consult = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table 
                 WHERE doctor_id = %d AND status = 'in_progress' AND id != %d 
                 LIMIT 1",
                $row->doctor_id,
                $id
            ) );
            if ( $busy_consult ) {
                $doctor_busy = true;
            }
        }

        // ── Patient early join check ───────────────────────────────────────────
        // Prevent patients from entering the meeting lobby/iframe more than 10 minutes
        // before their scheduled appointment date and time slot.
        if ( ! $is_moderator && $row->status !== 'in_progress' ) {
            $slot_parts = explode( '-', $row->preferred_time_slot );
            $start_str = ! empty( $slot_parts ) ? trim( $slot_parts[0] ) : '';
            if ( $start_str ) {
                try {
                    if ( function_exists( 'wp_timezone' ) ) {
                        $timezone = wp_timezone();
                    } else {
                        $timezone_string = get_option( 'timezone_string' );
                        if ( ! empty( $timezone_string ) ) {
                            $timezone = new DateTimeZone( $timezone_string );
                        } else {
                            $gmt_offset = get_option( 'gmt_offset' );
                            $hours      = (int) $gmt_offset;
                            $minutes    = abs( ( $gmt_offset - $hours ) * 60 );
                            $offset     = sprintf( '%+03d:%02d', $hours, $minutes );
                            $timezone   = new DateTimeZone( $offset );
                        }
                    }

                    // Fallback to Asia/Kolkata if timezone is UTC to prevent timezone mismatch on unconfigured WP instances
                    if ( $timezone->getName() === 'UTC' || $timezone->getName() === '+00:00' ) {
                        $timezone = new DateTimeZone( 'Asia/Kolkata' );
                    }

                    $slot_start = new DateTime( $row->preferred_date . ' ' . $start_str, $timezone );
                    $slot_start_time = $slot_start->getTimestamp();
                    $current_time = new DateTime( 'now', $timezone );
                    $current_timestamp = $current_time->getTimestamp();

                    $allowed_start_time = $slot_start_time - ( 10 * 60 ); // 10 minutes grace period
                    
                    if ( $current_timestamp < $allowed_start_time ) {
                        $formatted_date = esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->preferred_date ) ) );
                        $formatted_time = esc_html( $row->preferred_time_slot );
                        wp_send_json_error( [
                            'message' => sprintf(
                                /* translators: 1: date string 2: time slot string */
                                esc_html__( 'This consultation is scheduled for %1$s at %2$s. Please return closer to your appointment time (up to 10 minutes before).', 'cmi-partner-portal' ),
                                $formatted_date,
                                $formatted_time
                            )
                        ] );
                    }
                } catch ( Exception $e ) {
                    // Fallback to time parsing if DateTime throws
                }
            }
        }

        $doc = get_userdata( $row->doctor_id );
        $doc_name = $doc ? $doc->display_name : esc_html__( 'your doctor', 'cmi-partner-portal' );
        $formatted_date = date_i18n( get_option( 'date_format' ), strtotime( $row->preferred_date ) );
        $specialty = $row->doctor_id ? (get_user_meta( $row->doctor_id, '_cmi_specialty', true ) ?: esc_html__( 'General Physician', 'cmi-partner-portal' )) : esc_html__( 'General Physician', 'cmi-partner-portal' );
        $fee = $row->doctor_id ? (get_user_meta( $row->doctor_id, '_cmi_consultation_fee', true ) ?: '500') : '500';

        $response = [
            'room_name'            => $row->meeting_room_id,
            'is_moderator'         => $is_moderator,
            'status'               => $row->status,
            'doctor_name'          => $doc_name,
            'doctor_specialty'     => $specialty,
            'doctor_fee'           => $fee,
            'patient_name'         => $row->patient_name,
            'patient_gender'       => $row->patient_gender,
            'patient_dob'          => $row->patient_dob,
            'patient_mobile'       => $row->patient_mobile,
            'patient_relationship' => $row->patient_relationship,
            'symptoms'             => $row->symptoms,
            'consultation_type'    => $row->consultation_type,
            'preferred_date'       => $formatted_date,
            'preferred_time'       => $row->preferred_time_slot,
            'domain'               => rtrim( get_option( 'cmi_jitsi_domain', '8x8.vc' ), '/' ),
            'app_id'               => get_option( 'cmi_jitsi_app_id', '' ),
            'doctor_busy'          => $doctor_busy,
        ];

        // generate_jitsi_jwt() returns false when JWT is not configured —
        // Protected meeting mode fails closed below.
        $jwt = self::generate_jitsi_jwt( $row->meeting_room_id, $user_id, $is_moderator );
        if ( ! $jwt && self::jitsi_requires_jwt() ) {
            wp_send_json_error( [
                'message' => esc_html__( 'Video consultation access is not configured securely. Please contact CMI Healthcare.', 'cmi-partner-portal' ),
            ] );
        }
        if ( $jwt ) {
            $response['jwt'] = $jwt;
        }

        wp_send_json_success( $response );
    }

    /**
     * AJAX handler: Patient requests admin to reschedule an expired/missed consultation.
     */
    public function ajax_request_admin_reschedule() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Please log in first.', 'cmi-partner-portal' ) ] );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid consultation ID.', 'cmi-partner-portal' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        // Fetch row and ensure it belongs to the current user
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND user_id = %d LIMIT 1",
            $id,
            $user_id
        ) );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Consultation not found or unauthorized access.', 'cmi-partner-portal' ) ] );
        }

        // Allow reschedule request for any active or expired slot (not cancelled/completed).
        // The 'Expired / Missed' display label is computed client-side but the DB status
        // may still read 'assigned', 'scheduled', or 'requested' for overdue appointments.
        $allowed_for_reschedule = [ 'requested', 'assigned', 'scheduled', 'in_progress', 'needs_reschedule' ];
        if ( ! in_array( $row->status, $allowed_for_reschedule, true ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'This consultation cannot be rescheduled. It may already be completed or cancelled.', 'cmi-partner-portal' ) ] );
        }

        $updated = $wpdb->update(
            $table,
            [
                'status'     => 'needs_reschedule',
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Database error. Please try again.', 'cmi-partner-portal' ) ] );
        }

        wp_send_json_success( [ 'message' => esc_html__( 'Reschedule request submitted to admin successfully.', 'cmi-partner-portal' ) ] );
    }

    /**
     * AJAX handler: Update Jitsi Meeting status to in_progress on connect.
     */
    public function ajax_update_meeting_status() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-partner-portal' ) ] );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $status = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : '';

        if ( ! $id || ! in_array( $status, [ 'in_progress', 'awaiting_prescription' ], true ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid parameter inputs.', 'cmi-partner-portal' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';
        $updated = null;

        // Only the assigned doctor may update the consultation status.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND doctor_id = %d", $id, $user_id ) );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Only the assigned doctor may update this consultation.', 'cmi-partner-portal' ) ] );
        }

        if ( 'in_progress' === $status ) {
            // Accept both 'scheduled' and 'assigned' — in_progress is the next valid state from either.
            if ( in_array( $row->status, [ 'scheduled', 'assigned' ], true ) ) {
                $lock_name = $wpdb->prefix . 'cmi_doctor_meeting_' . absint( $user_id );
                $lock_acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
                if ( 1 !== $lock_acquired ) {
                    wp_send_json_error( [ 'message' => esc_html__( 'The consultation room is busy. Please try again in a few seconds.', 'cmi-partner-portal' ) ] );
                }

                $active_other = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $table
                     WHERE doctor_id = %d AND status = 'in_progress' AND id != %d
                     LIMIT 1",
                    $user_id,
                    $id
                ) );
                if ( $active_other ) {
                    $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
                    wp_send_json_error( [ 'message' => esc_html__( 'Another consultation is already in progress. Please end it before starting this room.', 'cmi-partner-portal' ) ] );
                }

                $updated = $wpdb->update(
                    $table,
                    [
                        'status'         => 'in_progress',
                        'meeting_status' => 'active',
                        'updated_at'     => current_time( 'mysql' )
                    ],
                    [ 'id' => $id ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );
                $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
                if ( false !== $updated ) {
                    do_action( 'cmi_consultation_in_progress', $id );
                }
            } else {
                wp_send_json_error( [ 'message' => esc_html__( 'This consultation cannot be started from its current status.', 'cmi-partner-portal' ) ] );
            }
        } elseif ( 'awaiting_prescription' === $status ) {
            // Transition to awaiting_prescription when doctor ends call.
            if ( in_array( $row->status, [ 'scheduled', 'assigned', 'in_progress' ], true ) ) {
                $updated = $wpdb->update(
                    $table,
                    [
                        'status'         => 'awaiting_prescription',
                        'meeting_status' => 'ended',
                        'updated_at'     => current_time( 'mysql' )
                    ],
                    [ 'id' => $id ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );
                if ( false !== $updated ) {
                    do_action( 'cmi_consultation_awaiting_prescription', $id );
                }
            } else {
                wp_send_json_error( [ 'message' => esc_html__( 'This consultation cannot be ended from its current status.', 'cmi-partner-portal' ) ] );
            }
        }

        if ( false === $updated ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Database error. Please try again.', 'cmi-partner-portal' ) ] );
        }

        wp_send_json_success();
    }

    /**
     * Render the doctor availability tab content inside partner portal.
     */
    public static function render_doctor_availability_tab() {
        $doctor_id = get_current_user_id();
        $curr_user = wp_get_current_user();
        $is_doctor = in_array( 'cmi_doctor', (array) $curr_user->roles );
        if ( ! $doctor_id || ( ! current_user_can( 'cmi_view_assignments' ) && ! $is_doctor ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_doctor_availability';

        $rules = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE doctor_id = %d ORDER BY FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time ASC",
            $doctor_id
        ) );

        ?>
        <div class="cmi-doctor-availability-wrapper" style="font-family: inherit;">
            <h2 style="font-size:20px; font-weight:700; color:#1a4f8a; margin-bottom:15px;"><?php esc_html_e( 'Manage Consultation Availability Slots', 'cmi-partner-portal' ); ?></h2>
            <div class="cmi-shadcn-layout" style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 20px;">
                <!-- Add Availability Form -->
                <div class="cmi-shadcn-card" style="background:#fff; border:1px solid #e4e4e7; border-radius:8px; padding:20px;">
                    <h3 style="margin-top:0; font-size:16px; font-weight:600; color:#09090b; margin-bottom:15px;"><?php esc_html_e( 'Add Availability Slot', 'cmi-partner-portal' ); ?></h3>
                    <form id="cmi-doctor-avail-form" style="display:flex; flex-direction:column; gap:12px;">
                        <div class="cmi-form-row" style="margin-bottom:0;">
                            <label style="display:block; font-weight:500; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Day of the Week', 'cmi-partner-portal' ); ?></label>
                            <select name="day" required style="width:100%;" class="cmi-shadcn-select">
                                <option value="Monday"><?php esc_html_e( 'Monday', 'cmi-partner-portal' ); ?></option>
                                <option value="Tuesday"><?php esc_html_e( 'Tuesday', 'cmi-partner-portal' ); ?></option>
                                <option value="Wednesday"><?php esc_html_e( 'Wednesday', 'cmi-partner-portal' ); ?></option>
                                <option value="Thursday"><?php esc_html_e( 'Thursday', 'cmi-partner-portal' ); ?></option>
                                <option value="Friday"><?php esc_html_e( 'Friday', 'cmi-partner-portal' ); ?></option>
                                <option value="Saturday"><?php esc_html_e( 'Saturday', 'cmi-partner-portal' ); ?></option>
                                <option value="Sunday"><?php esc_html_e( 'Sunday', 'cmi-partner-portal' ); ?></option>
                            </select>
                        </div>
                        <div class="cmi-shadcn-grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:0;">
                            <div class="cmi-form-row" style="margin-bottom:0;">
                                <label style="display:block; font-weight:500; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Start Time', 'cmi-partner-portal' ); ?></label>
                                <input type="time" name="start_time" required style="width:100%;" class="cmi-shadcn-input">
                            </div>
                            <div class="cmi-form-row" style="margin-bottom:0;">
                                <label style="display:block; font-weight:500; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'End Time', 'cmi-partner-portal' ); ?></label>
                                <input type="time" name="end_time" required style="width:100%;" class="cmi-shadcn-input">
                            </div>
                        </div>
                        <div class="cmi-form-row" style="margin-bottom:0;">
                            <label style="display:block; font-weight:500; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Slot Duration (minutes)', 'cmi-partner-portal' ); ?></label>
                            <select name="slot_duration" required style="width:100%;" class="cmi-shadcn-select">
                                <option value="15">15 <?php esc_html_e( 'Minutes', 'cmi-partner-portal' ); ?></option>
                                <option value="30" selected>30 <?php esc_html_e( 'Minutes', 'cmi-partner-portal' ); ?></option>
                                <option value="45">45 <?php esc_html_e( 'Minutes', 'cmi-partner-portal' ); ?></option>
                                <option value="60">60 <?php esc_html_e( 'Minutes', 'cmi-partner-portal' ); ?></option>
                            </select>
                        </div>
                        <div id="cmi-doctor-avail-msg" style="display:none; padding:8px; border-radius:4px; font-size:12px; font-weight:600; border: 1px solid transparent;"></div>
                        <button type="submit" class="button button-primary cmi-shadcn-btn-primary" style="margin-top: 5px;"><?php esc_html_e( 'Add Slot Window', 'cmi-partner-portal' ); ?></button>
                    </form>
                </div>

                <!-- Current Availability Slots -->
                <div class="cmi-shadcn-card" style="background:#fff; border:1px solid #e4e4e7; border-radius:8px; padding:20px;">
                    <h3 style="margin-top:0; font-size:16px; font-weight:600; color:#09090b; margin-bottom:15px;"><?php esc_html_e( 'Your Configured Availabilities', 'cmi-partner-portal' ); ?></h3>
                    <div class="cmi-avail-list-container" style="max-height: 320px; overflow-y: auto;">
                        <?php if ( empty( $rules ) ) : ?>
                            <p style="color:#71717a; font-size:13px; text-align:center; padding:30px 0;"><?php esc_html_e( 'No availability windows set. Default general slots will be offered.', 'cmi-partner-portal' ); ?></p>
                        <?php else : ?>
                            <div style="display:flex; flex-direction:column; gap:8px;">
                                <?php foreach ( $rules as $rule ) : ?>
                                    <div class="cmi-avail-item" style="display:flex; justify-content:space-between; align-items:center; padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                                        <div style="font-size:13px; color:#1e293b;">
                                            <strong><?php echo esc_html( $rule->day ); ?></strong>: 
                                            <span><?php echo date( 'h:i A', strtotime( $rule->start_time ) ); ?></span> - 
                                            <span><?php echo date( 'h:i A', strtotime( $rule->end_time ) ); ?></span>
                                            <span style="font-size:11px; color:#64748b; margin-left:6px;">(<?php echo esc_html( $rule->slot_duration ); ?>m slots)</span>
                                        </div>
                                        <button type="button" class="cmi-delete-avail-btn" data-id="<?php echo esc_attr( $rule->id ); ?>" style="background:none; border:none; color:#ef4444; font-size:12px; font-weight:600; cursor:pointer; padding:2px 6px;"><?php esc_html_e( 'Remove', 'cmi-partner-portal' ); ?></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <h2 style="font-size:20px; font-weight:700; color:#1a4f8a; margin-top:30px; margin-bottom:15px;"><?php esc_html_e( 'Manage Schedule Exceptions (Leaves & Overrides)', 'cmi-partner-portal' ); ?></h2>
            <div class="cmi-shadcn-layout" style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 20px;">
                <!-- Add Exception Form -->
                <div class="cmi-shadcn-card" style="background:#fff; border:1px solid #e4e4e7; border-radius:8px; padding:20px;">
                    <h3 style="margin-top:0; font-size:16px; font-weight:600; color:#09090b; margin-bottom:15px;"><?php esc_html_e( 'Add Leave or Override', 'cmi-partner-portal' ); ?></h3>
                    <form id="cmi-doctor-exception-form" style="display:flex; flex-direction:column; gap:12px;">
                        <div class="cmi-form-row" style="margin-bottom:0;">
                            <label style="display:block; font-weight:500; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Exception Type', 'cmi-partner-portal' ); ?></label>
                            <select name="type" id="cmi-exception-type" required style="width:100%;" class="cmi-shadcn-select">
                                <option value="leave"><?php esc_html_e( 'Doctor Leave / Out of Office', 'cmi-partner-portal' ); ?></option>
                                <option value="emergency"><?php esc_html_e( 'Emergency Block (Unavailable)', 'cmi-partner-portal' ); ?></option>
                                <option value="override"><?php esc_html_e( 'Specific Date Work Hours Override', 'cmi-partner-portal' ); ?></option>
                                <option value="holiday"><?php esc_html_e( 'Holiday Block', 'cmi-partner-portal' ); ?></option>
                            </select>
                        </div>
                        <div class="cmi-shadcn-grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:0;">
                            <div class="cmi-form-row" style="margin-bottom:0;">
                                <label style="display:block; font-weight:500; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Start Date', 'cmi-partner-portal' ); ?></label>
                                <input type="date" name="start_date" min="<?php echo current_time('Y-m-d'); ?>" required style="width:100%;" class="cmi-shadcn-input">
                            </div>
                            <div class="cmi-form-row" style="margin-bottom:0;">
                                <label style="display:block; font-weight:500; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'End Date', 'cmi-partner-portal' ); ?></label>
                                <input type="date" name="end_date" min="<?php echo current_time('Y-m-d'); ?>" required style="width:100%;" class="cmi-shadcn-input">
                            </div>
                        </div>
                        <div class="cmi-shadcn-grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:0;">
                            <div class="cmi-form-row" style="margin-bottom:0;">
                                <label style="display:block; font-weight:500; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Start Time (Optional)', 'cmi-partner-portal' ); ?></label>
                                <input type="time" name="start_time" style="width:100%;" class="cmi-shadcn-input">
                            </div>
                            <div class="cmi-form-row" style="margin-bottom:0;">
                                <label style="display:block; font-weight:500; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'End Time (Optional)', 'cmi-partner-portal' ); ?></label>
                                <input type="time" name="end_time" style="width:100%;" class="cmi-shadcn-input">
                            </div>
                        </div>
                        <div class="cmi-form-row" style="margin-bottom:0;">
                            <label style="display:block; font-weight:500; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Reason / Description', 'cmi-partner-portal' ); ?></label>
                            <input type="text" name="reason" placeholder="e.g. Attending a conference" style="width:100%;" class="cmi-shadcn-input">
                        </div>
                        <div id="cmi-doctor-exception-msg" style="display:none; padding:8px; border-radius:4px; font-size:12px; font-weight:600; border: 1px solid transparent;"></div>
                        <button type="submit" class="button button-primary cmi-shadcn-btn-primary" style="margin-top: 5px;"><?php esc_html_e( 'Add Exception Rule', 'cmi-partner-portal' ); ?></button>
                    </form>
                </div>

                <!-- Current Exceptions List -->
                <div class="cmi-shadcn-card" style="background:#fff; border:1px solid #e4e4e7; border-radius:8px; padding:20px;">
                    <h3 style="margin-top:0; font-size:16px; font-weight:600; color:#09090b; margin-bottom:15px;"><?php esc_html_e( 'Active Leaves & Overrides', 'cmi-partner-portal' ); ?></h3>
                    <div class="cmi-avail-list-container" style="max-height: 320px; overflow-y: auto;">
                        <?php
                        $exceptions_table = $wpdb->prefix . 'cmi_doctor_exceptions';
                        $exs = $wpdb->get_results( $wpdb->prepare(
                            "SELECT * FROM $exceptions_table WHERE doctor_id = %d ORDER BY start_date ASC",
                            $doctor_id
                        ) );
                        if ( empty( $exs ) ) : ?>
                            <p style="color:#71717a; font-size:13px; text-align:center; padding:30px 0;"><?php esc_html_e( 'No active leaves or override records configured.', 'cmi-partner-portal' ); ?></p>
                        <?php else : ?>
                            <div style="display:flex; flex-direction:column; gap:8px;">
                                <?php foreach ( $exs as $ex ) : 
                                    $badge_color = '#ef4444';
                                    $badge_bg = '#fef2f2';
                                    if ($ex->type === 'override') {
                                        $badge_color = '#3b82f6';
                                        $badge_bg = '#eff6ff';
                                    } elseif ($ex->type === 'holiday') {
                                        $badge_color = '#eab308';
                                        $badge_bg = '#fefce8';
                                    }
                                    ?>
                                    <div class="cmi-avail-item" style="display:flex; justify-content:space-between; align-items:center; padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                                        <div style="font-size:13px; color:#1e293b;">
                                            <span style="font-size:11px; font-weight:bold; text-transform:uppercase; color:<?php echo $badge_color; ?>; background:<?php echo $badge_bg; ?>; padding:2px 6px; border-radius:4px; margin-right:6px;"><?php echo esc_html($ex->type); ?></span>
                                            <strong><?php echo esc_html( date('d M Y', strtotime($ex->start_date)) ); ?></strong> 
                                            <?php if ($ex->start_date !== $ex->end_date) : ?>
                                                - <strong><?php echo esc_html( date('d M Y', strtotime($ex->end_date)) ); ?></strong>
                                            <?php endif; ?>
                                            <?php if ( ! empty($ex->start_time) && ! empty($ex->end_time) ) : ?>
                                                <span style="font-size:11px; color:#64748b; margin-left:4px;">(<?php echo date('h:i A', strtotime($ex->start_time)); ?> - <?php echo date('h:i A', strtotime($ex->end_time)); ?>)</span>
                                            <?php endif; ?>
                                            <?php if ( ! empty($ex->reason) ) : ?>
                                                <p style="margin: 4px 0 0 0; font-size:11px; color:#64748b; font-style:italic;"><?php echo esc_html($ex->reason); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="cmi-delete-exception-btn" data-id="<?php echo esc_attr( $ex->id ); ?>" style="background:none; border:none; color:#ef4444; font-size:12px; font-weight:600; cursor:pointer; padding:2px 6px;"><?php esc_html_e( 'Remove', 'cmi-partner-portal' ); ?></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Save Availability
            $('#cmi-doctor-avail-form').on('submit', function(e) {
                e.preventDefault();
                var form = $(this);
                var btn = form.find('button[type="submit"]');
                var msg = $('#cmi-doctor-avail-msg');

                btn.prop('disabled', true).text('Saving...');
                msg.hide();

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: form.serialize() + '&action=cmi_save_doctor_availability&nonce=<?php echo wp_create_nonce("cmi_pp_nonce"); ?>',
                    success: function(response) {
                        if (response.success) {
                            msg.css({'color':'#16a34a', 'background':'#f0fdf4', 'border-color':'#bbf7d0'}).text(response.data.message).show();
                            setTimeout(function() {
                                location.reload();
                            }, 800);
                        } else {
                            btn.prop('disabled', false).text('Add Slot Window');
                            msg.css({'color':'#ef4444', 'background':'#fef2f2', 'border-color':'#fecaca'}).text(response.data.message || 'Action failed.').show();
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Add Slot Window');
                        msg.css({'color':'#ef4444', 'background':'#fef2f2', 'border-color':'#fecaca'}).text('Connection error.').show();
                    }
                });
            });

            // Delete Availability
            $('.cmi-delete-avail-btn').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var id = btn.data('id');

                if (!confirm('Remove this availability window?')) {
                    return;
                }

                btn.prop('disabled', true).text('...');

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: {
                        action: 'cmi_delete_doctor_availability',
                        id: id,
                        nonce: '<?php echo wp_create_nonce("cmi_pp_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            btn.prop('disabled', false).text('Remove');
                            alert(response.data.message || 'Deletion failed.');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Remove');
                        alert('Connection error.');
                    }
                });
            });

            // Save Exception
            $('#cmi-doctor-exception-form').on('submit', function(e) {
                e.preventDefault();
                var form = $(this);
                var btn = form.find('button[type="submit"]');
                var msg = $('#cmi-doctor-exception-msg');

                btn.prop('disabled', true).text('Saving...');
                msg.hide();

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: form.serialize() + '&action=cmi_save_doctor_exception&nonce=<?php echo wp_create_nonce("cmi_pp_nonce"); ?>',
                    success: function(response) {
                        if (response.success) {
                            msg.css({'color':'#16a34a', 'background':'#f0fdf4', 'border-color':'#bbf7d0'}).text(response.data.message).show();
                            setTimeout(function() {
                                location.reload();
                            }, 800);
                        } else {
                            btn.prop('disabled', false).text('Add Exception Rule');
                            msg.css({'color':'#ef4444', 'background':'#fef2f2', 'border-color':'#fecaca'}).text(response.data.message || 'Action failed.').show();
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Add Exception Rule');
                        msg.css({'color':'#ef4444', 'background':'#fef2f2', 'border-color':'#fecaca'}).text('Connection error.').show();
                    }
                });
            });

            // Delete Exception
            $('.cmi-delete-exception-btn').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var id = btn.data('id');

                if (!confirm('Remove this exception rule? Any rescheduled appointments will remain reschedule-requested.')) {
                    return;
                }

                btn.prop('disabled', true).text('...');

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: {
                        action: 'cmi_delete_doctor_exception',
                        id: id,
                        nonce: '<?php echo wp_create_nonce("cmi_pp_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            btn.prop('disabled', false).text('Remove');
                            alert(response.data.message || 'Deletion failed.');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Remove');
                        alert('Connection error.');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render Jitsi overlay modal container.
     */
    public static function render_jitsi_overlay_modal() {
        ?>
        <!-- Jitsi Meeting Modal Overlay -->
        <div id="cmi-jitsi-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(9,9,11,0.8); backdrop-filter:blur(4px); z-index:99999; align-items:center; justify-content:center; padding:20px;">
            <div style="background:#fff; border:1px solid #e4e4e7; border-radius:12px; max-width:960px; width:100%; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); display:flex; flex-direction:column; overflow:hidden;">
                <!-- Modal Header -->
                <div style="padding:16px 20px; border-bottom:1px solid #e4e4e7; display:flex; justify-content:space-between; align-items:center; background:#f8fafc;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="display:inline-block; width:8px; height:8px; background:#ef4444; border-radius:50%; animation: cmi-pulse 2s infinite;"></span>
                        <h3 style="margin:0; font-size:16px; font-weight:600; color:#09090b;"><?php esc_html_e( 'Live Consultation Video Room', 'cmi-partner-portal' ); ?></h3>
                    </div>
                    <button type="button" id="cmi-close-jitsi-btn" style="background:#f4f4f5; border:none; color:#71717a; font-size:12px; font-weight:600; padding:6px 12px; border-radius:6px; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#e4e4e7';this.style.color='#09090b'" onmouseout="this.style.background='#f4f4f5';this.style.color='#71717a'"><?php esc_html_e( 'Leave Meeting', 'cmi-partner-portal' ); ?></button>
                </div>
                <!-- Split Layout Wrapper -->
                <div style="display:flex; position:relative; width:100%; height:600px; background:#09090b;">
                    <!-- Video Column -->
                    <div style="flex:1; position:relative; height:100%;">
                        <div id="cmi-jitsi-loading" style="position:absolute; top:0; left:0; width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#a1a1aa; gap:12px; pointer-events:none;">
                            <div class="cmi-spinner" style="width:32px; height:32px; border:3px solid #27272a; border-top-color:#3b82f6; border-radius:50%; animation: cmi-spin 1s linear infinite;"></div>
                            <span style="font-size:14px; font-weight:500;"><?php esc_html_e( 'Connecting to Jitsi video server...', 'cmi-partner-portal' ); ?></span>
                        </div>
                        <div id="cmi-jitsi-meeting-iframe" style="width:100%; height:100%;"></div>
                    </div>
                    <!-- Sidebar Column -->
                    <div id="cmi-consultation-details-sidebar" style="width:280px; border-left:1px solid #27272a; background:#18181b; display:flex; flex-direction:column; color:#f4f4f5; font-family:system-ui, -apple-system, sans-serif;">
                        <!-- Header -->
                        <div style="padding:16px 20px; border-bottom:1px solid #27272a;">
                            <h4 style="margin:0; font-size:12px; font-weight:600; color:#3b82f6; text-transform:uppercase; letter-spacing:0.05em;"><?php esc_html_e( 'Consultation Info', 'cmi-partner-portal' ); ?></h4>
                        </div>
                        <!-- Content -->
                        <div id="cmi-consultation-sidebar-content" style="padding:20px; flex:1; overflow-y:auto; display:flex; flex-direction:column; gap:20px;">
                            <!-- Will be dynamically populated by JS -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <style>
        @keyframes cmi-spin { to { transform: rotate(360deg); } }
        @keyframes cmi-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        @media (max-width: 768px) {
            #cmi-consultation-details-sidebar {
                display: none !important;
            }
        }
        </style>
        <?php
    }

    public function sync_doctor_profile_to_cpt( $user_id, $old_user_data ) {
        $this->sync_doctor_user_to_cpt( $user_id );
    }

    public function sync_doctor_user_meta_to_cpt( $meta_id, $object_id, $meta_key, $_meta_value ) {
        // Watch all user-profile meta keys that feed the doctor CPT display or slot generation.
        $watched_keys = [
            '_cmi_specialty',
            '_cmi_consultation_fee',
            '_cmi_org',
            '_cmi_mobile',
            '_cmi_license',
        ];
        if ( in_array( $meta_key, $watched_keys, true ) ) {
            $this->sync_doctor_user_to_cpt( $object_id );
        }
    }

    /**
     * Static entry point for calling sync_doctor_user_to_cpt without constructing
     * a new CMI_Consultations instance (which would double-register all WP hooks).
     * Called from class-cpt.php save_doctor_user_metabox().
     */
    public static function static_sync_doctor_user_to_cpt( $doctor_id ) {
        global $cmi_prevent_user_to_cpt_sync;
        if ( ! empty( $cmi_prevent_user_to_cpt_sync ) ) {
            return;
        }

        // Reuse the same static recursion guard and logic as the instance method.
        static $is_static_syncing = false;
        if ( $is_static_syncing ) {
            return;
        }
        $is_static_syncing = true;

        $doctor = get_userdata( $doctor_id );
        if ( ! $doctor || ! in_array( 'cmi_doctor', (array) $doctor->roles ) ) {
            $is_static_syncing = false;
            return;
        }

        // Reuse instance method via global plugin instance if available, or run inline.
        global $cmi_consultations_instance;
        if ( isset( $cmi_consultations_instance ) && $cmi_consultations_instance instanceof CMI_Consultations ) {
            $cmi_consultations_instance->sync_doctor_user_to_cpt( $doctor_id );
        } else {
            // Inline fallback — same logic as sync_doctor_user_to_cpt but without hook side-effects.
            $posts = get_posts( [
                'post_type'      => 'doctor',
                'post_status'    => 'any',
                'meta_query'     => [ [ 'key' => '_cmi_doctor_user_id', 'value' => $doctor_id ] ],
                'posts_per_page' => 1,
            ] );
            $doctor_post_id = ! empty( $posts ) ? $posts[0]->ID : 0;
            if ( $doctor_post_id ) {
                $specialty = get_user_meta( $doctor_id, '_cmi_specialty', true ) ?: 'General Physician';
                $fee       = get_user_meta( $doctor_id, '_cmi_consultation_fee', true ) ?: '500';
                update_post_meta( $doctor_post_id, '_cmi_specialty', $specialty );
                update_post_meta( $doctor_post_id, '_cmi_consultation_fee', $fee );
                wp_update_post( [
                    'ID'           => $doctor_post_id,
                    'post_title'   => $doctor->display_name,
                    'post_content' => $doctor->description,
                ] );
            }
        }

        $is_static_syncing = false;
    }

    /**
     * Synchronize a doctor's user meta and availability/exceptions to their corresponding doctor Custom Post Type post.
     */
    public function sync_doctor_user_to_cpt( $doctor_id ) {
        global $cmi_prevent_user_to_cpt_sync;
        if ( ! empty( $cmi_prevent_user_to_cpt_sync ) ) {
            return;
        }

        static $is_syncing = false;
        if ( $is_syncing ) {
            return;
        }
        $is_syncing = true;

        $doctor = get_userdata( $doctor_id );
        if ( ! $doctor || ! in_array( 'cmi_doctor', (array) $doctor->roles ) ) {
            $is_syncing = false;
            return;
        }

        // 1. Find corresponding doctor CPT post
        $doctor_post_id = 0;
        $posts = get_posts( [
            'post_type'   => 'doctor',
            'post_status' => 'any',
            'meta_query'  => [
                [
                    'key'   => '_cmi_doctor_user_id',
                    'value' => $doctor_id,
                ]
            ],
            'posts_per_page' => 1,
        ] );

        if ( ! empty( $posts ) ) {
            $doctor_post_id = $posts[0]->ID;
        } else {
            // Find by matching post title with doctor display name
            $posts_by_title = get_posts( [
                'post_type'   => 'doctor',
                'post_status' => 'any',
                'title'       => $doctor->display_name,
                'posts_per_page' => 1,
            ] );
            if ( ! empty( $posts_by_title ) ) {
                $doctor_post_id = $posts_by_title[0]->ID;
                update_post_meta( $doctor_post_id, '_cmi_doctor_user_id', $doctor_id );
            } else {
                // Create a new doctor CPT post
                $post_args = [
                    'post_title'   => $doctor->display_name,
                    'post_type'    => 'doctor',
                    'post_status'  => 'publish',
                    'post_author'  => $doctor_id,
                ];
                $doctor_post_id = wp_insert_post( $post_args );
                if ( ! is_wp_error( $doctor_post_id ) ) {
                    update_post_meta( $doctor_post_id, '_cmi_doctor_user_id', $doctor_id );
                } else {
                    $doctor_post_id = 0;
                }
            }
        }

        if ( ! $doctor_post_id ) {
            $is_syncing = false;
            return;
        }

        // 2. Fetch user metadata
        $specialty = get_user_meta( $doctor_id, '_cmi_specialty', true ) ?: 'General Physician';
        $fee = get_user_meta( $doctor_id, '_cmi_consultation_fee', true ) ?: '500';

        // 3. Fetch weekly schedule and exceptions
        global $wpdb;
        $avail_table = $wpdb->prefix . 'cmi_doctor_availability';
        $exceptions_table = $wpdb->prefix . 'cmi_doctor_exceptions';

        $weekly_schedule = $wpdb->get_results( $wpdb->prepare(
            "SELECT day, start_time, end_time, slot_duration, status FROM $avail_table WHERE doctor_id = %d ORDER BY day ASC, start_time ASC",
            $doctor_id
        ), ARRAY_A );

        $exceptions = $wpdb->get_results( $wpdb->prepare(
            "SELECT type, start_date, end_date, start_time, end_time, reason FROM $exceptions_table WHERE doctor_id = %d ORDER BY start_date ASC",
            $doctor_id
        ), ARRAY_A );

        $availability_data = [
            'weekly_schedule' => $weekly_schedule,
            'exceptions'      => $exceptions,
            'synced_at'       => current_time( 'mysql' ),
        ];

        // 4. Update post metadata
        update_post_meta( $doctor_post_id, '_cmi_specialty', $specialty );
        update_post_meta( $doctor_post_id, '_cmi_consultation_fee', $fee );
        update_post_meta( $doctor_post_id, '_cmi_availability_json', wp_json_encode( $availability_data ) );

        // Sync title and biography/description from user profile to doctor post
        wp_update_post( [
            'ID'           => $doctor_post_id,
            'post_title'   => $doctor->display_name,
            'post_content' => $doctor->description,
        ] );

        $is_syncing = false;
    }

    /**
     * AJAX handler: Save doctor availability exception (leave, override, holiday, emergency).
     */
    public function ajax_save_doctor_exception() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $doctor_id = get_current_user_id();
        $curr_user = wp_get_current_user();
        $is_doctor = in_array( 'cmi_doctor', (array) $curr_user->roles );
        if ( current_user_can( 'manage_options' ) && isset( $_POST['target_doctor_id'] ) ) {
            $doctor_id = intval( $_POST['target_doctor_id'] );
        } elseif ( ! $doctor_id || ( ! current_user_can( 'cmi_view_assignments' ) && ! $is_doctor ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-partner-portal' ) ] );
        }

        $type       = isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : '';
        $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : '';
        $end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : '';
        $start_time = isset( $_POST['start_time'] ) ? sanitize_text_field( $_POST['start_time'] ) : '';
        $end_time   = isset( $_POST['end_time'] ) ? sanitize_text_field( $_POST['end_time'] ) : '';
        $reason     = isset( $_POST['reason'] ) ? sanitize_text_field( $_POST['reason'] ) : '';

        if ( empty( $type ) || empty( $start_date ) || empty( $end_date ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Type, Start Date, and End Date are required.', 'cmi-partner-portal' ) ] );
        }

        if ( ! in_array( $type, [ 'leave', 'holiday', 'emergency', 'override' ], true ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid exception type.', 'cmi-partner-portal' ) ] );
        }

        $start_ts = strtotime( $start_date );
        $end_ts   = strtotime( $end_date );
        if ( ! $start_ts || ! $end_ts || $start_ts > $end_ts ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Start date must be before or equal to end date.', 'cmi-partner-portal' ) ] );
        }

        $start_t_str = null;
        $end_t_str = null;
        if ( ! empty( $start_time ) && ! empty( $end_time ) ) {
            $st_ts = strtotime( $start_time );
            $et_ts = strtotime( $end_time );
            if ( ! $st_ts || ! $et_ts || $st_ts >= $et_ts ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Start time must be before end time.', 'cmi-partner-portal' ) ] );
            }
            $start_t_str = date( 'H:i:s', $st_ts );
            $end_t_str = date( 'H:i:s', $et_ts );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_doctor_exceptions';

        $result = $wpdb->insert(
            $table,
            [
                'doctor_id'  => $doctor_id,
                'type'       => $type,
                'start_date' => date( 'Y-m-d', $start_ts ),
                'end_date'   => date( 'Y-m-d', $end_ts ),
                'start_time' => $start_t_str,
                'end_time'   => $end_t_str,
                'reason'     => $reason,
                'created_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' )
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( $result ) {
            // Cancel/Reschedule overlapping appointments if leave, emergency, or holiday
            if ( in_array( $type, [ 'leave', 'emergency', 'holiday' ], true ) ) {
                $this->handle_cancellation_affected_appointments( $doctor_id, $type, $start_date, $end_date, $start_t_str, $end_t_str );
            }

            $this->sync_doctor_user_to_cpt( $doctor_id );
            wp_send_json_success( [ 'message' => esc_html__( 'Exception window saved successfully.', 'cmi-partner-portal' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Database save failed.', 'cmi-partner-portal' ) ] );
        }
    }

    /**
     * AJAX handler: Delete doctor exception.
     */
    public function ajax_delete_doctor_exception() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $doctor_id = get_current_user_id();
        $curr_user = wp_get_current_user();
        $is_doctor = in_array( 'cmi_doctor', (array) $curr_user->roles );
        if ( current_user_can( 'manage_options' ) && isset( $_POST['target_doctor_id'] ) ) {
            $doctor_id = intval( $_POST['target_doctor_id'] );
        } elseif ( ! $doctor_id || ( ! current_user_can( 'cmi_view_assignments' ) && ! $is_doctor ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-partner-portal' ) ] );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid exception ID.', 'cmi-partner-portal' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_doctor_exceptions';

        $delete = $wpdb->delete(
            $table,
            [ 'id' => $id, 'doctor_id' => $doctor_id ],
            [ '%d', '%d' ]
        );

        if ( $delete !== false ) {
            $this->sync_doctor_user_to_cpt( $doctor_id );
            wp_send_json_success( [ 'message' => esc_html__( 'Exception removed successfully.', 'cmi-partner-portal' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Database delete failed.', 'cmi-partner-portal' ) ] );
        }
    }

    /**
     * Find and handle active consultations that are affected by a doctor leave, emergency block, or holiday.
     */
    public function handle_cancellation_affected_appointments( $doctor_id, $type, $start_date, $end_date, $start_time = null, $end_time = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        // Query active appointments ('assigned', 'scheduled', 'requested', 'in_progress') for this doctor in date range
        $query = "SELECT * FROM $table WHERE doctor_id = %d AND preferred_date BETWEEN %s AND %s AND status IN ('assigned', 'scheduled', 'requested', 'in_progress')";
        $appointments = $wpdb->get_results( $wpdb->prepare( $query, $doctor_id, $start_date, $end_date ) );

        if ( empty( $appointments ) ) {
            return;
        }

        foreach ( $appointments as $appt ) {
            $affected = false;
            if ( empty( $start_time ) || empty( $end_time ) ) {
                // All-day leave/emergency block covers everything
                $affected = true;
            } else {
                // Check if appointment preferred_time_slot overlaps with exception time range
                // preferred_time_slot format: "09:00 AM - 09:30 AM"
                $parts = explode( '-', $appt->preferred_time_slot );
                if ( count( $parts ) === 2 ) {
                    $appt_start_ts = strtotime( trim( $parts[0] ) );
                    $appt_end_ts   = strtotime( trim( $parts[1] ) );
                    $ex_start_ts   = strtotime( $start_time );
                    $ex_end_ts     = strtotime( $end_time );

                    if ( $appt_start_ts && $appt_end_ts && $ex_start_ts && $ex_end_ts ) {
                        // Check overlap
                        if ( ( $appt_start_ts >= $ex_start_ts && $appt_start_ts < $ex_end_ts ) ||
                             ( $appt_end_ts > $ex_start_ts && $appt_end_ts <= $ex_end_ts ) ||
                             ( $appt_start_ts <= $ex_start_ts && $appt_end_ts >= $ex_end_ts ) ) {
                            $affected = true;
                        }
                    }
                }
            }

            if ( $affected ) {
                // Update status to needs_reschedule
                $wpdb->update(
                    $table,
                    [ 'status' => 'needs_reschedule', 'updated_at' => current_time( 'mysql' ) ],
                    [ 'id' => $appt->id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );

                // Dispatch notification
                do_action( 'cmi_consultation_needs_reschedule', $appt->id );
            }
        }
    }

    /**
     * Automatically append the patient consultation booking widget to single doctor CPT pages.
     */
    public function append_booking_widget_to_doctor_cpt( $content ) {
        if ( is_singular( 'doctor' ) && in_the_loop() && is_main_query() ) {
            $doctor_post_id = get_the_ID();
            $doctor_user_id = get_post_meta( $doctor_post_id, '_cmi_doctor_user_id', true );
            if ( $doctor_user_id ) {
                $booking_form = do_shortcode( '[cmi_doctor_consultation doctor_id="' . $doctor_user_id . '"]' );
                $content .= '<div class="cmi-doctor-booking-section" style="margin-top: 40px; border-top: 1px solid #e4e4e7; padding-top: 30px;">' . $booking_form . '</div>';
            }
        }
        return $content;
    }

    /**
     * Add custom 5-minute cron schedule for pre-meeting SMS reminders.
     */
    public function add_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['cmi_five_minutes'] ) ) {
            $schedules['cmi_five_minutes'] = [
                'interval' => 300, // 300 seconds = 5 minutes
                'display'  => __( 'Every 5 Minutes (CMI Reminders)', 'cmi-partner-portal' ),
            ];
        }
        return $schedules;
    }

    /**
     * Schedule recurring WP-Cron event if not already scheduled.
     */
    public function schedule_meeting_reminder_cron() {
        if ( ! wp_next_scheduled( 'cmi_check_upcoming_meeting_reminders_cron' ) ) {
            wp_schedule_event( time(), 'cmi_five_minutes', 'cmi_check_upcoming_meeting_reminders_cron' );
        }
    }

    /**
     * Cron callback: checks scheduled meetings starting in ~5 minutes and dispatches DLT SMS reminder.
     */
    public function dispatch_upcoming_meeting_reminders() {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';

        $tz = new DateTimeZone( 'Asia/Kolkata' );
        $now_dt = new DateTime( 'now', $tz );
        $today = $now_dt->format( 'Y-m-d' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE status IN ('scheduled', 'assigned') AND preferred_date = %s",
            $today
        ) );

        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $consult_id = intval( $row->id );
            
            // Check if reminder was already dispatched for this consultation ID
            $already_sent = get_option( "cmi_rem_sent_{$consult_id}", false );
            if ( $already_sent ) {
                continue;
            }

            // Parse preferred_time_slot start time (e.g. "03:00 PM - 03:30 PM" or "15:00 - 15:30")
            $parts = explode( '-', $row->preferred_time_slot );
            $start_str = ! empty( $parts[0] ) ? trim( $parts[0] ) : '';
            if ( empty( $start_str ) ) {
                continue;
            }

            try {
                $slot_dt = new DateTime( $today . ' ' . $start_str, $tz );
            } catch ( Exception $e ) {
                continue;
            }

            // Calculate minutes until meeting start time
            $diff_seconds = $slot_dt->getTimestamp() - $now_dt->getTimestamp();
            $diff_minutes = round( $diff_seconds / 60 );

            // Trigger reminder if meeting starts within 15 minutes (or up to 2 minutes past start)
            if ( $diff_minutes >= -2 && $diff_minutes <= 15 ) {
                $user_id = intval( $row->user_id );
                $patient_mobile = ! empty( $row->patient_mobile ) ? $row->patient_mobile
                    : ( get_user_meta( $user_id, '_cmi_mobile', true ) ?: get_user_meta( $user_id, 'billing_phone', true ) );

                if ( empty( $patient_mobile ) ) {
                    continue;
                }

                $doctor = ! empty( $row->doctor_id ) ? get_userdata( $row->doctor_id ) : null;
                $doc_clean_name = $doctor ? preg_replace( '/^Dr\.\s*/i', '', $doctor->display_name ) : 'Assigned Doctor';

                if ( class_exists( 'CMI_SMS_Manager' ) ) {
                    CMI_SMS_Manager::send_event_sms( 'consultation_reminder', $patient_mobile, [
                        'name'   => $row->patient_name,
                        'id'     => $consult_id,
                        'doctor' => $doc_clean_name
                    ] );
                }

                // Mark reminder as dispatched to prevent duplicate SMS
                update_option( "cmi_rem_sent_{$consult_id}", current_time( 'mysql' ) );
            }
        }
    }
}
