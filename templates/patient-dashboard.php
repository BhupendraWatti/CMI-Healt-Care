<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="cmi-patient-dashboard-wrapper">
    <div class="cmi-dashboard-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #eee; padding-bottom:15px;">
        <h3 style="margin:0;">Welcome, <?php echo esc_html( $user->display_name ); ?> <span class="cmi-patient-id-badge" style="font-size:12px; background:#e8faf0; color:#1e8449; padding:3px 8px; border-radius:12px; font-weight:600; margin-left:8px;">ID: <?php echo esc_html( get_user_meta( $user->ID, '_cmi_uid', true ) ); ?></span></h3>
        <p style="margin:0;"><a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="button button-secondary" style="font-size:13px; padding:5px 12px;">Log Out</a></p>
    </div>

    <!-- Tab navigation -->
    <div class="cmi-tabs cmi-dashboard-tabs" style="margin-bottom:24px;">
        <button type="button" class="cmi-tab-btn active" data-tab="reports">My Reports</button>
        <button type="button" class="cmi-tab-btn" data-tab="collections">Home Collections</button>
    </div>

    <!-- Tab Contents -->
    <div id="cmi-tab-content-reports" class="cmi-dashboard-tab-content">
        <?php include CMI_PP_PATH . 'templates/patient-reports.php'; ?>
    </div>

    <div id="cmi-tab-content-collections" class="cmi-dashboard-tab-content" style="display:none;">
        <?php 
        if ( class_exists( 'CMI_HT_Shortcodes' ) ) {
            $shortcodes = new CMI_HT_Shortcodes();
            echo $shortcodes->render_patient_dashboard();
        } else {
            echo '<p>' . esc_html__( 'Home testing is currently unavailable.', 'cmi-partner-portal' ) . '</p>';
        }
        ?>
    </div>
</div>

