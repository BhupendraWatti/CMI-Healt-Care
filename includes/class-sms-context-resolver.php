<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CMI SMS Context Resolver Engine
 *
 * Inspects raw arguments passed by WordPress action hooks (User IDs, Order IDs, Consultation IDs)
 * and resolves variable placeholders ({name}, {doctor_name}, {slot}) and target destination mobile numbers.
 */
class CMI_SMS_Context_Resolver {

    /**
     * Resolve placeholders and recipient mobile from hook arguments.
     *
     * @param string $resolver_type Type of entity resolver ('user', 'consultation', 'home_testing', 'order', 'generic')
     * @param string $recipient_type Destination target rule ('user_id', 'consultation_patient', 'consultation_doctor', 'partner_id', 'testing_patient', 'wc_order_billing', 'custom')
     * @param array  $hook_args Raw argument array supplied by do_action()
     * @param int    $arg_position 1-indexed primary argument position
     * @return array Array with 'placeholders' (array) and 'mobile' (string)
     */
    public static function resolve($resolver_type, $recipient_type = 'custom', array $hook_args = [], $arg_position = 1) {
        $idx = max(0, $arg_position - 1);
        $primary_arg = $hook_args[$idx] ?? null;

        $data = [
            'placeholders' => [],
            'mobile'       => '',
        ];

        switch ($resolver_type) {
            case 'user':
                $user_id = is_numeric($primary_arg) ? intval($primary_arg) : 0;
                if ($user_id > 0) {
                    $user = get_userdata($user_id);
                    if ($user) {
                        $mobile = get_user_meta($user_id, '_cmi_mobile', true) ?: get_user_meta($user_id, 'billing_phone', true);
                        $data['mobile'] = $mobile;
                        $data['placeholders'] = [
                            'name'     => $user->display_name ?: $user->user_login,
                            'email'    => $user->user_email,
                            'mobile'   => $mobile,
                            'user_id'  => $user_id,
                            'date'     => date_i18n(get_option('date_format')),
                        ];
                    }
                }
                break;

            case 'consultation':
                $consultation_id = is_numeric($primary_arg) ? intval($primary_arg) : 0;
                if ($consultation_id > 0) {
                    global $wpdb;
                    $table = $wpdb->prefix . 'cmi_consultations';
                    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $consultation_id));
                    if ($row) {
                        $user_id = intval($row->user_id ?? 0);
                        $patient = get_userdata($user_id);
                        $doctor  = !empty($row->doctor_id) ? get_userdata($row->doctor_id) : null;

                        $patient_name = !empty($row->patient_name) ? $row->patient_name : ($patient ? $patient->display_name : __('Patient', 'cmi-partner-portal'));
                        $doctor_display_name = $doctor ? preg_replace('/^Dr\.\s*/i', '', $doctor->display_name) : __('Doctor', 'cmi-partner-portal');
                        $doctor_full_title   = $doctor ? (strpos($doctor->display_name, 'Dr.') === 0 ? $doctor->display_name : 'Dr. ' . $doctor->display_name) : __('Doctor', 'cmi-partner-portal');

                        $patient_mobile = !empty($row->patient_mobile) ? $row->patient_mobile : (get_user_meta($user_id, '_cmi_mobile', true) ?: get_user_meta($user_id, 'billing_phone', true));
                        $doctor_mobile  = $doctor ? (get_user_meta($row->doctor_id, '_cmi_mobile', true) ?: get_user_meta($row->doctor_id, 'billing_phone', true)) : '';

                        if ($recipient_type === 'consultation_doctor') {
                            $data['mobile'] = $doctor_mobile;
                        } else {
                            $data['mobile'] = $patient_mobile;
                        }

                        $data['placeholders'] = [
                            'id'          => $consultation_id,
                            'name'        => $patient_name,
                            'patient_name'=> $patient_name,
                            'doctor'      => $doctor_display_name, // e.g. "John Doe" (so "Dr.  {doctor}" in DLT template resolves to "Dr.  John Doe")
                            'doctor_name' => $doctor_full_title,
                            'slot'        => !empty($row->preferred_time_slot) ? $row->preferred_time_slot : (!empty($row->slot_time) ? date_i18n('d M Y, h:i A', strtotime($row->slot_time)) : __('Scheduled Slot', 'cmi-partner-portal')),
                            'date'        => !empty($row->preferred_date) ? date_i18n(get_option('date_format'), strtotime($row->preferred_date)) : (!empty($row->slot_time) ? date_i18n(get_option('date_format'), strtotime($row->slot_time)) : date_i18n(get_option('date_format'))),
                            'status'      => $row->status ?? 'scheduled',
                        ];
                    }
                }
                break;

