<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMI_Security {

    public static function requested_partner_type( $value ) {
        $value = sanitize_key( $value );
        return in_array( $value, [ 'medical_partner', 'cmi_doctor' ], true ) ? $value : 'medical_partner';
    }

    public static function mark_pending_partner( $user_id, $requested_type, $meta = [] ) {
        $requested_type = self::requested_partner_type( $requested_type );
        $user = new WP_User( $user_id );
        $user->set_role( 'pending_partner' );

        update_user_meta( $user_id, '_cmi_partner_type', $requested_type );
        update_user_meta( $user_id, '_cmi_partner_requested_type', $requested_type );
        update_user_meta( $user_id, '_cmi_approval_status', 'pending' );
        delete_user_meta( $user_id, '_cmi_approved' );

        foreach ( $meta as $key => $value ) {
            update_user_meta( $user_id, $key, $value );
        }
    }

    public static function local_redirect_url( $url, $fallback = '' ) {
        $fallback = $fallback ? $fallback : home_url( '/' );
        $url = esc_url_raw( $url );
        if ( empty( $url ) ) {
            return $fallback;
        }

        $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $url_host  = wp_parse_url( $url, PHP_URL_HOST );
        if ( $url_host && strcasecmp( $home_host, $url_host ) !== 0 ) {
            return $fallback;
        }

        return $url;
    }

    public static function mask_identifier( $identifier ) {
        $identifier = (string) $identifier;
        if ( is_email( $identifier ) ) {
            $parts = explode( '@', $identifier );
            return substr( $parts[0], 0, 2 ) . '***@' . $parts[1];
        }
        $digits = preg_replace( '/\D+/', '', $identifier );
        if ( strlen( $digits ) >= 6 ) {
            return substr( $digits, 0, 2 ) . str_repeat( '*', max( 0, strlen( $digits ) - 4 ) ) . substr( $digits, -2 );
        }
        return '***';
    }

    public static function identity_claim( $type, $value ) {
        $type = sanitize_key( $type );
        if ( 'email' === $type ) {
            $value = sanitize_email( $value );
        } elseif ( 'mobile' === $type ) {
            $value = CMI_CPT::normalize_mobile( $value );
        } elseif ( 'uid' === $type ) {
            $value = sanitize_text_field( $value );
        } else {
            return false;
        }

        if ( empty( $value ) ) {
            return false;
        }

        return [
            'identity_type'  => $type,
            'identity_value' => $value,
        ];
    }

    public static function identity_can_access_report( $report_id, $claim ) {
        $report_id = absint( $report_id );
        $post = get_post( $report_id );
        if ( ! $post || ! in_array( $post->post_type, [ 'cmi_report', 'cmi_prescription' ], true ) || 'publish' !== $post->post_status ) {
            return false;
        }

        if ( empty( $claim['identity_type'] ) || empty( $claim['identity_value'] ) ) {
            return false;
        }

        if ( 'mobile' === $claim['identity_type'] ) {
            $report_mobile = CMI_CPT::normalize_mobile( get_post_meta( $report_id, '_cmi_patient_mobile', true ) );
            return $report_mobile && $report_mobile === CMI_CPT::normalize_mobile( $claim['identity_value'] );
        }

        if ( 'email' === $claim['identity_type'] ) {
            $report_email = get_post_meta( $report_id, '_cmi_patient_email', true );
            return $report_email && 0 === strcasecmp( $report_email, $claim['identity_value'] );
        }

        if ( 'uid' === $claim['identity_type'] ) {
            $report_uid = get_post_meta( $report_id, '_cmi_patient_uid', true );
            return $report_uid && hash_equals( (string) $report_uid, (string) $claim['identity_value'] );
        }

        return false;
    }

    public static function validate_uploaded_file( $file, $allowed_exts = [ 'pdf', 'jpg', 'jpeg', 'png' ], $max_bytes = 10485760 ) {
        if ( empty( $file ) || ! isset( $file['tmp_name'], $file['name'], $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
            return new WP_Error( 'invalid_upload', __( 'Please select a valid file to upload.', 'cmi-partner-portal' ) );
        }

        if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
            return new WP_Error( 'file_too_large', __( 'File size must be under 10 MB.', 'cmi-partner-portal' ) );
        }

        $allowed_mimes = [
            'pdf'  => 'application/pdf',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
        ];
        $allowed_mimes = array_intersect_key( $allowed_mimes, array_flip( $allowed_exts ) );

        $checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
        $ext     = strtolower( $checked['ext'] ?? '' );
        $mime    = strtolower( $checked['type'] ?? '' );

        if ( ! $ext || ! isset( $allowed_mimes[ $ext ] ) || $mime !== strtolower( $allowed_mimes[ $ext ] ) ) {
            return new WP_Error( 'invalid_file_type', __( 'Only valid PDF, JPG, and PNG files are allowed.', 'cmi-partner-portal' ) );
        }

        if ( 'pdf' === $ext ) {
            $fh = @fopen( $file['tmp_name'], 'rb' );
            $sig = $fh ? fread( $fh, 5 ) : '';
            if ( $fh ) {
                fclose( $fh );
            }
            if ( '%PDF-' !== $sig ) {
                return new WP_Error( 'invalid_pdf', __( 'The uploaded PDF file is not valid.', 'cmi-partner-portal' ) );
            }
        } elseif ( ! @getimagesize( $file['tmp_name'] ) ) {
            return new WP_Error( 'invalid_image', __( 'The uploaded image file is not valid.', 'cmi-partner-portal' ) );
        }

        return [
            'ext'  => $ext,
            'mime' => $mime,
            'name' => sanitize_file_name( $file['name'] ),
        ];
    }
}
