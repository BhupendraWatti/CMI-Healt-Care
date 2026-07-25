<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="cmi-patients-portal">

    <?php if ( ! is_null( $history ) ) : ?>
        <!-- ================= PATIENT HISTORY VIEW ================= -->
        <div class="cmi-dashboard-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border:1px solid var(--cmi-border); background:#f8fafc; padding:15px 20px; border-radius:8px;">
            <h2 style="margin:0; font-size:20px; font-weight:700; color:var(--cmi-primary);">Patient History: <?php echo esc_html( $patient_name ); ?></h2>
            <a href="<?php echo esc_url( remove_query_arg( 'view_history' ) ); ?>" class="button button-secondary" style="font-size:13px; padding:6px 12px; display:inline-flex; align-items:center; gap:5px;">
                <span>← Back to My Patients</span>
            </a>
        </div>

        <!-- History Stats -->
        <div class="cmi-dashboard-stats" style="display: flex; gap: 20px; margin-bottom: 24px;">
            <div class="cmi-stat-card" style="flex: 1; background: #fff; border: 1px solid var(--cmi-border); border-radius: 10px; padding: 15px 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                <div style="font-size: 11px; color: var(--cmi-text-muted); font-weight: 600; text-transform: uppercase; margin-bottom: 4px;">Total Records Found</div>
                <div style="font-size: 24px; font-weight: 700; color: var(--cmi-primary);"><?php echo count($history); ?></div>
            </div>
            <div class="cmi-stat-card" style="flex: 2; background: #fff; border: 1px solid var(--cmi-border); border-radius: 10px; padding: 15px 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <div style="font-size: 11px; color: var(--cmi-text-muted); font-weight: 600; text-transform: uppercase; margin-bottom: 4px;">Filter History</div>
                    <input type="text" id="cmi-history-search-input" placeholder="Type to filter reports..." style="padding: 5px 10px; font-size: 12px; border: 1px solid var(--cmi-border); border-radius: 6px; width: 220px;" />
                </div>
            </div>
        </div>

        <?php if ( empty( $history ) ) : ?>
            <div class="cmi-empty" style="text-align: center; padding: 40px 20px;">
                <span style="font-size: 40px; display: block; margin-bottom: 10px;">📭</span>
                <p style="margin: 0; font-weight: 500;">No reports or prescriptions uploaded for this patient yet.</p>
            </div>
        <?php else : ?>
            <div style="background:#fff; border: 1px solid var(--cmi-border); border-radius: 12px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); overflow-x: auto;">
                <table class="cmi-reports-table" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Title / Description</th>
                            <th>Type</th>
                            <th>Upload Date</th>
                            <th>Notes</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="cmi-history-tbody">
                    <?php foreach ( $history as $r ) :
                        $terms = wp_get_post_terms( $r->ID, 'cmi_report_type', [ 'fields' => 'names' ] );
                        $type  = ! empty( $terms ) ? $terms[0] : ( $r->post_type === 'cmi_prescription' ? 'Prescription' : 'Report' );
                        $notes = get_post_meta( $r->ID, '_cmi_notes', true );
                        
                        $type_badge_color = ($r->post_type === 'cmi_prescription') ? 'background:#eafaf1; color:#27ae60; border: 1px solid #d4efdf;' : 'background:#eef4ff; color:#1a4f8a; border: 1px solid #d6e4ff;';
                    ?>
                        <tr class="cmi-history-row">
                            <td class="cmi-history-title" style="font-weight: 600; color: var(--cmi-text-main);"><?php echo esc_html( get_the_title( $r ) ); ?></td>
                            <td class="cmi-history-type">
                                <span style="font-size: 11px; padding: 3px 8px; border-radius: 12px; font-weight: 600; display: inline-block; <?php echo $type_badge_color; ?>">
                                    <?php echo esc_html( $type ); ?>
                                </span>
                            </td>
                            <td style="font-size: 13px; color: var(--cmi-text-muted);"><?php echo date( 'd M Y', strtotime( $r->post_date ) ); ?></td>
                            <td style="font-size: 13px; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo esc_attr($notes); ?>">
                                <?php echo esc_html( $notes ?: '—' ); ?>
                            </td>
                            <td>
                                <button class="button cmi-download-btn" data-id="<?php echo absint( $r->ID ); ?>" style="display:inline-flex; align-items:center; gap:5px;">
                                    <span>Download</span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>


    <?php else : ?>
        <!-- ================= MY PATIENTS TABLE VIEW ================= -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0; font-size:22px; font-weight:700; color:var(--cmi-primary);">My Patients</h2>
            <!-- Stat Badge -->
            <span style="background:var(--cmi-primary-light); color:var(--cmi-primary); font-size:12px; padding:4px 10px; border-radius:12px; font-weight:600;">
                <?php echo empty($patients) ? '0 Patients' : count($patients) . ' Records'; ?>
            </span>
        </div>

        <?php if ( empty($patients) ) : ?>
            <div class="cmi-empty" style="text-align: center; padding: 40px 20px;">
                <span style="font-size: 40px; display: block; margin-bottom: 10px;">👥</span>
                <p style="margin: 0; font-weight: 500;">You have not uploaded any patient reports or prescriptions yet.</p>
                <p style="margin: 5px 0 0 0; font-size: 12px; color: var(--cmi-text-muted);">Uploaded patient records will automatically build your list here.</p>
            </div>
        <?php else :
            // Deduplicate by mobile, email, or UID
            $seen = []; $unique = [];
            foreach ( $patients as $p ) {
                $key = $p->mobile ?: ( $p->email ?: $p->uid );
                if ( $key && ! isset($seen[$key]) ) { $seen[$key] = true; $unique[] = $p; }
            }
        ?>
        
        <!-- Stats and Live Search Filter -->
        <div style="background:#fff; border: 1px solid var(--cmi-border); border-radius: 12px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div style="display: flex; gap: 15px;">
                    <div style="border-right: 1px solid var(--cmi-border); padding-right: 20px;">
                        <span style="display: block; font-size: 11px; color: var(--cmi-text-muted); font-weight: 600; text-transform: uppercase;">Unique Patients</span>
                        <strong style="font-size: 20px; color: var(--cmi-primary);"><?php echo count($unique); ?></strong>
                    </div>
                    <div>
                        <span style="display: block; font-size: 11px; color: var(--cmi-text-muted); font-weight: 600; text-transform: uppercase;">Total Uploaded Records</span>
                        <strong style="font-size: 20px; color: var(--cmi-primary);"><?php echo count($patients); ?></strong>
                    </div>
                </div>
                <div>
                    <!-- Search Input -->
                    <input type="text" id="cmi-patient-search-input" placeholder="Search patients by name, mobile, UID..." style="width: 280px; max-width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--cmi-border); border-radius: 6px;" />
                </div>
            </div>
        </div>

        <!-- Patients Table -->
        <div style="background:#fff; border: 1px solid var(--cmi-border); border-radius: 12px; padding: 15px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); overflow-x: auto;">
            <table class="cmi-reports-table" style="margin: 0;">
                <thead>
                    <tr>
                        <th>Patient Name</th>
                        <th>Mobile Number</th>
                        <th>Email Address</th>
                        <th>Unique ID (UID)</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="cmi-patients-tbody">
                <?php foreach ( $unique as $p ) : ?>
                    <tr class="cmi-patient-row">
                        <td class="cmi-p-name" style="font-weight: 600; color: var(--cmi-text-main);"><?php echo esc_html($p->name ?: 'Unnamed Patient'); ?></td>
                        <td class="cmi-p-mobile" style="font-size: 13px;"><?php echo esc_html($p->mobile ?: '—'); ?></td>
                        <td class="cmi-p-email" style="font-size: 13px; color: var(--cmi-text-muted);"><?php echo esc_html($p->email ?: '—'); ?></td>
                        <td class="cmi-p-uid" style="font-size: 12px; font-family: monospace; font-weight: 600; color: var(--cmi-primary);"><?php echo esc_html($p->uid ?: '—'); ?></td>
                        <td style="text-align: right; white-space: nowrap;">
                            <?php
                            $prefill = $p->mobile ?: ( $p->email ?: $p->uid );
                            ?>
                            <a href="<?php echo esc_url( add_query_arg( 'prefill', urlencode( $prefill ), home_url('/partner-portal/') ) ); ?>" class="button button-primary" style="font-size:12px; padding:5px 10px; margin-right:5px; background:var(--cmi-primary); color:#fff; border-radius:4px; text-decoration:none; display:inline-block;">Upload Report</a>
                            <a href="<?php echo esc_url( add_query_arg( 'view_history', urlencode( $prefill ), get_permalink() ) ); ?>" class="button button-secondary" style="font-size:12px; padding:5px 10px; border-radius:4px; text-decoration:none; display:inline-block;">View History</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>
