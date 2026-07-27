<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CMI SMS Dynamic Trigger Registry Store
 *
 * Manages dynamic mappings between WordPress Action Hooks and Airtel DLT SMS Templates.
 */
class CMI_SMS_Trigger_Registry {

    private static $option_key = 'cmi_sms_dynamic_triggers';

    /**
     * Get all registered dynamic trigger mappings.
     * Merges user-configured DB triggers with default seed triggers.
     *
     * @return array Array of trigger definition arrays.
     */
    public static function get_all_triggers() {
        $saved = get_option(self::$option_key, false);
        if (is_array($saved) && !empty($saved)) {
            return $saved;
        }

        $defaults = self::get_default_triggers();
        update_option(self::$option_key, $defaults);
        return $defaults;
    }

    /**
     * Get single trigger definition by ID.
     *
     * @param string $trigger_id Trigger ID.
     * @return array|null Trigger definition or null.
     */
    public static function get_trigger($trigger_id) {
        $all = self::get_all_triggers();
        return $all[$trigger_id] ?? null;
    }

    /**
     * Seed default triggers for core plugin and WordPress action hooks.
     *
     * @return array Default triggers mapping array.
     */
    public static function get_default_triggers() {
        return [
            'trig_user_register' => [
                'trigger_id'     => 'trig_user_register',
                'title'          => __('User Account Registration Welcome', 'cmi-partner-portal'),
                'desc'           => __('Fired when a new user account is created on WordPress or WooCommerce checkout.', 'cmi-partner-portal'),
                'hook_name'      => 'user_register',
                'hook_priority'  => 10,
                'accepted_args'  => 1,
                'template_key'   => 'welcome_user',
                'recipient_type' => 'user_id',
                'arg_position'   => 1,
                'resolver'       => 'user',
                'enabled'        => 'yes',
                'async'          => 'no',
            ],
            'trig_consultation_scheduled' => [
                'trigger_id'     => 'trig_consultation_scheduled',
                'title'          => __('Doctor Video Consultation Booked/Scheduled', 'cmi-partner-portal'),
                'desc'           => __('Fired when a telemedicine appointment slot is confirmed for a patient.', 'cmi-partner-portal'),
                'hook_name'      => 'cmi_consultation_scheduled',
                'hook_priority'  => 10,
                'accepted_args'  => 1,
                'template_key'   => 'consultation_scheduled',
                'recipient_type' => 'consultation_patient',
                'arg_position'   => 1,
                'resolver'       => 'consultation',
                'enabled'        => 'yes',
                'async'          => 'no',
            ],
            'trig_consultation_assigned' => [
                'trigger_id'     => 'trig_consultation_assigned',
                'title'          => __('Doctor Assigned to Consultation (Doctor Notice)', 'cmi-partner-portal'),
                'desc'           => __('Fired when admin assigns a doctor to an existing consultation session.', 'cmi-partner-portal'),
                'hook_name'      => 'cmi_consultation_assigned',
                'hook_priority'  => 10,
                'accepted_args'  => 2,
                'template_key'   => 'consultation_assigned',
                'recipient_type' => 'consultation_doctor',
                'arg_position'   => 1,
                'resolver'       => 'consultation',
                'enabled'        => 'yes',
                'async'          => 'no',
            ],
            'trig_testing_partner_assigned' => [
                'trigger_id'     => 'trig_testing_partner_assigned',
                'title'          => __('Home Collection Job Assigned to Partner', 'cmi-partner-portal'),
                'desc'           => __('Fired when a diagnostic lab/partner is assigned a home sample collection order.', 'cmi-partner-portal'),
                'hook_name'      => 'cmi_testing_partner_assigned',
                'hook_priority'  => 10,
                'accepted_args'  => 3,
                'template_key'   => 'partner_assigned',
                'recipient_type' => 'partner_id',
                'arg_position'   => 2,
                'resolver'       => 'home_testing',
                'enabled'        => 'yes',
                'async'          => 'no',
            ],
            'trig_testing_assignment_accepted' => [
                'trigger_id'     => 'trig_testing_assignment_accepted',
                'title'          => __('Partner Accepted Home Collection Job (Customer Notice)', 'cmi-partner-portal'),
                'desc'           => __('Fired when a diagnostic partner accepts the collection job in partner portal.', 'cmi-partner-portal'),
                'hook_name'      => 'cmi_testing_assignment_accepted',
                'hook_priority'  => 10,
                'accepted_args'  => 2,
                'template_key'   => 'partner_accepted',
                'recipient_type' => 'testing_patient',
                'arg_position'   => 1,
                'resolver'       => 'home_testing',
                'enabled'        => 'yes',
                'async'          => 'no',
            ],
            'trig_testing_report_uploaded' => [
                'trigger_id'     => 'trig_testing_report_uploaded',
                'title'          => __('Medical Test Report PDF Uploaded & Ready', 'cmi-partner-portal'),
                'desc'           => __('Fired when lab partner uploads a verified patient PDF report.', 'cmi-partner-portal'),
                'hook_name'      => 'cmi_testing_report_uploaded',
                'hook_priority'  => 10,
                'accepted_args'  => 2,
                'template_key'   => 'report_uploaded',
                'recipient_type' => 'testing_patient',
                'arg_position'   => 1,
                'resolver'       => 'home_testing',
                'enabled'        => 'yes',
                'async'          => 'no',
            ],
            'trig_wc_order_completed' => [
                'trigger_id'     => 'trig_wc_order_completed',
                'title'          => __('WooCommerce Checkout / Package Order Completed', 'cmi-partner-portal'),
                'desc'           => __('Fired when a WooCommerce order status changes to Completed.', 'cmi-partner-portal'),
                'hook_name'      => 'woocommerce_order_status_completed',
                'hook_priority'  => 10,
                'accepted_args'  => 1,
                'template_key'   => 'booking_confirmed',
                'recipient_type' => 'wc_order_billing',
                'arg_position'   => 1,
                'resolver'       => 'order',
                'enabled'        => 'yes',
                'async'          => 'no',
            ],
        ];
    }

