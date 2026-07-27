<?php
require_once 'D:/xampp/htdocs/plugin_testing/wp-load.php';

$mobile_raw = '6261200968';
$norm = CMI_SMS_Manager::format_mobile($mobile_raw);

echo "1. Generating OTP for {$norm}...\n";
$otp = CMI_OTP::generate($norm);
echo "   Generated OTP: {$otp}\n";

global $wpdb;
$table = $wpdb->prefix . 'cmi_otp';
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE mobile = %s ORDER BY id DESC LIMIT 1", $norm));
echo "   DB Row:\n";
print_r($row);

$now = gmdate('Y-m-d H:i:s');
echo "   Current GM Date (\$now): {$now}\n";
echo "   Expires at in DB      : {$row->expires_at}\n";
echo "   Is expires_at > \$now ? " . ($row->expires_at > $now ? 'YES' : 'NO') . "\n";

echo "2. Verifying OTP {$otp}...\n";
$verified = CMI_OTP::verify($norm, $otp);
echo "   Verify result: " . ($verified ? 'SUCCESS ✅' : 'FAILED ❌') . "\n";
