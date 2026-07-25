<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WooCommerce' ) ) {
    echo '<p>' . esc_html__( 'WooCommerce is not active.', 'cmi-partner-portal' ) . '</p>';
    return;
}

$user_id = get_current_user_id();
if ( ! $user_id ) {
    return;
}

global $wpdb;
$table = $wpdb->prefix . 'cmi_home_testing';

// Check if the home testing database table exists first
if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
    echo '<p>' . esc_html__( 'Home testing database table not found.', 'cmi-partner-portal' ) . '</p>';
    return;
}

// Fetch jobs assigned to this partner
$results = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM $table WHERE partner_id = %d ORDER BY id DESC",
    $user_id
) );

// Fetch custom report types from taxonomy
$report_types = [];
if ( taxonomy_exists( 'cmi_report_type' ) ) {
    $report_types = get_terms([ 'taxonomy' => 'cmi_report_type', 'hide_empty' => false ]);
}
?>

<div class="cmi-partner-assignments-wrapper">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
        <h2 style="margin:0; font-size:20px; font-weight:700; color:var(--cmi-primary);"><?php esc_html_e( 'My Collection Assignments', 'cmi-partner-portal' ); ?></h2>
    </div>

    <?php if ( empty( $results ) ) : ?>
        <p class="cmi-empty"><?php esc_html_e( 'No assignments found.', 'cmi-partner-portal' ); ?></p>
    <?php else : ?>
        <div class="cmi-table-responsive">
            <table class="cmi-reports-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Order ID', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Patient Details', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Collection Address', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Schedule Date', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Time Slot', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'cmi-partner-portal' ); ?></th>
                        <th><?php esc_html_e( 'Workflow Action', 'cmi-partner-portal' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $results as $row ) :
                        $order = wc_get_order( $row->order_id );
                        if ( ! $order ) continue;

                        // Fetch patient snapshot details if they exist, fallback to billing details
                        $patient_name   = $order->get_meta( '_cmi_patient_name' ) ?: ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
                        $patient_phone  = $order->get_meta( '_cmi_patient_mobile' ) ?: $order->get_billing_phone();
                        $patient_email  = $order->get_billing_email();
                        $patient_gender = $order->get_meta( '_cmi_patient_gender' ) ?: 'Unspecified';
                        $patient_dob    = $order->get_meta( '_cmi_patient_dob' ) ?: '—';
                        $patient_rel    = $order->get_meta( '_cmi_patient_relationship' ) ?: 'Self';

                        // Use formatted shipping address first, fallback to billing
                        $address = $order->get_formatted_shipping_address();
                        if ( empty( $address ) ) {
                            $address = $order->get_formatted_billing_address();
                        }
                        ?>
                        <tr data-id="<?php echo esc_attr( $row->id ); ?>">
                            <td style="font-weight:600;">#<?php echo esc_html( $row->order_id ); ?></td>
                            <td>
                                <strong><?php echo esc_html( $patient_name ); ?></strong><br>
                                <span style="font-size:12px; color:var(--cmi-text-muted);"><?php echo esc_html( $patient_phone ); ?></span><br>
                                <span style="font-size:12px; color:var(--cmi-text-muted);"><?php echo esc_html( $patient_email ); ?></span>
                                <?php if ( 'Self' !== $patient_rel && ! empty( $patient_rel ) ) : ?>
                                    <div style="margin-top: 6px;">
                                        <span class="cmi-badge" style="background:#fffaf0; border:1px solid #feebc8; color:#c05621; font-size:11px; padding:2px 6px; border-radius:4px; font-weight:600; display:inline-block;">
                                            <?php echo esc_html( $patient_rel ); ?> (<?php echo esc_html( $patient_gender ); ?>, DOB: <?php echo esc_html( $patient_dob ); ?>)
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="max-width:250px; font-size:13px; line-height:1.4;">
                                <?php echo wp_kses_post( $address ); ?>
                            </td>
                            <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->collection_date ) ) ); ?></td>
                            <td style="font-size:13px;"><?php echo esc_html( $row->collection_time_slot ); ?></td>
                            <td>
                                <?php if ( $row->reschedule_status === 'pending' ) : ?>
                                    <span class="cmi-badge cmi-status-reschedule-requested" style="background:#fffaf0; border:1px solid #feebc8; color:#c05621;">
                                        <?php esc_html_e( 'Reschedule Requested', 'cmi-partner-portal' ); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="cmi-badge cmi-status-<?php echo esc_attr( $row->status ); ?>">
                                        <?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $row->status === 'assigned' ) : ?>
                                    <div style="display:flex; gap:6px;">
                                        <button class="button button-secondary cmi-partner-accept-btn" data-id="<?php echo esc_attr( $row->id ); ?>"><?php esc_html_e( 'Accept', 'cmi-partner-portal' ); ?></button>
                                        <button class="button cmi-partner-reject-btn" data-id="<?php echo esc_attr( $row->id ); ?>" style="border-color:var(--cmi-error) !important; color:var(--cmi-error) !important;"><?php esc_html_e( 'Reject', 'cmi-partner-portal' ); ?></button>
                                    </div>
                                <?php elseif ( $row->status === 'accepted' || $row->status === 'rescheduled' ) : ?>
                                    <?php if ( $row->reschedule_status === 'pending' ) : ?>
                                        <span class="description" style="color: #718096;"><?php esc_html_e( 'Reschedule Pending Approval', 'cmi-partner-portal' ); ?></span>
                                    <?php else : ?>
                                        <div style="display:flex; flex-direction:column; gap:6px;">
                                            <button class="button button-primary cmi-trigger-upload-report" 
                                                    data-id="<?php echo esc_attr( $row->id ); ?>" 
                                                    data-order-id="<?php echo esc_attr( $row->order_id ); ?>" 
                                                    data-patient-name="<?php echo esc_attr( $patient_name ); ?>"
                                                    data-detected-type="<?php echo esc_attr( CMI_HT_Partner_Workflow::detect_report_type_by_order_items( $row->order_id ) ); ?>">
                                                <?php esc_html_e( 'Upload Report', 'cmi-partner-portal' ); ?>
                                            </button>
                                            <button class="button cmi-partner-revoke-btn" data-id="<?php echo esc_attr( $row->id ); ?>" style="border-color:var(--cmi-error) !important; color:var(--cmi-error) !important; font-size:11px !important; padding:4px 10px !important; line-height:1.2; background:none;"><?php esc_html_e( 'Revoke Acceptance', 'cmi-partner-portal' ); ?></button>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif ( $row->status === 'completed' ) : ?>
                                    <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-start;">
                                        <span class="cmi-success-text" style="color:var(--cmi-success); font-weight:600;"><?php esc_html_e( 'Report Uploaded', 'cmi-partner-portal' ); ?></span>
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
                                                 } else {
                                                     $download_url = CMI_Download::generate_link( $row->report_pdf );
                                                 }
                                             }
                                             if ( empty( $download_url ) && ! empty( $row->report_pdf ) ) {
                                                 $download_url = content_url( 'cmi-secure-reports/' . $row->report_pdf );
                                             }
                                             if ( $download_url ) : ?>
                                                <a class="button cmi-download-btn" href="<?php echo esc_url( $download_url ); ?>" target="_blank" style="font-size:11px !important; padding:4px 10px !important; line-height:1.2; text-decoration:none;"><?php esc_html_e( 'Download', 'cmi-partner-portal' ); ?></a>
                                            <?php endif; ?>
                                            <button class="button button-secondary cmi-trigger-upload-report" 
                                                    data-id="<?php echo esc_attr( $row->id ); ?>" 
                                                    data-order-id="<?php echo esc_attr( $row->order_id ); ?>" 
                                                    data-patient-name="<?php echo esc_attr( $patient_name ); ?>"
                                                    data-detected-type="<?php echo esc_attr( CMI_HT_Partner_Workflow::detect_report_type_by_order_items( $row->order_id ) ); ?>"
                                                    style="font-size:11px !important; padding:4px 10px !important; line-height:1.2; border-color:var(--cmi-primary) !important; color:var(--cmi-primary) !important;">
                                                <?php esc_html_e( 'Re-upload', 'cmi-partner-portal' ); ?>
                                            </button>
                                        </div>
                                    </div>
                                <?php else : ?>
                                    <span class="description">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Reject Reason Modal -->
