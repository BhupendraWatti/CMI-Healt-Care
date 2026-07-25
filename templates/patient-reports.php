<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="cmi-patient-reports">
    <h2>My Reports</h2>



    <?php if ( empty($reports) && empty($rxs) ) : ?>
        <p class="cmi-empty">No reports have been uploaded yet. If you have recently had a test done through CMI Healthcare, please allow 24 hours for your report to appear here.</p>
        <p>Your registered mobile: <strong><?php echo esc_html($mobile ?: '—'); ?></strong> &nbsp;|&nbsp; Your CMI ID: <strong><?php echo esc_html($uid ?: '—'); ?></strong></p>
    <?php else : ?>

        <?php if ( ! empty($reports) ) : ?>
        <h3>Lab Reports &amp; Test Results</h3>
        <table class="cmi-reports-table">
            <thead><tr><th>Report</th><th>Type</th><th>Date</th><th>Uploaded By</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ( $reports as $r ) :
                $terms    = wp_get_post_terms($r->ID,'cmi_report_type',['fields'=>'names']);
                $uploader = get_userdata(get_post_meta($r->ID,'_cmi_uploaded_by',true));
            ?>
                <tr>
                    <td><?php echo esc_html(get_the_title($r)); ?></td>
                    <td><?php echo esc_html(implode(', ', $terms)); ?></td>
                    <td><?php echo date('d M Y', strtotime($r->post_date)); ?></td>
                    <td><?php echo $uploader ? esc_html($uploader->display_name) : 'CMI Healthcare'; ?></td>
                    <td><button class="button cmi-patient-download-btn" data-id="<?php echo absint( $r->ID ); ?>">Download PDF</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ( ! empty($rxs) ) : ?>
        <h3 style="margin-top:2em">Prescriptions</h3>
        <table class="cmi-reports-table">
            <thead><tr><th>Prescription</th><th>Date</th><th>Doctor</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ( $rxs as $r ) :
                $uploader = get_userdata(get_post_meta($r->ID,'_cmi_uploaded_by',true));
            ?>
                <tr>
                    <td><?php echo esc_html(get_the_title($r)); ?></td>
                    <td><?php echo date('d M Y', strtotime($r->post_date)); ?></td>
                    <td><?php echo $uploader ? esc_html($uploader->display_name) : 'CMI Healthcare'; ?></td>
                    <td><button class="button cmi-patient-download-btn" data-id="<?php echo absint( $r->ID ); ?>">Download</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

    <?php endif; ?>
</div>


<div id="cmi-patient-otp-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; padding:32px; max-width:420px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.3); position:relative;">
        <h3 style="margin:0 0 8px; color:#1a4f8a;">Verify Your Identity</h3>
        <p id="cmi-otp-modal-msg" style="color:#718096; margin:0 0 20px; font-size:14px;"></p>
        <div class="cmi-form-row">
            <label>Enter OTP</label>
            <input type="text" id="cmi-modal-otp-input" placeholder="6-digit OTP" maxlength="6" style="text-align:center; letter-spacing:6px; font-size:20px;" />
        </div>
        <div id="cmi-modal-error" class="cmi-msg cmi-msg-error" style="display:none;"></div>
        <div style="display:flex; gap:12px; margin-top:16px;">
            <button id="cmi-modal-verify-btn" class="button button-primary" style="flex:1;">Verify &amp; Download</button>
            <button id="cmi-modal-cancel-btn" class="button" style="flex:0 0 auto;">Cancel</button>
        </div>
        <p style="margin-top:14px; font-size:12px; color:#a0aec0; text-align:center;">
            <a href="#" id="cmi-modal-resend-btn">Resend OTP</a>
        </p>
    </div>
</div>

<?php 
if ( class_exists( 'CMI_Consultations' ) ) {
    CMI_Consultations::render_jitsi_overlay_modal();
}
?>

