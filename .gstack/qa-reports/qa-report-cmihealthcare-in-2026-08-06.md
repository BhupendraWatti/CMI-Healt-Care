# QA Report: cmihealthcare.in

Date: 2026-08-06
Target: https://cmihealthcare.in
Plugin: CMI Partner Portal 1.0.28
Mode: Production-safe final QA after FTP deployment

## Summary

Status: DONE_WITH_CONCERNS
Health score: 96/100 for production-safe checks
Release recommendation: Ready for production use, with authenticated end-to-end smoke testing still required using real test accounts/orders.

## Verified

- FTP deploy target verified as `/wp-content/plugins/cmi-partner-portal`.
- Remote files hash-match local files for:
  - `assets/script.js`
  - `cmi-partner-portal.php`
  - `includes/class-consultations.php`
  - `includes/class-db.php`
  - `includes/class-partner-workflow.php`
- Remote plugin header verified: `CMI Partner Portal`, version `1.0.28`, text domain `cmi-partner-portal`.
- WordPress init triggered successfully after version bump.
- Homepage returned `200`, no PHP fatal/parse/warning signature.
- `/my-account/` returned `200`, no PHP fatal/parse/warning signature.
- Plugin `script.js` returned `200`.
- Plugin `style.css` returned `200`.
- Negative meeting-status AJAX without nonce/auth returned `400`/`0`.
- All PHP files passed `php -l`.
- `git diff --check` passed.
- Zip rebuilt as a standard WordPress archive with forward-slash paths.

## Load Probe

10 concurrent GET requests:

- Failures: 0
- Average response time: 1467 ms
- Max response time: 2780 ms
- No PHP fatal/parse/warning signatures detected

## Production Fixes Confirmed

- Version bumped to `1.0.28` so schema migration checks actually rerun in production.
- Schema migration does not mark complete if critical unique indexes cannot be created.
- Doctor meeting status transitions now return errors for invalid state or DB failure.
- Doctor meeting start is protected by a MySQL named lock and active-room check.
- Patient waiting-room polling reduced from 5s to 10s.

## Remaining Concerns

- Authenticated end-to-end flows were not mutated on production because no safe test patient/order/doctor credentials were provided.
- Headless Chrome is challenged by the site protection layer with `403`. Raw HTTP and normal GET checks pass. Uptime monitors should be whitelisted or configured with an allowed browser profile.

## Required Manual Smoke Test

- Patient login.
- Consultation checkout and booking with paid WooCommerce order item.
- Doctor starts consultation, patient joins only the booked consultation.
- Second simultaneous doctor room start is rejected.
- Doctor ends consultation and uploads prescription.
- Patient can access own prescription/report only.
- Home collection assignment accept/reject/revoke/upload flow with a test order.
