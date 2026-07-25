<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMI_Roles {

    public static function create_roles() {
        // Pending partner – awaiting admin approval
        add_role( 'pending_partner', 'Pending Partner', [
            'read' => true,
        ]);

        // Medical partner (lab / clinic) – can upload reports
        add_role( 'medical_partner', 'Medical Partner', [
            'read'                    => true,
            'cmi_upload_report'       => true,
            'cmi_view_own_uploads'    => true,
            'cmi_view_assignments'    => true,
            'cmi_manage_availability' => true,
            'cmi_upload_reports'      => true,
        ]);

        // Doctor – can upload reports + view/upload prescriptions
        add_role( 'cmi_doctor', 'Doctor (CMI)', [
            'read'                      => true,
            'cmi_upload_report'         => true,
            'cmi_view_own_uploads'      => true,
            'cmi_upload_prescription'   => true,
            'cmi_view_prescription'     => true,
            'cmi_view_assignments'      => true,
            'cmi_manage_availability'   => true,
            'cmi_upload_reports'        => true,
        ]);

        // Force-update existing roles with new capabilities (add_role doesn't update existing roles)
        $mp_role = get_role( 'medical_partner' );
        if ( $mp_role ) {
            $mp_role->add_cap( 'cmi_upload_report' );
            $mp_role->add_cap( 'cmi_view_own_uploads' );
            $mp_role->add_cap( 'cmi_view_assignments' );
            $mp_role->add_cap( 'cmi_manage_availability' );
            $mp_role->add_cap( 'cmi_upload_reports' );
        }

        $doc_role = get_role( 'cmi_doctor' );
        if ( $doc_role ) {
            $doc_role->add_cap( 'cmi_upload_report' );
            $doc_role->add_cap( 'cmi_view_own_uploads' );
            $doc_role->add_cap( 'cmi_upload_prescription' );
            $doc_role->add_cap( 'cmi_view_prescription' );
            $doc_role->add_cap( 'cmi_view_assignments' );
            $doc_role->add_cap( 'cmi_manage_availability' );
            $doc_role->add_cap( 'cmi_upload_reports' );
        }

        // Extend administrator
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( 'cmi_upload_report' );
            $admin->add_cap( 'cmi_view_own_uploads' );
            $admin->add_cap( 'cmi_upload_prescription' );
            $admin->add_cap( 'cmi_view_prescription' );
            $admin->add_cap( 'cmi_manage_partners' );
            $admin->add_cap( 'cmi_manage_reports' );
            $admin->add_cap( 'cmi_manage_assignments' );
            $admin->add_cap( 'cmi_manage_reschedules' );
        }

        // Extend customer
        $customer = get_role( 'customer' );
        if ( $customer ) {
            $customer->add_cap( 'cmi_request_reschedule' );
        }
    }

    public static function is_partner( $user = null ) {
        if ( ! $user ) $user = wp_get_current_user();
        return in_array( 'medical_partner', (array) $user->roles )
            || in_array( 'cmi_doctor', (array) $user->roles );
    }

    public static function is_doctor( $user = null ) {
        if ( ! $user ) $user = wp_get_current_user();
        return in_array( 'cmi_doctor', (array) $user->roles );
    }

    public static function is_pending( $user = null ) {
        if ( ! $user ) $user = wp_get_current_user();
        return in_array( 'pending_partner', (array) $user->roles );
    }
}
