# CMI Partner Portal — Changelog

All notable changes to this project are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [1.0.21] — 2026-07-27

### Summary
Major reliability and architecture upgrade: replaced all hardcoded SMS logic with a **Dynamic DLT Template & Action Hook Trigger Registry** system. Fixed 100% silent failures in all Doctor Consultation SMS flows caused by WP-Cron loopback blocking and empty patient mobile resolution.

### Added — Dynamic SMS Engine (3 New Files)

| File | Class | Responsibility |
|---|---|---|
| `includes/class-sms-trigger-registry.php` | `CMI_SMS_Trigger_Registry` | Stores and manages mappable WordPress action hooks → DLT template bindings in the database. Supports per-trigger enable/disable, template key, and priority. |
| `includes/class-sms-context-resolver.php` | `CMI_SMS_Context_Resolver` | Inspects raw WordPress action hook arguments (User IDs, Order IDs, Consultation IDs) and resolves recipient mobile numbers and message variable placeholders (`{name}`, `{doctor}`, `{slot}`, `{date}`). |
| `includes/class-sms-listener.php` | `CMI_SMS_Listener` | Dynamically binds to WordPress action hooks at `init`, resolves context via `CMI_SMS_Context_Resolver`, and dispatches via `CMI_SMS_Manager`. |

### Added — Admin SMS AJAX Endpoints (`includes/class-sms.php`)
- `wp_ajax_cmi_add_dynamic_sms_trigger` — Add new hook → template binding.
- `wp_ajax_cmi_remove_dynamic_sms_trigger` — Remove binding by ID.
- `wp_ajax_cmi_toggle_dynamic_sms_trigger` — Enable/disable binding.
- `wp_ajax_cmi_get_dynamic_sms_triggers` — Fetch all bindings for Admin UI.

### Changed — DLT Template Registry (`includes/class-sms.php`)
- Updated `default_tmpl` and `default_msg` for all **11 registered Airtel DLT templates** to precisely match the approved content from `templates_2026-07-27.xlsx` (the TRAI-registered DLT corpus).
- **Doctor Consultation Scheduled / Assigned** mapped to DLT ID `1077191330019642880`.
- **Doctor Consultation Requested** mapped to DLT ID `1077519700022776221`.
- **Doctor Consultation Missed** mapped to DLT ID `1077008630022798024`.
- **Doctor Consultation Rescheduled** mapped to DLT ID `1077257090019664680`.
- **Home Sample Collections** mapped to DLT ID `1077262420022411974`.
- **Partner Accepted Job** mapped to DLT ID `1077291080022671240`.
- **Collection Reschedule Requested** mapped to DLT ID `1077391380019568068`.
- **Collection Reschedule Approved** mapped to DLT ID `1077062430022639988`.
- **Test Report PDF Ready** mapped to DLT ID `1077112990022812102`.
- **Security OTP Verification** mapped to DLT ID `1077566980019242921`.
- **Registration Welcome** mapped to DLT ID `1077037040016332738`.

### Fixed — Doctor Consultation SMS Silent Failures (`includes/class-notifications.php`)

#### Fix 1 — WP-Cron Loopback Bypass (6 methods)
All `schedule_consultation_*` methods were routing through `wp_schedule_single_event()`. On local XAMPP servers and production hosts with disabled HTTP loopbacks, this caused **100% silent failure** of all consultation notification delivery. Now call notify handlers directly — identical to the already-working `schedule_partner_revoked()` pattern.

```php
// BEFORE (silently fails on firewalled hosts):
wp_schedule_single_event(time(), 'cmi_deferred_notify_consultation_requested', [$id]);

// AFTER (immediate, reliable):
$this->notify_consultation_requested($id);
```

**Methods updated:** `schedule_consultation_requested`, `schedule_consultation_assigned`, `schedule_consultation_scheduled`, `schedule_consultation_completed`, `schedule_consultation_cancelled`, `schedule_consultation_needs_reschedule`

#### Fix 2 — 3-Tier Patient Mobile Fallback (7 SMS dispatch sites)
Every `notify_consultation_*` method checked only `!empty($row->patient_mobile)`. When a patient books for themselves (the common case), the `patient_mobile` column in `wp_cmi_consultations` is often empty (the lookup must come from user meta). SMS was silently skipped with no error.

**Resolution chain applied at all 7 dispatch sites:**
```php
$patient_mobile = !empty($row->patient_mobile) ? $row->patient_mobile
    : (get_user_meta($row->user_id, '_cmi_mobile', true) ?: get_user_meta($row->user_id, 'billing_phone', true));
```

**Methods updated:** `notify_consultation_requested`, `notify_consultation_assigned` (patient), `notify_consultation_scheduled`, `notify_consultation_completed` (prescription_ready), `notify_consultation_missed`, `notify_consultation_rescheduled_by_admin`

### Fixed — Context Resolver Consultation Entity Bug (`includes/class-sms-context-resolver.php`)
- `resolve('consultation', ...)` was reading `$row->patient_id` (non-existent column). Corrected to `$row->user_id`.
- Added fallback name from `$row->patient_name` when `get_userdata($user_id)` returns `null`.
- Added `$row->preferred_time_slot` and `$row->preferred_date` as primary date/slot sources (with `slot_time` as fallback).
- Added null guard for `$row->doctor_id` to prevent PHP Warning when consultation is not yet assigned to a doctor.

---

## [1.0.20] — 2026-07-25

### Added
- Airtel IQ prepaid DLT SMS integration (`CMI_SMS_Manager`).
- Home collection SMS notifications for partner assignment, acceptance, and report upload flows.
- Doctor consultation notification system (email only — SMS pending fix).

---

## [1.0.19] and earlier

See commit history for previous changelog entries.
