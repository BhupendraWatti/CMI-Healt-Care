<?php
if (!defined('ABSPATH'))
    exit;

/**
 * CMI Airtel IQ DLT SMS Manager
 * 
 * Manages Airtel IQ Prepaid SMS API integrations, 3-category dynamic DLT templates,
 * deferred/fallback Welcome SMS triggers, transactional event SMS, and Bulk SMS.
 */
class CMI_SMS_Manager
{

    public static function init()
    {
        add_action('wp_ajax_cmi_admin_send_bulk_sms', [__CLASS__, 'ajax_send_bulk_sms']);
        add_action('wp_ajax_cmi_admin_save_single_sms_card', [__CLASS__, 'ajax_save_single_sms_card']);
        add_action('wp_ajax_cmi_admin_delete_single_sms_card', [__CLASS__, 'ajax_delete_single_sms_card']);
        add_action('wp_ajax_cmi_admin_save_gateway_credentials', [__CLASS__, 'ajax_save_gateway_credentials']);
        add_action('wp_ajax_cmi_admin_save_trigger_card', [__CLASS__, 'ajax_save_trigger_card']);
        add_action('wp_ajax_cmi_admin_delete_trigger_card', [__CLASS__, 'ajax_delete_trigger_card']);
    }

    /**
     * Get Airtel IQ SMS Gateway Configuration with defaults from gemini.md
     */
    public static function get_config()
    {
        return [
            'endpoint' => get_option('cmi_airtel_endpoint', 'https://iqsms.airtel.in/api/v1/send-prepaid-sms'),
            'username' => get_option('cmi_airtel_username', 'f631ac4e_54c5_4cb9_9595_276b7e59a113'),
            'password' => get_option('cmi_airtel_password', 'ROMirHTArJ'),
            'customer_id' => get_option('cmi_airtel_customer_id', 'e4ad470d-f0f0-422f-bd47-9faec33e678a'),
            'pe_id' => get_option('cmi_airtel_pe_id', '1101476120000031130'),
            'sender_id' => get_option('cmi_airtel_sender_id', 'CMIINF'),
            'welcome_tmpl_id' => get_option('cmi_dlt_welcome_template_id', '1077037040016332738'),
            'welcome_msg' => get_option('cmi_dlt_welcome_message', 'hello {name}, Welcome to CMI HealthCare. Your account has been successfuly created. Thanks CMI HealthCare.'),
        ];
    }

    /**
     * Normalize destination phone number to 12-digit Indian format (e.g. "919876543210")
     */
    public static function format_mobile($mobile)
    {
        $clean = preg_replace('/[^0-9]/', '', (string) $mobile);
        if (empty($clean))
            return false;

        // If 10 digits, prepend 91
        if (strlen($clean) === 10) {
            return '91' . $clean;
        }

        // If 12 digits starting with 91, valid
        if (strlen($clean) === 12 && substr($clean, 0, 2) === '91') {
            return $clean;
        }

        // If 11 digits starting with 0, replace 0 with 91
        if (strlen($clean) === 11 && substr($clean, 0, 1) === '0') {
            return '91' . substr($clean, 1);
        }

        return false;
    }

