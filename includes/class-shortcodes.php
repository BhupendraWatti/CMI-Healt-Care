<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CMI_HT_Shortcodes {

    public function __construct() {
        // Register shortcodes
        add_shortcode( 'cmi_patient_dashboard', [ $this, 'render_patient_dashboard' ] );
        add_shortcode( 'cmi_partner_dashboard', [ $this, 'render_partner_dashboard' ] );

        // WooCommerce My Account integration
        add_filter( 'woocommerce_account_menu_items', [ $this, 'customize_account_menu_items' ] );
        add_action( 'template_redirect', [ $this, 'override_my_account_orders' ] );

        // Custom My Account Orders table column for reports
        add_filter( 'woocommerce_my_account_my_orders_columns', [ $this, 'add_my_account_report_column' ] );
        add_action( 'woocommerce_my_account_my_orders_column_cmi_report', [ $this, 'render_my_account_report_column' ] );

        // Custom My Account endpoints content
        add_action( 'woocommerce_account_patient-reports_endpoint', [ $this, 'render_my_account_patient_reports' ] );
        add_action( 'woocommerce_account_patient-consultations_endpoint', [ $this, 'render_my_account_patient_consultations' ] );
        add_action( 'woocommerce_account_home-collections_endpoint', [ $this, 'render_my_account_home_collections' ] );
        add_action( 'woocommerce_account_family-members_endpoint', [ $this, 'render_my_account_family_members' ] );
        add_filter( 'the_title', [ $this, 'change_my_account_endpoint_titles' ] );

        // AJAX actions for family members management
        add_action( 'wp_ajax_cmi_edit_family_member',   [ $this, 'ajax_edit_family_member' ] );
        add_action( 'wp_ajax_cmi_delete_family_member',  [ $this, 'ajax_delete_family_member' ] );
    }



    /**
     * Add "Your Report" column header to My Account > Orders table.
     */
    public function add_my_account_report_column( $columns ) {
        $new_columns = [];
        foreach ( $columns as $key => $name ) {
            $new_columns[$key] = $name;
            if ( 'order-status' === $key ) {
                $new_columns['cmi_report'] = esc_html__( 'Your Report', 'cmi-home-testing' );
            }
        }
        if ( ! isset( $new_columns['cmi_report'] ) ) {
            $new_columns['cmi_report'] = esc_html__( 'Your Report', 'cmi-home-testing' );
        }
        return $new_columns;
    }

    /**
     * Render the download link / status in "Your Report" column.
     */
    public function render_my_account_report_column( $order ) {
        $order_id = $order->get_id();
        
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';
        
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d LIMIT 1",
            $order_id
        ) );
        
        if ( ! $row ) {
            echo '<span class="description">-</span>';
            return;
        }
        
        if ( $row->status === 'completed' && ! empty( $row->report_pdf ) ) {
            $download_url = '';
            if ( class_exists( 'CMI_Download' ) ) {
                $report_post_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cmi_file_name' AND meta_value = %s LIMIT 1",
                    $row->report_pdf
                ) );
                if ( $report_post_id ) {
                    $download_url = CMI_Download::generate_link( $report_post_id );
                } else {
                    $download_url = CMI_Download::generate_link( $row->report_pdf );
                }
            }
            if ( empty( $download_url ) ) {
                $download_url = content_url( 'cmi-secure-reports/' . $row->report_pdf );
            }
            
            echo '<a class="button cmi-my-account-download-btn" href="' . esc_url( $download_url ) . '" target="_blank" style="padding: 5px 10px; font-size: 12px; line-height: 1.2; background-color: #1a4f8a; color: #fff; border-radius: 4px; display: inline-block; text-decoration: none; border: none;">' . esc_html__( 'Download', 'cmi-home-testing' ) . '</a>';
        } else {
            if ( $row->status === 'pending_assignment' ) {
                echo '<span class="cmi-badge" style="background-color: #feebc8; color: #c05621; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 500;">' . esc_html__( 'Pending Assign', 'cmi-home-testing' ) . '</span>';
            } elseif ( $row->status === 'assigned' || $row->status === 'accepted' ) {
                echo '<span class="cmi-badge" style="background-color: #ebf8ff; color: #2b6cb0; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 500;">' . esc_html__( 'In Progress', 'cmi-home-testing' ) . '</span>';
            } else {
                echo '<span class="description">-</span>';
            }
        }
    }

    /**
     * Rename "Orders" tab to "Order Requests" for partners in WooCommerce My Account.
     */
    public function customize_account_menu_items( $items ) {
        $user      = wp_get_current_user();
        $is_doctor = $user->ID && in_array( 'cmi_doctor', (array) $user->roles );

        if ( $is_doctor ) {
            // Doctors: rename Orders -> "Partner Dashboard" so it loads
            // the full tabbed interface (Consultations, Home Collections, etc.)
            $items['orders'] = esc_html__( 'Partner Dashboard', 'cmi-home-testing' );

        } elseif ( current_user_can( 'cmi_view_assignments' ) ) {
            // Medical Partners: rename Orders -> "Order Requests"
            $items['orders'] = esc_html__( 'Order Requests', 'cmi-home-testing' );

        } else {
            // Patients: Insert Consultations, My Reports, Home Collections, Family Members
            $new_items = [];
            foreach ( $items as $key => $val ) {
                $new_items[$key] = $val;
                if ( 'dashboard' === $key ) {
                    $new_items['patient-consultations'] = esc_html__( 'Consultations', 'cmi-partner-portal' );
                    $new_items['patient-reports']       = esc_html__( 'My Reports', 'cmi-home-testing' );
                    $new_items['home-collections']      = esc_html__( 'Home Collections', 'cmi-home-testing' );
                    $new_items['family-members']        = esc_html__( 'Family Members', 'cmi-partner-portal' );
                }
            }
            $items = $new_items;
        }
        return $items;
    }

    /**
     * Override standard WooCommerce orders page in My Account for partners.
     */
    public function override_my_account_orders() {
        if ( is_account_page() && is_user_logged_in() && current_user_can( 'cmi_view_assignments' ) ) {
            // Remove WooCommerce default orders template output
            remove_action( 'woocommerce_account_orders_endpoint', 'woocommerce_account_orders' );
            
            // Add our partner dashboard template output instead
            add_action( 'woocommerce_account_orders_endpoint', [ $this, 'render_partner_orders_tab' ] );
        }
    }

    /**
     * Render the full tabbed partner/doctor dashboard inside the WC My Account endpoint.
     * Loads templates/partner-dashboard.php (same as the portal page) so that doctors
     * and medical partners see ALL tabs: Home Collections, Consultations, Prescriptions,
     * Availability, My Patients, My Profile — not just the old simple inline table.
     */
    public function render_partner_orders_tab() {
        $user = wp_get_current_user();
        if ( $user->ID && defined( 'CMI_PP_PATH' ) && file_exists( CMI_PP_PATH . 'templates/partner-dashboard.php' ) ) {
            // Full tabbed dashboard — same as [cmi_portal] shortcode page
            include CMI_PP_PATH . 'templates/partner-dashboard.php';
        } else {
            // Fallback to inline shortcode version if template file is missing
            echo $this->render_partner_dashboard();
        }
    }

    /**
     * Render Patient Dashboard shortcode.
     */
    public function render_patient_dashboard() {
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['elementor-preview'] ) ) {
            return '<div class="cmi-portal-preview-mode"><p>' . esc_html__( '[CMI Patient Dashboard - Preview Mode]', 'cmi-home-testing' ) . '</p></div>';
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return '<p>' . esc_html__( 'Please log in to view your testing dashboard.', 'cmi-home-testing' ) . '</p>';
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';

        // Fetch user's orders
        $customer_orders = wc_get_orders( [
            'customer_id' => $user_id,
            'limit'       => -1,
            'return'      => 'ids',
        ] );

        if ( empty( $customer_orders ) ) {
            return '<p>' . esc_html__( 'No home testing bookings found.', 'cmi-home-testing' ) . '</p>';
        }

        $placeholders = implode( ',', array_fill( 0, count( $customer_orders ), '%d' ) );
        $query = $wpdb->prepare(
            "SELECT * FROM $table WHERE order_id IN ($placeholders) ORDER BY id DESC",
            $customer_orders
        );
        $results = $wpdb->get_results( $query );

        if ( empty( $results ) ) {
            return '<p>' . esc_html__( 'No home testing appointments found for your orders.', 'cmi-home-testing' ) . '</p>';
        }

        ob_start();
        ?>
        <div class="cmi-patient-dashboard-wrapper">
            <h2><?php esc_html_e( 'My Home Collection Bookings', 'cmi-home-testing' ); ?></h2>
            <div class="cmi-table-responsive">
                <table class="cmi-dashboard-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Order ID', 'cmi-home-testing' ); ?></th>
                            <th><?php esc_html_e( 'Appointment Date', 'cmi-home-testing' ); ?></th>
                            <th><?php esc_html_e( 'Time Slot', 'cmi-home-testing' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'cmi-home-testing' ); ?></th>
                            <th><?php esc_html_e( 'Actions / Reports', 'cmi-home-testing' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $results as $row ) : ?>
                            <tr>
                                <td>#<?php echo esc_html( $row->order_id ); ?></td>
                                <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->collection_date ) ) ); ?></td>
                                <td><?php echo esc_html( $row->collection_time_slot ); ?></td>
                                <td>
                                    <span class="cmi-badge cmi-status-<?php echo esc_attr( $row->status ); ?>">
                                        <?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ( $row->status === 'completed' && ! empty( $row->report_pdf ) ) : ?>
                                        <?php 
                                        $download_url = '';
                                        if ( class_exists( 'CMI_Download' ) ) {
                                            global $wpdb;
                                            $report_post_id = $wpdb->get_var( $wpdb->prepare(
                                                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cmi_file_name' AND meta_value = %s LIMIT 1",
                                                $row->report_pdf
                                            ) );
                                            if ( $report_post_id ) {
                                                $download_url = CMI_Download::generate_link( $report_post_id );
                                            } else {
                                                $download_url = CMI_Download::generate_link( $row->report_pdf );
                                            }
                                        }
                                        if ( empty( $download_url ) ) {
                                            $download_url = content_url( 'cmi-secure-reports/' . $row->report_pdf );
                                        }
                                        ?>
                                        <a class="button cmi-download-btn" href="<?php echo esc_url( $download_url ); ?>" target="_blank">
                                            <?php esc_html_e( 'Download Report', 'cmi-home-testing' ); ?>
                                        </a>
                                    <?php elseif ( $row->reschedule_status === 'pending' ) : ?>
                                        <span class="description"><?php esc_html_e( 'Reschedule Pending Approval', 'cmi-home-testing' ); ?></span>
                                    <?php elseif ( in_array( $row->status, [ 'pending_assignment', 'assigned', 'accepted', 'rescheduled' ] ) ) : ?>
                                        <button class="button cmi-trigger-reschedule" data-id="<?php echo esc_attr( $row->id ); ?>">
                                            <?php esc_html_e( 'Reschedule Appointment', 'cmi-home-testing' ); ?>
                                        </button>
                                    <?php else : ?>
                                        <span class="description">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Reschedule Modal -->
            <div id="cmi-reschedule-modal" style="display:none;">
                <div class="cmi-modal-content">
                    <h3><?php esc_html_e( 'Request Rescheduling', 'cmi-home-testing' ); ?></h3>
                    <form id="cmi-reschedule-form">
                        <input type="hidden" id="cmi-reschedule-id" name="id" value="">
                        
                        <p>
                            <label for="cmi-new-date"><?php esc_html_e( 'Choose New Date', 'cmi-home-testing' ); ?></label>
                            <input type="date" id="cmi-new-date" name="reschedule_date" required min="<?php echo date( 'Y-m-d', strtotime( '+1 day' ) ); ?>">
                        </p>
                        
                        <p>
                            <label for="cmi-new-slot"><?php esc_html_e( 'Choose Time Slot', 'cmi-home-testing' ); ?></label>
                            <select id="cmi-new-slot" name="reschedule_time_slot" required>
                                <option value=""><?php esc_html_e( 'Select Slot', 'cmi-home-testing' ); ?></option>
                                <?php 
                                $slots = get_option( 'cmi_ht_time_slots', [ '08:00 AM - 10:00 AM', '10:00 AM - 12:00 PM', '12:00 PM - 02:00 PM', '02:00 PM - 04:00 PM' ] );
                                foreach ( $slots as $slot ) {
                                    echo '<option value="' . esc_attr( $slot ) . '">' . esc_html( $slot ) . '</option>';
                                }
                                ?>
                            </select>
                        </p>
                        
                        <p>
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Submit Request', 'cmi-home-testing' ); ?></button>
                            <button type="button" class="button cmi-close-modal"><?php esc_html_e( 'Cancel', 'cmi-home-testing' ); ?></button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render Partner Dashboard.
     */
    public function render_partner_dashboard() {
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['elementor-preview'] ) ) {
            return '<div class="cmi-portal-preview-mode"><p>' . esc_html__( '[CMI Partner Dashboard - Preview Mode]', 'cmi-home-testing' ) . '</p></div>';
        }

        $user_id = get_current_user_id();
        if ( ! $user_id || ! current_user_can( 'cmi_view_assignments' ) ) {
            return '<p>' . esc_html__( 'Please log in to your partner account to view assignments.', 'cmi-home-testing' ) . '</p>';
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';

        // Fetch jobs assigned to this partner
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE partner_id = %d ORDER BY id DESC",
            $user_id
        ) );

        // Fetch custom report types from sibling taxonomy
        $report_types = [];
        if ( taxonomy_exists( 'cmi_report_type' ) ) {
            $report_types = get_terms([ 'taxonomy' => 'cmi_report_type', 'hide_empty' => false ]);
        }

        ob_start();
        ?>
        <div class="cmi-partner-dashboard-wrapper">
            <h2><?php esc_html_e( 'My Collection Assignments', 'cmi-home-testing' ); ?></h2>
            <div class="cmi-table-responsive">
                <table class="cmi-dashboard-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Order ID', 'cmi-home-testing' ); ?></th>
                            <th><?php esc_html_e( 'Patient Details', 'cmi-home-testing' ); ?></th>
                            <th><?php esc_html_e( 'Collection Address', 'cmi-home-testing' ); ?></th>
                            <th><?php esc_html_e( 'Schedule Date', 'cmi-home-testing' ); ?></th>
                            <th><?php esc_html_e( 'Time Slot', 'cmi-home-testing' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'cmi-home-testing' ); ?></th>
                            <th><?php esc_html_e( 'Workflow Action', 'cmi-home-testing' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $results ) ) : ?>
                            <tr>
                                <td colspan="7"><?php esc_html_e( 'No assignments found.', 'cmi-home-testing' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $results as $row ) :
                                $order = wc_get_order( $row->order_id );
                                if ( ! $order ) continue;

                                // Fetch Patient Snapshot Details from Order Meta
                                $patient_name    = $order->get_meta( '_cmi_patient_name' ) ?: ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
                                $patient_gender  = $order->get_meta( '_cmi_patient_gender' ) ?: 'Unspecified';
                                $patient_dob     = $order->get_meta( '_cmi_patient_dob' ) ?: '';
                                $patient_phone   = $order->get_meta( '_cmi_patient_mobile' ) ?: $order->get_billing_phone();

                                // Calculate Age from DOB
                                $patient_age = '';
                                if ( $patient_dob && '—' !== $patient_dob && '1990-01-01' !== $patient_dob ) {
                                    try {
                                        $dob_date = new DateTime( $patient_dob );
                                        $today = new DateTime();
                                        $age_diff = $today->diff( $dob_date );
                                        $patient_age = $age_diff->y . ' ' . esc_html__( 'years', 'cmi-home-testing' );
                                    } catch ( Exception $e ) {
                                        $patient_age = '';
                                    }
                                }

                                // Fetch Package/Product Details from Order Items
                                $order_items = $order->get_items();
                                $packages = [];
                                foreach ( $order_items as $item ) {
                                    $packages[] = $item->get_name();
                                }
                                $packages_list = implode( ', ', $packages );

                                // Auto-detect Report Type based on order items
                                $detected_type_id = '';
                                if ( taxonomy_exists( 'cmi_report_type' ) ) {
                                    $terms = get_terms([ 'taxonomy' => 'cmi_report_type', 'hide_empty' => false ]);
                                    foreach ( $order_items as $item ) {
                                        $product_name = strtolower( $item->get_name() );
                                        foreach ( $terms as $term ) {
                                            $term_name = strtolower( $term->name );
                                            if ( strpos( $product_name, $term_name ) !== false || strpos( $term_name, $product_name ) !== false ) {
                                                $detected_type_id = $term->term_id;
                                                break 2;
                                            }
                                        }
                                    }
                                }

                                // Use formatted shipping address first, fallback to billing
                                $address = $order->get_formatted_shipping_address();
                                if ( empty( $address ) ) {
                                    $address = $order->get_formatted_billing_address();
                                }
                                ?>
                                <tr data-id="<?php echo esc_attr( $row->id ); ?>">
                                    <td>#<?php echo esc_html( $row->order_id ); ?></td>
                                    <td>
                                        <strong><?php echo esc_html( $patient_name ); ?></strong><br>
                                        <span class="description"><?php echo esc_html( $patient_gender ); ?><?php echo $patient_age ? ', ' . esc_html( $patient_age ) : ''; ?></span><br>
                                        <span class="description" style="color: #2b6cb0; font-weight: 600;"><?php echo esc_html( $packages_list ); ?></span>
                                    </td>
                                    <td>
                                        <?php echo wp_kses_post( $address ); ?>
                                    </td>
                                    <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->collection_date ) ) ); ?></td>
                                    <td><?php echo esc_html( $row->collection_time_slot ); ?></td>
                                    <td>
                                        <span class="cmi-badge cmi-status-<?php echo esc_attr( $row->status ); ?>">
                                            <?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ( $row->status === 'assigned' ) : ?>
                                            <button class="button cmi-partner-accept-btn" data-id="<?php echo esc_attr( $row->id ); ?>"><?php esc_html_e( 'Accept', 'cmi-home-testing' ); ?></button>
                                            <button class="button cmi-partner-reject-btn" data-id="<?php echo esc_attr( $row->id ); ?>"><?php esc_html_e( 'Reject', 'cmi-home-testing' ); ?></button>
                                        <?php elseif ( $row->status === 'accepted' ) : ?>
                                            <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-start;">
                                                <span class="description" style="font-size:11px;"><strong><?php esc_html_e( 'Contact:', 'cmi-home-testing' ); ?></strong> <?php echo esc_html( $patient_phone ); ?></span>
                                                <button class="button button-primary cmi-trigger-upload-report" data-id="<?php echo esc_attr( $row->id ); ?>" data-order-id="<?php echo esc_attr( $row->order_id ); ?>" data-patient-name="<?php echo esc_attr( $patient_name ); ?>" data-detected-type="<?php echo esc_attr( $detected_type_id ); ?>">
                                                    <?php esc_html_e( 'Upload Report', 'cmi-home-testing' ); ?>
                                                </button>
                                            </div>
                                        <?php elseif ( $row->status === 'completed' ) : ?>
                                            <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-start;">
                                                <span class="description" style="font-size:11px;"><strong><?php esc_html_e( 'Contact:', 'cmi-home-testing' ); ?></strong> <?php echo esc_html( $patient_phone ); ?></span>
                                                <span class="cmi-success-text" style="color:#27ae60; font-weight:600;"><?php esc_html_e( 'Report Uploaded', 'cmi-home-testing' ); ?></span>
                                                <div style="display:flex; gap:6px;">
                                                    <?php 
                                                    $download_url = '';
                                                    $report_post_id = 0;
                                                    if ( class_exists( 'CMI_Download' ) ) {
                                                        global $wpdb;
                                                        $report_post_id = $wpdb->get_var( $wpdb->prepare(
                                                            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cmi_file_name' AND meta_value = %s LIMIT 1",
                                                            $row->report_pdf
                                                        ) );
                                                        if ( $report_post_id ) {
                                                            $download_url = CMI_Download::generate_link( $report_post_id );
                                                        }
                                                    }
                                                    if ( $download_url ) : ?>
                                                        <a class="button cmi-download-btn" href="<?php echo esc_url( $download_url ); ?>" target="_blank" style="font-size:11px !important; padding:4px 10px !important; line-height:1.2; text-decoration:none;"><?php esc_html_e( 'Download', 'cmi-home-testing' ); ?></a>
                                                    <?php endif; ?>
                                                    <button class="button button-secondary cmi-trigger-upload-report" data-id="<?php echo esc_attr( $row->id ); ?>" data-order-id="<?php echo esc_attr( $row->order_id ); ?>" data-patient-name="<?php echo esc_attr( $patient_name ); ?>" data-detected-type="<?php echo esc_attr( $detected_type_id ); ?>" style="font-size:11px !important; padding:4px 10px !important; line-height:1.2; border-color:#1a4f8a !important; color:#1a4f8a !important;">
                                                        <?php esc_html_e( 'Re-upload', 'cmi-home-testing' ); ?>
                                                    </button>
                                                </div>
                                            </div>
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

            <!-- Reject Reason Modal -->
            <div id="cmi-reject-modal" style="display:none;">
                <div class="cmi-modal-content">
                    <h3><?php esc_html_e( 'Reject Assignment', 'cmi-home-testing' ); ?></h3>
                    <form id="cmi-reject-form">
                        <input type="hidden" id="cmi-reject-id" name="id" value="">
                        <p>
                            <label for="cmi-reject-reason"><?php esc_html_e( 'Reason for Rejection', 'cmi-home-testing' ); ?></label>
                            <textarea id="cmi-reject-reason" name="reason" required class="large-text"></textarea>
                        </p>
                        <p>
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Submit Rejection', 'cmi-home-testing' ); ?></button>
                            <button type="button" class="button cmi-close-modal"><?php esc_html_e( 'Cancel', 'cmi-home-testing' ); ?></button>
                        </p>
                    </form>
                </div>
            </div>

            <!-- New Separate Upload Report Modal (Takes Reference from cmi-partner-portal) -->
            <div id="cmi-upload-report-modal" style="display:none;">
                <div class="cmi-modal-content">
                    <h3><?php esc_html_e( 'Upload Patient Report', 'cmi-home-testing' ); ?></h3>
                    <form id="cmi-upload-report-form" enctype="multipart/form-data">
                        <input type="hidden" id="cmi-upload-id" name="id" value="">
                        
                        <div class="cmi-form-row">
                            <label><?php esc_html_e( 'Order ID', 'cmi-home-testing' ); ?></label>
                            <input type="text" id="cmi-display-order-id" readonly disabled>
                        </div>

                        <div class="cmi-form-row">
                            <label><?php esc_html_e( 'Patient Name', 'cmi-home-testing' ); ?></label>
                            <input type="text" id="cmi-display-patient-name" readonly disabled>
                        </div>

                        <div class="cmi-form-row">
                            <label for="cmi-modal-report-type"><?php esc_html_e( 'Report Type', 'cmi-home-testing' ); ?> <span class="req">*</span></label>
                            <select id="cmi-modal-report-type" name="report_type_id" required>
                                <option value=""><?php esc_html_e( 'Select report type...', 'cmi-home-testing' ); ?></option>
                                <?php 
                                if ( taxonomy_exists( 'cmi_report_type' ) ) {
                                    $report_types = get_terms([ 'taxonomy' => 'cmi_report_type', 'hide_empty' => false ]);
                                }
                                foreach ( $report_types as $term ) : ?>
                                    <option value="<?php echo esc_attr($term->term_id); ?>"><?php echo esc_html($term->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="cmi-form-row">
                            <label for="cmi-modal-report-file"><?php esc_html_e( 'Report File', 'cmi-home-testing' ); ?> <span class="req">*</span> <small><?php esc_html_e( '(PDF only, max 10 MB)', 'cmi-home-testing' ); ?></small></label>
                            <input type="file" id="cmi-modal-report-file" name="report_file" accept="application/pdf" required>
                        </div>

                        <div class="cmi-form-row">
                            <label for="cmi-modal-report-notes"><?php esc_html_e( 'Notes / Remarks', 'cmi-home-testing' ); ?></label>
                            <textarea id="cmi-modal-report-notes" name="notes" rows="3" placeholder="<?php esc_attr_e( 'Any additional notes for the patient or admin...', 'cmi-home-testing' ); ?>"></textarea>
                        </div>

                        <div class="cmi-form-actions" style="margin-top:20px;">
                            <button type="submit" class="button button-primary" id="cmi-modal-upload-btn"><?php esc_html_e( 'Upload Report', 'cmi-home-testing' ); ?></button>
                            <button type="button" class="button cmi-close-modal"><?php esc_html_e( 'Cancel', 'cmi-home-testing' ); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render patient reports under My Account > My Reports.
     */
    public function render_my_account_patient_reports() {
        $user = wp_get_current_user();
        if ( ! $user->ID ) {
            return;
        }

        $mobile = get_user_meta( $user->ID, '_cmi_mobile', true );
        $uid    = get_user_meta( $user->ID, '_cmi_uid', true );
        $email  = $user->user_email;

        // Fetch reports and prescriptions
        $reports = CMI_CPT::get_patient_reports( $mobile, $uid, 'cmi_report', $email );
        $rxs     = CMI_CPT::get_patient_reports( $mobile, $uid, 'cmi_prescription', $email );

        include CMI_PP_PATH . 'templates/patient-reports.php';
    }

    /**
     * Render patient active consultations list under My Account > Consultations.
     */
    public function render_my_account_patient_consultations() {
        $user = wp_get_current_user();
        if ( ! $user->ID ) {
            return;
        }

        global $wpdb;
        $table_consultations = $wpdb->prefix . 'cmi_consultations';
        $active_consultations = [];
        if ( class_exists( 'CMI_Consultations' ) ) {
            CMI_Consultations::sync_paid_consultation_orders_for_user( $user->ID );
        }
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_consultations'" ) === $table_consultations ) {
            $active_consultations = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $table_consultations 
                 WHERE user_id = %d AND status NOT IN ('completed', 'cancelled')
                 ORDER BY preferred_date ASC, preferred_time_slot ASC",
                $user->ID
            ) );
        }

        include CMI_PP_PATH . 'templates/patient-consultations.php';
    }

    /**
     * Render patient home collections list under My Account > Home Collections.
     */
    public function render_my_account_home_collections() {
        echo $this->render_patient_dashboard();
    }

    /**
     * Modify endpoint titles dynamically.
     */
    public function change_my_account_endpoint_titles( $title ) {
        global $wp_query;
        $is_endpoint = is_main_query() && in_the_loop() && is_account_page();
        
        if ( $is_endpoint && isset( $wp_query->query_vars['patient-consultations'] ) ) {
            return esc_html__( 'Consultations', 'cmi-partner-portal' );
        }
        if ( $is_endpoint && isset( $wp_query->query_vars['patient-reports'] ) ) {
            return esc_html__( 'My Reports', 'cmi-home-testing' );
        }
        if ( $is_endpoint && isset( $wp_query->query_vars['home-collections'] ) ) {
            return esc_html__( 'Home Collections', 'cmi-home-testing' );
        }
        if ( $is_endpoint && isset( $wp_query->query_vars['family-members'] ) ) {
            return esc_html__( 'Family Members', 'cmi-partner-portal' );
        }
        
        return $title;
    }

    /**
     * Render family members list under My Account > Family Members.
     */
    public function render_my_account_family_members() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            echo '<p>' . esc_html__( 'Please log in to view your family members.', 'cmi-partner-portal' ) . '</p>';
            return;
        }

        $members = CMI_HT_DB::get_user_members( $user_id );
        ?>
        <div class="cmi-family-members-wrapper" style="font-family: inherit;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0;"><?php esc_html_e( 'Manage Family Members', 'cmi-partner-portal' ); ?></h3>
                <button type="button" class="button cmi-add-member-btn" style="background:#1a4f8a; color:#fff; border:none; padding: 6px 12px; border-radius: 4px; font-weight:600; cursor:pointer;"><?php esc_html_e( 'Add Family Member', 'cmi-partner-portal' ); ?></button>
            </div>

            <!-- List Members Table -->
            <table class="cmi-dashboard-table cmi-members-table" style="width:100%; border-collapse:collapse; margin-top:15px;">
                <thead>
                    <tr style="border-bottom:2px solid #eee; text-align:left;">
                        <th style="padding:10px;"><?php esc_html_e( 'Name', 'cmi-partner-portal' ); ?></th>
                        <th style="padding:10px;"><?php esc_html_e( 'Relation', 'cmi-partner-portal' ); ?></th>
                        <th style="padding:10px;"><?php esc_html_e( 'Gender / DOB', 'cmi-partner-portal' ); ?></th>
                        <th style="padding:10px;"><?php esc_html_e( 'Mobile', 'cmi-partner-portal' ); ?></th>
                        <th style="padding:10px; text-align:right;"><?php esc_html_e( 'Actions', 'cmi-partner-portal' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $members ) ) : ?>
                        <tr>
                            <td colspan="5" style="padding:20px; text-align:center; color:#718096;"><?php esc_html_e( 'No family members found.', 'cmi-partner-portal' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $members as $m ) : ?>
                            <tr style="border-bottom:1px solid #edf2f7;" data-id="<?php echo esc_attr( $m->id ); ?>" data-name="<?php echo esc_attr( $m->name ); ?>" data-relationship="<?php echo esc_attr( $m->relationship ); ?>" data-gender="<?php echo esc_attr( $m->gender ); ?>" data-dob="<?php echo esc_attr( $m->dob ); ?>" data-mobile="<?php echo esc_attr( $m->mobile ); ?>">
                                <td style="padding:10px; font-weight:600;"><?php echo esc_html( $m->name ); ?></td>
                                <td style="padding:10px;"><span class="cmi-badge" style="background:#edf2f7; color:#4a5568; font-size:11px; padding:2px 6px; border-radius:4px; font-weight:500;"><?php echo esc_html( $m->relationship ); ?></span></td>
                                <td style="padding:10px;"><?php echo esc_html( $m->gender ); ?> / <?php echo esc_html( $m->dob ); ?></td>
                                <td style="padding:10px;"><?php echo esc_html( $m->mobile ?: '—' ); ?></td>
                                <td style="padding:10px; text-align:right;">
                                    <button type="button" class="button cmi-edit-member-trigger" style="font-size:11px; padding:4px 8px; margin-right:5px; cursor:pointer;"><?php esc_html_e( 'Edit', 'cmi-partner-portal' ); ?></button>
                                    <?php if ( 'Self' !== $m->relationship ) : ?>
                                        <button type="button" class="button cmi-delete-member-trigger" style="font-size:11px; padding:4px 8px; background:#fff; color:#dc2626; border-color:#e5e7eb; cursor:pointer;"><?php esc_html_e( 'Delete', 'cmi-partner-portal' ); ?></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Add/Edit Member Modal (hidden by default) -->
            <div id="cmi-member-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
                <div style="background:#fff; padding:25px; border-radius:8px; max-width:400px; width:100%; box-shadow:0 4px 15px rgba(0,0,0,0.15); box-sizing:border-box;">
                    <h4 id="cmi-member-modal-title" style="margin-top:0; color:#1a4f8a; font-weight:700; margin-bottom:15px;"><?php esc_html_e( 'Add Family Member', 'cmi-partner-portal' ); ?></h4>
                    <form id="cmi-member-modal-form">
                        <input type="hidden" name="member_id" id="cmi-modal-member-id">
                        
                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Full Name', 'cmi-partner-portal' ); ?> *</label>
                            <input type="text" name="name" id="cmi-modal-name" required style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
                        </div>

                        <div style="margin-bottom:12px; display:flex; gap:10px;">
                            <div style="flex:1;">
                                <label style="display:block; font-weight:600; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Gender', 'cmi-partner-portal' ); ?> *</label>
                                <select name="gender" id="cmi-modal-gender" required style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
                                    <option value="Male"><?php esc_html_e( 'Male', 'cmi-partner-portal' ); ?></option>
                                    <option value="Female"><?php esc_html_e( 'Female', 'cmi-partner-portal' ); ?></option>
                                    <option value="Other"><?php esc_html_e( 'Other', 'cmi-partner-portal' ); ?></option>
                                </select>
                            </div>
                            <div style="flex:1;">
                                <label style="display:block; font-weight:600; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Relationship', 'cmi-partner-portal' ); ?> *</label>
                                <select name="relationship" id="cmi-modal-relationship" required style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
                                    <option value="Mother"><?php esc_html_e( 'Mother', 'cmi-partner-portal' ); ?></option>
                                    <option value="Father"><?php esc_html_e( 'Father', 'cmi-partner-portal' ); ?></option>
                                    <option value="Spouse"><?php esc_html_e( 'Spouse', 'cmi-partner-portal' ); ?></option>
                                    <option value="Child"><?php esc_html_e( 'Child', 'cmi-partner-portal' ); ?></option>
                                    <option value="Sibling"><?php esc_html_e( 'Sibling', 'cmi-partner-portal' ); ?></option>
                                    <option value="Other"><?php esc_html_e( 'Other', 'cmi-partner-portal' ); ?></option>
                                    <option value="Self" id="cmi-modal-rel-self-option" style="display:none;"><?php esc_html_e( 'Self', 'cmi-partner-portal' ); ?></option>
                                </select>
                            </div>
                        </div>

                        <div style="margin-bottom:12px; display:flex; gap:10px;">
                            <div style="flex:1;">
                                <label style="display:block; font-weight:600; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Date of Birth', 'cmi-partner-portal' ); ?> *</label>
                                <input type="date" name="dob" id="cmi-modal-dob" max="<?php echo current_time('Y-m-d'); ?>" required style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
                            </div>
                            <div style="flex:1;">
                                <label style="display:block; font-weight:600; font-size:13px; margin-bottom:4px;"><?php esc_html_e( 'Mobile Number', 'cmi-partner-portal' ); ?></label>
                                <input type="tel" name="mobile" id="cmi-modal-mobile" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
                            </div>
                        </div>

                        <div id="cmi-modal-error-msg" style="display:none; margin-bottom:12px; color:#dc2626; font-size:12px; font-weight:600;"></div>

                        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                            <button type="button" class="button cmi-modal-close-btn" style="background:#fff; border:1px solid #ddd;"><?php esc_html_e( 'Cancel', 'cmi-partner-portal' ); ?></button>
                            <button type="submit" class="button button-primary" id="cmi-modal-submit-btn" style="background:#1a4f8a; border:none; color:#fff; cursor:pointer;"><?php esc_html_e( 'Save Member', 'cmi-partner-portal' ); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Open Add modal
            $('.cmi-add-member-btn').on('click', function() {
                $('#cmi-member-modal-form')[0].reset();
                $('#cmi-modal-member-id').val('');
                $('#cmi-member-modal-title').text('<?php esc_html_e( "Add Family Member", "cmi-partner-portal" ); ?>');
                $('#cmi-modal-rel-self-option').hide();
                $('#cmi-modal-relationship').prop('disabled', false);
                $('#cmi-modal-error-msg').hide();
                $('#cmi-member-modal').css('display', 'flex');
            });

            // Open Edit modal
            $('.cmi-edit-member-trigger').on('click', function() {
                var row = $(this).closest('tr');
                var id = row.data('id');
                var name = row.data('name');
                var rel = row.data('relationship');
                var gender = row.data('gender');
                var dob = row.data('dob');
                var mobile = row.data('mobile');

                $('#cmi-modal-member-id').val(id);
                $('#cmi-modal-name').val(name);
                $('#cmi-modal-gender').val(gender);
                $('#cmi-modal-dob').val(dob);
                $('#cmi-modal-mobile').val(mobile);

                $('#cmi-modal-relationship').val(rel);
                if (rel === 'Self') {
                    $('#cmi-modal-rel-self-option').show();
                    $('#cmi-modal-relationship').val('Self').prop('disabled', true);
                } else {
                    $('#cmi-modal-rel-self-option').hide();
                    $('#cmi-modal-relationship').prop('disabled', false);
                }

                $('#cmi-member-modal-title').text('<?php esc_html_e( "Edit Member Details", "cmi-partner-portal" ); ?>');
                $('#cmi-modal-error-msg').hide();
                $('#cmi-member-modal').css('display', 'flex');
            });

            // Close Modal
            $('.cmi-modal-close-btn').on('click', function() {
                $('#cmi-member-modal').hide();
            });

            // Submit Add/Edit Form
            $('#cmi-member-modal-form').on('submit', function(e) {
                e.preventDefault();
                var btn = $('#cmi-modal-submit-btn');
                var errorMsg = $('#cmi-modal-error-msg');

                btn.prop('disabled', true).text('Saving...');
                errorMsg.hide();

                // Enable relationship select if disabled so it gets submitted
                $('#cmi-modal-relationship').prop('disabled', false);
                var formData = $(this).serialize();
                // Restore disabled state if id is self
                if ($('#cmi-modal-relationship').val() === 'Self') {
                    $('#cmi-modal-relationship').prop('disabled', true);
                }

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: formData + '&action=cmi_edit_family_member&nonce=<?php echo wp_create_nonce("cmi_pp_nonce"); ?>',
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            btn.prop('disabled', false).text('Save Member');
                            errorMsg.text(response.data.message || 'Action failed.').show();
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Save Member');
                        errorMsg.text('Connection error. Please try again.').show();
                    }
                });
            });

            // Delete family member
            $('.cmi-delete-member-trigger').on('click', function() {
                var btn = $(this);
                var row = btn.closest('tr');
                var id = row.data('id');
                var name = row.data('name');

                if (!confirm('Are you sure you want to delete family member: ' + name + '? This will not affect past orders but will remove them from your profile.')) {
                    return;
                }

                btn.prop('disabled', true).text('Deleting...');

                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: {
                        action: 'cmi_delete_family_member',
                        member_id: id,
                        nonce: '<?php echo wp_create_nonce("cmi_pp_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            btn.prop('disabled', false).text('Delete');
                            alert(response.data.message || 'Action failed.');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Delete');
                        alert('Connection error.');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler to add/edit family member.
     */
    public function ajax_edit_family_member() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-partner-portal' ) ] );
            wp_die();
        }

        $id           = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;
        $name         = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
        $gender       = isset( $_POST['gender'] ) ? sanitize_text_field( $_POST['gender'] ) : 'Male';
        $dob          = isset( $_POST['dob'] ) ? sanitize_text_field( $_POST['dob'] ) : '';
        $relationship = isset( $_POST['relationship'] ) ? sanitize_text_field( $_POST['relationship'] ) : '';
        $mobile       = isset( $_POST['mobile'] ) ? sanitize_text_field( $_POST['mobile'] ) : '';

        if ( empty( $name ) || empty( $dob ) || empty( $relationship ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Please fill in all required fields.', 'cmi-partner-portal' ) ] );
            wp_die();
        }

        if ( $id ) {
            // Edit existing member
            $member = CMI_HT_DB::get_member( $id );
            if ( ! $member || intval( $member->user_id ) !== intval( $user_id ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized member update.', 'cmi-partner-portal' ) ] );
                wp_die();
            }

            // If relation was 'Self', prevent changing it
            if ( 'Self' === $member->relationship && 'Self' !== $relationship ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Cannot modify the relationship of your primary profile.', 'cmi-partner-portal' ) ] );
                wp_die();
            }

            $update = CMI_HT_DB::update_member( $id, $user_id, $name, $gender, $dob, $relationship, $mobile );
            if ( $update !== false ) {
                wp_send_json_success( [ 'message' => esc_html__( 'Member updated successfully.', 'cmi-partner-portal' ) ] );
            } else {
                wp_send_json_error( [ 'message' => esc_html__( 'Failed to update member.', 'cmi-partner-portal' ) ] );
            }
        } else {
            // Add new member
            $new_id = CMI_HT_DB::add_member( $user_id, $name, $gender, $dob, $relationship, $mobile );
            if ( $new_id ) {
                wp_send_json_success( [ 'message' => esc_html__( 'Family member added successfully.', 'cmi-partner-portal' ) ] );
            } else {
                wp_send_json_error( [ 'message' => esc_html__( 'Failed to add family member.', 'cmi-partner-portal' ) ] );
            }
        }
        wp_die();
    }

    /**
     * AJAX handler to delete family member.
     */
    public function ajax_delete_family_member() {
        check_ajax_referer( 'cmi_pp_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-partner-portal' ) ] );
            wp_die();
        }

        $member_id = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;
        if ( ! $member_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid member ID.', 'cmi-partner-portal' ) ] );
            wp_die();
        }

        $delete = CMI_HT_DB::delete_member( $member_id, $user_id );
        if ( $delete ) {
            wp_send_json_success( [ 'message' => esc_html__( 'Family member removed successfully.', 'cmi-partner-portal' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Cannot remove member. Primary profiles cannot be deleted.', 'cmi-partner-portal' ) ] );
        }
        wp_die();
    }
}