    /**
     * Save or update a trigger definition.
     *
     * @param array $data Trigger parameters.
     * @return string Trigger ID.
     */
    public static function save_trigger($data) {
        $triggers = self::get_all_triggers();
        $id = !empty($data['trigger_id']) ? sanitize_key($data['trigger_id']) : 'trig_' . time() . '_' . rand(100, 999);

        $triggers[$id] = [
            'trigger_id'     => $id,
            'title'          => sanitize_text_field($data['title'] ?? $id),
            'desc'           => sanitize_text_field($data['desc'] ?? ''),
            'hook_name'      => sanitize_text_field($data['hook_name'] ?? ''),
            'hook_priority'  => intval($data['hook_priority'] ?? 10),
            'accepted_args'  => intval($data['accepted_args'] ?? 1),
            'template_key'   => sanitize_text_field($data['template_key'] ?? ''),
            'recipient_type' => sanitize_text_field($data['recipient_type'] ?? 'custom'),
            'arg_position'   => intval($data['arg_position'] ?? 1),
            'resolver'       => sanitize_text_field($data['resolver'] ?? 'generic'),
            'enabled'        => (!empty($data['enabled']) && $data['enabled'] !== 'no') ? 'yes' : 'no',
            'async'          => (!empty($data['async']) && $data['async'] === 'yes') ? 'yes' : 'no',
        ];

        update_option(self::$option_key, $triggers);
        return $id;
    }

    /**
     * Delete a trigger definition.
     *
     * @param string $trigger_id Trigger ID.
     * @return bool True if found and deleted.
     */
    public static function delete_trigger($trigger_id) {
        $triggers = self::get_all_triggers();
        if (isset($triggers[$trigger_id])) {
            unset($triggers[$trigger_id]);
            update_option(self::$option_key, $triggers);
            return true;
        }
        return false;
    }
}
