<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CMI_HT_Checkout {

    public function __construct() {
        // Show fields on checkout page
        add_action( 'woocommerce_after_order_notes', [ $this, 'render_checkout_fields' ] );

        // Validate fields
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_checkout_fields' ] );

        // Save fields (HPOS Compatible)
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_checkout_fields' ], 10, 2 );

        // Create the home-testing record immediately after the order row + meta
        // are fully committed.  This covers cash-on-delivery and any gateway that
        // marks the order 'processing' synchronously inside the checkout flow.
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'create_assignment_record' ], 10, 3 );

        // Payment integrity hook — fires when the order definitively transitions
        // to 'processing' status after confirmed payment.  Handles:
        //   1. Paid order orphaning: async IPN/webhooks (Razorpay, PayU, Stripe)
        //      that arrive after the page load — the record was never written by
        //      checkout_order_processed because payment wasn't confirmed yet.
        //   2. Order status race: card payments where the gateway redirects the
        //      customer before the IPN fires; the order goes pending → processing
        //      via the IPN, and this hook ensures the record is created then.
        // The idempotency guard inside ensure_assignment_record() means running
        // both hooks on the same order is completely safe.
        add_action( 'woocommerce_order_status_processing', [ $this, 'handle_payment_confirmed' ], 10, 2 );

        // AJAX to add family member dynamically
        add_action( 'wp_ajax_cmi_add_family_member', [ $this, 'ajax_add_family_member' ] );
        add_action( 'wp_ajax_nopriv_cmi_add_family_member', [ $this, 'ajax_add_family_member' ] );
    }

    /**
     * Render the Collection Date, Time Slot, and Patient Selection fields at checkout.
     */
    public function render_checkout_fields( $checkout ) {
        echo '<div id="cmi_home_testing_fields"><h3>' . esc_html__( 'Home Testing Appointment', 'cmi-home-testing' ) . '</h3>';

        // 1. Patient Selection
        $user_id = get_current_user_id();
        $options = [];
        $default_selected = '';
        $has_self = false;

        if ( $user_id ) {
            $members = CMI_HT_DB::get_user_members( $user_id );
            if ( ! empty( $members ) ) {
                foreach ( $members as $member ) {
                    $options[$member->id] = sprintf( '%s (%s)', esc_html( $member->name ), esc_html( $member->relationship ) );
                    if ( 'Self' === $member->relationship ) {
                        $default_selected = $member->id;
                        $has_self = true;
                    }
                }
            }
        }

        if ( ! $has_self && ! isset( $options['myself'] ) ) {
            $options['myself'] = esc_html__( 'Myself', 'cmi-home-testing' );
            if ( empty( $default_selected ) ) {
                $default_selected = 'myself';
            }
        }
        $options['new'] = esc_html__( 'Add New Family Member', 'cmi-home-testing' );

        woocommerce_form_field( 'cmi_patient_member_id', [
            'type'     => 'select',
            'class'    => [ 'form-row-wide' ],
            'label'    => esc_html__( 'Who is this booking for?', 'cmi-home-testing' ),
            'required' => true,
            'options'  => $options,
            'default'  => $default_selected,
        ], $checkout->get_value( 'cmi_patient_member_id' ) ?: $default_selected );

        // Render the Add New Family Member form (hidden by default)
        ?>
        <div id="cmi_new_patient_form_wrapper" style="display:none; background: #f7fafc; padding: 15px; border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 20px; margin-top: 10px;">
            <h4 style="margin-top:0; margin-bottom:15px;"><?php esc_html_e( 'Add New Family Member', 'cmi-home-testing' ); ?></h4>
            <div style="display: flex; flex-wrap: wrap; gap: 15px;">
                <div style="flex: 1 1 100%;">
                    <label for="cmi_new_patient_name" style="display:block; margin-bottom:5px; font-weight:600;"><?php esc_html_e( 'Full Name', 'cmi-home-testing' ); ?> <span class="required" style="color:red;">*</span></label>
                    <input type="text" id="cmi_new_patient_name" name="cmi_new_patient_name" placeholder="Enter Full Name" class="input-text" style="width:100%;">
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="cmi_new_patient_gender" style="display:block; margin-bottom:5px; font-weight:600;"><?php esc_html_e( 'Gender', 'cmi-home-testing' ); ?> <span class="required" style="color:red;">*</span></label>
                    <select id="cmi_new_patient_gender" name="cmi_new_patient_gender" style="width:100%;">
                        <option value="Male"><?php esc_html_e( 'Male', 'cmi-home-testing' ); ?></option>
                        <option value="Female"><?php esc_html_e( 'Female', 'cmi-home-testing' ); ?></option>
                        <option value="Other"><?php esc_html_e( 'Other', 'cmi-home-testing' ); ?></option>
                    </select>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="cmi_new_patient_dob" style="display:block; margin-bottom:5px; font-weight:600;"><?php esc_html_e( 'Date of Birth', 'cmi-home-testing' ); ?> <span class="required" style="color:red;">*</span></label>
                    <input type="date" id="cmi_new_patient_dob" name="cmi_new_patient_dob" class="input-text" max="<?php echo current_time('Y-m-d'); ?>" style="width:100%;">
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="cmi_new_patient_relationship" style="display:block; margin-bottom:5px; font-weight:600;"><?php esc_html_e( 'Relationship', 'cmi-home-testing' ); ?> <span class="required" style="color:red;">*</span></label>
                    <select id="cmi_new_patient_relationship" name="cmi_new_patient_relationship" style="width:100%;">
                        <option value="Mother"><?php esc_html_e( 'Mother', 'cmi-home-testing' ); ?></option>
                        <option value="Father"><?php esc_html_e( 'Father', 'cmi-home-testing' ); ?></option>
                        <option value="Spouse"><?php esc_html_e( 'Spouse', 'cmi-home-testing' ); ?></option>
                        <option value="Child"><?php esc_html_e( 'Child', 'cmi-home-testing' ); ?></option>
                        <option value="Sibling"><?php esc_html_e( 'Sibling', 'cmi-home-testing' ); ?></option>
                        <option value="Other"><?php esc_html_e( 'Other', 'cmi-home-testing' ); ?></option>
                    </select>
                </div>
                <div style="flex: 1 1 45%;">
                    <label for="cmi_new_patient_mobile" style="display:block; margin-bottom:5px; font-weight:600;"><?php esc_html_e( 'Mobile Number', 'cmi-home-testing' ); ?></label>
                    <input type="tel" id="cmi_new_patient_mobile" name="cmi_new_patient_mobile" placeholder="Mobile Number" class="input-text" style="width:100%;">
                </div>
            </div>
            <?php if ( $user_id ) : ?>
                <div style="margin-top: 15px;">
                    <button type="button" id="cmi_add_patient_ajax_btn" class="button alt" style="background-color: #1a4f8a; color: #fff; border:none; padding: 10px 20px; border-radius: 4px; cursor: pointer;"><?php esc_html_e( 'Save & Select Member', 'cmi-home-testing' ); ?></button>
                    <span id="cmi_ajax_member_msg" style="margin-left: 10px; font-weight: 500;"></span>
                </div>
            <?php endif; ?>
        </div>
        <?php

        // 2. Collection Date
        woocommerce_form_field( 'cmi_collection_date', [
            'type'        => 'date',
            'class'       => [ 'form-row-wide' ],
            'label'       => esc_html__( 'Preferred Collection Date', 'cmi-home-testing' ),
            'required'    => true,
            'placeholder' => esc_html__( 'Select Date', 'cmi-home-testing' ),
            'custom_attributes' => [
                'min' => current_time( 'Y-m-d' )
            ]
        ], $checkout->get_value( 'cmi_collection_date' ) );

        // 3. Collection Time Slot
        $slots = get_option( 'cmi_ht_time_slots', [
            '08:00 AM - 10:00 AM',
            '10:00 AM - 12:00 PM',
            '12:00 PM - 02:00 PM',
            '02:00 PM - 04:00 PM'
        ] );

        $options_slots = [ '' => esc_html__( 'Choose a time slot', 'cmi-home-testing' ) ];
        foreach ( $slots as $slot ) {
            $options_slots[$slot] = $slot;
        }

        woocommerce_form_field( 'cmi_collection_time_slot', [
            'type'     => 'select',
            'class'    => [ 'form-row-wide' ],
            'label'    => esc_html__( 'Preferred Time Slot', 'cmi-home-testing' ),
            'required' => true,
            'options'  => $options_slots,
        ], $checkout->get_value( 'cmi_collection_time_slot' ) );

        echo '</div>';
    }

    /**
     * Validate checkout fields, including patient details and serviceable pincode validation.
     */
    public function validate_checkout_fields() {
        // Patient selection validation
        if ( empty( $_POST['cmi_patient_member_id'] ) ) {
            wc_add_notice( esc_html__( 'Please select who this booking is for.', 'cmi-home-testing' ), 'error' );
        } elseif ( 'new' === $_POST['cmi_patient_member_id'] ) {
            if ( empty( $_POST['cmi_new_patient_name'] ) ) {
                wc_add_notice( esc_html__( 'Please enter the family member\'s full name.', 'cmi-home-testing' ), 'error' );
            }
            if ( empty( $_POST['cmi_new_patient_dob'] ) ) {
                wc_add_notice( esc_html__( 'Please enter the family member\'s date of birth.', 'cmi-home-testing' ), 'error' );
            }
            if ( empty( $_POST['cmi_new_patient_relationship'] ) ) {
                wc_add_notice( esc_html__( 'Please select your relationship to the family member.', 'cmi-home-testing' ), 'error' );
            }
        }

        // Date validation
        if ( empty( $_POST['cmi_collection_date'] ) ) {
            wc_add_notice( esc_html__( 'Please select a preferred collection date.', 'cmi-home-testing' ), 'error' );
        } else {
            $date = sanitize_text_field( $_POST['cmi_collection_date'] );
            $today = current_time( 'Y-m-d' );
            if ( $date < $today ) {
                wc_add_notice( esc_html__( 'Preferred collection date must be a future date or today.', 'cmi-home-testing' ), 'error' );
            }
        }

        // Time slot validation
        if ( empty( $_POST['cmi_collection_time_slot'] ) ) {
            wc_add_notice( esc_html__( 'Please select a preferred collection time slot.', 'cmi-home-testing' ), 'error' );
        } elseif ( ! empty( $_POST['cmi_collection_date'] ) ) {
            $date = sanitize_text_field( $_POST['cmi_collection_date'] );
            $today = current_time( 'Y-m-d' );
            if ( $date === $today ) {
                // Check if any valid slots remain today
                $slots = get_option( 'cmi_ht_time_slots', [
                    '08:00 AM - 10:00 AM',
                    '10:00 AM - 12:00 PM',
                    '12:00 PM - 02:00 PM',
                    '02:00 PM - 04:00 PM'
                ] );
                
                $any_valid_slot_remains = false;
                $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( get_option( 'timezone_string' ) ?: 'UTC' );
                if ( $timezone->getName() === 'UTC' || $timezone->getName() === '+00:00' ) {
                    $timezone = new DateTimeZone( 'Asia/Kolkata' );
                }
                $current_time = new DateTime( 'now', $timezone );
                $current_timestamp = $current_time->getTimestamp();
                $buffer_minutes = absint( get_option( 'cmi_same_day_buffer_minutes', 30 ) );
                $buffer_seconds = $buffer_minutes * 60;

                foreach ( $slots as $s ) {
                    $parts = explode( '-', $s );
                    $start_str = ! empty( $parts ) ? trim( $parts[0] ) : '';
                    if ( $start_str ) {
                        try {
                            $slot_start_time = new DateTime( $today . ' ' . $start_str, $timezone );
                            $slot_start_timestamp = $slot_start_time->getTimestamp();
                            if ( $slot_start_timestamp >= $current_timestamp + $buffer_seconds ) {
                                $any_valid_slot_remains = true;
                                break;
                            }
                        } catch ( Exception $e ) {
                            $slot_start_timestamp = strtotime( $today . ' ' . $start_str );
                            $current_timestamp_fallback = current_time( 'timestamp' );
                            if ( $slot_start_timestamp >= $current_timestamp_fallback + $buffer_seconds ) {
                                $any_valid_slot_remains = true;
                                break;
                            }
                        }
                    }
                }

                if ( $any_valid_slot_remains ) {
                    $slot = sanitize_text_field( $_POST['cmi_collection_time_slot'] );
                    $parts = explode( '-', $slot );
                    $start_str = ! empty( $parts ) ? trim( $parts[0] ) : '';
                    if ( $start_str ) {
                        try {
                            $slot_start_time = new DateTime( $date . ' ' . $start_str, $timezone );
                            $slot_start_timestamp = $slot_start_time->getTimestamp();
                            if ( $slot_start_timestamp < $current_timestamp + $buffer_seconds ) {
                                $buffer_label = $buffer_minutes . ' ' . _n( 'minute', 'minutes', $buffer_minutes, 'cmi-partner-portal' );
                                wc_add_notice( sprintf( esc_html__( 'Same-day collections require a minimum %s preparation window. Please choose a later slot.', 'cmi-home-testing' ), $buffer_label ), 'error' );
                            }
                        } catch ( Exception $e ) {
                            $slot_start_timestamp = strtotime( $today . ' ' . $start_str );
                            $current_timestamp_fallback = current_time( 'timestamp' );
                            if ( $slot_start_timestamp < ( $current_timestamp_fallback + $buffer_seconds ) ) {
                                $buffer_label = $buffer_minutes . ' ' . _n( 'minute', 'minutes', $buffer_minutes, 'cmi-partner-portal' );
                                wc_add_notice( sprintf( esc_html__( 'Same-day collections require a minimum %s preparation window. Please choose a later slot.', 'cmi-home-testing' ), $buffer_label ), 'error' );
                            }
                        }
                    }
                }
            }
        }

        // Serviceable Pincode Validation
        if ( get_option( 'cmi_ht_enable_pincode_validation', 'no' ) === 'yes' ) {
            $shipping_postcode = ! empty( $_POST['shipping_postcode'] ) ? sanitize_text_field( $_POST['shipping_postcode'] ) : '';
            $billing_postcode  = ! empty( $_POST['billing_postcode'] ) ? sanitize_text_field( $_POST['billing_postcode'] ) : '';
            
            $pincode = ! empty( $_POST['ship_to_different_address'] ) ? $shipping_postcode : $billing_postcode;
            $pincode = trim( $pincode );

            if ( empty( $pincode ) ) {
                wc_add_notice( esc_html__( 'Pincode is required to schedule home collection.', 'cmi-home-testing' ), 'error' );
                return;
            }

            $allowed_pincodes_raw = get_option( 'cmi_ht_allowed_pincodes', '' );
            if ( ! empty( $allowed_pincodes_raw ) ) {
                $allowed_pincodes = array_map( 'trim', explode( ',', $allowed_pincodes_raw ) );
                if ( ! in_array( $pincode, $allowed_pincodes ) ) {
                    wc_add_notice( esc_html__( 'Home testing is currently not available in your pincode. We only serve selected locations in Delhi.', 'cmi-home-testing' ), 'error' );
                }
            } else {
                if ( ! preg_match( '/^11[0-9]{4}$/', $pincode ) ) {
                    wc_add_notice( esc_html__( 'Home testing is only available within Delhi (Pincodes starting with 11xxxx).', 'cmi-home-testing' ), 'error' );
                }
            }
        }
    }

    /**
     * Save the fields to the order metadata (with Patient Snapshot) using HPOS compatible APIs.
     */
    public function save_checkout_fields( $order, $data ) {
        if ( ! empty( $_POST['cmi_collection_date'] ) ) {
            $date = sanitize_text_field( $_POST['cmi_collection_date'] );
            $order->update_meta_data( '_cmi_collection_date', $date );

            $today = current_time( 'Y-m-d' );
            if ( $date === $today ) {
                $slots = get_option( 'cmi_ht_time_slots', [
                    '08:00 AM - 10:00 AM',
                    '10:00 AM - 12:00 PM',
                    '12:00 PM - 02:00 PM',
                    '02:00 PM - 04:00 PM'
                ] );
                
                $any_valid_slot_remains = false;
                $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( get_option( 'timezone_string' ) ?: 'UTC' );
                if ( $timezone->getName() === 'UTC' || $timezone->getName() === '+00:00' ) {
                    $timezone = new DateTimeZone( 'Asia/Kolkata' );
                }
                $current_time = new DateTime( 'now', $timezone );
                $current_timestamp = $current_time->getTimestamp();
                $buffer_minutes = absint( get_option( 'cmi_same_day_buffer_minutes', 30 ) );
                $buffer_seconds = $buffer_minutes * 60;

                foreach ( $slots as $s ) {
                    $parts = explode( '-', $s );
                    $start_str = ! empty( $parts ) ? trim( $parts[0] ) : '';
                    if ( $start_str ) {
                        try {
                            $slot_start_time = new DateTime( $today . ' ' . $start_str, $timezone );
                            $slot_start_timestamp = $slot_start_time->getTimestamp();
                            if ( $slot_start_timestamp >= $current_timestamp + $buffer_seconds ) {
                                $any_valid_slot_remains = true;
                                break;
                            }
                        } catch ( Exception $e ) {
                            $slot_start_timestamp = strtotime( $today . ' ' . $start_str );
                            $current_timestamp_fallback = current_time( 'timestamp' );
                            if ( $slot_start_timestamp >= $current_timestamp_fallback + $buffer_seconds ) {
                                $any_valid_slot_remains = true;
                                break;
                            }
                        }
                    }
                }

                if ( ! $any_valid_slot_remains ) {
                    $order->update_meta_data( '_cmi_pending_confirmation', 'yes' );
                }
            }
        }
        if ( ! empty( $_POST['cmi_collection_time_slot'] ) ) {
            $order->update_meta_data( '_cmi_collection_time_slot', sanitize_text_field( $_POST['cmi_collection_time_slot'] ) );
        }

        // Process patient selection snapshot
        $member_id = sanitize_text_field( $_POST['cmi_patient_member_id'] ?? '' );
        $user_id   = get_current_user_id();
        
        $p_id = '';
        $p_name = '';
        $p_gender = '';
        $p_dob = '';
        $p_relationship = '';
        $p_mobile = '';

        if ( 'new' === $member_id ) {
            // Save new family member to DB if not saved via AJAX
            $name = sanitize_text_field( $_POST['cmi_new_patient_name'] ?? '' );
            $gender = sanitize_text_field( $_POST['cmi_new_patient_gender'] ?? '' );
            $dob = sanitize_text_field( $_POST['cmi_new_patient_dob'] ?? '' );
            $relationship = sanitize_text_field( $_POST['cmi_new_patient_relationship'] ?? '' );
            $mobile = sanitize_text_field( $_POST['cmi_new_patient_mobile'] ?? '' );

            if ( $name && $dob ) {
                if ( $user_id ) {
                    $db_member_id = CMI_HT_DB::add_member( $user_id, $name, $gender, $dob, $relationship, $mobile );
                    $p_id = $db_member_id ? $db_member_id : 'new';
                } else {
                    $p_id = 'guest_new';
                }
                $p_name = $name;
                $p_gender = $gender;
                $p_dob = $dob;
                $p_relationship = $relationship;
                $p_mobile = $mobile;
            }
        } elseif ( $user_id && ( 'myself' === $member_id || empty( $member_id ) ) ) {
            // Logged in user with 'myself' or empty option selection - resolve to 'Self' member record
            $db_members = CMI_HT_DB::get_user_members( $user_id );
            $found_self = false;
            foreach ( $db_members as $m ) {
                if ( 'Self' === $m->relationship ) {
                    $p_id = $m->id;
                    $p_name = $m->name;
                    $p_gender = $m->gender;
                    $p_dob = $m->dob;
                    $p_relationship = 'Self';
                    $p_mobile = $m->mobile ?: sanitize_text_field( $_POST['billing_phone'] ?? '' );
                    $found_self = true;
                    break;
                }
            }
            if ( ! $found_self ) {
                // Create a 'Self' member profile using billing details if not found
                $name = sanitize_text_field( $_POST['billing_first_name'] ?? '' ) . ' ' . sanitize_text_field( $_POST['billing_last_name'] ?? '' );
                if ( empty( trim( $name ) ) ) {
                    $user = get_userdata( $user_id );
                    $name = $user ? $user->display_name : 'Guest';
                }
                $mobile = sanitize_text_field( $_POST['billing_phone'] ?? '' );
                $db_member_id = CMI_HT_DB::add_member( $user_id, $name, 'Male', '1990-01-01', 'Self', $mobile );
                if ( $db_member_id ) {
                    $p_id = $db_member_id;
                    $p_name = $name;
                    $p_gender = 'Male';
                    $p_dob = '1990-01-01';
                    $p_relationship = 'Self';
                    $p_mobile = $mobile;
                } else {
                    // Fail-safe fallback to billing details if DB creation fails
                    $p_id = 'myself';
                    $p_name = $name;
                    $p_gender = 'Unspecified';
                    $p_dob = '';
                    $p_relationship = 'Self';
                    $p_mobile = $mobile;
                }
            }
        } elseif ( 'myself' === $member_id || ( empty( $member_id ) && ! $user_id ) ) {
            // Guest or non-logged-in "myself" fallback to billing details
            $p_id = 'myself';
            $p_name = sanitize_text_field( $_POST['billing_first_name'] ?? '' ) . ' ' . sanitize_text_field( $_POST['billing_last_name'] ?? '' );
            $p_gender = 'Unspecified';
            $p_dob = '';
            $p_relationship = 'Self';
            $p_mobile = sanitize_text_field( $_POST['billing_phone'] ?? '' );
        } else {
            // Existing member
            $member = CMI_HT_DB::get_member( intval( $member_id ) );
            if ( $member ) {
                $p_id = $member->id;
                $p_name = $member->name;
                $p_gender = $member->gender;
                $p_dob = $member->dob;
                $p_relationship = $member->relationship;
                $p_mobile = $member->mobile ?: sanitize_text_field( $_POST['billing_phone'] ?? '' );
            }
        }

        // Store patient snapshot fields directly inside WooCommerce order meta data
        $order->update_meta_data( '_cmi_patient_member_id', $p_id );
        $order->update_meta_data( '_cmi_patient_name', $p_name );
        $order->update_meta_data( '_cmi_patient_gender', $p_gender );
        $order->update_meta_data( '_cmi_patient_dob', $p_dob );
        $order->update_meta_data( '_cmi_patient_relationship', $p_relationship );
        $order->update_meta_data( '_cmi_patient_mobile', $p_mobile );
        $order->update_meta_data( '_cmi_patient_relationship', $p_relationship );
    }

    /**
     * Fired when WooCommerce confirms payment by transitioning the order to
     * 'processing' status (via IPN, webhook, or synchronous gateway callback).
     *
     * This is the payment integrity backstop that handles:
     *   - Async payment gateways (Razorpay, PayU, Stripe) where the IPN arrives
     *     after the customer has already left the checkout page.
     *   - Any order that was pending when checkout_order_processed fired but whose
     *     payment confirmation came later, leaving no cmi_home_testing row.
     *
     * @param int      $order_id The order ID.
     * @param WC_Order $order    The WC_Order object (passed since WC 3.0).
     */
    public function handle_payment_confirmed( $order_id, $order = null ) {
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }
        // Delegate to the shared helper — idempotency guard prevents duplicates.
        $this->ensure_assignment_record( $order_id, $order );
    }

    /**
     * Create home testing record in custom db table.
     *
     * Hooked to woocommerce_checkout_order_processed (NOT woocommerce_new_order) because:
     * - woocommerce_checkout_order_processed fires AFTER all order meta has been committed
     *   to the database by woocommerce_checkout_create_order, so get_meta() reliably returns
     *   the collection date and time slot written by save_checkout_fields().
     * - woocommerce_new_order fires too early in some WC/HPOS configurations — the order row
     *   exists in the DB but custom meta may not yet be flushed, causing get_meta() to return
     *   empty and silently bailing out, resulting in a "ghost" order (placed but invisible in
     *   the patient dashboard which queries cmi_home_testing by order_id).
     *
     * @param int   $order_id    The new order ID.
     * @param array $posted_data Raw checkout POST data (not used here, included for hook compat).
     * @param WC_Order $order    The fully-saved WC_Order object.
     */
    public function create_assignment_record( $order_id, $posted_data, $order = null ) {
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }
        $this->ensure_assignment_record( $order_id, $order );
    }

    /**
     * Shared helper: idempotently create the cmi_home_testing record for an order.
     *
     * Called by both create_assignment_record() (checkout flow) and
     * handle_payment_confirmed() (IPN / webhook flow).  The SELECT guard ensures
     * only one row is ever written per order_id regardless of how many times
     * either hook fires.
     *
     * @param int      $order_id
     * @param WC_Order $order
     */
    private function ensure_assignment_record( $order_id, $order ) {
        global $wpdb;

        $date = $order->get_meta( '_cmi_collection_date' );
        $slot = $order->get_meta( '_cmi_collection_time_slot' );

        if ( ! $date || ! $slot ) {
            return; // Not a scheduled home collection order — skip silently
        }

        // Prevent duplicate assignment records for the same order (e.g. hook fires twice)
        $table    = $wpdb->prefix . 'cmi_home_testing';
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE order_id = %d LIMIT 1",
            $order_id
        ) );

        // Phone number sync and Fallback Welcome SMS Trigger upon package purchase/checkout
        $customer_id   = $order->get_customer_id();
        $billing_phone = $order->get_billing_phone();
        if ( $customer_id && ! empty( $billing_phone ) ) {
            $norm = function_exists( 'CMI_CPT' ) ? CMI_CPT::normalize_mobile( $billing_phone ) : preg_replace( '/[^0-9+]/', '', $billing_phone );
            if ( ! get_user_meta( $customer_id, '_cmi_mobile', true ) ) {
                update_user_meta( $customer_id, '_cmi_mobile', $norm );
            }
            if ( ! get_user_meta( $customer_id, 'billing_phone', true ) ) {
                update_user_meta( $customer_id, 'billing_phone', $norm );
            }
            if ( class_exists( 'CMI_SMS_Manager' ) ) {
                CMI_SMS_Manager::maybe_send_welcome_sms( $customer_id, $billing_phone );
            }
        }

        if ( $existing ) {
            return; // Already recorded — idempotent guard
        }

        $is_pending_conf = $order->get_meta( '_cmi_pending_confirmation' ) === 'yes';
        $status = $is_pending_conf ? 'pending_confirmation' : 'pending_assignment';

        $inserted = $wpdb->insert(
            $table,
            [
                'order_id'             => $order_id,
                'collection_date'      => $date,
                'collection_time_slot' => $slot,
                'status'               => $status,
                'created_at'           => current_time( 'mysql' ),
                'updated_at'           => current_time( 'mysql' )
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( $inserted && class_exists( 'CMI_SMS_Manager' ) ) {
            $patient_name   = $order->get_meta( '_cmi_patient_name' ) ?: ( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            $patient_mobile = $order->get_meta( '_cmi_patient_mobile' ) ?: $order->get_billing_phone();
            if ( ! empty( $patient_mobile ) ) {
                CMI_SMS_Manager::send_event_sms( 'booking_confirmed', $patient_mobile, [
                    'name'     => $patient_name,
                    'order_id' => $order_id,
                    'date'     => $date,
                    'slot'     => $slot,
                ] );
            }
        }
    }

    /**
     * AJAX action to save a family member dynamically during checkout.
     */
    public function ajax_add_family_member() {
        if ( ! check_ajax_referer( 'cmi_pp_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Security check failed. Please refresh the page and try again.', 'cmi-home-testing' ) ] );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'You must be logged in to add a family member.', 'cmi-home-testing' ) ] );
        }

        $name         = sanitize_text_field( $_POST['name'] ?? '' );
        $gender       = sanitize_text_field( $_POST['gender'] ?? '' );
        $dob          = sanitize_text_field( $_POST['dob'] ?? '' );
        $relationship = sanitize_text_field( $_POST['relationship'] ?? '' );
        $mobile       = sanitize_text_field( $_POST['mobile'] ?? '' );

        if ( empty( $name ) || empty( $gender ) || empty( $dob ) || empty( $relationship ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Please fill in all required fields.', 'cmi-home-testing' ) ] );
        }

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid Date of Birth format. Please use YYYY-MM-DD.', 'cmi-home-testing' ) ] );
        }

        $member_id = CMI_HT_DB::add_member( $user_id, $name, $gender, $dob, $relationship, $mobile );

        if ( $member_id ) {
            wp_send_json_success( [
                'message'   => esc_html__( 'Family member added successfully.', 'cmi-home-testing' ),
                'member_id' => $member_id,
                'name'      => $name,
                'gender'    => $gender,
                'dob'       => $dob,
                'relationship'=> $relationship
            ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to save family member. Please try again.', 'cmi-home-testing' ) ] );
        }
    }
}