<div id="cmi-reject-modal" style="display:none;">
    <div class="cmi-modal-content">
        <h3><?php esc_html_e( 'Reject Assignment', 'cmi-partner-portal' ); ?></h3>
        <form id="cmi-reject-form">
            <input type="hidden" id="cmi-reject-id" name="id" value="">
            <div class="cmi-form-row">
                <label for="cmi-reject-reason"><?php esc_html_e( 'Reason for Rejection', 'cmi-partner-portal' ); ?></label>
                <textarea id="cmi-reject-reason" name="reason" required class="large-text"></textarea>
            </div>
            <div class="cmi-form-actions" style="margin-top:20px; display:flex; gap:10px;">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Submit Rejection', 'cmi-partner-portal' ); ?></button>
                <button type="button" class="button cmi-close-modal"><?php esc_html_e( 'Cancel', 'cmi-partner-portal' ); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Upload Report Modal -->
<div id="cmi-upload-report-modal" style="display:none;">
    <div class="cmi-modal-content">
        <h3><?php esc_html_e( 'Upload Patient Report', 'cmi-partner-portal' ); ?></h3>
        <form id="cmi-upload-report-form" enctype="multipart/form-data">
            <input type="hidden" id="cmi-upload-id" name="id" value="">
            
            <div class="cmi-form-row">
                <label><?php esc_html_e( 'Order ID', 'cmi-partner-portal' ); ?></label>
                <input type="text" id="cmi-display-order-id" readonly disabled>
            </div>

            <div class="cmi-form-row">
                <label><?php esc_html_e( 'Patient Name', 'cmi-partner-portal' ); ?></label>
                <input type="text" id="cmi-display-patient-name" readonly disabled>
            </div>

            <div class="cmi-form-row">
                <label for="cmi-modal-report-type"><?php esc_html_e( 'Report Type', 'cmi-partner-portal' ); ?> <span class="req">*</span></label>
                <select id="cmi-modal-report-type" name="report_type_id" required>
                    <option value=""><?php esc_html_e( 'Select report type...', 'cmi-partner-portal' ); ?></option>
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
                <label for="cmi-modal-report-file"><?php esc_html_e( 'Report File', 'cmi-partner-portal' ); ?> <span class="req">*</span> <small><?php esc_html_e( '(PDF only, max 10 MB)', 'cmi-partner-portal' ); ?></small></label>
                <input type="file" id="cmi-modal-report-file" name="report_file" accept="application/pdf" required>
            </div>

            <div class="cmi-form-row">
                <label for="cmi-modal-report-notes"><?php esc_html_e( 'Notes / Remarks', 'cmi-partner-portal' ); ?></label>
                <textarea id="cmi-modal-report-notes" name="notes" rows="3" placeholder="<?php esc_attr_e( 'Any additional notes for the patient or admin...', 'cmi-partner-portal' ); ?>"></textarea>
            </div>

            <div class="cmi-form-actions" style="margin-top:20px; display:flex; gap:10px;">
                <button type="submit" class="button button-primary" id="cmi-modal-upload-btn"><?php esc_html_e( 'Upload Report', 'cmi-partner-portal' ); ?></button>
                <button type="button" class="button cmi-close-modal"><?php esc_html_e( 'Cancel', 'cmi-partner-portal' ); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Revoke Reason Modal -->
<div id="cmi-revoke-modal" style="display:none;">
    <div class="cmi-modal-content">
        <h3><?php esc_html_e( 'Revoke Acceptance', 'cmi-partner-portal' ); ?></h3>
        <form id="cmi-revoke-form">
            <input type="hidden" id="cmi-revoke-id" name="id" value="">
            <div class="cmi-form-row">
                <label for="cmi-revoke-reason"><?php esc_html_e( 'Reason for Revoking', 'cmi-partner-portal' ); ?></label>
                <textarea id="cmi-revoke-reason" name="reason" required class="large-text" placeholder="<?php esc_attr_e( 'Explain why you cannot perform this test collection...', 'cmi-partner-portal' ); ?>"></textarea>
            </div>
            <div class="cmi-form-actions" style="margin-top:20px; display:flex; gap:10px;">
                <button type="submit" class="button button-primary" style="background-color:var(--cmi-error) !important; border-color:var(--cmi-error) !important;"><?php esc_html_e( 'Submit Revocation', 'cmi-partner-portal' ); ?></button>
                <button type="button" class="button cmi-close-modal"><?php esc_html_e( 'Cancel', 'cmi-partner-portal' ); ?></button>
            </div>
        </form>
    </div>
</div>