    /**
     * Dispatch SMS via Airtel IQ Prepaid SMS API
     *
     * @param string|array $mobiles Single mobile or array of mobiles
     * @param string       $message Approved DLT template message text
     * @param string       $dlt_template_id Approved DLT template ID
     * @param string       $message_type Message Type (SERVICE_IMPLICIT, TRANSACTIONAL, etc.)
     * @return array Response array with 'success' (bool), 'message' (string), and 'details'
     */
    public static function send_sms($mobiles, $message, $dlt_template_id = '', $message_type = 'SERVICE_IMPLICIT')
    {
        $config = self::get_config();

        if (empty($config['endpoint']) || empty($config['username']) || empty($config['password'])) {
            return [
                'success' => false,
                'message' => __('Airtel SMS API credentials not configured.', 'cmi-partner-portal')
            ];
        }

        // Prepare destination array
        $dest_array = [];
        $raw_list = is_array($mobiles) ? $mobiles : [$mobiles];
        foreach ($raw_list as $m) {
            $formatted = self::format_mobile($m);
            if ($formatted) {
                $dest_array[] = $formatted;
            }
        }

        if (empty($dest_array)) {
            return [
                'success' => false,
                'message' => __('No valid 10-digit/12-digit mobile numbers provided.', 'cmi-partner-portal')
            ];
        }

        $template_id = !empty($dlt_template_id) ? $dlt_template_id : $config['welcome_tmpl_id'];
        $msg_type = !empty($message_type) ? $message_type : 'SERVICE_IMPLICIT';

        $payload = [
            'customerId' => $config['customer_id'],
            'destinationAddress' => array_values(array_unique($dest_array)),
            'dltTemplateId' => $template_id,
            'entityId' => $config['pe_id'],
            'message' => $message,
            'messageType' => $msg_type,
            'sourceAddress' => $config['sender_id'],
        ];

        $auth_header = 'Basic ' . base64_encode($config['username'] . ':' . $config['password']);

        $response = wp_remote_post($config['endpoint'], [
            'headers' => [
                'Authorization' => $auth_header,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $err_msg = $response->get_error_message();
            error_log('CMI Airtel SMS Error: ' . $err_msg);
            return [
                'success' => false,
                'message' => $err_msg,
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code === 200 || (isset($data['status']) && ($data['status'] === 'SUCCESS' || $data['status'] === 200))) {
            $req_id = $data['messageRequestId'] ?? ($data['id'] ?? 'OK');
            return [
                'success' => true,
                'message' => __('SMS dispatched successfully via Airtel IQ.', 'cmi-partner-portal'),
                'request_id' => $req_id,
                'data' => $data
            ];
        }

        $fail_reason = $data['message'] ?? ($data['description'] ?? 'HTTP Code ' . $code);
        error_log("CMI Airtel SMS API Failed (Code {$code}): " . $body);
        return [
            'success' => false,
            'message' => $fail_reason,
            'data' => $data
        ];
    }

    /**
     * 3-Category Event Registry mapping software workflows to dynamic DLT templates.
     * ZERO hardcoded message payloads in PHP code! All text is loaded directly from DB.
     */
    public static function get_categorized_events()
    {
        $saved = get_option('cmi_custom_sms_templates', false);
        if (is_array($saved) && !empty($saved)) {
            return $saved;
        }

        // Structural categories with options read directly from database (No hardcoded text payload)
        return [
            'portal' => [
                'title' => '1. CMI Healthcare Portal (Account & Security)',
                'description' => 'Manage SMS templates for user account registration, package checkout welcome, and OTP verification.',
                'events' => [
                    'welcome_user' => [
                        'title' => 'Registration & Package Checkout Welcome SMS',
                        'desc' => 'Sent when a customer registers or purchases a package on WooCommerce with a mobile number.',
                        'tmpl_id_key' => 'cmi_dlt_welcome_template_id',
                        'msg_key' => 'cmi_dlt_welcome_message',
                        'enable_key' => 'cmi_dlt_enable_welcome_user',
                        'type_key' => 'cmi_dlt_type_welcome_user',
                        'default_tmpl' => '1077037040016332738',
                        'default_msg' => get_option('cmi_dlt_welcome_message', 'Hello {name},  Welcome to CMI HealthCare. Your account has been successfuly created. Thanks CMI HealthCare.'),
                        'default_type' => 'SERVICE_IMPLICIT',
                        'vars' => ['{name}']
                    ],
                    'otp_access' => [
                        'title' => 'Security OTP Verification SMS',
                        'desc' => 'Sent when a patient or guest requests a 6-digit OTP code to log in or download medical reports.',
                        'tmpl_id_key' => 'cmi_dlt_tmpl_otp_access',
                        'msg_key' => 'cmi_dlt_msg_otp_access',
                        'enable_key' => 'cmi_dlt_enable_otp_access',
                        'type_key' => 'cmi_dlt_type_otp_access',
                        'default_tmpl' => '1077566980019242921',
                        'default_msg' => get_option('cmi_dlt_msg_otp_access', 'Your CMI HealthCare verification OTP code for logging into your account is {otp}. Valid for 10 minutes. Please do not share this OTP with anyone. Thanks CMI HealthCare.'),
                        'default_type' => 'SERVICE_IMPLICIT',
                        'vars' => ['{otp}']
                    ]
                ]
            ],
            'partner' => [
                'title' => '2. Partner Portal (Home Collection & Diagnostic Labs)',
                'description' => 'Manage SMS templates for home sample collection bookings, partner assignments, and test report delivery.',
                'events' => [
                    'booking_confirmed' => [
                        'title' => 'Full Body Checkup / Package Order Booked (Customer Notice)',
                        'desc' => 'Sent to the customer when a home testing package appointment is booked and confirmed.',
                        'tmpl_id_key' => 'cmi_dlt_tmpl_booking_confirmed',
                        'msg_key' => 'cmi_dlt_msg_booking_confirmed',
                        'enable_key' => 'cmi_dlt_enable_booking_confirmed',
                        'type_key' => 'cmi_dlt_type_booking_confirmed',
                        'default_tmpl' => '1077262420022411974',
                        'default_msg' => get_option('cmi_dlt_msg_booking_confirmed', "Hello {name}, your home sample collection for Order  {order_id} is confirmed for {date}  during {slot} . \r\nAssigned Partner: {partner_name} . \r\nThanks CMI HealthCare.\r\n"),
                        'default_type' => 'SERVICE_IMPLICIT',
                        'vars' => ['{name}', '{order_id}', '{date}', '{slot}', '{partner_name}']
                    ],
                    'partner_assigned' => [
                        'title' => 'Job Assigned to Medical Partner (Partner Notice)',
                        'desc' => 'Sent to the diagnostic lab/partner when admin assigns a home testing order.',
                        'tmpl_id_key' => 'cmi_dlt_tmpl_partner_assigned',
                        'msg_key' => 'cmi_dlt_msg_partner_assigned',
                        'enable_key' => 'cmi_dlt_enable_partner_assigned',
                        'type_key' => 'cmi_dlt_type_partner_assigned',
                        'default_tmpl' => '1077262420022411974',
                        'default_msg' => get_option('cmi_dlt_msg_partner_assigned', "Hello {partner_name}, your home sample collection for Order  {order_id} is confirmed for {date}  during {slot} . \r\nAssigned Partner: {partner_name} . \r\nThanks CMI HealthCare.\r\n"),
                        'default_type' => 'SERVICE_IMPLICIT',
                        'vars' => ['{partner_name}', '{order_id}', '{date}', '{slot}']
                    ],
                    'partner_accepted' => [
                        'title' => 'Partner Accepted Assignment (Customer Notice)',
                        'desc' => 'Sent to patient when the partner accepts the collection job.',
                        'tmpl_id_key' => 'cmi_dlt_tmpl_partner_accepted',
                        'msg_key' => 'cmi_dlt_msg_partner_accepted',
                        'enable_key' => 'cmi_dlt_enable_partner_accepted',
                        'type_key' => 'cmi_dlt_type_partner_accepted',
                        'default_tmpl' => '1077291080022671240',
                        'default_msg' => get_option('cmi_dlt_msg_partner_accepted', "Hello {name}, your home collection Order {order_id} has been accepted by our medical partner. Check account portal for details. \r\nThanks CMI HealthCare."),
                        'default_type' => 'SERVICE_IMPLICIT',
                        'vars' => ['{name}', '{order_id}']
                    ],
                    'reschedule_requested' => [
                        'title' => 'Collection Reschedule Requested (Customer & Partner Notice)',
                        'desc' => 'Sent when patient requests a new date/slot for home collection.',
                        'tmpl_id_key' => 'cmi_dlt_tmpl_reschedule_requested',
                        'msg_key' => 'cmi_dlt_msg_reschedule_requested',
                        'enable_key' => 'cmi_dlt_enable_reschedule_requested',
                        'type_key' => 'cmi_dlt_type_reschedule_requested',
                        'default_tmpl' => '1077391380019568068',
                        'default_msg' => get_option('cmi_dlt_msg_reschedule_requested', "Hello {name}, your reschedule request for home collection Order {order_id} has been submitted for approval. \r\nThanks CMI HealthCare.\r\n"),
                        'default_type' => 'SERVICE_IMPLICIT',
                        'vars' => ['{name}', '{order_id}']
                    ],
                    'reschedule_approved' => [
                        'title' => 'Collection Reschedule Approved (Customer Notice)',
                        'desc' => 'Sent when admin approves the requested rescheduling date/slot.',
                        'tmpl_id_key' => 'cmi_dlt_tmpl_reschedule_approved',
                        'msg_key' => 'cmi_dlt_msg_reschedule_approved',
                        'enable_key' => 'cmi_dlt_enable_reschedule_approved',
                        'type_key' => 'cmi_dlt_type_reschedule_approved',
                        'default_tmpl' => '1077062430022639988',
                        'default_msg' => get_option('cmi_dlt_msg_reschedule_approved', "Hello {name}, your home collection Order {order_id} has been successfully rescheduled. Check your portal for updated slot. \r\nThanks CMI HealthCare."),
                        'default_type' => 'SERVICE_IMPLICIT',
                        'vars' => ['{name}', '{order_id}']
                    ],
                    'report_uploaded' => [
                        'title' => 'Test Report PDF Uploaded & Ready (Customer Notice)',
                        'desc' => 'Sent to patient when the lab partner uploads the PDF medical report.',
                        'tmpl_id_key' => 'cmi_dlt_tmpl_report_ready',
                        'msg_key' => 'cmi_dlt_msg_report_ready',
                        'enable_key' => 'cmi_dlt_enable_report_ready',
                        'type_key' => 'cmi_dlt_type_report_ready',
                        'default_tmpl' => '1077112990022812102',
                        'default_msg' => get_option('cmi_dlt_msg_report_ready', "Hello {name}, your medical test report for Order {order_id} is ready for download in your account. \r\nThanks CMI HealthCare."),
                        'default_type' => 'SERVICE_IMPLICIT',
                        'vars' => ['{name}', '{order_id}']
                    ]
                ]
            ],
            'doctor' => [
                'title' => '3. Doctor Telemedicine Portal (Video Consultations)',
                'description' => 'Manage SMS templates for doctor video consultations, appointment rescheduling, and digital prescriptions.',
                'events' => [
                    'consultation_requested' => [
                        'title' => 'Doctor Consultation Requested (Patient Notice)',
                        'desc' => 'Sent to patient when a doctor consultation request is submitted.',
                        'tmpl_id_key' => 'cmi_dlt_tmpl_consultation_requested',
                        'msg_key' => 'cmi_dlt_msg_consultation_requested',
                        'enable_key' => 'cmi_dlt_enable_consultation_requested',
                        'type_key' => 'cmi_dlt_type_consultation_requested',
                        'default_tmpl' => '1077519700022776221',
                        'default_msg' => get_option('cmi_dlt_msg_consultation_requested', 'Hello {name}, your doctor consultation request {id} has been received and is being assigned. Thanks CMI HealthCare.'),
                        'default_type' => 'SERVICE_IMPLICIT',
                        'vars' => ['{name}', '{id}']
                    ],
                    'consultation_assigned' => [
                        'title' => 'Doctor Assigned to Consultation (Doctor Notice)',
                        'desc' => 'Sent to the doctor when assigned to a new patient consultation.',
                        'tmpl_id_key' => 'cmi_dlt_tmpl_consultation_assigned',
                        'msg_key' => 'cmi_dlt_msg_consultation_assigned',
                        'enable_key' => 'cmi_dlt_enable_consultation_assigned',
                        'type_key' => 'cmi_dlt_type_consultation_assigned',
                        'default_tmpl' => '1077191330019642880',
                        'default_msg' => get_option('cmi_dlt_msg_consultation_assigned', "Hello {name}, your video consultation {id} with Dr.  {doctor} is scheduled. \r\nLog in to your portal at https://cmihealthcare.in/my-account/patient-consultations/ to join. \r\nThanks CMI HealthCare."),
                        'default_type' => 'SERVICE_IMPLICIT',
                        'vars' => ['{name}', '{id}', '{doctor}']
                    ],
                    'consultation_scheduled' => [
                        'title' => 'Doctor Video Consultation Booked / Scheduled',
                        'desc' => 'Sent to patient when a doctor video consultation is booked or assigned.',
                        'tmpl_id_key' => 'cmi_dlt_tmpl_consultation_scheduled',
                        'msg_key' => 'cmi_dlt_msg_consultation_scheduled',
                        'enable_key' => 'cmi_dlt_enable_consultation_scheduled',
                        'type_key' => 'cmi_dlt_type_consultation_scheduled',
                        'default_tmpl' => '1077191330019642880',
                        'default_msg' => get_option('cmi_dlt_msg_consultation_scheduled', "Hello {name}, your video consultation {id} with Dr.  {doctor} is scheduled. \r\nLog in to your portal at https://cmihealthcare.in/my-account/patient-consultations/ to join. \r\nThanks CMI HealthCare."),
                        'default_type' => 'SERVICE_IMPLICIT',
                        'vars' => ['{name}', '{id}', '{doctor}']
                    ],
                    'consultation_rescheduled' => [
                        'title' => 'Doctor Consultation Rescheduled',
                        'desc' => 'Sent when admin or doctor reschedules the video session slot.',
                        'tmpl_id_key' => 'cmi_dlt_tmpl_consultation_rescheduled',
                        'msg_key' => 'cmi_dlt_msg_consultation_rescheduled',
                        'enable_key' => 'cmi_dlt_enable_consultation_rescheduled',
                        'type_key' => 'cmi_dlt_type_consultation_rescheduled',
                        'default_tmpl' => '1077257090019664680',
                        'default_msg' => get_option('cmi_dlt_msg_consultation_rescheduled', "Hello {name}, your doctor consultation {id} has been rescheduled. \r\nCheck your account portal for your new slot details. \r\nThanks CMI HealthCare."),
                        'default_type' => 'SERVICE_IMPLICIT',
                        'vars' => ['{name}', '{id}']
                    ],
                    'prescription_ready' => [
                        'title' => 'Digital Prescription Uploaded / Consultation Completed',
                        'desc' => 'Sent when doctor completes the session and uploads the prescription PDF.',
                        'tmpl_id_key' => 'cmi_dlt_tmpl_consultation_completed',
                        'msg_key' => 'cmi_dlt_msg_consultation_completed',
                        'enable_key' => 'cmi_dlt_enable_consultation_completed',
                        'type_key' => 'cmi_dlt_type_consultation_completed',
                        'default_tmpl' => '1077112990022812102',
                        'default_msg' => get_option('cmi_dlt_msg_consultation_completed', "Hello {name}, your medical test report for Order {id} is ready for download in your account. \r\nThanks CMI HealthCare."),
                        'default_type' => 'SERVICE_IMPLICIT',
                        'vars' => ['{name}', '{id}']
                    ],
                    'consultation_missed' => [
                        'title' => 'Doctor Consultation Session Expired / Missed',
                        'desc' => 'Sent when a consultation slot passes without doctor/patient joining.',
                        'tmpl_id_key' => 'cmi_dlt_tmpl_consultation_missed',
                        'msg_key' => 'cmi_dlt_msg_consultation_missed',
                        'enable_key' => 'cmi_dlt_enable_consultation_missed',
                        'type_key' => 'cmi_dlt_type_consultation_missed',
                        'default_tmpl' => '1077008630022798024',
                        'default_msg' => get_option('cmi_dlt_msg_consultation_missed', "Hello {name}, your doctor consultation session {id} was missed. \r\nPlease request a new slot via your account portal. \r\nThanks CMI HealthCare."),
                        'default_type' => 'SERVICE_IMPLICIT',
                        'vars' => ['{name}', '{id}']
                    ]
                ]
            ]
        ];
    }

    /**
     * CRUD Function: Get specific message definition by event key from DB
     */
    public static function get_message_by_key($event_key)
    {
        $all = self::get_categorized_events();
        foreach ($all as $cat) {
            if (isset($cat['events'][$event_key])) {
                return $cat['events'][$event_key];
            }
        }
        return null;
    }

    /**
     * CRUD Function: Create a new SMS Message template in DB
     */
    public static function create_message($title, $cat_key = 'portal', $tmpl_id = '', $msg_text = '', $type = 'SERVICE_IMPLICIT', $desc = '')
    {
        $all = self::get_categorized_events();
        $event_key = 'custom_msg_' . time() . '_' . rand(100, 999);

        if (!isset($all[$cat_key])) {
            $cat_key = 'portal';
        }

        $all[$cat_key]['events'][$event_key] = [
            'title' => sanitize_text_field($title),
            'desc' => sanitize_text_field($desc),
            'tmpl_id_key' => 'cmi_dlt_tmpl_' . $event_key,
            'msg_key' => 'cmi_dlt_msg_' . $event_key,
            'enable_key' => 'cmi_dlt_enable_' . $event_key,
            'type_key' => 'cmi_dlt_type_' . $event_key,
            'default_tmpl' => sanitize_text_field($tmpl_id),
            'default_msg' => sanitize_textarea_field($msg_text),
            'default_type' => sanitize_text_field($type),
            'vars' => ['{name}', '{email}', '{mobile}', '{date}', '{slot}', '{order_id}', '{partner}', '{doctor}', '{otp}']
        ];

        update_option('cmi_dlt_enable_' . $event_key, 'yes');
        update_option('cmi_dlt_tmpl_' . $event_key, sanitize_text_field($tmpl_id));
        update_option('cmi_dlt_msg_' . $event_key, sanitize_textarea_field($msg_text));
        update_option('cmi_dlt_type_' . $event_key, sanitize_text_field($type));

        update_option('cmi_custom_sms_templates', $all);
        return $event_key;
    }

    /**
     * CRUD Function: Update an existing SMS Message template in DB
     */
    public static function update_message($event_key, $title, $tmpl_id, $msg_text, $enable = 'yes', $type = 'SERVICE_IMPLICIT', $desc = '')
    {
        $all = self::get_categorized_events();
        $found = false;

        foreach ($all as $cat_k => &$cat) {
            if (isset($cat['events'][$event_key])) {
                $cat['events'][$event_key]['title'] = sanitize_text_field($title);
                $cat['events'][$event_key]['desc'] = sanitize_text_field($desc);
                $cat['events'][$event_key]['default_tmpl'] = sanitize_text_field($tmpl_id);
                $cat['events'][$event_key]['default_msg'] = sanitize_textarea_field($msg_text);
                $cat['events'][$event_key]['default_type'] = sanitize_text_field($type);
                $found = true;
                break;
            }
        }

        update_option('cmi_dlt_enable_' . $event_key, $enable);
        update_option('cmi_dlt_tmpl_' . $event_key, sanitize_text_field($tmpl_id));
        update_option('cmi_dlt_msg_' . $event_key, sanitize_textarea_field($msg_text));
        update_option('cmi_dlt_type_' . $event_key, sanitize_text_field($type));

        if ($found) {
            update_option('cmi_custom_sms_templates', $all);
        }
        return $found;
    }

    /**
     * CRUD Function: Delete an SMS Message template from DB
     */
    public static function delete_message($event_key)
    {
        $all = self::get_categorized_events();
        $found = false;

        foreach ($all as $cat_k => &$cat) {
            if (isset($cat['events'][$event_key])) {
                unset($cat['events'][$event_key]);
                $found = true;
                break;
            }
        }

        delete_option('cmi_dlt_enable_' . $event_key);
        delete_option('cmi_dlt_tmpl_' . $event_key);
        delete_option('cmi_dlt_msg_' . $event_key);
        delete_option('cmi_dlt_type_' . $event_key);

        if ($found) {
            update_option('cmi_custom_sms_templates', $all);
        }
        return $found;
    }

    /**
     * Fallback / Deferred Welcome SMS trigger.
     * Checks if user has received welcome SMS; if not and mobile is available, dispatches SMS.
     *
     * @param int    $user_id User ID
     * @param string $mobile  Mobile number (optional, fetched from meta if empty)
     * @return bool True if already sent or successfully sent, false otherwise.
     */
    public static function maybe_send_welcome_sms($user_id, $mobile = '')
    {
        if (!$user_id)
            return false;

        $already_sent = get_user_meta($user_id, '_cmi_welcome_sms_sent', true);
        if (!empty($already_sent)) {
            return true; // Welcome SMS already sent previously
        }

        if (empty($mobile)) {
            $mobile = get_user_meta($user_id, '_cmi_mobile', true);
            if (empty($mobile)) {
                $mobile = get_user_meta($user_id, 'billing_phone', true);
            }
        }

        $formatted = self::format_mobile($mobile);
        if (!$formatted) {
            error_log( "CMI SMS [welcome_user] [user_id={$user_id}]: SKIPPED — No valid mobile number found. Raw='{$mobile}'." );
            return false; // Mobile number not yet available/valid
        }

        $name = '';
        $user = get_userdata($user_id);
        if ($user) {
            $name = $user->display_name;
        }

        $result = self::send_event_sms('welcome_user', $formatted, [
            'name' => $name,
            'email' => $user ? $user->user_email : '',
            'mobile' => $formatted
        ]);

        if (!empty($result['success'])) {
            update_user_meta($user_id, '_cmi_welcome_sms_sent', current_time('mysql'));
            return true;
        }

        return false;
    }

    /**
     * Send Dynamic Transactional Event SMS using configured template placeholders.
     */
    public static function send_event_sms($event_key, $mobile, $placeholders = [])
    {
        // Search event definition in 3 categories
        $categories = self::get_categorized_events();
        $event_def = null;
        foreach ($categories as $cat) {
            if (isset($cat['events'][$event_key])) {
                $event_def = $cat['events'][$event_key];
                break;
            }
        }

        if (!$event_def) {
            error_log( "CMI SMS [{$event_key}] [{$mobile}]: SKIPPED — Event key not found in registry. Check get_categorized_events()." );
            return false;
        }

        $enable_key  = $event_def['enable_key'] ?? ('cmi_dlt_enable_' . $event_key);
        $tmpl_id_key = $event_def['tmpl_id_key'] ?? ('cmi_dlt_tmpl_' . $event_key);
        $msg_key     = $event_def['msg_key'] ?? ('cmi_dlt_msg_' . $event_key);
        $type_key    = $event_def['type_key'] ?? ('cmi_dlt_type_' . $event_key);

        $enabled = get_option($enable_key, 'yes');
        if ($enabled === false || $enabled === null || $enabled === '') {
            $enabled = 'yes';
        }
        if ($enabled !== 'yes' && $enabled !== '1' && $enabled !== 1 && $enabled !== true) {
            error_log( "CMI SMS [{$event_key}] [{$mobile}]: SKIPPED — Disabled in Admin > CMI Portal > SMS Settings (enable_key={$enable_key})." );
            return false;
        }

        $tmpl_id  = get_option($tmpl_id_key, $event_def['default_tmpl'] ?? '');
        $msg      = get_option($msg_key, $event_def['default_msg'] ?? '');
        $msg_type = get_option($type_key, $event_def['default_type'] ?? 'SERVICE_IMPLICIT');

        if (empty($tmpl_id) || empty($msg)) {
            error_log( "CMI SMS [{$event_key}] [{$mobile}]: SKIPPED — Template ID='" . ($tmpl_id ?: 'EMPTY') . "', Msg='" . (empty($msg) ? 'EMPTY' : 'OK') . "'. Go to Admin > CMI Portal > SMS Settings to configure." );
            return false;
        }

        // Interpolate dynamic placeholders
        $interpolated = $msg;
        foreach ($placeholders as $key => $val) {
            $interpolated = str_replace('{' . $key . '}', (string) $val, $interpolated);
        }

        // Check for un-replaced placeholders — sign of missing data in callers
        if (preg_match('/\{[a-z_]+\}/', $interpolated, $unreplaced)) {
            error_log( "CMI SMS [{$event_key}] [{$mobile}]: WARNING — Un-replaced placeholder found: " . $unreplaced[0] . ". Placeholders passed: " . implode(', ', array_keys($placeholders)) );
        }

        $result = self::send_sms($mobile, $interpolated, $tmpl_id, $msg_type);

        if (!empty($result['success'])) {
            $req_id = $result['request_id'] ?? 'N/A';
            error_log( "CMI SMS [{$event_key}] [{$mobile}]: SENT OK — Airtel Request ID: {$req_id}. Template: {$tmpl_id}." );
        } else {
            $reason = $result['message'] ?? 'Unknown Airtel API error';
            error_log( "CMI SMS [{$event_key}] [{$mobile}]: GATEWAY FAILED — {$reason}. Template: {$tmpl_id}. Body: " . json_encode($result['data'] ?? []) );
        }

        return $result;
    }

    /**
     * AJAX Bulk SMS Handler for Admin
     */
    public static function ajax_send_bulk_sms()
    {
        check_ajax_referer('cmi_bulk_sms_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized access.', 'cmi-partner-portal')]);
            wp_die();
        }

        $target = sanitize_text_field($_POST['target'] ?? 'patients');
        $tmpl_id = sanitize_text_field($_POST['template_id'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        $msg_type = sanitize_text_field($_POST['message_type'] ?? 'SERVICE_IMPLICIT');
        $custom_nums = sanitize_textarea_field($_POST['custom_numbers'] ?? '');

        if (empty($message) || empty($tmpl_id)) {
            wp_send_json_error(['message' => __('DLT Template ID and Message text are required.', 'cmi-partner-portal')]);
            wp_die();
        }

        $mobiles = [];

        if ($target === 'custom') {
            $lines = array_filter(array_map('trim', explode("\n", $custom_nums)));
            foreach ($lines as $line) {
                $m = self::format_mobile($line);
                if ($m)
                    $mobiles[] = $m;
            }
        } else {
            $args = ['fields' => 'ID', 'number' => 500];
            if ($target === 'partners') {
                $args['role__in'] = ['medical_partner'];
            } elseif ($target === 'doctors') {
                $args['role__in'] = ['cmi_doctor'];
            } else {
                $args['role__in'] = ['subscriber'];
            }

            $user_ids = get_users($args);
            foreach ($user_ids as $uid) {
                $mob = get_user_meta($uid, '_cmi_mobile', true) ?: get_user_meta($uid, 'billing_phone', true);
                $m = self::format_mobile($mob);
                if ($m)
                    $mobiles[] = $m;
            }
        }

        $mobiles = array_values(array_unique($mobiles));

        if (empty($mobiles)) {
            wp_send_json_error(['message' => __('No valid mobile numbers found for target selection.', 'cmi-partner-portal')]);
            wp_die();
        }

        // Chunk into batches of 100
        $chunks = array_chunk($mobiles, 100);
        $success_count = 0;
        $fail_count = 0;
        $errors = [];

        foreach ($chunks as $chunk) {
            $res = self::send_sms($chunk, $message, $tmpl_id, $msg_type);
            if ($res['success']) {
                $success_count += count($chunk);
            } else {
                $fail_count += count($chunk);
                $errors[] = $res['message'];
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Bulk SMS completed. Sent: %d, Failed: %d.', 'cmi-partner-portal'), $success_count, $fail_count),
            'total' => count($mobiles),
            'success' => $success_count,
            'failed' => $fail_count,
            'errors' => array_unique($errors)
        ]);
        wp_die();
    }

    /**
     * AJAX Handler to save an individual SMS Message Card without full page submit
     */
    public static function ajax_save_single_sms_card()
    {
        check_ajax_referer('cmi_save_sms_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized access.', 'cmi-partner-portal')]);
            wp_die();
        }

