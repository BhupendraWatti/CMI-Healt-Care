<?php if ( ! defined('ABSPATH') ) exit; 

$user_id = get_current_user_id();
global $wpdb;
$table = $wpdb->prefix . 'cmi_home_testing';
$assigned_orders = [];
if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table ) {
    $assigned_orders = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table WHERE partner_id = %d AND status IN ('assigned', 'accepted') ORDER BY id DESC",
        $user_id
    ) );
}
?>
<div class="cmi-partner-upload">

    <div class="cmi-upload-box">
        <h2>Upload Patient Report</h2>
        <div id="cmi-upload-msg" class="cmi-msg" style="display:none"></div>

        <div class="cmi-form-row">
            <label><?php esc_html_e( 'Select Assigned Order (Optional - Auto-fills details)', 'cmi-partner-portal' ); ?></label>
            <select id="cmi-upload-order-select" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
                <option value=""><?php esc_html_e( '-- Select an assigned order --', 'cmi-partner-portal' ); ?></option>
                <?php foreach ( $assigned_orders as $o_row ) : 
                    $o_order = wc_get_order( $o_row->order_id );
                    if ( ! $o_order ) continue;
                    $o_patient_name = $o_order->get_billing_first_name() . ' ' . $o_order->get_billing_last_name();
                ?>
                    <option value="<?php echo esc_attr( $o_row->id ); ?>" 
                            data-order-id="<?php echo esc_attr( $o_row->order_id ); ?>"
                            data-patient-name="<?php echo esc_attr( $o_patient_name ); ?>"
                            data-patient-mobile="<?php echo esc_attr( $o_order->get_billing_phone() ); ?>"
                            data-patient-email="<?php echo esc_attr( $o_order->get_billing_email() ); ?>"
                            data-patient-uid="<?php echo esc_attr( get_user_meta( $o_order->get_customer_id(), '_cmi_patient_uid', true ) ); ?>"
                            data-detected-type="<?php echo esc_attr( CMI_HT_Partner_Workflow::detect_report_type_by_order_items( $o_row->order_id ) ); ?>">
                        <?php echo sprintf( __( 'Order #%d - %s (%s)', 'cmi-partner-portal' ), $o_row->order_id, $o_patient_name, ucfirst( $o_row->status ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="cmi-form-row">
            <label>Patient Mobile Number <span class="req">*</span> <small>(required if no email)</small></label>
            <input type="tel" id="cmi-patient-mobile" placeholder="e.g. 9876543210" maxlength="15" />
        </div>
        <div class="cmi-form-row">
            <label>Patient Email Address <small>(required if no mobile)</small></label>
            <input type="email" id="cmi-patient-email" placeholder="patient@example.com" />
        </div>
        <div class="cmi-form-row">
            <label>Patient Name</label>
            <input type="text" id="cmi-patient-name" placeholder="Full name of patient" />
        </div>
        <div class="cmi-form-row">
            <label>Patient Unique ID (optional)</label>
            <input type="text" id="cmi-patient-uid" placeholder="CMI UID if known" />
        </div>
        <div class="cmi-form-row">
            <label>Report Type <span class="req">*</span></label>
            <select id="cmi-report-type">
                <option value="">Select report type…</option>
                <?php 
                if ( taxonomy_exists( 'cmi_report_type' ) ) {
                    $report_types = get_terms([ 'taxonomy' => 'cmi_report_type', 'hide_empty' => false ]);
                }
                foreach ( $report_types as $term ) : ?>
                    <option value="<?php echo $term->term_id; ?>"><?php echo esc_html($term->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cmi-form-row">
            <label>Report File <span class="req">*</span> <small>(PDF, JPG or PNG, max 10 MB)</small></label>
            <input type="file" id="cmi-report-file" accept=".pdf,.jpg,.jpeg,.png" />
        </div>
        <div class="cmi-form-row">
            <label>Notes / Remarks</label>
            <textarea id="cmi-report-notes" rows="3" placeholder="Any additional notes for the patient or doctor…"></textarea>
        </div>
        <button id="cmi-upload-btn" class="button button-primary">Upload Report</button>
    </div>

    <div class="cmi-uploads-list">
        <h2>Previously Uploaded Reports</h2>
        <?php if ( empty($reports) ) : ?>
            <p>You have not uploaded any reports yet.</p>
        <?php else : ?>
        <table class="cmi-reports-table">
            <thead><tr><th>Patient Name</th><th>Mobile</th><th>Email</th><th>Type</th><th>Date</th><th>Download</th></tr></thead>
            <tbody>
            <?php foreach ( $reports as $r ) :
                $terms   = wp_get_post_terms($r->ID, 'cmi_report_type', ['fields'=>'names']);
                $pname   = get_post_meta($r->ID, '_cmi_patient_name', true);
                $pmobile = get_post_meta($r->ID, '_cmi_patient_mobile', true);
                $pemail  = get_post_meta($r->ID, '_cmi_patient_email', true);
                // Fallback: parse name from post title (e.g. "John Doe – 15 Jun 2025")
                if ( ! $pname ) {
                    $title_parts = explode(' – ', get_the_title($r));
                    $pname = $title_parts[0] !== 'Report' ? $title_parts[0] : '';
                }
            ?>
                <tr>
                    <td><?php echo esc_html( $pname ?: '—' ); ?></td>
                    <td><?php echo esc_html( $pmobile ?: '—' ); ?></td>
                    <td><?php echo esc_html( $pemail ?: '—' ); ?></td>
                    <td><?php echo esc_html( implode(', ', $terms) ?: '—' ); ?></td>
                    <td><?php echo date('d M Y', strtotime($r->post_date)); ?></td>
                    <td><button class="button cmi-download-btn" data-id="<?php echo $r->ID; ?>">Download</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>
