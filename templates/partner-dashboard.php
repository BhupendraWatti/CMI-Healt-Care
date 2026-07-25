<?php 
if ( ! defined('ABSPATH') ) exit; 
$is_doctor = in_array('cmi_doctor', (array) $user->roles);
$cookie_tab = isset($_COOKIE['cmi_active_tab']) ? sanitize_key($_COOKIE['cmi_active_tab']) : '';
$default_tab = $is_doctor ? 'consultations' : 'assignments';
$active_tab = !empty($cookie_tab) ? $cookie_tab : $default_tab;

// Make sure the active tab is valid for this user role
// Doctors can also see Home Collections — both roles have cmi_view_assignments
if ($is_doctor && !in_array($active_tab, ['prescriptions', 'consultations', 'availability', 'assignments', 'patients', 'profile'], true)) {
    $active_tab = 'consultations';
} elseif (!$is_doctor && !in_array($active_tab, ['assignments', 'patients', 'profile'], true)) {
    $active_tab = 'assignments';
}

if (isset($_GET['view_history'])) {
    $active_tab = 'patients';
}
?>
<div class="cmi-partner-dashboard-wrapper">
    <div class="cmi-dashboard-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #eee; padding-bottom:15px;">
        <h3 style="margin:0;">Welcome, <?php echo esc_html( $user->display_name ); ?> <span class="cmi-role-badge" style="font-size:12px; background:#eef4ff; color:#1a4f8a; padding:3px 8px; border-radius:12px; font-weight:600; margin-left:8px;"><?php echo $is_doctor ? 'Doctor' : 'Medical Partner'; ?></span></h3>
        <p style="margin:0;"><a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="button button-secondary" style="font-size:13px; padding:5px 12px;">Log Out</a></p>
    </div>

    <!-- Tab navigation -->
    <div class="cmi-tabs cmi-dashboard-tabs" style="margin-bottom:24px;">
        <?php if ( $is_doctor ) : ?>
            <button type="button" class="cmi-tab-btn <?php echo ($active_tab === 'prescriptions') ? 'active' : ''; ?>" data-tab="prescriptions">Prescriptions</button>
            <button type="button" class="cmi-tab-btn <?php echo ($active_tab === 'consultations') ? 'active' : ''; ?>" data-tab="consultations">Consultations</button>
            <button type="button" class="cmi-tab-btn <?php echo ($active_tab === 'availability') ? 'active' : ''; ?>" data-tab="availability">Availability</button>
        <?php endif; ?>
        <?php /* Home Collections visible for ALL partners — both doctors and medical_partner can be assigned jobs */ ?>
        <button type="button" class="cmi-tab-btn <?php echo ($active_tab === 'assignments') ? 'active' : ''; ?>" data-tab="assignments">Home Collections</button>
        <button type="button" class="cmi-tab-btn <?php echo ($active_tab === 'patients') ? 'active' : ''; ?>" data-tab="patients">My Patients</button>
        <button type="button" class="cmi-tab-btn <?php echo ($active_tab === 'profile') ? 'active' : ''; ?>" data-tab="profile"><?php esc_html_e( 'My Profile', 'cmi-partner-portal' ); ?></button>
    </div>

    <!-- Tab Contents -->
    <?php /* Home Collections and Patients tabs render for ALL partner types */ ?>
    <div id="cmi-tab-content-assignments" class="cmi-dashboard-tab-content" style="<?php echo ($active_tab === 'assignments') ? '' : 'display:none;'; ?>">
        <?php include CMI_PP_PATH . 'templates/partner-assignments.php'; ?>
    </div>

    <div id="cmi-tab-content-patients" class="cmi-dashboard-tab-content" style="<?php echo ($active_tab === 'patients') ? '' : 'display:none;'; ?>">
        <?php include CMI_PP_PATH . 'templates/partner-patients.php'; ?>
    </div>

    <?php if ( $is_doctor ) : ?>
        <div id="cmi-tab-content-prescriptions" class="cmi-dashboard-tab-content" style="<?php echo ($active_tab === 'prescriptions') ? '' : 'display:none;'; ?>">
            <?php include CMI_PP_PATH . 'templates/doctor-prescriptions.php'; ?>
        </div>
        <div id="cmi-tab-content-consultations" class="cmi-dashboard-tab-content" style="<?php echo ($active_tab === 'consultations') ? '' : 'display:none;'; ?>">
            <?php CMI_Consultations::render_doctor_consultations_tab(); ?>
        </div>
        <div id="cmi-tab-content-availability" class="cmi-dashboard-tab-content" style="<?php echo ($active_tab === 'availability') ? '' : 'display:none;'; ?>">
            <?php CMI_Consultations::render_doctor_availability_tab(); ?>
        </div>
    <?php endif; ?>

    <div id="cmi-tab-content-profile" class="cmi-dashboard-tab-content" style="<?php echo ($active_tab === 'profile') ? '' : 'display:none;'; ?>">
        <?php include CMI_PP_PATH . 'templates/partner-profile.php'; ?>
    </div>
</div>