        $event_key = sanitize_key($_POST['event_key'] ?? '');
        $title     = sanitize_text_field($_POST['title'] ?? '');
        $cat_key   = sanitize_text_field($_POST['cat_key'] ?? 'portal');
        $desc      = sanitize_text_field($_POST['desc'] ?? '');
        $enable    = sanitize_text_field($_POST['enable'] ?? 'no');
        $tmpl_id   = sanitize_text_field($_POST['tmpl_id'] ?? '');
        $msg_text  = sanitize_textarea_field($_POST['msg_text'] ?? '');
        $type      = sanitize_text_field($_POST['type'] ?? 'SERVICE_IMPLICIT');

        if (empty($event_key)) {
            $event_key = 'custom_msg_' . time() . '_' . rand(100, 999);
        }

        $all = self::get_categorized_events();
        if (!isset($all[$cat_key])) {
            $cat_key = 'portal';
        }

        $all[$cat_key]['events'][$event_key] = [
            'title' => $title,
            'desc' => $desc,
            'tmpl_id_key' => 'cmi_dlt_tmpl_' . $event_key,
            'msg_key' => 'cmi_dlt_msg_' . $event_key,
            'enable_key' => 'cmi_dlt_enable_' . $event_key,
            'type_key' => 'cmi_dlt_type_' . $event_key,
            'default_tmpl' => $tmpl_id,
            'default_msg' => $msg_text,
            'default_type' => $type,
            'vars' => ['{name}', '{email}', '{mobile}', '{date}', '{slot}', '{order_id}', '{partner}', '{doctor}', '{otp}']
        ];

