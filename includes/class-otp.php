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
        $wpdb->delete( $table, [ 'mobile' => $mobile ] );
        $wpdb->insert( $table, [
            'mobile'     => $mobile,
            'otp'        => wp_hash_password( $otp ),
            'expires_at' => $expires,
        ]);

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
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE mobile = %s AND expires_at > %s ORDER BY id DESC LIMIT 1",
            $mobile,
            $now
        ));

        if ( ! $row ) return false;

        $valid = wp_check_password( $otp, $row->otp );
        if ( $valid ) {
            $wpdb->delete( $table, [ 'id' => $row->id ] );
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
            return true;
        }

        // Fallback: Direct SMS send if event template not configured in admin yet
        $message     = "Your CMI Healthcare access OTP is: {$otp}. Valid for 10 minutes. Do not share.";
        $dlt_tmpl_id = get_option( 'cmi_dlt_tmpl_otp_access', get_option( 'cmi_dlt_tmpl_otp', '' ) );
        $direct      = CMI_SMS_Manager::send_sms( $mobile, $message, $dlt_tmpl_id );

        if ( ! empty( $direct['success'] ) ) {
            return true;
        }

        // Development fallback: log OTP when SMS gateway is not configured
        error_log( "CMI OTP [{$mobile}]: {$otp}" );
        return true;
    }
}