            case 'home_testing':
                $ht_id = is_numeric($primary_arg) ? intval($primary_arg) : 0;
                if ($ht_id > 0) {
                    global $wpdb;
                    $table = $wpdb->prefix . 'cmi_home_testing';
                    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $ht_id));
                    if ($row) {
                        $order = function_exists('wc_get_order') ? wc_get_order($row->order_id) : null;
                        $patient_name = $order ? ($order->get_meta('_cmi_patient_name') ?: ($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())) : __('Patient', 'cmi-partner-portal');
                        $patient_mobile = $order ? ($order->get_meta('_cmi_patient_mobile') ?: $order->get_billing_phone()) : '';

                        $partner_id = !empty($hook_args[1]) && is_numeric($hook_args[1]) ? intval($hook_args[1]) : $row->partner_id;
                        $partner = get_userdata($partner_id);
                        $partner_name = $partner ? $partner->display_name : __('Medical Partner', 'cmi-partner-portal');
                        $partner_org  = $partner ? get_user_meta($partner_id, '_cmi_org', true) : '';
                        $partner_display = !empty($partner_org) ? $partner_org : $partner_name;
                        $partner_mobile  = $partner ? (get_user_meta($partner_id, '_cmi_mobile', true) ?: get_user_meta($partner_id, 'billing_phone', true)) : '';

                        if ($recipient_type === 'partner_id') {
                            $data['mobile'] = $partner_mobile;
                        } else {
                            $data['mobile'] = $patient_mobile;
                        }

                        $data['placeholders'] = [
                            'name'         => $patient_name,
                            'patient_name' => $patient_name,
                            'partner'      => $partner_display,
                            'partner_name' => $partner_display,
                            'order_id'     => $row->order_id,
                            'id'           => $row->order_id,
                            'date'         => !empty($row->collection_date) ? date_i18n(get_option('date_format'), strtotime($row->collection_date)) : '',
                            'slot'         => $row->collection_time_slot ?? '',
                        ];
                    }
                }
                break;

            case 'order':
                $order_id = is_numeric($primary_arg) ? intval($primary_arg) : 0;
                if ($order_id > 0 && function_exists('wc_get_order')) {
                    $order = wc_get_order($order_id);
                    if ($order) {
                        $patient_name = $order->get_meta('_cmi_patient_name') ?: ($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                        $patient_mobile = $order->get_meta('_cmi_patient_mobile') ?: $order->get_billing_phone();

                        $data['mobile'] = $patient_mobile;
                        $data['placeholders'] = [
                            'name'     => $patient_name ?: __('Customer', 'cmi-partner-portal'),
                            'order_id' => $order_id,
                            'id'       => $order_id,
                            'total'    => $order->get_total(),
                            'date'     => date_i18n(get_option('date_format'), strtotime($order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : 'now')),
                            'slot'     => $order->get_meta('_cmi_booking_slot') ?: __('Scheduled Slot', 'cmi-partner-portal'),
                        ];
                    }
                }
                break;

            case 'generic':
            default:
                // If first arg is an associative array of placeholders
                if (is_array($primary_arg)) {
                    $data['placeholders'] = $primary_arg;
                    $data['mobile']       = $primary_arg['mobile'] ?? ($primary_arg['phone'] ?? ($hook_args[1] ?? ''));
                } elseif (!empty($primary_arg) && is_string($primary_arg)) {
                    // Primitive argument passed
                    $data['placeholders'] = [
                        'value' => $primary_arg,
                        'name'  => $primary_arg,
                    ];
                    $data['mobile'] = $hook_args[1] ?? '';
                }
                break;
        }

        return apply_filters('cmi_sms_resolved_context', $data, $resolver_type, $recipient_type, $hook_args);
    }
}
