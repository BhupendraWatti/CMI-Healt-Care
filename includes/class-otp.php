<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CMI OTP Manager
 *
 * Generates, stores, and verifies 6-digit OTP codes for secure guest report
 * access and portal authentication. Dispatches OTP via Airtel IQ DLT SMS API.
 *
 * Provider: Airtel IQ Prepaid SMS (CMIINF | PE: 1101476120000031130)
 */
class CMI_OTP {

    /**
     * Generate a 6-digit OTP, hash and store it in wp_cmi_otp table (10 min TTL).
     *
     * @param string $mobile Normalized 10-digit or 12-digit Indian mobile number.
     * @return string Plain-text OTP to be dispatched via SMS.
     */
    public static function generate( $mobile ) {
        global $wpdb;
        $otp     = str_pad( random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
        $expires = gmdate( 'Y-m-d H:i:s', time() + 600 ); // 10 minutes, UTC

        $table = $wpdb->prefix . 'cmi_otp';
        
        $del = $wpdb->delete( $table, [ 'mobile' => $mobile ] );
        if ( false === $del ) {
            error_log( 'CMI OTP GENERATE: failed to clear prior OTP for ' . CMI_Security::mask_identifier( $mobile ) . ". last_error='" . $wpdb->last_error . "'" );
        }
        
        $ins = $wpdb->insert( $table, [
            'mobile'     => $mobile,
            'otp'        => wp_hash_password( $otp ),
            'expires_at' => $expires,
        ]);
        if ( false === $ins ) {
            error_log( 'CMI OTP GENERATE: failed to store OTP for ' . CMI_Security::mask_identifier( $mobile ) . ". last_error='" . $wpdb->last_error . "'" );
        }

        return $otp;
    }

    /**
     * Verify OTP code for the given mobile number. Deletes record on success.
     *
     * @param string $mobile Mobile number used during generation.
     * @param string $otp    The 6-digit OTP code entered by the user.
     * @return bool True if OTP is valid and not expired; false otherwise.
     */
    public static function verify( $mobile, $otp ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_otp';
        $now   = gmdate( 'Y-m-d H:i:s' );
        $otp   = trim( (string) $otp );
        
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE mobile = %s AND expires_at > %s ORDER BY id DESC LIMIT 1",
            $mobile,
            $now
        ));

        if ( ! $row ) {
            $latest = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE mobile = %s ORDER BY id DESC LIMIT 1", $mobile ) );
            if ( $latest ) {
                error_log( 'CMI OTP VERIFY FAILED: expired OTP for ' . CMI_Security::mask_identifier( $mobile ) . '.' );
            } else {
                error_log( 'CMI OTP VERIFY FAILED: no OTP record for ' . CMI_Security::mask_identifier( $mobile ) . '.' );
            }
            return false;
        }

        $valid = wp_check_password( $otp, $row->otp );
        if ( $valid ) {
            error_log( 'CMI OTP VERIFY SUCCESS for ' . CMI_Security::mask_identifier( $mobile ) . '.' );
            $wpdb->delete( $table, [ 'id' => $row->id ] );
        } else {
            error_log( 'CMI OTP VERIFY FAILED: hash mismatch for ' . CMI_Security::mask_identifier( $mobile ) . '.' );
        }
        return $valid;
    }

    /**
     * Send OTP via Airtel IQ DLT SMS API.
     *
     * Uses the otp_access DLT template configured in Admin > CMI Portal > SMS Settings.
     * Falls back to logging OTP if template is not configured (development mode).
     *
     * @param string $mobile 10-digit mobile number (will be formatted to 12-digit before sending).
     * @param string $otp    The plain-text OTP to include in the message.
     * @return bool True if SMS was dispatched successfully, false on failure.
     */
    public static function send( $mobile, $otp ) {
        // Use Airtel IQ DLT SMS — sole configured SMS provider for CMI Healthcare.
        // DLT Template for OTP access is configured under Admin > CMI Portal > SMS Settings > otp_access.
        $result = CMI_SMS_Manager::send_event_sms( 'otp_access', $mobile, [
            'otp'    => $otp,
            'mobile' => $mobile,
        ]);

        if ( $result && ! empty( $result['success'] ) ) {
            error_log( "CMI OTP SENT OK [{$mobile}] via send_event_sms (otp_access template)." );
            return true;
        }

        // Log why send_event_sms failed (template or message might be empty in admin)
        error_log( "CMI OTP send_event_sms('otp_access') failed for [{$mobile}]. Trying direct fallback." );

        // Fallback: use the DLT-approved message text stored in DB option
        // DO NOT use hardcoded non-DLT message — Airtel will reject it.
        $dlt_tmpl_id = get_option( 'cmi_dlt_tmpl_otp_access', get_option( 'cmi_dlt_tmpl_otp', '' ) );
        $dlt_msg     = get_option( 'cmi_dlt_msg_otp_access', '' );

        // Interpolate {otp} placeholder in the DLT-approved message text
        if ( ! empty( $dlt_msg ) ) {
            $dlt_msg = str_replace( '{otp}', $otp, $dlt_msg );
        } else {
            // If no message is configured at all, log and fail — do NOT use hardcoded text.
            error_log( "CMI OTP FAILED [{$mobile}]: No DLT message text configured for otp_access. Go to Admin > CMI Portal > SMS Settings and set the OTP message text." );
            return false;
        }

        if ( empty( $dlt_tmpl_id ) ) {
            error_log( "CMI OTP FAILED [{$mobile}]: No DLT Template ID configured for otp_access. Go to Admin > CMI Portal > SMS Settings and set the OTP Template ID." );
            return false;
        }

        $direct = CMI_SMS_Manager::send_sms( $mobile, $dlt_msg, $dlt_tmpl_id );

        if ( ! empty( $direct['success'] ) ) {
            error_log( "CMI OTP SENT OK [{$mobile}] via direct send_sms fallback. Template ID: {$dlt_tmpl_id}" );
            return true;
        }

        $fail_reason = $direct['message'] ?? 'Unknown Airtel API error';
        error_log( "CMI OTP GATEWAY FAILED [{$mobile}]: {$fail_reason}. Template ID: {$dlt_tmpl_id}" );
        return false;
    }
}
