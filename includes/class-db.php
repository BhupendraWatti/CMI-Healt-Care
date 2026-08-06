<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CMI_HT_DB {

    /**
     * Create the plugin database tables dynamically.
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. Home Testing Assignments & Status Table
        $table_testing = $wpdb->prefix . 'cmi_home_testing';
        $sql_testing = "CREATE TABLE IF NOT EXISTS $table_testing (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            partner_id BIGINT UNSIGNED DEFAULT NULL,
            collection_date DATE NOT NULL,
            collection_time_slot VARCHAR(50) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'pending_assignment',
            rejection_reason TEXT DEFAULT NULL,
            report_pdf VARCHAR(255) DEFAULT NULL,
            reschedule_date DATE DEFAULT NULL,
            reschedule_time_slot VARCHAR(50) DEFAULT NULL,
            reschedule_status VARCHAR(50) NOT NULL DEFAULT 'none',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY partner_id (partner_id),
            KEY status (status)
        ) $charset_collate;";

        dbDelta( $sql_testing );

        // 2. Partner Availability Slots Table
        $table_availability = $wpdb->prefix . 'cmi_partner_availability';
        $sql_availability = "CREATE TABLE IF NOT EXISTS $table_availability (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            partner_id BIGINT UNSIGNED NOT NULL,
            available_date DATE NOT NULL,
            time_slots TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY partner_date (partner_id, available_date)
        ) $charset_collate;";

        dbDelta( $sql_availability );

        // 3. Members/Patients Table
        $table_members = $wpdb->prefix . 'cmi_members';
        $sql_members = "CREATE TABLE IF NOT EXISTS $table_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            gender VARCHAR(50) NOT NULL,
            dob DATE NOT NULL,
            relationship VARCHAR(100) NOT NULL,
            mobile VARCHAR(20) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) $charset_collate;";

        dbDelta( $sql_members );

        // 4. Doctor Consultations Table
        $table_consultations = $wpdb->prefix . 'cmi_consultations';
        $sql_consultations = "CREATE TABLE IF NOT EXISTS $table_consultations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            order_item_id BIGINT UNSIGNED DEFAULT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            patient_member_id BIGINT UNSIGNED NOT NULL,
            patient_name VARCHAR(255) NOT NULL,
            patient_gender VARCHAR(50) NOT NULL,
            patient_dob DATE NOT NULL,
            patient_relationship VARCHAR(100) NOT NULL,
            patient_mobile VARCHAR(20) DEFAULT NULL,
            consultation_type VARCHAR(100) NOT NULL,
            symptoms TEXT DEFAULT NULL,
            preferred_date DATE NOT NULL,
            preferred_time_slot VARCHAR(100) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'requested',
            doctor_id BIGINT UNSIGNED DEFAULT NULL,
            meeting_room_id VARCHAR(100) DEFAULT NULL,
            meeting_url VARCHAR(255) DEFAULT NULL,
            meeting_status VARCHAR(50) DEFAULT 'not_started',
            rejection_reason TEXT DEFAULT NULL,
            prescription_id BIGINT UNSIGNED DEFAULT NULL,
            prescription_file VARCHAR(255) DEFAULT NULL,
            prescription_notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY order_item_id (order_item_id),
            KEY user_id (user_id),
            KEY doctor_id (doctor_id),
            KEY status (status),
            KEY user_date (user_id, patient_member_id, preferred_date)
        ) $charset_collate;";

        dbDelta( $sql_consultations );

        // 5. Doctor Availability slots
        $table_doc_avail = $wpdb->prefix . 'cmi_doctor_availability';
        $sql_doc_avail = "CREATE TABLE IF NOT EXISTS $table_doc_avail (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            doctor_id BIGINT UNSIGNED NOT NULL,
            day VARCHAR(20) NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            slot_duration INT NOT NULL DEFAULT 30,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY doctor_id (doctor_id)
        ) $charset_collate;";

        dbDelta( $sql_doc_avail );

        // 6. Doctor Availability Exceptions/Overrides Table
        $table_doc_exceptions = $wpdb->prefix . 'cmi_doctor_exceptions';
        $sql_doc_exceptions = "CREATE TABLE IF NOT EXISTS $table_doc_exceptions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            doctor_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(50) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            start_time TIME DEFAULT NULL,
            end_time TIME DEFAULT NULL,
            reason TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY doctor_id (doctor_id),
            KEY start_date (start_date)
        ) $charset_collate;";

        dbDelta( $sql_doc_exceptions );

        // Dynamic schema upgrades check
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_consultations'" ) === $table_consultations ) {
            $cols = $wpdb->get_col( "DESCRIBE $table_consultations" );
            if ( ! in_array( 'order_id', $cols ) ) {
                $wpdb->query( "ALTER TABLE $table_consultations ADD COLUMN order_id BIGINT UNSIGNED DEFAULT NULL AFTER id" );
            }
            if ( ! in_array( 'order_item_id', $cols ) ) {
                $wpdb->query( "ALTER TABLE $table_consultations ADD COLUMN order_item_id BIGINT UNSIGNED DEFAULT NULL AFTER order_id" );
            }
            if ( ! in_array( 'meeting_room_id', $cols ) ) {
                $wpdb->query( "ALTER TABLE $table_consultations ADD COLUMN meeting_room_id VARCHAR(100) DEFAULT NULL AFTER doctor_id" );
            }
            if ( ! in_array( 'meeting_url', $cols ) ) {
                $wpdb->query( "ALTER TABLE $table_consultations ADD COLUMN meeting_url VARCHAR(255) DEFAULT NULL AFTER meeting_room_id" );
            }
            if ( ! in_array( 'meeting_status', $cols ) ) {
                $wpdb->query( "ALTER TABLE $table_consultations ADD COLUMN meeting_status VARCHAR(50) DEFAULT 'not_started' AFTER meeting_url" );
            }

            // Slot-locking composite index — backstop for the PHP transaction guards.
            // Lets MySQL enumerate booked slots efficiently under FOR UPDATE lock.
            $existing_indexes = $wpdb->get_col( "SHOW INDEX FROM $table_consultations WHERE Key_name = 'idx_doctor_slot'" );
            if ( empty( $existing_indexes ) ) {
                $wpdb->query( "ALTER TABLE $table_consultations ADD INDEX idx_doctor_slot (doctor_id, preferred_date, preferred_time_slot)" );
            }
            $existing_order_item = $wpdb->get_col( "SHOW INDEX FROM $table_consultations WHERE Key_name = 'order_item_id'" );
            if ( empty( $existing_order_item ) ) {
                $wpdb->query( "ALTER TABLE $table_consultations ADD UNIQUE KEY order_item_id (order_item_id)" );
            }
            $existing_user_date = $wpdb->get_col( "SHOW INDEX FROM $table_consultations WHERE Key_name = 'user_date'" );
            if ( empty( $existing_user_date ) ) {
                $wpdb->query( "ALTER TABLE $table_consultations ADD INDEX user_date (user_id, patient_member_id, preferred_date)" );
            }
        }
    }

    /**
     * Helper to ensure the members table exists (dynamic check)
     */
    public static function ensure_members_table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_members';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
            self::create_tables();
        }
    }

    /**
     * Helper to ensure the consultations table exists (dynamic check)
     */
    public static function ensure_consultations_table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_consultations';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
            self::create_tables();
        }
    }

    /**
     * Get all members for a user, automatically creating a "Self" member if none exist.
     */
    public static function get_user_members( $user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_members';

        self::ensure_members_table_exists();

        $members = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY id ASC",
            $user_id
        ) );

        if ( empty( $members ) ) {
            $user = get_userdata( $user_id );
            if ( $user ) {
                $name = trim( $user->first_name . ' ' . $user->last_name );
                if ( empty( $name ) ) {
                    $name = $user->display_name;
                }
                $mobile = get_user_meta( $user_id, '_cmi_mobile', true );
                if ( empty( $mobile ) ) {
                    $mobile = get_user_meta( $user_id, 'billing_phone', true );
                }

                $wpdb->insert(
                    $table,
                    [
                        'user_id'      => $user_id,
                        'name'         => $name,
                        'gender'       => 'Male', // default fallback, can be updated/edited later
                        'dob'          => '1990-01-01', // default fallback
                        'relationship' => 'Self',
                        'mobile'       => $mobile,
                        'created_at'   => current_time( 'mysql' ),
                        'updated_at'   => current_time( 'mysql' )
                    ],
                    [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
                );

                $members = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM $table WHERE user_id = %d ORDER BY id ASC",
                    $user_id
                ) );
            }
        }

        return $members;
    }

    /**
     * Add a new member to the database.
     */
    public static function add_member( $user_id, $name, $gender, $dob, $relationship, $mobile = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_members';

        self::ensure_members_table_exists();

        $result = $wpdb->insert(
            $table,
            [
                'user_id'      => $user_id,
                'name'         => sanitize_text_field( $name ),
                'gender'       => sanitize_text_field( $gender ),
                'dob'          => sanitize_text_field( $dob ),
                'relationship' => sanitize_text_field( $relationship ),
                'mobile'       => sanitize_text_field( $mobile ),
                'created_at'   => current_time( 'mysql' ),
                'updated_at'   => current_time( 'mysql' )
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( $result ) {
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Get a specific member by ID.
     */
    public static function get_member( $member_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_members';

        self::ensure_members_table_exists();

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d LIMIT 1",
            $member_id
        ) );
    }

    /**
     * Update an existing member.
     */
    public static function update_member( $member_id, $user_id, $name, $gender, $dob, $relationship, $mobile = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_members';

        self::ensure_members_table_exists();

        return $wpdb->update(
            $table,
            [
                'name'         => sanitize_text_field( $name ),
                'gender'       => sanitize_text_field( $gender ),
                'dob'          => sanitize_text_field( $dob ),
                'relationship' => sanitize_text_field( $relationship ),
                'mobile'       => sanitize_text_field( $mobile ),
                'updated_at'   => current_time( 'mysql' )
            ],
            [ 'id' => intval( $member_id ), 'user_id' => intval( $user_id ) ],
            [ '%s', '%s', '%s', '%s', '%s', '%s' ],
            [ '%d', '%d' ]
        );
    }

    /**
     * Delete a family member.
     */
    public static function delete_member( $member_id, $user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmi_members';

        self::ensure_members_table_exists();

        // Cannot delete Self member
        $member = self::get_member( $member_id );
        if ( ! $member || 'Self' === $member->relationship || intval( $member->user_id ) !== intval( $user_id ) ) {
            return false;
        }

        return $wpdb->delete(
            $table,
            [ 'id' => intval( $member_id ), 'user_id' => intval( $user_id ) ],
            [ '%d', '%d' ]
        );
    }
}
