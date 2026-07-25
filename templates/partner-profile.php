<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$user_id = get_current_user_id();
if ( ! $user_id ) return;

$user = wp_get_current_user();
$org     = get_user_meta( $user_id, '_cmi_org', true );
$mobile  = get_user_meta( $user_id, '_cmi_mobile', true );
$license = get_user_meta( $user_id, '_cmi_license', true );

$is_doctor = in_array( 'cmi_doctor', (array) $user->roles );
$specialty = $is_doctor ? get_user_meta( $user_id, '_cmi_specialty', true ) : '';
$fee       = $is_doctor ? get_user_meta( $user_id, '_cmi_consultation_fee', true ) : '';
?>
<div class="cmi-partner-profile-wrapper">
    <h2 style="font-size:20px; font-weight:700; color:var(--cmi-primary); margin-bottom:15px;"><?php esc_html_e( 'Update Profile Details', 'cmi-partner-portal' ); ?></h2>
    
    <form id="cmi-partner-profile-form" style="max-width: 500px; background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0;">
        <div class="cmi-form-row" style="margin-bottom: 15px;">
            <label for="cmi-profile-name" style="display:block; font-weight:600; margin-bottom:5px;"><?php esc_html_e( 'Full Name', 'cmi-partner-portal' ); ?> <span style="color:var(--cmi-error);">*</span></label>
            <input type="text" id="cmi-profile-name" name="display_name" value="<?php echo esc_attr( $user->display_name ); ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>

        <div class="cmi-form-row" style="margin-bottom: 15px;">
            <label for="cmi-profile-mobile" style="display:block; font-weight:600; margin-bottom:5px;"><?php esc_html_e( 'Mobile Number', 'cmi-partner-portal' ); ?> <span style="color:var(--cmi-error);">*</span></label>
            <input type="text" id="cmi-profile-mobile" name="mobile" value="<?php echo esc_attr( $mobile ); ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>

        <div class="cmi-form-row" style="margin-bottom: 15px;">
            <label for="cmi-profile-org" style="display:block; font-weight:600; margin-bottom:5px;"><?php esc_html_e( 'Organisation / Clinic Name', 'cmi-partner-portal' ); ?></label>
            <input type="text" id="cmi-profile-org" name="org" value="<?php echo esc_attr( $org ); ?>" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>

        <div class="cmi-form-row" style="margin-bottom: 15px;">
            <label for="cmi-profile-license" style="display:block; font-weight:600; margin-bottom:5px;"><?php esc_html_e( 'License / Registration Number', 'cmi-partner-portal' ); ?></label>
            <input type="text" id="cmi-profile-license" name="license" value="<?php echo esc_attr( $license ); ?>" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>

        <?php if ( $is_doctor ) : ?>
        <div class="cmi-form-row" style="margin-bottom: 15px;">
            <label for="cmi-profile-specialty" style="display:block; font-weight:600; margin-bottom:5px;"><?php esc_html_e( 'Specialty / Department', 'cmi-partner-portal' ); ?></label>
            <input type="text" id="cmi-profile-specialty" name="specialty" value="<?php echo esc_attr( $specialty ); ?>" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;" placeholder="e.g. General Physician">
        </div>

        <div class="cmi-form-row" style="margin-bottom: 15px;">
            <label for="cmi-profile-fee" style="display:block; font-weight:600; margin-bottom:5px;"><?php esc_html_e( 'Consultation Fee (INR)', 'cmi-partner-portal' ); ?></label>
            <input type="number" id="cmi-profile-fee" name="consultation_fee" value="<?php echo esc_attr( $fee ); ?>" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;" placeholder="500">
        </div>

        <div class="cmi-form-row" style="margin-bottom: 20px;">
            <label for="cmi-profile-bio" style="display:block; font-weight:600; margin-bottom:5px;"><?php esc_html_e( 'Biography / Profile Bio', 'cmi-partner-portal' ); ?></label>
            <textarea id="cmi-profile-bio" name="description" rows="5" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;" placeholder="e.g. Describe your experience, credentials, or introduction..."><?php echo esc_textarea( $user->description ); ?></textarea>
        </div>
        <?php endif; ?>

        <div id="cmi-profile-msg" class="cmi-msg" style="display:none; margin-bottom: 15px; padding: 10px; border-radius: 4px;"></div>

        <button type="submit" class="button button-primary" style="width: 100%; padding: 10px; font-weight: bold;"><?php esc_html_e( 'Save Profile Details', 'cmi-partner-portal' ); ?></button>
    </form>
</div>
