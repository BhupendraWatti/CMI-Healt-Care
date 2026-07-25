<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="cmi-prescriptions-portal">

    <!-- Stats Summary Header -->
    <div class="cmi-dashboard-stats" style="display: flex; gap: 20px; margin-bottom: 24px;">
        <div class="cmi-stat-card" style="flex: 1; background: #fff; border: 1px solid var(--cmi-border); border-radius: 10px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
            <div style="font-size: 13px; color: var(--cmi-text-muted); font-weight: 600; text-transform: uppercase; margin-bottom: 4px;">Total Prescriptions Uploaded</div>
            <div style="font-size: 28px; font-weight: 700; color: var(--cmi-primary);"><?php echo count($prescriptions); ?></div>
        </div>
        <div class="cmi-stat-card" style="flex: 1; background: #fff; border: 1px solid var(--cmi-border); border-radius: 10px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
            <div style="font-size: 13px; color: var(--cmi-text-muted); font-weight: 600; text-transform: uppercase; margin-bottom: 4px;">Recent Activity</div>
            <div style="font-size: 14px; font-weight: 500; color: var(--cmi-text-main); margin-top: 10px;">
                <?php if ( ! empty($prescriptions) ) : 
                    $latest = $prescriptions[0];
                    echo 'Last Rx uploaded on ' . date('d M Y', strtotime($latest->post_date));
                else :
                    echo 'No recent uploads';
                endif; ?>
            </div>
        </div>
    </div>

    <!-- Main Layout Container (Flexible Columns) -->
    <div class="cmi-split-layout" style="display: flex; gap: 30px; flex-wrap: wrap;">
        
        <!-- Left: Upload Form -->
        <div class="cmi-upload-box" style="flex: 1 1 380px; margin-bottom: 0;">
            <h2>Upload Prescription</h2>
            <p style="font-size: 13px; color: var(--cmi-text-muted); margin-bottom: 20px;">Upload patient prescriptions securely. Files are stored encrypted and accessible only via secure OTP.</p>
            
            <div id="cmi-rx-upload-msg" class="cmi-msg" style="display:none"></div>

            <div class="cmi-form-row">
                <label>Patient Mobile Number or Email Address <span class="req">*</span></label>
                <input type="text" id="cmi-rx-patient-mobile" placeholder="Mobile number or email address" />
                <small>Used for guest download and security verification.</small>
            </div>
            
            <div class="cmi-form-row">
                <label>Patient Name</label>
                <input type="text" id="cmi-rx-patient-name" placeholder="Full name of patient" />
            </div>
            
            <div class="cmi-form-row">
                <label>Patient Unique ID (optional)</label>
                <input type="text" id="cmi-rx-patient-uid" placeholder="e.g. CMI12345" />
            </div>
            
            <div class="cmi-form-row">
                <label>Prescription File <span class="req">*</span> <small>(PDF, JPG or PNG, max 10 MB)</small></label>
                <input type="file" id="cmi-rx-report-file" accept=".pdf,.jpg,.jpeg,.png" />
            </div>
            
            <div class="cmi-form-row">
                <label>Notes / Diagnosis Summary</label>
                <textarea id="cmi-rx-report-notes" rows="3" placeholder="Add diagnosis details, dosage instructions or notes..."></textarea>
            </div>
            
            <button id="cmi-rx-upload-btn" class="button button-primary" style="width: 100%;">Upload Prescription</button>
        </div>

        <!-- Right: Uploaded List -->
        <div class="cmi-uploads-list" style="flex: 2 1 500px; background: #fff; border: 1px solid var(--cmi-border); border-radius: 12px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 20px; gap: 10px;">
                <h2 style="margin: 0; font-size: 20px; font-weight: 700; color: var(--cmi-primary);">Upload History</h2>
                <!-- Instant Search Field -->
                <input type="text" id="cmi-rx-search-input" placeholder="Search by name, contact..." style="max-width: 250px; padding: 6px 12px; font-size: 13px; border: 1px solid var(--cmi-border); border-radius: 6px;" />
            </div>

            <?php if ( empty($prescriptions) ) : ?>
                <div class="cmi-empty" style="text-align: center; padding: 40px 20px;">
                    <span style="font-size: 40px; display: block; margin-bottom: 10px;">📄</span>
                    <p style="margin: 0; font-weight: 500;">No prescriptions uploaded yet.</p>
                    <p style="margin: 5px 0 0 0; font-size: 12px; color: var(--cmi-text-muted);">Uploaded prescriptions will appear here.</p>
                </div>
            <?php else : ?>
            <div style="overflow-x: auto;">
                <table class="cmi-reports-table" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Patient Name</th>
                            <th>Contact Info</th>
                            <th>Date Uploaded</th>
                            <th>Diagnosis/Notes</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="cmi-rx-history-tbody">
                    <?php foreach ( $prescriptions as $r ) : 
                        $pname   = get_post_meta($r->ID, '_cmi_patient_name', true) ?: '—';
                        $pmobile = get_post_meta($r->ID, '_cmi_patient_mobile', true);
                        $pemail  = get_post_meta($r->ID, '_cmi_patient_email', true);
                        $notes   = get_post_meta($r->ID, '_cmi_notes', true);
                        $contact = [];
                        if ( $pmobile ) $contact[] = $pmobile;
                        if ( $pemail ) $contact[] = $pemail;
                        $contact_str = implode(' / ', $contact) ?: '—';
                    ?>
                        <tr class="cmi-rx-row">
                            <td class="cmi-rx-pname" style="font-weight: 600; color: var(--cmi-text-main);"><?php echo esc_html($pname); ?></td>
                            <td class="cmi-rx-contact" style="font-size: 13px; color: var(--cmi-text-muted);"><?php echo esc_html($contact_str); ?></td>
                            <td style="font-size: 13px;"><?php echo date('d M Y', strtotime($r->post_date)); ?></td>
                            <td style="font-size: 13px; max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo esc_attr($notes); ?>">
                                <?php echo esc_html($notes ?: '—'); ?>
                            </td>
                            <td>
                                <button class="button cmi-download-btn" data-id="<?php echo absint( $r->ID ); ?>" style="display: inline-flex; align-items: center; gap: 5px; white-space: nowrap;">
                                    <span>Download</span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>

</div>

