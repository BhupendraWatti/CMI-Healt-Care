<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CMI SMS Dynamic Action Listener Engine
 *
 * Automatically binds WordPress action hook listeners on 'init' based on active triggers registered
 * in CMI_SMS_Trigger_Registry and dispatches Airtel DLT SMS.
 */
class CMI_SMS_Listener {

    public static function init() {
        // Register single-shot background cron handler for async trigger actions
        add_action('cmi_async_dispatch_sms_trigger', [__CLASS__, 'async_dispatch_sms_trigger_handler'], 10, 3);

        // Fetch all active triggers from registry
        $triggers = CMI_SMS_Trigger_Registry::get_all_triggers();

        foreach ($triggers as $trigger_id => $config) {
            if (empty($config['enabled']) || $config['enabled'] === 'no' || empty($config['hook_name'])) {
                continue;
            }

            $hook_name     = sanitize_text_field($config['hook_name']);
            $priority      = intval($config['hook_priority'] ?? 10);
            $accepted_args = intval($config['accepted_args'] ?? 1);

            // Bind dynamic action hook listener
            add_action($hook_name, function() use ($trigger_id, $config) {
                $raw_args = func_get_args();
                self::handle_trigger($trigger_id, $config, $raw_args);
            }, $priority, $accepted_args);
        }
    }

    /**
     * Handle incoming action hook trigger execution.
     *
     * @param string $trigger_id Unique trigger identifier.
     * @param array  $config     Trigger configuration definition.
     * @param array  $raw_args   Raw arguments passed to do_action().
     */
    public static function handle_trigger($trigger_id, array $config, array $raw_args) {
        $resolver_type  = $config['resolver'] ?? 'generic';
        $recipient_type = $config['recipient_type'] ?? 'custom';
        $arg_position   = intval($config['arg_position'] ?? 1);
        $template_key   = $config['template_key'] ?? '';
        $is_async       = (!empty($config['async']) && $config['async'] === 'yes');

        if (empty($template_key)) {
            error_log("CMI SMS Listener [{$trigger_id}]: SKIPPED — No DLT template_key mapped to trigger.");
            return;
        }

        // Resolve context (placeholders and recipient phone)
        $resolved = CMI_SMS_Context_Resolver::resolve($resolver_type, $recipient_type, $raw_args, $arg_position);

        if (empty($resolved['mobile'])) {
            error_log("CMI SMS Listener [{$trigger_id}]: SKIPPED — No valid mobile number resolved for recipient_type '{$recipient_type}'.");
            return;
        }

        if ($is_async) {
            // Schedule non-blocking background event
            wp_schedule_single_event(time(), 'cmi_async_dispatch_sms_trigger', [
                $template_key,
                $resolved['mobile'],
                $resolved['placeholders']
            ]);
        } else {
            // Synchronous SMS dispatch via Airtel IQ Gateway
            if (class_exists('CMI_SMS_Manager')) {
                CMI_SMS_Manager::send_event_sms($template_key, $resolved['mobile'], $resolved['placeholders']);
            }
        }
    }

    /**
     * Background cron handler for deferred SMS trigger dispatch.
     *
     * @param string $template_key DLT Event Template Key
     * @param string $mobile Target phone number
     * @param array  $placeholders Dynamic variable mapping array
     */
    public static function async_dispatch_sms_trigger_handler($template_key, $mobile, array $placeholders = []) {
        if (class_exists('CMI_SMS_Manager')) {
            CMI_SMS_Manager::send_event_sms($template_key, $mobile, $placeholders);
        }
    }
}

add_action('init', ['CMI_SMS_Listener', 'init']);
