<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="cmi-patient-consultations">
    <h2>Active Video Consultations</h2>

    <?php if ( empty( $active_consultations ) ) : ?>
        <p class="cmi-empty"><?php esc_html_e( 'No active consultations found.', 'cmi-partner-portal' ); ?></p>
    <?php else : ?>
        <table class="cmi-reports-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:2px solid #eee; text-align:left;">
                    <th style="padding:10px; font-weight:600;"><?php esc_html_e( 'Doctor', 'cmi-partner-portal' ); ?></th>
                    <th style="padding:10px; font-weight:600;"><?php esc_html_e( 'Category', 'cmi-partner-portal' ); ?></th>
                    <th style="padding:10px; font-weight:600;"><?php esc_html_e( 'Schedule Details', 'cmi-partner-portal' ); ?></th>
                    <th style="padding:10px; font-weight:600;"><?php esc_html_e( 'Status', 'cmi-partner-portal' ); ?></th>
                    <th style="padding:10px; font-weight:600; text-align:right;"><?php esc_html_e( 'Action', 'cmi-partner-portal' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $active_consultations as $row ) : 
                $doc = get_userdata( $row->doctor_id );
                $doc_name = $doc ? $doc->display_name : esc_html__( 'Assigned Doctor', 'cmi-partner-portal' );
                
                // Check if consultation time slot has passed
                $is_slot_over = false;
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

                $status_text = str_replace( '_', ' ', $row->status );
                $badge_class = 'cmi-status-' . str_replace( '_', '-', $row->status );

                if ( in_array( $row->status, [ 'scheduled', 'assigned', 'requested' ], true ) && $is_slot_over ) {
                    $status_text = 'Expired / Missed';
                    $badge_class = 'cmi-status-expired-missed';
                } elseif ( ( $row->status === 'in_progress' || $row->status === 'awaiting_prescription' ) && $is_slot_over ) {
                    $status_text = 'Awaiting Prescription';
                    $badge_class = 'cmi-status-awaiting-prescription';
                } elseif ( $row->status === 'needs_reschedule' ) {
                    $status_text = 'Reschedule Requested';
                    $badge_class = 'cmi-status-needs-reschedule';
                } elseif ( $row->status === 'rescheduled' ) {
                    $status_text = 'Rescheduled';
                    $badge_class = 'cmi-status-rescheduled';
                }
            ?>
                <tr style="border-bottom:1px solid #edf2f7;" data-id="<?php echo esc_attr( $row->id ); ?>">
                    <td style="padding:10px;"><strong><?php echo esc_html( $doc_name ); ?></strong></td>
                    <td style="padding:10px;"><?php echo esc_html( $row->consultation_type ); ?></td>
                    <td style="padding:10px;"><?php echo esc_html( date_i18n( get_option('date_format'), strtotime($row->preferred_date) ) ) . ' (' . esc_html( $row->preferred_time_slot ) . ')'; ?></td>
                    <td style="padding:10px;">
                        <span class="cmi-badge <?php echo esc_attr( $badge_class ); ?>">
                            <?php echo esc_html( $status_text ); ?>
                        </span>
                    </td>
                    <td style="padding:10px; text-align:right;">
                        <?php if ( in_array( $row->status, [ 'scheduled', 'assigned', 'requested' ], true ) && $is_slot_over ) : ?>
                            <button type="button" class="button cmi-request-reschedule-btn" data-id="<?php echo esc_attr( $row->id ); ?>" style="font-size:12px;"><?php esc_html_e( 'Request Reschedule', 'cmi-partner-portal' ); ?></button>
                        <?php elseif ( $row->status === 'needs_reschedule' ) : ?>
                            <span style="font-size:12px; color:#d69e2e; font-weight:600;"><?php esc_html_e( 'Pending Admin Review', 'cmi-partner-portal' ); ?></span>
                        <?php elseif ( $row->status === 'awaiting_prescription' || ( $row->status === 'in_progress' && $is_slot_over ) ) : ?>
                            <span style="font-size:12px; color:#718096;"><?php esc_html_e( 'Waiting for prescription', 'cmi-partner-portal' ); ?></span>
                        <?php elseif ( ( $row->status === 'scheduled' || $row->status === 'assigned' || $row->status === 'in_progress' || $row->status === 'rescheduled' ) && ! $is_slot_over ) : ?>
                            <button type="button" class="button cmi-join-video-btn" data-id="<?php echo esc_attr( $row->id ); ?>" style="background-color: #1a4f8a; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; font-weight: 600; cursor: pointer; font-size:12px;">
                                <?php esc_html_e( 'Join Video Call', 'cmi-partner-portal' ); ?>
                            </button>
                        <?php else : ?>
                            <span class="description">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php 
if ( class_exists( 'CMI_Consultations' ) ) {
    CMI_Consultations::render_jitsi_overlay_modal();
}
?>
