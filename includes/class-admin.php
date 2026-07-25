<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMI_Admin {

    public static function init() {
        add_action( 'admin_menu',                  [ __CLASS__, 'register_menus' ] );
        add_action( 'wp_ajax_cmi_approve_partner', [ __CLASS__, 'ajax_approve_partner' ] );
        add_action( 'wp_ajax_cmi_reject_partner',  [ __CLASS__, 'ajax_reject_partner' ] );
        add_action( 'wp_ajax_cmi_disable_partner', [ __CLASS__, 'ajax_disable_partner' ] );
        add_action( 'wp_ajax_cmi_ht_assign_partner', [ __CLASS__, 'ajax_assign_partner' ] );
        add_action( 'show_user_profile',           [ __CLASS__, 'user_profile_fields' ] );
        add_action( 'edit_user_profile',           [ __CLASS__, 'user_profile_fields' ] );
        add_action( 'personal_options_update',     [ __CLASS__, 'save_user_profile_fields' ] );
        add_action( 'edit_user_profile_update',    [ __CLASS__, 'save_user_profile_fields' ] );
    }

    public static function register_menus() {
        add_menu_page(
            'CMI Partner Portal',
            'CMI Portal',
            'manage_options',
            'cmi-portal',
            [ __CLASS__, 'page_dashboard' ],
            'dashicons-heart',
            30
        );
        add_submenu_page( 'cmi-portal', 'Partner Approvals', 'Partner Approvals', 'manage_options', 'cmi-partner-approvals', [ __CLASS__, 'page_approvals' ] );
        add_submenu_page( 'cmi-portal', 'All Reports',       'All Reports',       'manage_options', 'cmi-all-reports',       [ __CLASS__, 'page_all_reports' ] );
        add_submenu_page( 'cmi-portal', 'Home Collections',  'Home Collections',  'manage_options', 'cmi-home-testing-assignments', [ __CLASS__, 'page_assignments' ] );
        add_submenu_page( 'cmi-portal', 'Download Log',      'Download Log',      'manage_options', 'cmi-download-log',      [ __CLASS__, 'page_download_log' ] );
        add_submenu_page( 'cmi-portal', 'Collection Settings','Collection Settings','manage_options', 'cmi-home-testing-settings',    [ __CLASS__, 'page_assignments_settings' ] );
        add_submenu_page( 'cmi-portal', 'SMS Settings',       'SMS Settings',       'manage_options', 'cmi-sms-settings',      [ __CLASS__, 'page_sms_settings' ] );
        add_submenu_page( 'cmi-portal', 'Bulk SMS Broadcast', 'Bulk SMS',          'manage_options', 'cmi-bulk-sms',          [ __CLASS__, 'page_bulk_sms' ] );
        add_submenu_page( 'cmi-portal', 'Settings',           'Settings',          'manage_options', 'cmi-settings',          [ __CLASS__, 'page_settings' ] );
    }

    public static function page_dashboard() {
        global $wpdb;
        $counts_report = wp_count_posts('cmi_report');
        $counts_rx     = wp_count_posts('cmi_prescription');
        $total_reports  = isset( $counts_report->publish ) ? (int) $counts_report->publish : 0;
        $total_rx       = isset( $counts_rx->publish )     ? (int) $counts_rx->publish     : 0;
        $pending        = count( get_users([ 'role' => 'pending_partner' ]) );
        $partners       = count( get_users([ 'role__in' => ['medical_partner','cmi_doctor'] ]) );
        $downloads_today = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cmi_download_log WHERE DATE(downloaded_at) = CURDATE()" );
        ?>
        <div class="wrap">
            <h1>CMI Partner Portal</h1>
            <div class="cmi-admin-cards">
                <div class="cmi-card"><span class="cmi-num"><?php echo $total_reports; ?></span><span class="cmi-lbl">Reports</span></div>
                <div class="cmi-card"><span class="cmi-num"><?php echo $total_rx; ?></span><span class="cmi-lbl">Prescriptions</span></div>
                <div class="cmi-card cmi-warn"><span class="cmi-num"><?php echo $pending; ?></span><span class="cmi-lbl">Pending Partners</span></div>
                <div class="cmi-card"><span class="cmi-num"><?php echo $partners; ?></span><span class="cmi-lbl">Active Partners</span></div>
                <div class="cmi-card"><span class="cmi-num"><?php echo $downloads_today; ?></span><span class="cmi-lbl">Downloads Today</span></div>
            </div>
        </div>
        <?php
    }

    public static function page_approvals() {
        $pending = get_users([ 'role' => 'pending_partner', 'number' => 100 ]);
        $active  = get_users([ 'role__in' => [ 'medical_partner', 'cmi_doctor' ], 'number' => 100 ]);
        ?>
        <div class="wrap">
            <h1>Pending Partner Registrations</h1>
            <?php if ( empty( $pending ) ) : ?>
                <p>No pending partner registrations.</p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Name</th><th>Email</th><th>Type</th><th>Mobile</th><th>Organisation</th><th>License</th><th>Registered</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $pending as $u ) : ?>
                    <tr>
                        <td><?php echo esc_html( $u->display_name ); ?></td>
                        <td><?php echo esc_html( $u->user_email ); ?></td>
                        <td><?php echo esc_html( get_user_meta( $u->ID, '_cmi_partner_type', true ) ); ?></td>
                        <td><?php echo esc_html( get_user_meta( $u->ID, '_cmi_mobile', true ) ); ?></td>
                        <td><?php echo esc_html( get_user_meta( $u->ID, '_cmi_org', true ) ); ?></td>
                        <td><?php echo esc_html( get_user_meta( $u->ID, '_cmi_license', true ) ); ?></td>
                        <td><?php echo esc_html( $u->user_registered ); ?></td>
                        <td>
                            <button class="button button-primary cmi-approve-btn" data-id="<?php echo $u->ID; ?>">Approve</button>
                            <button class="button cmi-reject-btn" data-id="<?php echo $u->ID; ?>">Reject</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <h1 style="margin-top: 40px;">Active Partners</h1>
            <?php if ( empty( $active ) ) : ?>
                <p>No active partners.</p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Name</th><th>Email</th><th>Role</th><th>Mobile</th><th>Organisation</th><th>License</th><th>Approved On</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $active as $u ) :
                    $role = in_array( 'cmi_doctor', $u->roles ) ? 'Doctor' : 'Medical Partner';
                ?>
                    <tr>
                        <td><?php echo esc_html( $u->display_name ); ?></td>
                        <td><?php echo esc_html( $u->user_email ); ?></td>
                        <td><?php echo esc_html( $role ); ?></td>
                        <td><?php echo esc_html( get_user_meta( $u->ID, '_cmi_mobile', true ) ); ?></td>
                        <td><?php echo esc_html( get_user_meta( $u->ID, '_cmi_org', true ) ); ?></td>
                        <td><?php echo esc_html( get_user_meta( $u->ID, '_cmi_license', true ) ); ?></td>
                        <td><?php echo esc_html( get_user_meta( $u->ID, '_cmi_approved', true ) ); ?></td>
                        <td>
                            <button class="button button-link-delete cmi-disable-btn" data-id="<?php echo $u->ID; ?>">Disable</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php
    }

    public static function ajax_approve_partner() {
        check_ajax_referer( 'cmi_ht_admin_nonce', 'nonce' );
        if ( ! current_user_can('manage_options') ) wp_send_json_error(['message' => 'Access denied.']);

        $user_id = absint( $_POST['user_id'] ?? 0 );
        $user    = get_userdata( $user_id );
        if ( ! $user ) wp_send_json_error(['message' => 'User not found.']);

        $type = get_user_meta( $user_id, '_cmi_partner_type', true );
        $allowed_types = [ 'medical_partner', 'cmi_doctor' ];
        if ( ! in_array( $type, $allowed_types, true ) ) {
            $type = 'medical_partner';
        }
        $user->set_role( $type );
        update_user_meta( $user_id, '_cmi_approved', current_time('mysql') );

        // Defer email delivery via cron
        wp_schedule_single_event( time(), 'cmi_send_deferred_email_cron', [
            $user->user_email,
            'Your CMI Partner Account has been Approved',
            "Dear {$user->display_name},\n\nYour partner account on CMI Healthcare has been approved. You can now login and upload patient reports.\n\nLogin: " . home_url( '/partner-portal/' ),
            ''
        ] );

        wp_send_json_success(['message' => 'Partner approved and notified.']);
    }

    public static function ajax_reject_partner() {
        check_ajax_referer( 'cmi_ht_admin_nonce', 'nonce' );
        if ( ! current_user_can('manage_options') ) wp_send_json_error(['message' => 'Access denied.']);

        $user_id = absint( $_POST['user_id'] ?? 0 );
        $user    = get_userdata( $user_id );
        if ( ! $user ) wp_send_json_error(['message' => 'User not found.']);

        // Defer email delivery via cron
        wp_schedule_single_event( time(), 'cmi_send_deferred_email_cron', [
            $user->user_email,
            'CMI Partner Registration Update',
            "Dear {$user->display_name},\n\nUnfortunately your partner registration could not be approved at this time. Please contact us at info@cmitimes.in for more information.",
            ''
        ] );

        wp_delete_user( $user_id );
        wp_send_json_success(['message' => 'Partner rejected and removed.']);
    }

    public static function ajax_disable_partner() {
        check_ajax_referer( 'cmi_ht_admin_nonce', 'nonce' );
        if ( ! current_user_can('manage_options') ) wp_send_json_error(['message' => 'Access denied.']);

        $user_id = absint( $_POST['user_id'] ?? 0 );
        $user    = get_userdata( $user_id );
        if ( ! $user ) wp_send_json_error(['message' => 'User not found.']);

        // Demote back to pending partner and clear approval timestamp
        $user->set_role( 'pending_partner' );
        delete_user_meta( $user_id, '_cmi_approved' );

        // Defer email delivery via cron
        wp_schedule_single_event( time(), 'cmi_send_deferred_email_cron', [
            $user->user_email,
            'Your CMI Partner Account has been Disabled',
            "Dear {$user->display_name},\n\nYour partner account on CMI Healthcare has been disabled. Please contact the administrator for more information.",
            ''
        ] );

        wp_send_json_success(['message' => 'Partner account disabled and set to pending.']);
    }

    public static function page_all_reports() {
        $paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $search = sanitize_text_field( $_GET['s'] ?? '' );
        $type   = sanitize_text_field( $_GET['rtype'] ?? '' );

        $args = [
            'post_type'      => [ 'cmi_report', 'cmi_prescription' ],
            'post_status'    => 'publish',
            'posts_per_page' => 30,
            'paged'          => $paged,
        ];
        if ( $search ) {
            $args['meta_query'] = [
                'relation' => 'OR',
                [ 'key' => '_cmi_patient_mobile', 'value' => $search, 'compare' => 'LIKE' ],
                [ 'key' => '_cmi_patient_email',  'value' => $search, 'compare' => 'LIKE' ],
                [ 'key' => '_cmi_patient_name',   'value' => $search, 'compare' => 'LIKE' ],
                [ 'key' => '_cmi_patient_uid',    'value' => $search, 'compare' => 'LIKE' ],
            ];
        }
        $reports = get_posts( $args );
        ?>
        <div class="wrap">
            <h1>All Reports</h1>
            <form method="get">
                <input type="hidden" name="page" value="cmi-all-reports" />
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search by name, mobile, email, UID…" style="width:260px" />
                <input type="submit" class="button" value="Search" />
            </form>
            <table class="wp-list-table widefat fixed striped" style="margin-top:16px">
                <thead>
                    <tr><th>ID</th><th>Patient Name</th><th>Mobile / Email</th><th>UID</th><th>Type</th><th>Uploaded By</th><th>Date</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ( $reports as $r ) :
                    $uploader = get_userdata( get_post_meta($r->ID,'_cmi_uploaded_by',true) );
                    $terms    = wp_get_post_terms($r->ID,'cmi_report_type',['fields'=>'names']);
                ?>
                    <tr>
                        <td><?php echo $r->ID; ?></td>
                        <td><?php echo esc_html( get_post_meta($r->ID,'_cmi_patient_name',true) ); ?></td>
                        <td>
                            <?php
                            $pm = get_post_meta($r->ID,'_cmi_patient_mobile',true);
                            $pe = get_post_meta($r->ID,'_cmi_patient_email',true);
                            if ( $pm && $pe ) {
                                echo esc_html( $pm . ' / ' . $pe );
                            } elseif ( $pm ) {
                                echo esc_html( $pm );
                            } else {
                                echo esc_html( $pe ?: '—' );
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html( get_post_meta($r->ID,'_cmi_patient_uid',true) ); ?></td>
                        <td><?php echo esc_html( implode(', ', $terms) ); ?> <em style="color:#999">(<?php echo $r->post_type==='cmi_prescription'?'Rx':'Report'; ?>)</em></td>
                        <td><?php echo $uploader ? esc_html($uploader->display_name) : 'Unknown'; ?></td>
                        <td><?php echo date('d M Y', strtotime($r->post_date)); ?></td>
                        <td>
                            <a href="<?php echo CMI_Download::generate_link($r->ID,'admin'); ?>" class="button button-small">Download</a>
                            <a href="#" class="button button-small button-link-delete" onclick="return confirm('Delete this report?')" data-id="<?php echo $r->ID; ?>">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function page_download_log() {
        global $wpdb;
        $logs = $wpdb->get_results(
            "SELECT l.*, p.post_title, p.post_type FROM {$wpdb->prefix}cmi_download_log l
             LEFT JOIN {$wpdb->posts} p ON p.ID = l.report_id
             ORDER BY l.downloaded_at DESC LIMIT 100"
        );
        ?>
        <div class="wrap">
            <h1>Download Log (Last 100)</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Report</th><th>User</th><th>IP</th><th>Downloaded At</th></tr></thead>
                <tbody>
                <?php foreach ( $logs as $l ) :
                    $user = $l->user_id ? get_userdata($l->user_id) : null;
                ?>
                    <tr>
                        <td><?php echo esc_html($l->post_title); ?> <em>(<?php echo $l->report_id; ?>)</em></td>
                        <td><?php echo $user ? esc_html($user->display_name) : 'Guest / ' . esc_html($l->mobile); ?></td>
                        <td><?php echo esc_html($l->ip); ?></td>
                        <td><?php echo esc_html($l->downloaded_at); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function page_sms_settings() {
        $categories = class_exists('CMI_SMS_Manager') ? CMI_SMS_Manager::get_categorized_events() : [];

        if ( isset( $_POST['cmi_sms_settings_nonce'] ) && wp_verify_nonce( $_POST['cmi_sms_settings_nonce'], 'cmi_save_sms_settings' ) ) {
            // Save Gateway credentials
            update_option( 'cmi_sms_provider',        sanitize_text_field( $_POST['sms_provider'] ?? 'airtel' ) );
            update_option( 'cmi_airtel_endpoint',    sanitize_text_field( $_POST['airtel_endpoint'] ?? '' ) );
            update_option( 'cmi_airtel_username',    sanitize_text_field( $_POST['airtel_username'] ?? '' ) );
            update_option( 'cmi_airtel_password',    sanitize_text_field( $_POST['airtel_password'] ?? '' ) );
            update_option( 'cmi_airtel_customer_id', sanitize_text_field( $_POST['airtel_customer_id'] ?? '' ) );
            update_option( 'cmi_airtel_pe_id',        sanitize_text_field( $_POST['airtel_pe_id'] ?? '' ) );
            update_option( 'cmi_airtel_sender_id',   sanitize_text_field( $_POST['airtel_sender_id'] ?? '' ) );

            // Process dynamic custom SMS messages array
            if ( isset( $_POST['cmi_msg_title'] ) && is_array( $_POST['cmi_msg_title'] ) ) {
                $categories_built = [
                    'portal' => [
                        'title' => '1. CMI Healthcare Portal (Account & Security)',
                        'description' => 'Manage SMS templates for user account registration, package checkout welcome, and OTP verification.',
                        'events' => []
                    ],
                    'partner' => [
                        'title' => '2. Partner Portal (Home Collection & Diagnostic Labs)',
                        'description' => 'Manage SMS templates for home sample collection bookings, partner assignments, and test report delivery.',
                        'events' => []
                    ],
                    'doctor' => [
                        'title' => '3. Doctor Telemedicine Portal (Video Consultations)',
                        'description' => 'Manage SMS templates for telemedicine video appointments, digital prescriptions, and slot notifications.',
                        'events' => []
                    ]
                ];

                foreach ( $_POST['cmi_msg_title'] as $index => $raw_title ) {
                    $title      = sanitize_text_field( $raw_title );
                    if ( empty( $title ) ) continue;

                    $cat_key    = sanitize_text_field( $_POST['cmi_msg_cat'][$index] ?? 'portal' );
                    $raw_key    = sanitize_key( $_POST['cmi_msg_event_key'][$index] ?? '' );
                    $event_key  = ! empty( $raw_key ) ? $raw_key : ( 'msg_' . $index );

                    $enable_val = isset( $_POST['cmi_msg_enable'][$index] ) ? 'yes' : 'no';
                    $tmpl_val   = sanitize_text_field( $_POST['cmi_msg_tmpl'][$index] ?? '' );
                    $msg_val    = sanitize_textarea_field( $_POST['cmi_msg_text'][$index] ?? '' );
                    $type_val   = sanitize_text_field( $_POST['cmi_msg_type'][$index] ?? 'SERVICE_IMPLICIT' );
                    $desc_val   = sanitize_text_field( $_POST['cmi_msg_desc'][$index] ?? '' );

                    $enable_key = 'cmi_dlt_enable_' . $event_key;
                    $tmpl_key   = 'cmi_dlt_tmpl_' . $event_key;
                    $msg_key    = 'cmi_dlt_msg_' . $event_key;
                    $type_key   = 'cmi_dlt_type_' . $event_key;

                    update_option( $enable_key, $enable_val );
                    update_option( $tmpl_key, $tmpl_val );
                    update_option( $msg_key, $msg_val );
                    update_option( $type_key, $type_val );

                    if ( ! isset( $categories_built[$cat_key] ) ) {
                        $cat_key = 'portal';
                    }

                    $categories_built[$cat_key]['events'][$event_key] = [
                        'title' => $title,
                        'desc' => $desc_val,
                        'tmpl_id_key' => $tmpl_key,
                        'msg_key' => $msg_key,
                        'enable_key' => $enable_key,
                        'type_key' => $type_key,
                        'default_tmpl' => $tmpl_val,
                        'default_msg' => $msg_val,
                        'default_type' => $type_val,
                        'vars' => ['{name}', '{email}', '{mobile}', '{date}', '{slot}', '{order_id}', '{partner}', '{doctor}', '{otp}']
                    ];
                }

                update_option( 'cmi_custom_sms_templates', $categories_built );
            }

            echo '<div class="notice notice-success is-dismissible"><p><strong>SMS & DLT Template Settings saved successfully.</strong></p></div>';
            $categories = class_exists('CMI_SMS_Manager') ? CMI_SMS_Manager::get_categorized_events() : [];
        }

        $provider      = get_option( 'cmi_sms_provider', 'airtel' );
        $airtel_ep     = get_option( 'cmi_airtel_endpoint', 'https://iqsms.airtel.in/api/v1/send-prepaid-sms' );
        $airtel_user   = get_option( 'cmi_airtel_username', 'f631ac4e_54c5_4cb9_9595_276b7e59a113' );
        $airtel_pass   = get_option( 'cmi_airtel_password', 'ROMirHTArJ' );
        $airtel_cust   = get_option( 'cmi_airtel_customer_id', 'e4ad470d-f0f0-422f-bd47-9faec33e678a' );
        $airtel_pe     = get_option( 'cmi_airtel_pe_id', '1101476120000031130' );
        $airtel_sender = get_option( 'cmi_airtel_sender_id', 'CMIIMS' );
        ?>
        <div class="wrap">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <div>
                    <h1 style="margin-bottom: 5px;">CMI Healthcare SMS & DLT Template Settings</h1>
                    <p class="description" style="margin: 0;">Configure Airtel IQ Prepaid SMS Gateway credentials and add/customize SMS DLT templates.</p>
                </div>
                <button type="button" class="button button-primary button-large cmi-add-msg-btn" data-cat="portal" style="background: #00a99d; border-color: #00a99d;">
                    <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle; margin-right: 4px;"></span> Add New SMS Message
                </button>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field( 'cmi_save_sms_settings', 'cmi_sms_settings_nonce' ); ?>

                <!-- Gateway Configuration Card -->
                <div class="card" style="max-width: 1000px; padding: 20px; margin-top: 10px; border-left: 4px solid #1a4f8a;">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 15px;">
                        <h2 style="margin: 0;">Airtel IQ Gateway Credentials</h2>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span id="cmi-creds-status-msg" style="display: none; font-size: 13px; font-weight: bold;"></span>
                            <button type="button" class="button button-primary cmi-save-creds-btn" style="background: #1a4f8a; border-color: #1a4f8a; font-weight: 600;">
                                <span class="dashicons dashicons-saved" style="vertical-align: text-bottom; font-size: 15px; width: 15px; height: 15px; margin-right: 3px;"></span> Save Credentials
                            </button>
                        </div>
                    </div>
                    <table class="form-table" style="margin-top:0;">
                        <tr>
                            <th scope="row"><label>SMS Provider</label></th>
                            <td>
                                <select name="sms_provider" style="width:300px;">
                                    <option value="airtel"   <?php selected( $provider, 'airtel' ); ?>>Airtel IQ Prepaid DLT SMS (Production Active)</option>
                                    <option value="fast2sms" <?php selected( $provider, 'fast2sms' ); ?>>Fast2SMS (Legacy)</option>
                                    <option value="msg91"    <?php selected( $provider, 'msg91' ); ?>>MSG91 (Legacy)</option>
                                    <option value="none"     <?php selected( $provider, 'none' ); ?>>None (Testing - Log Only)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Airtel API Endpoint</label></th>
                            <td><input type="text" name="airtel_endpoint" value="<?php echo esc_attr( $airtel_ep ); ?>" class="large-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Basic Auth Username</label></th>
                            <td><input type="text" name="airtel_username" value="<?php echo esc_attr( $airtel_user ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Basic Auth Password</label></th>
                            <td><input type="password" name="airtel_password" value="<?php echo esc_attr( $airtel_pass ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Airtel Customer ID</label></th>
                            <td><input type="text" name="airtel_customer_id" value="<?php echo esc_attr( $airtel_cust ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label>DLT Entity ID (PE ID)</label></th>
                            <td><input type="text" name="airtel_pe_id" value="<?php echo esc_attr( $airtel_pe ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Header / Sender ID</label></th>
                            <td><input type="text" name="airtel_sender_id" value="<?php echo esc_attr( $airtel_sender ); ?>" class="regular-text" placeholder="CMIINF" /></td>
                        </tr>
                    </table>
                </div>

                <!-- Render 3 Category Sections -->
                <?php 
                $card_counter = 0;
                foreach ( $categories as $cat_key => $cat ) : 
                ?>
                    <div style="margin-top: 30px; max-width: 1000px;">
                        <div style="background: #1a4f8a; color: #fff; padding: 12px 20px; border-radius: 6px 6px 0 0; font-size: 16px; font-weight: 600; display: flex; justify-content: space-between; align-items: center;">
                            <span><?php echo esc_html( $cat['title'] ); ?></span>
                            <button type="button" class="button button-secondary button-small cmi-add-msg-btn" data-cat="<?php echo esc_attr($cat_key); ?>" style="background: #ffffff; color: #1a4f8a; font-weight: 600; border: none;">
                                + Add Message to this Category
                            </button>
                        </div>
                        <div id="cmi-cat-wrapper-<?php echo esc_attr($cat_key); ?>" style="background: #fff; border: 1px solid #c3c4c7; border-top: none; padding: 20px; border-radius: 0 0 6px 6px;">
                            <p class="description" style="margin-top:0; margin-bottom: 20px;"><?php echo esc_html( $cat['description'] ); ?></p>

                            <?php foreach ( $cat['events'] as $event_key => $event ) :
                                $card_counter++;
                                $is_enabled  = get_option( $event['enable_key'], 'yes' ) === 'yes';
                                $tmpl_val    = get_option( $event['tmpl_id_key'], $event['default_tmpl'] ?? '' );
                                $msg_val     = get_option( $event['msg_key'], $event['default_msg'] ?? '' );
                                $type_val    = get_option( $event['type_key'], $event['default_type'] ?? 'SERVICE_IMPLICIT' );
                                ?>
                                <div class="cmi-sms-tmpl-box" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 18px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 15px;">
                                        <div style="flex: 1; margin-right: 15px;">
                                            <input type="text" name="cmi_msg_title[]" value="<?php echo esc_attr( $event['title'] ); ?>" class="regular-text" style="font-weight: 700; font-size: 15px; width: 100%;" placeholder="SMS Message Title / Name" />
                                            <input type="hidden" name="cmi_msg_event_key[]" value="<?php echo esc_attr($event_key); ?>" />
                                            <input type="hidden" name="cmi_msg_cat[]" value="<?php echo esc_attr($cat_key); ?>" />
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <span class="cmi-card-status-msg" style="display: none; font-size: 13px; margin-right: 5px;"></span>
                                            <label style="font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px; font-size: 13px;">
                                                <input type="checkbox" name="cmi_msg_enable[<?php echo ($card_counter - 1); ?>]" value="yes" <?php checked( $is_enabled ); ?> />
                                                Enable
                                            </label>
                                            <button type="button" class="button button-primary cmi-save-single-card-btn" style="background: #1a4f8a; border-color: #1a4f8a; font-weight: 600;">
                                                <span class="dashicons dashicons-saved" style="vertical-align: text-bottom; font-size: 15px; width: 15px; height: 15px; margin-right: 3px;"></span> Save Message
                                            </button>
                                            <button type="button" class="button button-link-delete cmi-remove-msg-btn" style="color: #b91c1c; text-decoration: none; font-size: 13px;">
                                                <span class="dashicons dashicons-trash" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom;"></span> Remove
                                            </button>
                                        </div>
                                    </div>

                                    <table class="form-table" style="margin-top: 0;">
                                        <tr>
                                            <th scope="row" style="width: 180px;"><label>Description / Usage</label></th>
                                            <td>
                                                <input type="text" name="cmi_msg_desc[]" value="<?php echo esc_attr( $event['desc'] ?? '' ); ?>" class="large-text" placeholder="e.g. Sent when customer purchases a package" />
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label>DLT Template ID</label></th>
                                            <td>
                                                <input type="text" name="cmi_msg_tmpl[]" value="<?php echo esc_attr( $tmpl_val ); ?>" class="regular-text" placeholder="Enter Airtel DLT Template ID e.g. 1707170652590376026" />
                                                <span class="description" style="margin-left: 10px;">Approved Template ID from Airtel DLT Portal</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label>Message Type</label></th>
                                            <td>
                                                <select name="cmi_msg_type[]" style="width: 220px;">
                                                    <option value="SERVICE_IMPLICIT" <?php selected( $type_val, 'SERVICE_IMPLICIT' ); ?>>SERVICE_IMPLICIT (Default)</option>
                                                    <option value="SERVICE_EXPLICIT" <?php selected( $type_val, 'SERVICE_EXPLICIT' ); ?>>SERVICE_EXPLICIT</option>
                                                    <option value="TRANSACTIONAL"    <?php selected( $type_val, 'TRANSACTIONAL' ); ?>>TRANSACTIONAL</option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label>DLT Approved Text Payload</label></th>
                                            <td>
                                                <textarea id="msg_box_<?php echo $card_counter; ?>" name="cmi_msg_text[]" rows="3" class="large-text" placeholder="Enter exact approved DLT template wording..."><?php echo esc_textarea( $msg_val ); ?></textarea>
                                                <div style="margin-top: 6px;">
                                                    <strong style="font-size: 12px; color: #475569;">Click tag to insert dynamic variable:</strong><br>
                                                    <?php 
                                                    $vars = $event['vars'] ?? ['{name}', '{email}', '{mobile}', '{date}', '{slot}', '{order_id}', '{partner}', '{doctor}', '{otp}'];
                                                    foreach ( $vars as $var_tag ) : ?>
                                                        <button type="button" class="button button-small cmi-insert-var-btn" data-target="msg_box_<?php echo $card_counter; ?>" data-var="<?php echo esc_attr( $var_tag ); ?>" style="margin-right: 4px; margin-top: 4px; font-family: monospace;">
                                                            <?php echo esc_html( $var_tag ); ?>
                                                        </button>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <p class="submit" style="margin-top: 25px;">
                    <input type="submit" name="cmi_save_sms_settings_submit" class="button button-primary button-large" value="Save All Gateway Credentials & Settings" style="background: #1a4f8a; border-color: #1a4f8a;" />
                </p>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var cardCounter = <?php echo $card_counter; ?>;

            // Handle Save Gateway Credentials via AJAX
            $(document).on('click', '.cmi-save-creds-btn', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $status = $('#cmi-creds-status-msg');

                $btn.prop('disabled', true).text('Saving...');
                $status.hide();

                $.post(ajaxurl, {
                    action: 'cmi_admin_save_gateway_credentials',
                    nonce: $('input[name="cmi_sms_settings_nonce"]').val(),
                    sms_provider: $('select[name="sms_provider"]').val(),
                    airtel_endpoint: $('input[name="airtel_endpoint"]').val(),
                    airtel_username: $('input[name="airtel_username"]').val(),
                    airtel_password: $('input[name="airtel_password"]').val(),
                    airtel_customer_id: $('input[name="airtel_customer_id"]').val(),
                    airtel_pe_id: $('input[name="airtel_pe_id"]').val(),
                    airtel_sender_id: $('input[name="airtel_sender_id"]').val()
                }, function(r){
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved" style="vertical-align: text-bottom; font-size: 15px; width: 15px; height: 15px; margin-right: 3px;"></span> Save Credentials');
                    if (r.success) {
                        $status.css({color: '#15803d', display: 'inline-block'}).html('✅ Credentials Saved!').fadeIn();
                        setTimeout(function(){ $status.fadeOut(); }, 3500);
                    } else {
                        $status.css({color: '#b91c1c', display: 'inline-block'}).html('❌ Failed to save.').fadeIn();
                    }
                }).fail(function(){
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved" style="vertical-align: text-bottom; font-size: 15px; width: 15px; height: 15px; margin-right: 3px;"></span> Save Credentials');
                    $status.css({color: '#b91c1c', display: 'inline-block'}).html('❌ Server error.').fadeIn();
                });
            });

            // Handle Save Single Card via AJAX
            $(document).on('click', '.cmi-save-single-card-btn', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $card = $btn.closest('.cmi-sms-tmpl-box');
                var $status = $card.find('.cmi-card-status-msg');

                var eventKey = $card.find('input[name="cmi_msg_event_key[]"]').val();
                var catKey   = $card.find('input[name="cmi_msg_cat[]"]').val();
                var title    = $card.find('input[name="cmi_msg_title[]"]').val();
                var desc     = $card.find('input[name="cmi_msg_desc[]"]').val();
                var tmplId   = $card.find('input[name="cmi_msg_tmpl[]"]').val();
                var msgText  = $card.find('textarea[name="cmi_msg_text[]"]').val();
                var type     = $card.find('select[name="cmi_msg_type[]"]').val();
                var enable   = $card.find('input[type="checkbox"]').is(':checked') ? 'yes' : 'no';

                $btn.prop('disabled', true).text('Saving...');
                $status.hide();

                $.post(ajaxurl, {
                    action: 'cmi_admin_save_single_sms_card',
                    nonce: $('input[name="cmi_sms_settings_nonce"]').val(),
                    event_key: eventKey,
                    cat_key: catKey,
                    title: title,
                    desc: desc,
                    enable: enable,
                    tmpl_id: tmplId,
                    msg_text: msgText,
                    type: type
                }, function(r){
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved" style="vertical-align: text-bottom; font-size: 15px; width: 15px; height: 15px; margin-right: 3px;"></span> Save Message');
                    if (r.success) {
                        $status.css({color: '#15803d', display: 'inline-block', fontWeight: 'bold'}).html('✅ Card Saved!').fadeIn();
                        setTimeout(function(){ $status.fadeOut(); }, 3500);
                    } else {
                        $status.css({color: '#b91c1c', display: 'inline-block', fontWeight: 'bold'}).html('❌ Failed to save.').fadeIn();
                    }
                }).fail(function(){
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved" style="vertical-align: text-bottom; font-size: 15px; width: 15px; height: 15px; margin-right: 3px;"></span> Save Message');
                    $status.css({color: '#b91c1c', display: 'inline-block', fontWeight: 'bold'}).html('❌ Server error.').fadeIn();
                });
            });

            // Handle inserting dynamic variables into textareas
            $(document).on('click', '.cmi-insert-var-btn', function(e) {
                e.preventDefault();
                var targetId = $(this).data('target');
                var varTag   = $(this).data('var');
                var $txtarea = $('#' + targetId);

                if ($txtarea.length) {
                    var cursorPos = $txtarea[0].selectionStart;
                    var v = $txtarea.val();
                    var textBefore = v.substring(0, cursorPos);
                    var textAfter  = v.substring(cursorPos, v.length);
                    $txtarea.val(textBefore + varTag + textAfter);
                    $txtarea.focus();
                }
            });

            // Handle Removing Message Card
            $(document).on('click', '.cmi-remove-msg-btn', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $card = $btn.closest('.cmi-sms-tmpl-box');
                var eventKey = $card.find('input[name="cmi_msg_event_key[]"]').val();

                if (confirm('Are you sure you want to remove this SMS Message Template?')) {
                    if (eventKey) {
                        $.post(ajaxurl, {
                            action: 'cmi_admin_delete_single_sms_card',
                            nonce: $('input[name="cmi_sms_settings_nonce"]').val(),
                            event_key: eventKey
                        });
                    }
                    $card.slideUp(250, function() {
                        $(this).remove();
                    });
                }
            });

            // Handle Adding New SMS Message Card
            $('.cmi-add-msg-btn').on('click', function(e) {
                e.preventDefault();
                cardCounter++;
                var catKey = $(this).data('cat') || 'portal';
                var $container = $('#cmi-cat-wrapper-' + catKey);
                if (!$container.length) {
                    $container = $('#cmi-cat-wrapper-portal');
                }

                var newCardHtml = `
                    <div class="cmi-sms-tmpl-box" style="background: #ffffff; border: 2px solid #00a99d; border-radius: 8px; padding: 18px; margin-bottom: 20px; box-shadow: 0 4px 12px rgba(0,169,157,0.08);">
                        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 15px;">
                            <div style="flex: 1; margin-right: 15px;">
                                <input type="text" name="cmi_msg_title[]" value="" class="regular-text" style="font-weight: 700; font-size: 15px; width: 100%;" placeholder="Enter New SMS Message Title e.g. Lab Report Notification" />
                                <input type="hidden" name="cmi_msg_event_key[]" value="msg_custom_${cardCounter}" />
                                <input type="hidden" name="cmi_msg_cat[]" value="${catKey}" />
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span class="cmi-card-status-msg" style="display: none; font-size: 13px; margin-right: 5px;"></span>
                                <label style="font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px; font-size: 13px;">
                                    <input type="checkbox" name="cmi_msg_enable[${cardCounter - 1}]" value="yes" checked />
                                    Enable
                                </label>
                                <button type="button" class="button button-primary cmi-save-single-card-btn" style="background: #1a4f8a; border-color: #1a4f8a; font-weight: 600;">
                                    <span class="dashicons dashicons-saved" style="vertical-align: text-bottom; font-size: 15px; width: 15px; height: 15px; margin-right: 3px;"></span> Save Message
                                </button>
                                <button type="button" class="button button-link-delete cmi-remove-msg-btn" style="color: #b91c1c; text-decoration: none; font-size: 13px;">
                                    <span class="dashicons dashicons-trash" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom;"></span> Remove
                                </button>
                            </div>
                        </div>

                        <table class="form-table" style="margin-top: 0;">
                            <tr>
                                <th scope="row" style="width: 180px;"><label>Description / Usage</label></th>
                                <td>
                                    <input type="text" name="cmi_msg_desc[]" value="" class="large-text" placeholder="e.g. Sent when patient PDF report is uploaded" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>DLT Template ID</label></th>
                                <td>
                                    <input type="text" name="cmi_msg_tmpl[]" value="" class="regular-text" placeholder="Enter Airtel DLT Template ID e.g. 1707170652590376026" />
                                    <span class="description" style="margin-left: 10px;">Approved Template ID from Airtel DLT Portal</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>Message Type</label></th>
                                <td>
                                    <select name="cmi_msg_type[]" style="width: 220px;">
                                        <option value="SERVICE_IMPLICIT" selected>SERVICE_IMPLICIT (Default)</option>
                                        <option value="SERVICE_EXPLICIT">SERVICE_EXPLICIT</option>
                                        <option value="TRANSACTIONAL">TRANSACTIONAL</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>DLT Approved Text Payload</label></th>
                                <td>
                                    <textarea id="msg_box_${cardCounter}" name="cmi_msg_text[]" rows="3" class="large-text" placeholder="Type or paste exact approved Airtel DLT wording here..."></textarea>
                                    <div style="margin-top: 6px;">
                                        <strong style="font-size: 12px; color: #475569;">Click tag to insert dynamic variable:</strong><br>
                                        <button type="button" class="button button-small cmi-insert-var-btn" data-target="msg_box_${cardCounter}" data-var="{name}" style="margin-right: 4px; margin-top: 4px; font-family: monospace;">{name}</button>
                                        <button type="button" class="button button-small cmi-insert-var-btn" data-target="msg_box_${cardCounter}" data-var="{email}" style="margin-right: 4px; margin-top: 4px; font-family: monospace;">{email}</button>
                                        <button type="button" class="button button-small cmi-insert-var-btn" data-target="msg_box_${cardCounter}" data-var="{mobile}" style="margin-right: 4px; margin-top: 4px; font-family: monospace;">{mobile}</button>
                                        <button type="button" class="button button-small cmi-insert-var-btn" data-target="msg_box_${cardCounter}" data-var="{date}" style="margin-right: 4px; margin-top: 4px; font-family: monospace;">{date}</button>
                                        <button type="button" class="button button-small cmi-insert-var-btn" data-target="msg_box_${cardCounter}" data-var="{slot}" style="margin-right: 4px; margin-top: 4px; font-family: monospace;">{slot}</button>
                                        <button type="button" class="button button-small cmi-insert-var-btn" data-target="msg_box_${cardCounter}" data-var="{order_id}" style="margin-right: 4px; margin-top: 4px; font-family: monospace;">{order_id}</button>
                                        <button type="button" class="button button-small cmi-insert-var-btn" data-target="msg_box_${cardCounter}" data-var="{partner}" style="margin-right: 4px; margin-top: 4px; font-family: monospace;">{partner}</button>
                                        <button type="button" class="button button-small cmi-insert-var-btn" data-target="msg_box_${cardCounter}" data-var="{doctor}" style="margin-right: 4px; margin-top: 4px; font-family: monospace;">{doctor}</button>
                                        <button type="button" class="button button-small cmi-insert-var-btn" data-target="msg_box_${cardCounter}" data-var="{otp}" style="margin-right: 4px; margin-top: 4px; font-family: monospace;">{otp}</button>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                `;

                $container.append(newCardHtml);
                $('html, body').animate({
                    scrollTop: $('#msg_box_' + cardCounter).offset().top - 200
                }, 400);
            });
        });
        </script>
        <?php
    }

    public static function page_settings() {
        self::page_sms_settings();
    }

    public static function page_bulk_sms() {
        $welcome_tmpl = get_option( 'cmi_dlt_welcome_template_id', '1707170652590376026' );
        $welcome_msg  = get_option( 'cmi_dlt_welcome_message', 'Welcome to CMI Healthcare! Your account has been successfully registered. Thank you for choosing us.' );
        ?>
        <div class="wrap">
            <h1>CMI Bulk SMS Broadcast</h1>
            <p>Send DLT-compliant transactional or broadcast SMS to patients, partners, doctors, or custom contact lists via Airtel IQ DLT Gateway.</p>

            <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
                <form id="cmi-bulk-sms-form">
                    <?php wp_nonce_field( 'cmi_bulk_sms_nonce', 'cmi_bulk_sms_nonce' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="cmi-bulk-target">Target Recipients</label></th>
                            <td>
                                <select id="cmi-bulk-target" name="target" style="width: 300px;">
                                    <option value="patients">All Registered Patients / Subscribers</option>
                                    <option value="partners">Medical Partners / Labs</option>
                                    <option value="doctors">Doctors</option>
                                    <option value="custom">Custom Phone Numbers List</option>
                                </select>
                            </td>
                        </tr>
                        <tr id="cmi-custom-numbers-row" style="display: none;">
                            <th><label for="cmi-bulk-custom-numbers">Custom Mobile Numbers</label></th>
                            <td>
                                <textarea id="cmi-bulk-custom-numbers" name="custom_numbers" rows="5" class="large-text" placeholder="Enter one 10-digit mobile number per line e.g.&#10;9876543210&#10;9123456789"></textarea>
                                <p class="description">One 10-digit mobile number per line.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cmi-bulk-tmpl-id">DLT Template ID</label></th>
                            <td>
                                <input type="text" id="cmi-bulk-tmpl-id" name="template_id" value="<?php echo esc_attr($welcome_tmpl); ?>" class="regular-text" required />
                                <p class="description">Approved Airtel DLT Template ID (Default: Welcome Message Template ID).</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cmi-bulk-message">Message Payload</label></th>
                            <td>
                                <textarea id="cmi-bulk-message" name="message" rows="4" class="large-text" required><?php echo esc_textarea($welcome_msg); ?></textarea>
                                <p class="description">Must match your approved Airtel DLT template wording exactly.</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" id="cmi-send-bulk-btn" class="button button-primary button-large">Send Bulk SMS Broadcast</button>
                    </p>
                </form>

                <div id="cmi-bulk-result" style="margin-top: 20px; display: none;"></div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#cmi-bulk-target').on('change', function() {
                if ($(this).val() === 'custom') {
                    $('#cmi-custom-numbers-row').show();
                } else {
                    $('#cmi-custom-numbers-row').hide();
                }
            });

            $('#cmi-bulk-sms-form').on('submit', function(e) {
                e.preventDefault();
                var $btn = $('#cmi-send-bulk-btn');
                var $res = $('#cmi-bulk-result');

                $btn.prop('disabled', true).text('Dispatching SMS Batches...');
                $res.show().html('<div class="notice notice-info"><p>Sending SMS via Airtel IQ Gateway... Please wait.</p></div>');

                var data = $(this).serialize() + '&action=cmi_admin_send_bulk_sms';

                $.post(ajaxurl, data, function(response) {
                    $btn.prop('disabled', false).text('Send Bulk SMS Broadcast');
                    if (response.success) {
                        $res.html('<div class="notice notice-success"><p><strong>Success!</strong> ' + response.data.message + '</p></div>');
                    } else {
                        var err = response.data && response.data.message ? response.data.message : 'Execution failed.';
                        $res.html('<div class="notice notice-error"><p><strong>Error:</strong> ' + err + '</p></div>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Send Bulk SMS Broadcast');
                    $res.html('<div class="notice notice-error"><p>Server communication error.</p></div>');
                });
            });
        });
        </script>
        <?php
    }

    public static function user_profile_fields( $user ) {
        if ( ! current_user_can('edit_user', $user->ID) ) return;
        ?>
        <h2>CMI Healthcare – Patient / Partner Info</h2>
        <table class="form-table">
            <tr><th>CMI Unique ID</th><td><input type="text" name="_cmi_uid" value="<?php echo esc_attr(get_user_meta($user->ID,'_cmi_uid',true)); ?>" class="regular-text" /></td></tr>
            <tr><th>Mobile Number</th><td><input type="text" name="_cmi_mobile" value="<?php echo esc_attr(get_user_meta($user->ID,'_cmi_mobile',true)); ?>" class="regular-text" /></td></tr>
            <tr><th>Organisation</th><td><input type="text" name="_cmi_org" value="<?php echo esc_attr(get_user_meta($user->ID,'_cmi_org',true)); ?>" class="regular-text" /></td></tr>
            <tr><th>License No.</th><td><input type="text" name="_cmi_license" value="<?php echo esc_attr(get_user_meta($user->ID,'_cmi_license',true)); ?>" class="regular-text" /></td></tr>
            <tr><th>Specialty (Doctors Only)</th><td><input type="text" name="_cmi_specialty" value="<?php echo esc_attr(get_user_meta($user->ID,'_cmi_specialty',true)); ?>" class="regular-text" placeholder="e.g. General Physician" /></td></tr>
            <tr><th>Consultation Fee (INR)</th><td><input type="number" name="_cmi_consultation_fee" value="<?php echo esc_attr(get_user_meta($user->ID,'_cmi_consultation_fee',true)); ?>" class="regular-text" placeholder="500" /></td></tr>
        </table>
        <?php
    }

    public static function save_user_profile_fields( $user_id ) {
        if ( ! current_user_can('edit_user', $user_id) ) return;
        update_user_meta( $user_id, '_cmi_uid',     sanitize_text_field( $_POST['_cmi_uid'] ?? '' ) );
        update_user_meta( $user_id, '_cmi_mobile',  preg_replace('/[^0-9+]/','',$_POST['_cmi_mobile'] ?? '') );
        update_user_meta( $user_id, '_cmi_org',     sanitize_text_field( $_POST['_cmi_org'] ?? '' ) );
        update_user_meta( $user_id, '_cmi_license', sanitize_text_field( $_POST['_cmi_license'] ?? '' ) );
        update_user_meta( $user_id, '_cmi_specialty',        sanitize_text_field( $_POST['_cmi_specialty'] ?? '' ) );
        update_user_meta( $user_id, '_cmi_consultation_fee', sanitize_text_field( $_POST['_cmi_consultation_fee'] ?? '' ) );
    }

    public static function page_assignments() {
        global $wpdb;

        // Fetch all assignments
        $table = $wpdb->prefix . 'cmi_home_testing';
        $results = $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC" );

        // Fetch partners
        $partners = get_users( [
            'role__in' => [ 'medical_partner', 'cmi_doctor' ]
        ] );

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Home Testing Assignments', 'cmi-partner-portal' ); ?></h1>
            <hr class="wp-header-end">

            <table class="wp-list-table widefat fixed striped table-view-list entries">
                <thead>
                    <tr>
                        <th style="width: 80px;"><?php esc_html_e( 'Order ID', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Booked By (Account Holder)', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Patient (Member) Details', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Package Details', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Scheduled Date & Slot', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Assigned Partner', 'cmi-partner-portal' ); ?></th>
                        <th style="width: 90px;"><?php esc_html_e( 'Report File', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Reschedule Status', 'cmi-partner-portal' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $results ) ) : ?>
                        <tr>
                            <td colspan="9"><?php esc_html_e( 'No testing requests found.', 'cmi-partner-portal' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $results as $row ) :
                            $order = wc_get_order( $row->order_id );
                            if ( ! $order ) continue;
                            
                            // Booked By (Account Holder) details
                            $booked_by_name  = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                            $booked_by_email = $order->get_billing_email();
                            $booked_by_phone = $order->get_billing_phone();

                            // Patient Snapshot details from Order Metadata
                            $patient_name    = $order->get_meta( '_cmi_patient_name' ) ?: $booked_by_name;
                            $patient_gender  = $order->get_meta( '_cmi_patient_gender' ) ?: 'Unspecified';
                            $patient_dob     = $order->get_meta( '_cmi_patient_dob' ) ?: '—';
                            $patient_relation= $order->get_meta( '_cmi_patient_relationship' ) ?: 'Self';

                            // Package details (ordered products)
                            $order_items = $order->get_items();
                            $packages = [];
                            foreach ( $order_items as $item ) {
                                $packages[] = $item->get_name();
                            }
                            $packages_list = implode( ', ', $packages );
                            ?>
                            <tr data-id="<?php echo esc_attr( $row->id ); ?>">
                                <td><strong><a href="<?php echo esc_url( get_edit_post_link( $row->order_id ) ); ?>">#<?php echo esc_html( $row->order_id ); ?></a></strong></td>
                                <td>
                                    <strong><?php echo esc_html( $booked_by_name ); ?></strong><br>
                                    <span class="description"><?php echo esc_html( $booked_by_email ); ?></span><br>
                                    <span class="description"><?php echo esc_html( $booked_by_phone ); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html( $patient_name ); ?></strong><br>
                                    <span class="description"><?php printf( __( 'Relation: %s', 'cmi-partner-portal' ), esc_html( $patient_relation ) ); ?></span><br>
                                    <span class="description"><?php printf( __( 'Gender: %s | DOB: %s', 'cmi-partner-portal' ), esc_html( $patient_gender ), esc_html( $patient_dob ) ); ?></span>
                                </td>
                                <td><?php echo esc_html( $packages_list ); ?></td>
                                <td>
                                    <strong><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->collection_date ) ) ); ?></strong><br>
                                    <span class="description"><?php echo esc_html( $row->collection_time_slot ); ?></span>
                                </td>
                                <td>
                                    <span class="cmi-status-badge cmi-status-<?php echo esc_attr( $row->status ); ?>">
                                        <?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ( $row->status === 'completed' ) : ?>
                                        <?php 
                                        $p_user = get_userdata( $row->partner_id );
                                        echo esc_html( $p_user ? $p_user->display_name : 'Deleted Partner' );
                                        ?>
                                    <?php else : ?>
                                        <select class="cmi-partner-assign-select" data-id="<?php echo esc_attr( $row->id ); ?>">
                                            <option value=""><?php esc_html_e( 'Select Partner', 'cmi-partner-portal' ); ?></option>
                                            <?php foreach ( $partners as $partner ) : ?>
                                                <option value="<?php echo esc_attr( $partner->ID ); ?>" <?php selected( $row->partner_id, $partner->ID ); ?>>
                                                    <?php echo esc_html( $partner->display_name ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( ! empty( $row->report_pdf ) ) : 
                                        $download_url = '';
                                        if ( class_exists( 'CMI_Download' ) ) {
                                            $report_post_id = $wpdb->get_var( $wpdb->prepare(
                                                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cmi_file_name' AND meta_value = %s LIMIT 1",
                                                $row->report_pdf
                                            ) );
                                            if ( $report_post_id ) {
                                                $download_url = CMI_Download::generate_link( $report_post_id, 'admin' );
                                            } else {
                                                $download_url = CMI_Download::generate_link( $row->report_pdf, 'admin' );
                                            }
                                        }
                                        if ( empty( $download_url ) ) {
                                            $download_url = content_url( 'cmi-secure-reports/' . $row->report_pdf );
                                        }
                                        ?>
                                        <a href="<?php echo esc_url( $download_url ); ?>" target="_blank">
                                            <?php esc_html_e( 'View PDF', 'cmi-partner-portal' ); ?>
                                        </a>
                                    <?php else : ?>
                                        <span class="description"><?php esc_html_e( 'Pending upload', 'cmi-partner-portal' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( $row->reschedule_status === 'pending' ) : ?>
                                        <div class="cmi-reschedule-actions">
                                            <span class="dashicons dashicons-warning" title="<?php echo esc_attr( sprintf( __( 'Requested Date: %s (%s)', 'cmi-partner-portal' ), $row->reschedule_date, $row->reschedule_time_slot ) ); ?>"></span>
                                            <button class="button button-small button-primary cmi-approve-reschedule" data-id="<?php echo esc_attr( $row->id ); ?>"><?php esc_html_e( 'Approve', 'cmi-partner-portal' ); ?></button>
                                            <button class="button button-small cmi-deny-reschedule" data-id="<?php echo esc_attr( $row->id ); ?>"><?php esc_html_e( 'Deny', 'cmi-partner-portal' ); ?></button>
                                        </div>
                                    <?php else : ?>
                                        <span class="description"><?php echo esc_html( ucfirst( $row->reschedule_status ) ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function page_assignments_settings() {
        if ( isset( $_POST['cmi_ht_save_settings'] ) ) {
            check_admin_referer( 'cmi_ht_settings_nonce' );

            if ( isset( $_POST['cmi_ht_allowed_pincodes'] ) ) {
                update_option( 'cmi_ht_allowed_pincodes', sanitize_textarea_field( $_POST['cmi_ht_allowed_pincodes'] ) );
            }

            if ( isset( $_POST['cmi_ht_time_slots'] ) ) {
                $slots = array_filter( array_map( 'sanitize_text_field', explode( "\n", $_POST['cmi_ht_time_slots'] ) ) );
                update_option( 'cmi_ht_time_slots', $slots );
            }

            $enable_pincode_val = isset( $_POST['cmi_ht_enable_pincode_validation'] ) ? 'yes' : 'no';
            update_option( 'cmi_ht_enable_pincode_validation', $enable_pincode_val );

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully.', 'cmi-partner-portal' ) . '</p></div>';
        }

        $pincodes = get_option( 'cmi_ht_allowed_pincodes', '' );
        $pincode_validation = get_option( 'cmi_ht_enable_pincode_validation', 'no' );
        $slots = implode( "\n", get_option( 'cmi_ht_time_slots', [
            '08:00 AM - 10:00 AM',
            '10:00 AM - 12:00 PM',
            '12:00 PM - 02:00 PM',
            '02:00 PM - 04:00 PM'
        ] ) );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Home Testing Settings', 'cmi-partner-portal' ); ?></h1>
            <form method="post" action="">
                <?php wp_nonce_field( 'cmi_ht_settings_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="cmi_ht_enable_pincode_validation"><?php esc_html_e( 'Enable Pincode Validation', 'cmi-partner-portal' ); ?></label></th>
                        <td>
                            <input type="checkbox" id="cmi_ht_enable_pincode_validation" name="cmi_ht_enable_pincode_validation" value="yes" <?php checked( $pincode_validation, 'yes' ); ?>>
                            <p class="description"><?php esc_html_e( 'Restrict scheduled collections to serviceable pincodes or Delhi list.', 'cmi-partner-portal' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cmi_ht_allowed_pincodes"><?php esc_html_e( 'Serviceable Pincodes', 'cmi-partner-portal' ); ?></label></th>
                        <td>
                            <textarea id="cmi_ht_allowed_pincodes" name="cmi_ht_allowed_pincodes" rows="4" class="large-text"><?php echo esc_textarea( $pincodes ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Enter serviceable pincodes separated by commas. Leave blank to allow any standard 6-digit Delhi pincodes (starting with 11).', 'cmi-partner-portal' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cmi_ht_time_slots"><?php esc_html_e( 'Testing Time Slots', 'cmi-partner-portal' ); ?></label></th>
                        <td>
                            <textarea id="cmi_ht_time_slots" name="cmi_ht_time_slots" rows="6" class="large-text"><?php echo esc_textarea( $slots ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Enter one time slot option per line.', 'cmi-partner-portal' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="cmi_ht_save_settings" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Changes', 'cmi-partner-portal' ); ?>">
                </p>
            </form>
        </div>
        <?php
    }

    public static function ajax_assign_partner() {
        check_ajax_referer( 'cmi_ht_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'cmi_manage_assignments' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized access.', 'cmi-partner-portal' ) ] );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $partner_id = isset( $_POST['partner_id'] ) ? intval( $_POST['partner_id'] ) : 0;

        if ( ! $id || ! $partner_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid parameter inputs.', 'cmi-partner-portal' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmi_home_testing';

        // Check if row exists
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Record not found.', 'cmi-partner-portal' ) ] );
        }

        // Check if this was a reschedule approval assignment
        $is_rescheduled = ( $row->reschedule_status === 'approved' );

        $update = $wpdb->update(
            $table,
            [
                'partner_id'        => $partner_id,
                'status'            => 'assigned',
                'reschedule_status' => 'none', // Reset so it doesn't trigger again on subsequent assignments
                'updated_at'        => current_time( 'mysql' )
            ],
            [ 'id' => $id ],
            [ '%d', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $update !== false ) {
            // Note: Order note and notification email are now handled in the deferred background cron!
            do_action( 'cmi_testing_partner_assigned', $id, $partner_id, $is_rescheduled );
            wp_send_json_success( [ 'message' => esc_html__( 'Partner successfully assigned.', 'cmi-partner-portal' ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Database write failed.', 'cmi-partner-portal' ) ] );
        }
    }
}

CMI_Admin::init();
