<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMI_CPT {

    public static function register() {
        // Patient Report CPT
        register_post_type( 'cmi_report', [
            'label'               => 'Patient Reports',
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'supports'            => [ 'title', 'author' ],
            'rewrite'             => false,
        ]);

        // Register doctor CPT if it doesn't already exist (fallback for local environments)
        if ( ! post_type_exists( 'doctor' ) ) {
            register_post_type( 'doctor', [
                'labels'              => [
                    'name'          => 'Doctors',
                    'singular_name' => 'Doctor',
                ],
                'public'              => true,
                'show_ui'             => true,
                'show_in_menu'        => true,
                'supports'            => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
                'has_archive'         => true,
                'rewrite'             => [ 'slug' => 'doctors' ],
            ]);
        }

        // Hook Doctor CPT linked user metabox
        add_action( 'add_meta_boxes_doctor', [ 'CMI_CPT', 'add_doctor_user_metabox' ] );
        add_action( 'save_post_doctor', [ 'CMI_CPT', 'save_doctor_user_metabox' ] );

        // Prescription CPT
        register_post_type( 'cmi_prescription', [
            'label'               => 'Prescriptions',
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'supports'            => [ 'title', 'author' ],
            'rewrite'             => false,
        ]);

        // Report types taxonomy
        register_taxonomy( 'cmi_report_type', 'cmi_report', [
            'label'        => 'Report Types',
            'public'       => false,
            'show_ui'      => true,
            'hierarchical' => true,
        ]);

        self::seed_report_types();
    }

    private static function seed_report_types() {
        $types = [
            'Blood Test',
            'Urine Test',
            'X-Ray Report',
            'ECG Report',
            'MRI / CT Scan',
            'Pathology Report',
            'Prescription',
            'Discharge Summary',
            'Other',
        ];
        foreach ( $types as $type ) {
            if ( ! term_exists( $type, 'cmi_report_type' ) ) {
                wp_insert_term( $type, 'cmi_report_type' );
            }
        }
    }

    /**
     * Save a report record and move uploaded file to secure storage.
     *
     * @param array $args {
     *   patient_mobile, patient_uid, patient_name,
     *   report_type_id, file_tmp, file_name, file_type,
     *   notes, uploaded_by (user_id), post_type (cmi_report|cmi_prescription)
     * }
     * @return int|WP_Error Post ID or error.
     */
    public static function save_report( $args ) {
        $defaults = [
            'patient_mobile'  => '',
            'patient_email'   => '',
            'patient_uid'     => '',
            'patient_name'    => '',
            'report_type_id'  => 0,
            'file_tmp'        => '',
            'file_name'       => '',
            'file_type'       => '',
            'notes'           => '',
            'uploaded_by'     => get_current_user_id(),
            'post_type'       => 'cmi_report',
        ];
        $args = wp_parse_args( $args, $defaults );

        // Sanitize & Normalize
        $mobile  = self::normalize_mobile( $args['patient_mobile'] );
        $email   = sanitize_email( $args['patient_email'] );
        $uid     = sanitize_text_field( $args['patient_uid'] );
        $name    = sanitize_text_field( $args['patient_name'] );

        $validation = CMI_Security::validate_uploaded_file( [
            'tmp_name' => $args['file_tmp'],
            'name'     => $args['file_name'],
            'type'     => $args['file_type'],
            'error'    => UPLOAD_ERR_OK,
            'size'     => file_exists( $args['file_tmp'] ) ? filesize( $args['file_tmp'] ) : 0,
        ] );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        if ( ! file_exists( CMI_PP_UPLOAD_DIR ) ) {
            wp_mkdir_p( CMI_PP_UPLOAD_DIR );
        }

        $secure_name = wp_unique_filename( CMI_PP_UPLOAD_DIR, wp_generate_password( 16, false ) . '.' . $validation['ext'] );
        $dest        = CMI_PP_UPLOAD_DIR . '/' . $secure_name;

        if ( ! move_uploaded_file( $args['file_tmp'], $dest ) ) {
            return new WP_Error( 'upload_failed', 'File could not be saved. Please try again.' );
        }

        // Create post
        $title   = $name ? $name . ' – ' . date('d M Y') : 'Report – ' . date('d M Y');
        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_type'    => $args['post_type'],
            'post_status'  => 'publish',
            'post_author'  => $args['uploaded_by'],
        ]);

        if ( is_wp_error( $post_id ) ) {
            @unlink( $dest );
            return $post_id;
        }

        // Meta
        update_post_meta( $post_id, '_cmi_patient_mobile',  $mobile );
        update_post_meta( $post_id, '_cmi_patient_email',   $email );
        update_post_meta( $post_id, '_cmi_patient_uid',     $uid );
        update_post_meta( $post_id, '_cmi_patient_name',    $name );
        update_post_meta( $post_id, '_cmi_file_name',       $secure_name );
        update_post_meta( $post_id, '_cmi_file_type',       $validation['mime'] );
        update_post_meta( $post_id, '_cmi_notes',           sanitize_textarea_field( $args['notes'] ) );
        update_post_meta( $post_id, '_cmi_uploaded_by',     $args['uploaded_by'] );
        update_post_meta( $post_id, '_cmi_upload_date',     current_time('mysql') );

        // Taxonomy
        if ( $args['report_type_id'] ) {
            wp_set_post_terms( $post_id, [ (int) $args['report_type_id'] ], 'cmi_report_type' );
        }

        return $post_id;
    }

    /**
     * Fetch reports for a patient by mobile or UID or email.
     */
    public static function get_patient_reports( $mobile = '', $uid = '', $post_type = 'cmi_report', $email = '', $limit = 100 ) {
        $meta_query = [ 'relation' => 'OR' ];
        if ( $mobile ) {
            $meta_query[] = [ 'key' => '_cmi_patient_mobile', 'value' => self::normalize_mobile( $mobile ) ];
        }
        if ( $uid ) {
            $meta_query[] = [ 'key' => '_cmi_patient_uid', 'value' => $uid ];
        }
        if ( $email ) {
            $meta_query[] = [ 'key' => '_cmi_patient_email', 'value' => $email ];
        }
        if ( count( $meta_query ) <= 1 ) return [];

        return get_posts([
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => max( 1, min( 200, absint( $limit ) ) ),
            'meta_query'     => $meta_query,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
    }

    /**
     * Normalize a mobile number to a standard 10-digit format.
     */
    public static function normalize_mobile( $mobile ) {
        // Strip all non-digits
        $clean = preg_replace( '/[^0-9]/', '', $mobile );
        // If it starts with 91 and is 12 digits (Indian country code), strip 91
        if ( strlen( $clean ) === 12 && strpos( $clean, '91' ) === 0 ) {
            $clean = substr( $clean, 2 );
        }
        // If it starts with 0 and is 11 digits, strip 0
        if ( strlen( $clean ) === 11 && strpos( $clean, '0' ) === 0 ) {
            $clean = substr( $clean, 1 );
        }
        return $clean;
    }

    public static function add_doctor_user_metabox() {
        add_meta_box(
            'cmi_doctor_user_link',
            'Link to WordPress Doctor User Account',
            [ 'CMI_CPT', 'render_doctor_user_metabox' ],
            'doctor',
            'side',
            'default'
        );

        add_meta_box(
            'cmi_doctor_availability_slots',
            'Doctor Availability Slots & Leaves',
            [ 'CMI_CPT', 'render_doctor_availability_metabox' ],
            'doctor',
            'normal',
            'high'
        );
    }

    public static function render_doctor_user_metabox( $post ) {
        $selected_user_id = get_post_meta( $post->ID, '_cmi_doctor_user_id', true );
        $specialty = get_post_meta( $post->ID, '_cmi_specialty', true ) ?: 'General Physician';
        $fee = get_post_meta( $post->ID, '_cmi_consultation_fee', true ) ?: '500';
        $mobile = get_post_meta( $post->ID, '_cmi_mobile', true ) ?: '';
        $license = get_post_meta( $post->ID, '_cmi_license', true ) ?: '';
        $doctors = get_users( [ 'role' => 'cmi_doctor' ] );
        wp_nonce_field( 'cmi_doctor_metabox_nonce', 'cmi_doctor_metabox_nonce_field' );
        ?>
        <p>
            <label for="cmi_doctor_user_select"><strong>Select Doctor User:</strong></label><br>
            <select name="cmi_doctor_user_id" id="cmi_doctor_user_select" style="width:100%; margin-top:5px;">
                <option value=""><?php esc_html_e( '-- Not Linked --', 'cmi-partner-portal' ); ?></option>
                <?php foreach ( $doctors as $doc ) : ?>
                    <option value="<?php echo esc_attr( $doc->ID ); ?>" <?php selected( $selected_user_id, $doc->ID ); ?>>
                        <?php echo esc_html( $doc->display_name ); ?> (<?php echo esc_html( $doc->user_email ); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="cmi_doctor_specialty"><strong>Specialty:</strong></label><br>
            <input type="text" name="cmi_doctor_specialty" id="cmi_doctor_specialty" value="<?php echo esc_attr( $specialty ); ?>" style="width:100%; margin-top:5px;">
        </p>
        <p>
            <label for="cmi_doctor_fee"><strong>Consultation Fee (INR):</strong></label><br>
            <input type="number" name="cmi_doctor_fee" id="cmi_doctor_fee" value="<?php echo esc_attr( $fee ); ?>" style="width:100%; margin-top:5px;" min="0">
        </p>
        <p>
            <label for="cmi_doctor_mobile"><strong>Mobile Number:</strong></label><br>
            <input type="text" name="cmi_doctor_mobile" id="cmi_doctor_mobile" value="<?php echo esc_attr( $mobile ); ?>" style="width:100%; margin-top:5px;">
        </p>
        <p>
            <label for="cmi_doctor_license"><strong>License Number:</strong></label><br>
            <input type="text" name="cmi_doctor_license" id="cmi_doctor_license" value="<?php echo esc_attr( $license ); ?>" style="width:100%; margin-top:5px;">
        </p>
        <?php
    }

    public static function save_doctor_user_metabox( $post_id ) {
        if ( ! isset( $_POST['cmi_doctor_metabox_nonce_field'] ) || ! wp_verify_nonce( $_POST['cmi_doctor_metabox_nonce_field'], 'cmi_doctor_metabox_nonce' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        global $cmi_prevent_user_to_cpt_sync;
        $cmi_prevent_user_to_cpt_sync = true;

        if ( isset( $_POST['cmi_doctor_user_id'] ) ) {
            $user_id = intval( $_POST['cmi_doctor_user_id'] );
            if ( $user_id > 0 ) {
                update_post_meta( $post_id, '_cmi_doctor_user_id', $user_id );

                $specialty = isset( $_POST['cmi_doctor_specialty'] ) ? sanitize_text_field( $_POST['cmi_doctor_specialty'] ) : 'General Physician';
                $fee       = isset( $_POST['cmi_doctor_fee'] ) ? sanitize_text_field( $_POST['cmi_doctor_fee'] ) : '500';
                $mobile    = isset( $_POST['cmi_doctor_mobile'] ) ? sanitize_text_field( $_POST['cmi_doctor_mobile'] ) : '';
                $license   = isset( $_POST['cmi_doctor_license'] ) ? sanitize_text_field( $_POST['cmi_doctor_license'] ) : '';

                update_post_meta( $post_id, '_cmi_specialty', $specialty );
                update_post_meta( $post_id, '_cmi_consultation_fee', $fee );
                update_post_meta( $post_id, '_cmi_mobile', $mobile );
                update_post_meta( $post_id, '_cmi_license', $license );

                // Two-way synchronization: update WordPress User Account
                if ( current_user_can( 'edit_users' ) ) {
                    update_user_meta( $user_id, '_cmi_specialty', $specialty );
                    update_user_meta( $user_id, '_cmi_consultation_fee', $fee );
                    update_user_meta( $user_id, '_cmi_mobile', $mobile );
                    update_user_meta( $user_id, '_cmi_license', $license );

                    $post = get_post( $post_id );
                    if ( $post ) {
                        wp_update_user( [
                            'ID'           => $user_id,
                            'display_name' => $post->post_title,
                            'description'  => $post->post_content,
                        ] );
                    }
                }
            } else {
                delete_post_meta( $post_id, '_cmi_doctor_user_id' );
            }
        }

        $cmi_prevent_user_to_cpt_sync = false;
    }

    public static function render_doctor_availability_metabox( $post ) {
        $doctor_id = get_post_meta( $post->ID, '_cmi_doctor_user_id', true );
        if ( ! $doctor_id ) {
            echo '<p>Please link this doctor post to a WordPress User Account in the side panel first and save the post.</p>';
            return;
        }

        global $wpdb;
        $avail_table = $wpdb->prefix . 'cmi_doctor_availability';
        $exceptions_table = $wpdb->prefix . 'cmi_doctor_exceptions';

        $rules = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $avail_table WHERE doctor_id = %d ORDER BY FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time ASC",
            $doctor_id
        ) );

        $exceptions = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $exceptions_table WHERE doctor_id = %d ORDER BY start_date ASC",
            $doctor_id
        ) );
        ?>
        <div class="cmi-admin-availability-box" style="padding: 10px 0;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <!-- Add Availability Form -->
                <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:15px;">
                    <h4 style="margin: 0 0 10px 0;">Add Weekly Availability Slot</h4>
                    <form id="cmi-admin-avail-form" style="display:flex; flex-direction:column; gap:10px;">
                        <input type="hidden" name="target_doctor_id" value="<?php echo esc_attr( $doctor_id ); ?>">
                        <div>
                            <label style="display:block; font-weight:500; font-size:12px; margin-bottom:3px;">Day of the Week</label>
                            <select name="day" required style="width:100%;">
                                <option value="Monday">Monday</option>
                                <option value="Tuesday">Tuesday</option>
                                <option value="Wednesday">Wednesday</option>
                                <option value="Thursday">Thursday</option>
                                <option value="Friday">Friday</option>
                                <option value="Saturday">Saturday</option>
                                <option value="Sunday">Sunday</option>
                            </select>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                            <div>
                                <label style="display:block; font-weight:500; font-size:12px; margin-bottom:3px;">Start Time</label>
                                <input type="time" name="start_time" required style="width:100%;">
                            </div>
                            <div>
                                <label style="display:block; font-weight:500; font-size:12px; margin-bottom:3px;">End Time</label>
                                <input type="time" name="end_time" required style="width:100%;">
                            </div>
                        </div>
                        <div>
                            <label style="display:block; font-weight:500; font-size:12px; margin-bottom:3px;">Slot Duration (minutes)</label>
                            <select name="slot_duration" required style="width:100%;">
                                <option value="15">15 Minutes</option>
                                <option value="30" selected>30 Minutes</option>
                                <option value="45">45 Minutes</option>
                                <option value="60">60 Minutes</option>
                            </select>
                        </div>
                        <div id="cmi-admin-avail-msg" style="display:none; padding:6px; border-radius:4px; font-size:11px; font-weight:600;"></div>
                        <button type="submit" class="button button-primary">Add Slot Window</button>
                    </form>
                </div>

                <!-- Add Exception Form -->
                <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:15px;">
                    <h4 style="margin: 0 0 10px 0;">Add Leaves / Exceptions</h4>
                    <form id="cmi-admin-exception-form" style="display:flex; flex-direction:column; gap:10px;">
                        <input type="hidden" name="target_doctor_id" value="<?php echo esc_attr( $doctor_id ); ?>">
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                            <div>
                                <label style="display:block; font-weight:500; font-size:12px; margin-bottom:3px;">Exception Type</label>
                                <select name="type" required style="width:100%;">
                                    <option value="leave">Leave / Day Off</option>
                                    <option value="override">Date Override (Hours)</option>
                                    <option value="emergency">Emergency Closure</option>
                                    <option value="holiday">Holiday</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-weight:500; font-size:12px; margin-bottom:3px;">Reason</label>
                                <input type="text" name="reason" placeholder="Conference" style="width:100%;">
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                            <div>
                                <label style="display:block; font-weight:500; font-size:12px; margin-bottom:3px;">Start Date</label>
                                <input type="date" name="start_date" required style="width:100%;">
                            </div>
                            <div>
                                <label style="display:block; font-weight:500; font-size:12px; margin-bottom:3px;">End Date</label>
                                <input type="date" name="end_date" required style="width:100%;">
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                            <div>
                                <label style="display:block; font-weight:500; font-size:12px; margin-bottom:3px;">Start Time (Optional)</label>
                                <input type="time" name="start_time" style="width:100%;">
                            </div>
                            <div>
                                <label style="display:block; font-weight:500; font-size:12px; margin-bottom:3px;">End Time (Optional)</label>
                                <input type="time" name="end_time" style="width:100%;">
                            </div>
                        </div>
                        <div id="cmi-admin-exception-msg" style="display:none; padding:6px; border-radius:4px; font-size:11px; font-weight:600;"></div>
                        <button type="submit" class="button button-primary">Add Exception</button>
                    </form>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <!-- Configured Availability Slots -->
                <div>
                    <h4 style="margin: 0 0 10px 0;">Configured Availability Slots</h4>
                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 4px; padding: 10px; background:#fff;">
                        <?php if ( empty( $rules ) ) : ?>
                            <p style="color:#71717a; font-size:12px; text-align:center;">No slots configured.</p>
                        <?php else : ?>
                            <div style="display:flex; flex-direction:column; gap:6px;">
                                <?php foreach ( $rules as $rule ) : ?>
                                    <div style="display:flex; justify-content:space-between; align-items:center; padding:6px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:4px; font-size:12px;">
                                        <span><strong><?php echo esc_html($rule->day); ?></strong>: <?php echo date('h:i A', strtotime($rule->start_time)); ?> - <?php echo date('h:i A', strtotime($rule->end_time)); ?> (<?php echo esc_html($rule->slot_duration); ?>m)</span>
                                        <button type="button" class="cmi-admin-delete-avail-btn" data-id="<?php echo esc_attr($rule->id); ?>" style="color:#ef4444; border:none; background:none; cursor:pointer; font-weight:bold;">✕</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Configured Leaves & Overrides -->
                <div>
                    <h4 style="margin: 0 0 10px 0;">Leaves & Overrides</h4>
                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 4px; padding: 10px; background:#fff;">
                        <?php if ( empty( $exceptions ) ) : ?>
                            <p style="color:#71717a; font-size:12px; text-align:center;">No exceptions configured.</p>
                        <?php else : ?>
                            <div style="display:flex; flex-direction:column; gap:6px;">
                                <?php foreach ( $exceptions as $ex ) : ?>
                                    <div style="display:flex; justify-content:space-between; align-items:center; padding:6px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:4px; font-size:12px;">
                                        <span><strong style="text-transform:uppercase; font-size:10px; color:#3b82f6;"><?php echo esc_html($ex->type); ?></strong>: <?php echo esc_html($ex->start_date); ?>
                                            <?php if ($ex->start_date !== $ex->end_date) echo ' to ' . esc_html($ex->end_date); ?>
                                            <?php if (!empty($ex->start_time)) echo ' (' . date('h:i A', strtotime($ex->start_time)) . '-' . date('h:i A', strtotime($ex->end_time)) . ')'; ?>
                                        </span>
                                        <button type="button" class="cmi-admin-delete-exception-btn" data-id="<?php echo esc_attr($ex->id); ?>" style="color:#ef4444; border:none; background:none; cursor:pointer; font-weight:bold;">✕</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo wp_create_nonce("cmi_pp_nonce"); ?>';
            var ajaxurl = '<?php echo admin_url("admin-ajax.php"); ?>';
            var target_doctor_id = '<?php echo esc_attr($doctor_id); ?>';

            // Add Availability
            $('#cmi-admin-avail-form').on('submit', function(e) {
                e.preventDefault();
                var form = $(this);
                var btn = form.find('button[type="submit"]');
                var msg = $('#cmi-admin-avail-msg');
                btn.prop('disabled', true).text('Saving...');
                msg.hide();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: form.serialize() + '&action=cmi_save_doctor_availability&nonce=' + nonce,
                    success: function(response) {
                        if (response.success) {
                            msg.css({'color':'green'}).text(response.data.message).show();
                            setTimeout(function() { location.reload(); }, 1000);
                        } else {
                            btn.prop('disabled', false).text('Add Slot Window');
                            msg.css({'color':'red'}).text(response.data.message).show();
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Add Slot Window');
                        msg.css({'color':'red'}).text('Connection error.').show();
                    }
                });
            });

            // Add Exception
            $('#cmi-admin-exception-form').on('submit', function(e) {
                e.preventDefault();
                var form = $(this);
                var btn = form.find('button[type="submit"]');
                var msg = $('#cmi-admin-exception-msg');
                btn.prop('disabled', true).text('Saving...');
                msg.hide();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: form.serialize() + '&action=cmi_save_doctor_exception&nonce=' + nonce,
                    success: function(response) {
                        if (response.success) {
                            msg.css({'color':'green'}).text(response.data.message).show();
                            setTimeout(function() { location.reload(); }, 1000);
                        } else {
                            btn.prop('disabled', false).text('Add Exception');
                            msg.css({'color':'red'}).text(response.data.message).show();
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Add Exception');
                        msg.css({'color':'red'}).text('Connection error.').show();
                    }
                });
            });

            // Delete Availability
            $('.cmi-admin-delete-avail-btn').on('click', function(e) {
                e.preventDefault();
                if (!confirm('Are you sure you want to delete this weekly availability slot? Overlapping scheduled appointments will be moved to Needs Reschedule.')) return;
                var btn = $(this);
                var id = btn.data('id');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cmi_delete_doctor_availability',
                        id: id,
                        target_doctor_id: target_doctor_id,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || 'Failed to delete slot.');
                        }
                    }
                });
            });

            // Delete Exception
            $('.cmi-admin-delete-exception-btn').on('click', function(e) {
                e.preventDefault();
                if (!confirm('Are you sure you want to delete this exception/leave?')) return;
                var btn = $(this);
                var id = btn.data('id');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cmi_delete_doctor_exception',
                        id: id,
                        target_doctor_id: target_doctor_id,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || 'Failed to delete exception.');
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }
}