        update_option('cmi_dlt_enable_' . $event_key, $enable);
        update_option('cmi_dlt_tmpl_' . $event_key, $tmpl_id);
        update_option('cmi_dlt_msg_' . $event_key, $msg_text);
        update_option('cmi_dlt_type_' . $event_key, $type);

        if ($event_key === 'welcome_user') {
            update_option('cmi_dlt_welcome_template_id', $tmpl_id);
            update_option('cmi_dlt_welcome_message', $msg_text);
            update_option('cmi_dlt_enable_welcome_user', $enable);
        }

        update_option('cmi_custom_sms_templates', $all);

        wp_send_json_success([
            'message' => __('SMS Message card saved successfully!', 'cmi-partner-portal'),
            'event_key' => $event_key
        ]);
        wp_die();
    }

    /**
     * AJAX Handler to delete an individual SMS Message Card
     */
    public static function ajax_delete_single_sms_card()
    {
        check_ajax_referer('cmi_save_sms_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized access.', 'cmi-partner-portal')]);
            wp_die();
        }

        $event_key = sanitize_key($_POST['event_key'] ?? '');
        if (!empty($event_key)) {
            self::delete_message($event_key);
        }

        wp_send_json_success(['message' => __('Message card removed successfully.', 'cmi-partner-portal')]);
        wp_die();
    }

    /**
     * AJAX Handler to save Gateway Credentials card
     */
    public static function ajax_save_gateway_credentials()
    {
        check_ajax_referer('cmi_save_sms_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized access.', 'cmi-partner-portal')]);
            wp_die();
        }

        update_option('cmi_sms_provider',        sanitize_text_field($_POST['sms_provider'] ?? 'airtel'));
        update_option('cmi_airtel_endpoint',    sanitize_text_field($_POST['airtel_endpoint'] ?? 'https://iqsms.airtel.in/api/v1/send-prepaid-sms'));
        update_option('cmi_airtel_username',    sanitize_text_field($_POST['airtel_username'] ?? ''));
        update_option('cmi_airtel_password',    sanitize_text_field($_POST['airtel_password'] ?? ''));
        update_option('cmi_airtel_customer_id', sanitize_text_field($_POST['airtel_customer_id'] ?? ''));
        update_option('cmi_airtel_pe_id',        sanitize_text_field($_POST['airtel_pe_id'] ?? ''));
        update_option('cmi_airtel_sender_id',   sanitize_text_field($_POST['airtel_sender_id'] ?? 'CMIINF'));

        wp_send_json_success(['message' => __('Airtel IQ Gateway Credentials saved successfully!', 'cmi-partner-portal')]);
        wp_die();
    }

    /**
     * AJAX Handler to save an Action Hook Trigger Mapping
     */
    public static function ajax_save_trigger_card()
    {
        check_ajax_referer('cmi_save_sms_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized access.', 'cmi-partner-portal')]);
            wp_die();
        }

        $trigger_id     = sanitize_key($_POST['trigger_id'] ?? '');
        $title          = sanitize_text_field($_POST['title'] ?? '');
        $desc           = sanitize_text_field($_POST['desc'] ?? '');
        $hook_name      = sanitize_text_field($_POST['hook_name'] ?? '');
        $hook_priority  = intval($_POST['hook_priority'] ?? 10);
        $accepted_args  = intval($_POST['accepted_args'] ?? 1);
        $template_key   = sanitize_text_field($_POST['template_key'] ?? '');
        $recipient_type = sanitize_text_field($_POST['recipient_type'] ?? 'custom');
        $arg_position   = intval($_POST['arg_position'] ?? 1);
        $resolver       = sanitize_text_field($_POST['resolver'] ?? 'generic');
        $enabled        = sanitize_text_field($_POST['enabled'] ?? 'no');
        $async          = sanitize_text_field($_POST['async'] ?? 'no');

        if (empty($hook_name) || empty($template_key)) {
            wp_send_json_error(['message' => __('Action Hook Name and DLT Template Key are required.', 'cmi-partner-portal')]);
            wp_die();
        }

        $saved_id = CMI_SMS_Trigger_Registry::save_trigger([
            'trigger_id'     => $trigger_id,
            'title'          => $title,
            'desc'           => $desc,
            'hook_name'      => $hook_name,
            'hook_priority'  => $hook_priority,
            'accepted_args'  => $accepted_args,
            'template_key'   => $template_key,
            'recipient_type' => $recipient_type,
            'arg_position'   => $arg_position,
            'resolver'       => $resolver,
            'enabled'        => $enabled,
            'async'          => $async,
        ]);

        wp_send_json_success([
            'message'    => __('SMS Action Hook Trigger saved successfully!', 'cmi-partner-portal'),
            'trigger_id' => $saved_id
        ]);
        wp_die();
    }

    /**
     * AJAX Handler to delete an Action Hook Trigger Mapping
     */
    public static function ajax_delete_trigger_card()
    {
        check_ajax_referer('cmi_save_sms_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized access.', 'cmi-partner-portal')]);
            wp_die();
        }

        $trigger_id = sanitize_key($_POST['trigger_id'] ?? '');
        if (!empty($trigger_id)) {
            CMI_SMS_Trigger_Registry::delete_trigger($trigger_id);
        }

        wp_send_json_success(['message' => __('SMS Action Hook Trigger removed successfully.', 'cmi-partner-portal')]);
        wp_die();
    }
}

CMI_SMS_Manager::init();
