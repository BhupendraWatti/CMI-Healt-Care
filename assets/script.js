/* CMI Partner Portal – Frontend JS */
jQuery(function ($) {

    // Helper to safely extract message from AJAX responses (handles raw server string outputs)
    function getAjaxErrorMessage(r, defaultMsg) {
        if (r && r.data && r.data.message) {
            return r.data.message;
        }
        if (typeof r === 'string' && r !== '-1' && r !== '0') {
            return r;
        }
        return defaultMsg || 'Action failed.';
    }

    // Helper to reload or redirect to redirect_to URL after successful auth
    function reloadWithCacheBuster() {
        var urlParams = new URLSearchParams(window.location.search);
        var redirectTo = urlParams.get('redirect_to') || urlParams.get('redirect');
        if (redirectTo) {
            window.location.href = decodeURIComponent(redirectTo);
            return;
        }
        var href = window.location.href;
        var cb = 'cb=' + Date.now();
        if (href.indexOf('cb=') > -1) {
            href = href.replace(/cb=\d+/, cb);
        } else {
            var separator = href.indexOf('?') > -1 ? '&' : '?';
            href = href + separator + cb;
        }
        window.location.href = href;
    }

    // ── Partner registration toggle ────────────────────────────────────────
    $(document).on('change', '#cmi-partner-toggle', function () {
        $('#cmi-partner-fields').toggle(this.checked);
    });

    // Pre-fill mobile or email if URL param present
    var urlParams = new URLSearchParams(window.location.search);
    var prefillVal = urlParams.get('prefill');
    if (prefillVal) {
        prefillVal = decodeURIComponent(prefillVal);
        if (prefillVal.indexOf('@') > -1) {
            if ($('#cmi-patient-email').length) {
                $('#cmi-patient-email').val(prefillVal);
            } else {
                $('#cmi-patient-mobile').val(prefillVal);
            }
        } else {
            if ($('#cmi-patient-mobile').length) {
                $('#cmi-patient-mobile').val(prefillVal);
            }
        }
    }

    // ── Same Day Slot Validation on Checkout ────────────────────────────────
    var $dateField = $('#cmi_collection_date');
    var $slotField = $('#cmi_collection_time_slot');

    if ($dateField.length && $slotField.length) {
        var $originalOptions = $slotField.find('option').clone();

        function filterSameDaySlots() {
            var selectedDate = $dateField.val();
            var now = new Date();
            var year = now.getFullYear();
            var month = String(now.getMonth() + 1).padStart(2, '0');
            var day = String(now.getDate()).padStart(2, '0');
            var todayStr = year + '-' + month + '-' + day;

            // Remove warning if it exists
            $('#cmi-same-day-warning').remove();

            // Clear and rebuild options
            $slotField.empty();

            var selectedSlot = $slotField.data('selected-value') || '';

            if (selectedDate === todayStr) {
                var bufferMinutes = parseInt(cmiPP.sameDayBuffer) || 30;
                var bufferLater = new Date(now.getTime() + bufferMinutes * 60 * 1000);
                var hasFiltered = false;

                $originalOptions.each(function() {
                    var $opt = $(this).clone();
                    var val = $opt.val();
                    if (!val) {
                        $slotField.append($opt);
                        return;
                    }

                    var parsedTime = parseSlotStartTime(val);
                    if (parsedTime) {
                        var slotStart = new Date();
                        slotStart.setHours(parsedTime.hours, parsedTime.minutes, 0, 0);

                        if (slotStart < bufferLater) {
                            hasFiltered = true;
                        } else {
                            $slotField.append($opt);
                        }
                    } else {
                        $slotField.append($opt);
                    }
                });

                if (hasFiltered) {
                    var bufferLabel = bufferMinutes + ' minutes';
                    $slotField.after('<div id="cmi-same-day-warning" style="margin-top: 10px; font-size: 13px; padding: 10px; background: #fff9e6; border-left: 3px solid #ffcc00; color: #664d03; border-radius: 4px;">Same-day collections require a minimum ' + bufferLabel + ' preparation window. Earlier slots are unavailable.</div>');
                }
            } else {
                $originalOptions.each(function() {
                    $slotField.append($(this).clone());
                });
            }

            // Restore selection if it's still available
            if ($slotField.find('option[value="' + selectedSlot + '"]').length) {
                $slotField.val(selectedSlot);
            } else {
                $slotField.val('');
            }
        }

        function parseSlotStartTime(timeStr) {
            var parts = timeStr.split('-');
            var startStr = parts[0].trim();
            var match = startStr.match(/(\d+):(\d+)\s*(AM|PM)/i);
            if (!match) return null;
            var hours = parseInt(match[1]);
            var minutes = parseInt(match[2]);
            var ampm = match[3].toUpperCase();
            if (ampm === 'PM' && hours < 12) hours += 12;
            if (ampm === 'AM' && hours === 12) hours = 0;
            return { hours: hours, minutes: minutes };
        }

        $slotField.on('change', function() {
            $slotField.data('selected-value', $slotField.val());
        });

        $dateField.on('change', filterSameDaySlots);
        filterSameDaySlots();
    }

    // ── Report upload (partner) ────────────────────────────────────────────
    $(document).on('click', '#cmi-upload-btn', function () {
        var $btn    = $(this).prop('disabled', true).text('Uploading…');
        var $msg    = $('#cmi-upload-msg').hide();

        var mobile  = $('#cmi-patient-mobile').val() ? $('#cmi-patient-mobile').val().replace(/\D/g, '') : '';
        var email   = $('#cmi-patient-email').val() ? $('#cmi-patient-email').val().trim() : '';
        var uid     = $('#cmi-patient-uid').val() ? $('#cmi-patient-uid').val().trim() : '';
        var file    = $('#cmi-report-file')[0].files[0];

        // At least mobile or email is required (uid alone is also OK)
        if (!mobile && !email && !uid) {
            showMsg($msg, 'Please enter patient mobile number or email address (or UID).', 'error');
            $btn.prop('disabled', false).text('Upload Report');
            return;
        }

        // Validate mobile format only if provided
        if (mobile && mobile.length < 10) {
            showMsg($msg, 'Please enter a valid 10-digit mobile number.', 'error');
            $btn.prop('disabled', false).text('Upload Report');
            return;
        }

        // Validate email format only if provided
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showMsg($msg, 'Please enter a valid email address.', 'error');
            $btn.prop('disabled', false).text('Upload Report');
            return;
        }

        if (!file) {
            showMsg($msg, 'Please select a file to upload.', 'error');
            $btn.prop('disabled', false).text('Upload Report');
            return;
        }

        var fd = new FormData();
        var isAssignedOrder = $('#cmi-upload-order-select').length && $('#cmi-upload-order-select').val();
        if (isAssignedOrder) {
            fd.append('action',          'cmi_ht_upload_report');
            fd.append('id',              $('#cmi-upload-order-select').val());
        } else {
            fd.append('action',          'cmi_upload_report');
        }
        fd.append('nonce',           cmiPP.nonce);
        fd.append('patient_mobile',  mobile);
        fd.append('patient_email',   email);
        fd.append('patient_uid',     uid);
        fd.append('patient_name',    $('#cmi-patient-name').val());
        fd.append('report_type_id',  $('#cmi-report-type').val());
        fd.append('notes',           $('#cmi-report-notes').val());
        fd.append('report_file',     file);

        $.ajax({
            url:         cmiPP.ajaxurl,
            type:        'POST',
            data:        fd,
            processData: false,
            contentType: false,
            success: function (r) {
                $btn.prop('disabled', false).text('Upload Report');
                if (r.success) {
                    showMsg($msg, r.data.message, 'success');
                    $('#cmi-patient-mobile, #cmi-patient-email, #cmi-patient-name, #cmi-patient-uid, #cmi-report-notes').val('');
                    $('#cmi-report-file').val('');
                    if ($('#cmi-upload-order-select').length) {
                        $('#cmi-upload-order-select').val('').trigger('change');
                    }
                    setTimeout(function () { reloadWithCacheBuster(); }, 1500);
                } else {
                    showMsg($msg, getAjaxErrorMessage(r, 'Upload failed.'), 'error');
                }
            },
            error: function () {
                $btn.prop('disabled', false).text('Upload Report');
                showMsg($msg, 'Upload failed. Please check your connection and try again.', 'error');
            }
        });
    });

    // ── Prescription upload (doctor) ───────────────────────────────────────
    $(document).on('click', '#cmi-rx-upload-btn', function () {
        var $btn = $(this).prop('disabled', true).text('Uploading…');
        var $msg = $('#cmi-rx-upload-msg').hide();

        var inputVal = $('#cmi-rx-patient-mobile').val().trim();
        var mobile = '';
        var email = '';
        var file   = $('#cmi-rx-report-file')[0].files[0];

        if (!inputVal) {
            showMsg($msg, 'Patient mobile number or email is required.', 'error');
            $btn.prop('disabled', false).text('Upload Prescription');
            return;
        }

        if (inputVal.indexOf('@') > -1) {
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(inputVal)) {
                showMsg($msg, 'Please enter a valid email address.', 'error');
                $btn.prop('disabled', false).text('Upload Prescription');
                return;
            }
            email = inputVal;
        } else {
            mobile = inputVal.replace(/\D/g, '');
            if (mobile.length < 10) {
                showMsg($msg, 'Please enter a valid 10-digit mobile number.', 'error');
                $btn.prop('disabled', false).text('Upload Prescription');
                return;
            }
        }

        if (!file) {
            showMsg($msg, 'Please select a file.', 'error');
            $btn.prop('disabled', false).text('Upload Prescription');
            return;
        }

        var fd = new FormData();
        fd.append('action',          'cmi_upload_prescription');
        fd.append('nonce',           cmiPP.nonce);
        fd.append('patient_mobile',  mobile);
        fd.append('patient_email',   email);
        fd.append('patient_uid',     $('#cmi-rx-patient-uid').val().trim());
        fd.append('patient_name',    $('#cmi-rx-patient-name').val().trim());
        fd.append('notes',           $('#cmi-rx-report-notes').val().trim());
        fd.append('report_file',     file);

        $.ajax({
            url: cmiPP.ajaxurl, type: 'POST', data: fd,
            processData: false, contentType: false,
            success: function (r) {
                $btn.prop('disabled', false).text('Upload Prescription');
                if (r.success) {
                    showMsg($msg, r.data.message, 'success');
                    setTimeout(function () { reloadWithCacheBuster(); }, 1500);
                } else {
                    showMsg($msg, getAjaxErrorMessage(r, 'Prescription upload failed.'), 'error');
                }
            },
            error: function () {
                $btn.prop('disabled', false).text('Upload Prescription');
                showMsg($msg, 'Upload failed. Please check your connection and try again.', 'error');
            }
        });
    });

    // ── Secure download ────────────────────────────────────────────────────
    $(document).on('click', '.cmi-download-btn', function (e) {
        var href = $(this).attr('href');
        if (href && href !== '#' && href !== 'javascript:void(0);') {
            return; // Let the default browser behavior handle it
        }
        e.preventDefault();

        var $btn = $(this).prop('disabled', true).text('Preparing…');
        var id   = $(this).data('id');

        $.post(cmiPP.ajaxurl, {
            action:    'cmi_get_download_link',
            nonce:     cmiPP.nonce,
            report_id: id
        }, function (r) {
            $btn.prop('disabled', false).text('Download PDF');
            if (r.success) {
                window.location.href = r.data.url;
            } else {
                alert(getAjaxErrorMessage(r, 'Failed to retrieve download link.'));
            }
        }).fail(function(){
            $btn.prop('disabled', false).text('Download PDF');
            alert('Connection error.');
        });
    });

    // ── Home Collection Workflow ───────────────────────────────────────────
    // 1. Patient Reschedule Modal Triggers
    $(document).on('click', '.cmi-trigger-reschedule', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        $('#cmi-reschedule-id').val(id);
        $('#cmi-reschedule-modal').fadeIn();
    });

    // Close Modals
    $(document).on('click', '.cmi-close-modal', function() {
        $('#cmi-reschedule-modal, #cmi-reject-modal, #cmi-upload-report-modal, #cmi-revoke-modal').fadeOut();
    });

    // Submit Reschedule Request
    $(document).on('submit', '#cmi-reschedule-form', function(e) {
        e.preventDefault();
        var form = $(this);
        var submitBtn = form.find('button[type="submit"]');
        submitBtn.prop('disabled', true).text('Submitting...');

        $.ajax({
            url: cmiPP.ajaxurl,
            type: 'POST',
            data: form.serialize() + '&action=cmi_ht_request_reschedule&nonce=' + cmiPP.nonce,
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    reloadWithCacheBuster();
                } else {
                    alert(getAjaxErrorMessage(response, 'Failed to submit reschedule request.'));
                    submitBtn.prop('disabled', false).text('Submit Request');
                }
            },
            error: function() {
                alert('Connection error. Please try again.');
                submitBtn.prop('disabled', false).text('Submit Request');
            }
        });
    });

    // 2. Partner Acceptance Action
    $(document).on('click', '.cmi-partner-accept-btn', function(e) {
        e.preventDefault();
        var btn = $(this);
        var id = btn.data('id');

        if (!confirm('Are you sure you want to accept this assignment?')) {
            return;
        }

        btn.prop('disabled', true).text('Processing...');

        $.ajax({
            url: cmiPP.ajaxurl,
            type: 'POST',
            data: {
                action: 'cmi_ht_partner_accept',
                id: id,
                nonce: cmiPP.nonce
            },
            success: function(response) {
                if (response.success) {
                    reloadWithCacheBuster();
                } else {
                    alert(getAjaxErrorMessage(response, 'Failed to accept job.'));
                    btn.prop('disabled', false).text('Accept');
                }
            },
            error: function() {
                alert('Connection error.');
                btn.prop('disabled', false).text('Accept');
            }
        });
    });

    // 3. Partner Rejection Modal Triggers
    $(document).on('click', '.cmi-partner-reject-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        $('#cmi-reject-id').val(id);
        $('#cmi-reject-modal').fadeIn();
    });

    // Submit Rejection Reason
    $(document).on('submit', '#cmi-reject-form', function(e) {
        e.preventDefault();
        var form = $(this);
        var submitBtn = form.find('button[type="submit"]');
        submitBtn.prop('disabled', true).text('Submitting...');

        $.ajax({
            url: cmiPP.ajaxurl,
            type: 'POST',
            data: form.serialize() + '&action=cmi_ht_partner_reject&nonce=' + cmiPP.nonce,
            success: function(response) {
                if (response.success) {
                    reloadWithCacheBuster();
                } else {
                    alert(getAjaxErrorMessage(response, 'Failed to submit rejection.'));
                    submitBtn.prop('disabled', false).text('Submit Rejection');
                }
            },
            error: function() {
                alert('Connection error.');
                submitBtn.prop('disabled', false).text('Submit Rejection');
            }
        });
    });

    // 4. Partner Trigger Upload Report Modal
    $(document).on('click', '.cmi-trigger-upload-report', function(e) {
        e.preventDefault();
        var btn = $(this);
        var id = btn.data('id');
        var orderId = btn.data('order-id');
        var patientName = btn.data('patient-name');
        var detectedType = btn.data('detected-type');

        $('#cmi-upload-id').val(id);
        $('#cmi-display-order-id').val('#' + orderId);
        $('#cmi-display-patient-name').val(patientName);

        if (detectedType) {
            $('#cmi-modal-report-type').val(detectedType);
        } else {
            $('#cmi-modal-report-type').val('');
        }

        $('#cmi-upload-report-modal').fadeIn();
    });

    // Submit Separate Upload Report Form via AJAX
    $(document).on('submit', '#cmi-upload-report-form', function(e) {
        e.preventDefault();
        var form = $(this);
        var fileInput = form.find('#cmi-modal-report-file')[0];
        
        if (fileInput.files.length === 0) {
            alert('Please select a PDF report file.');
            return;
        }

        var submitBtn = form.find('button[type="submit"]');
        submitBtn.prop('disabled', true).text('Uploading...');

        var formData = new FormData(this);
        formData.append('action', 'cmi_ht_upload_report');
        formData.append('nonce', cmiPP.nonce);

        $.ajax({
            url: cmiPP.ajaxurl,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    reloadWithCacheBuster();
                } else {
                    alert(getAjaxErrorMessage(response, 'Failed to upload report.'));
                    submitBtn.prop('disabled', false).text('Upload Report');
                }
            },
            error: function() {
                alert('Upload failed due to connection error.');
                submitBtn.prop('disabled', false).text('Upload Report');
            }
        });
    });

    // 5. Partner Revocation Modal Triggers
    $(document).on('click', '.cmi-partner-revoke-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        $('#cmi-revoke-id').val(id);
        $('#cmi-revoke-modal').fadeIn();
    });

    // Submit Revocation Reason
    $(document).on('submit', '#cmi-revoke-form', function(e) {
        e.preventDefault();
        var form = $(this);
        var submitBtn = form.find('button[type="submit"]');
        var id = $('#cmi-revoke-id').val();
        submitBtn.prop('disabled', true).text('Submitting...');

        $.ajax({
            url: cmiPP.ajaxurl,
            type: 'POST',
            data: form.serialize() + '&action=cmi_ht_partner_revoke&nonce=' + cmiPP.nonce,
            success: function(response) {
                if (response.success) {
                    $('#cmi-revoke-modal').fadeOut();
                    alert(response.data.message || 'Job acceptance successfully revoked.');
                    // Update DOM instantly: fade out and remove the row
                    var row = $('tr[data-id="' + id + '"]');
                    row.fadeOut(400, function() {
                        row.remove();
                        if ($('.cmi-reports-table tbody tr').length === 0) {
                            $('.cmi-table-responsive').replaceWith('<p class="cmi-empty">No assignments found.</p>');
                        }
                    });
                } else {
                    alert(getAjaxErrorMessage(response, 'Failed to submit revocation.'));
                    submitBtn.prop('disabled', false).text('Submit Revocation');
                }
            },
            error: function() {
                alert('Connection error.');
                submitBtn.prop('disabled', false).text('Submit Revocation');
            }
        });
    });


    // 6. Standalone upload: When order is selected, populate and disable/enable fields
    $(document).on('change', '#cmi-upload-order-select', function () {
        var option = $(this).find('option:selected');
        if (option.val()) {
            $('#cmi-patient-mobile').val(option.data('patient-mobile')).prop('disabled', true);
            $('#cmi-patient-email').val(option.data('patient-email')).prop('disabled', true);
            $('#cmi-patient-name').val(option.data('patient-name')).prop('disabled', true);
            $('#cmi-patient-uid').val(option.data('patient-uid')).prop('disabled', true);
            $('#cmi-report-type').val(option.data('detected-type'));
        } else {
            $('#cmi-patient-mobile').val('').prop('disabled', false);
            $('#cmi-patient-email').val('').prop('disabled', false);
            $('#cmi-patient-name').val('').prop('disabled', false);
            $('#cmi-patient-uid').val('').prop('disabled', false);
            $('#cmi-report-type').val('');
        }
    });

    // 7. Submit Partner Profile Form via AJAX
    $(document).on('submit', '#cmi-partner-profile-form', function(e) {
        e.preventDefault();
        var form = $(this);
        var submitBtn = form.find('button[type="submit"]');
        var msg = $('#cmi-profile-msg').hide();
        submitBtn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: cmiPP.ajaxurl,
            type: 'POST',
            data: form.serialize() + '&action=cmi_ht_update_partner_profile&nonce=' + cmiPP.nonce,
            success: function(response) {
                submitBtn.prop('disabled', false).text('Save Profile Details');
                if (response.success) {
                    showMsg(msg, response.data.message, 'success');
                    setTimeout(function () { reloadWithCacheBuster(); }, 1000);
                } else {
                    showMsg(msg, getAjaxErrorMessage(response, 'Failed to save profile.'), 'error');
                }
            },
            error: function() {
                submitBtn.prop('disabled', false).text('Save Profile Details');
                showMsg(msg, 'Connection error. Please try again.', 'error');
            }
        });
    });

    // ── Tab Navigation Event Delegations ────────────────────────────────────

    // Build a user-scoped storage key so tab state never leaks across accounts or roles.
    // key = cmi_active_tab_<userId>_<userRole>  e.g. cmi_active_tab_42_doctor
    var _cmiUserId   = (typeof cmiPP !== 'undefined' && cmiPP.userId)   ? cmiPP.userId   : '0';
    var _cmiUserRole = (typeof cmiPP !== 'undefined' && cmiPP.userRole) ? cmiPP.userRole : 'guest';
    var _cmiTabKey   = 'cmi_active_tab_' + _cmiUserId + '_' + _cmiUserRole;

    // Helper: read scoped tab value from sessionStorage, then cookie
    function cmiGetScopedTab() {
        var val = sessionStorage.getItem(_cmiTabKey);
        if (!val) {
            var m = document.cookie.match(new RegExp('(^| )' + _cmiTabKey + '=([^;]+)'));
            if (m) val = m[2];
        }
        return val || null;
    }

    // Helper: write scoped tab value to sessionStorage + cookie
    function cmiSetScopedTab(tab) {
        sessionStorage.setItem(_cmiTabKey, tab);
        document.cookie = _cmiTabKey + '=' + tab + '; path=/; SameSite=Strict';
    }

    // Helper: clear scoped tab from sessionStorage + expire cookie
    function cmiClearScopedTab() {
        sessionStorage.removeItem(_cmiTabKey);
        document.cookie = _cmiTabKey + '=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Strict';
        // Also clear legacy unscoped keys from older plugin versions
        sessionStorage.removeItem('cmi_active_tab');
        document.cookie = 'cmi_active_tab=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Strict';
    }

    // Patient / Partner Dashboard Main Tabs — click handler
    $(document).on('click', '.cmi-dashboard-tabs .cmi-tab-btn, .cmi-tabs.cmi-dashboard-tabs .cmi-tab-btn', function(){
        var tab = $(this).data('tab');
        $('.cmi-dashboard-tabs .cmi-tab-btn, .cmi-tabs.cmi-dashboard-tabs .cmi-tab-btn').removeClass('active');
        $(this).addClass('active');

        $('.cmi-dashboard-tab-content').hide();
        $('#cmi-tab-content-' + tab).show();

        // Save active tab using scoped key (user-specific)
        cmiSetScopedTab(tab);

        // Strip view_history and prefill if switching away from patients
        if (tab !== 'patients') {
            if (history.replaceState) {
                var url = new URL(window.location.href);
                if (url.searchParams.has('view_history') || url.searchParams.has('prefill')) {
                    url.searchParams.delete('view_history');
                    url.searchParams.delete('prefill');
                    history.replaceState({}, '', url.toString());
                }
            }
        } else {
            // If they clicked the 'patients' tab button and view_history is in URL, reload to list
            var url = new URL(window.location.href);
            if (url.searchParams.has('view_history')) {
                url.searchParams.delete('view_history');
                url.searchParams.delete('prefill');
                window.location.href = url.toString();
            }
        }
    });

    // ── Clear tab state on Logout ────────────────────────────────────────────
    // Wipes the scoped sessionStorage + cookie when the user clicks any logout link
    // so the next login always starts on the role-appropriate default tab.
    $(document).on('click', 'a[href*="logout"]', function(){
        cmiClearScopedTab();
    });

    // ── Restore active tab on page load ─────────────────────────────────────
    // Priority: view_history URL param → scoped storage value → role default
    var activeTab = null;
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('view_history')) {
        activeTab = 'patients';
    } else {
        activeTab = cmiGetScopedTab();
    }

    // Role-aware default: doctors default to consultations, not assignments
    if (typeof cmiPP !== 'undefined' && cmiPP.isDoctor && (!activeTab || activeTab === 'assignments')) {
        activeTab = 'consultations';
    }

    if (activeTab) {
        // ── DOM Validation ────────────────────────────────────────────────────
        // Check the restored tab button actually exists in the current user's dashboard.
        // If not (e.g. Doctor tab stored but patient is now logged in), fall back to
        // the first available tab button so the dashboard never shows a blank screen.
        var $tabBtn = $('.cmi-dashboard-tabs .cmi-tab-btn[data-tab="' + activeTab + '"], .cmi-tabs.cmi-dashboard-tabs .cmi-tab-btn[data-tab="' + activeTab + '"]');
        if (!$tabBtn.length) {
            // Tab doesn't exist for this user/role — clear stale state and use default
            cmiClearScopedTab();
            $tabBtn = $('.cmi-dashboard-tabs .cmi-tab-btn:first, .cmi-tabs.cmi-dashboard-tabs .cmi-tab-btn:first');
            activeTab = $tabBtn.data('tab') || null;
        }
        if ($tabBtn.length && activeTab) {
            $('.cmi-dashboard-tabs .cmi-tab-btn, .cmi-tabs.cmi-dashboard-tabs .cmi-tab-btn').removeClass('active');
            $tabBtn.addClass('active');
            $('.cmi-dashboard-tab-content').hide();
            $('#cmi-tab-content-' + activeTab).show();
        }
    }    // Portal Login / Register toggle tabs
    $(document).on('click', '#cmi-toggle-mobile-otp-btn', function(){
        $('.cmi-auth-toggle-tabs .cmi-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('#cmi-mobile-otp-form').show();
        $('#cmi-email-login-form').hide();
        $('#cmi-auth-msg').hide();
    });

    $(document).on('click', '#cmi-toggle-email-btn', function(e){
        e.preventDefault();
        $('.cmi-auth-toggle-tabs .cmi-tab-btn').removeClass('active');
        $('.cmi-auth-toggle-tabs .cmi-tab-btn').attr('aria-selected', 'false');
        $(this).addClass('active').attr('aria-selected', 'true');
        $('#cmi-mobile-direct-form, #cmi-mobile-otp-form').hide();
        $('#cmi-email-login-form').fadeIn(200);
        $('#cmi-auth-msg').hide();
    });

    $(document).on('click', '#cmi-toggle-phone-btn', function(e){
        e.preventDefault();
        $('.cmi-auth-toggle-tabs .cmi-tab-btn').removeClass('active');
        $('.cmi-auth-toggle-tabs .cmi-tab-btn').attr('aria-selected', 'false');
        $('#cmi-toggle-mobile-otp-btn').addClass('active').attr('aria-selected', 'true');
        $('#cmi-email-login-form').hide();
        $('#cmi-mobile-otp-form').fadeIn(200);
        $('#cmi-auth-msg').hide();
    });

    $(document).on('click', '#cmi-direct-auth-submit-btn', function(e){
        e.preventDefault();
        $('#cmi-mobile-direct-form').trigger('submit');
    });

    // Handle Direct Mobile Auth Form Submission (No OTP needed until DLT approval)
    $(document).on('submit', '#cmi-mobile-direct-form', function(e){
        e.preventDefault();
        var $msg = $('#cmi-auth-msg').hide();
        var $btn = $('#cmi-direct-auth-submit-btn');

        var mobile = $('#cmi-otp-mobile').val().replace(/\D/g, '');
        var name = $('#cmi-otp-name').val();
        var password = $('#cmi-otp-password').val();
        var type = $('#cmi-auth-container').data('type') || 'patient';
        var partnerType = $('#cmi-reg-partner-type').val() || 'medical_partner';

        if (mobile.length < 10) {
            showMsg($msg, 'Please enter a valid 10-digit mobile number.', 'error');
            return;
        }

        $btn.prop('disabled', true).text('Processing…');

        var ajaxUrl = (typeof cmiPP !== 'undefined') ? cmiPP.ajaxurl : (typeof cmi_obj !== 'undefined' ? cmi_obj.ajax_url : '/wp-admin/admin-ajax.php');
        var nonce = (typeof cmiPP !== 'undefined') ? cmiPP.nonce : (typeof cmi_obj !== 'undefined' ? cmi_obj.nonce : '');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'cmi_mobile_direct_auth',
                nonce: nonce,
                mobile: mobile,
                name: name,
                password: password,
                type: type,
                partner_type: partnerType
            },
            success: function(r){
                $btn.prop('disabled', false).text('Submit');
                if (r.success) {
                    showMsg($msg, r.data.message, 'success');
                    setTimeout(function(){
                        reloadWithCacheBuster();
                    }, 1200);
                } else {
                    showMsg($msg, r.data.message, 'error');
                }
            },
            error: function(){
                $btn.prop('disabled', false).text('Submit');
                showMsg($msg, 'Network error. Please try again.', 'error');
            }
        });
    });

    // Handle Send Auth OTP via SMS
    $(document).on('click', '#cmi-send-auth-otp-btn, #cmi-resend-auth-otp-btn', function(e){
        e.preventDefault();
        var $btn = $(this);
        var $msg = $('#cmi-auth-msg').hide();
        var mobile = $('#cmi-otp-mobile').val().replace(/\D/g, '');

        if (mobile.length < 10) {
            showMsg($msg, 'Please enter a valid 10-digit mobile number.', 'error');
            return;
        }

        $btn.prop('disabled', true).text('Sending OTP…');

        $.post(cmiPP.ajaxurl, {
            action: 'cmi_send_portal_otp',
            nonce: cmiPP.nonce,
            mobile: mobile
        }, function(r){
            $btn.prop('disabled', false).text('Resend OTP');
            if (r.success) {
                showMsg($msg, r.data.message, 'success');
                $('#cmi-otp-step-1').hide();
                $('#cmi-otp-step-2').fadeIn();
                $('#cmi-otp-code').focus();
            } else {
                showMsg($msg, getAjaxErrorMessage(r, 'Failed to send OTP.'), 'error');
            }
        }).fail(function(){
            $btn.prop('disabled', false).text('Send OTP via SMS');
            showMsg($msg, 'Connection error. Please try again.', 'error');
        });
    });

    // Handle Verify Auth OTP Submit
    $(document).on('submit', '#cmi-mobile-otp-form', function(e){
        e.preventDefault();
        var $msg = $('#cmi-auth-msg').hide();
        var $container = $('#cmi-auth-container');
        var portalType = $container.data('type'); // 'partner' or 'patient'

        var mobile = $('#cmi-otp-mobile').val().replace(/\D/g, '');
        var otp    = $('#cmi-otp-code').val().trim();
        var name   = $('#cmi-otp-name').val().trim();

        if (mobile.length < 10 || otp.length < 4) {
            showMsg($msg, 'Please enter a valid mobile number and OTP code.', 'error');
            return;
        }

        var $btn = $('#cmi-verify-auth-otp-btn').prop('disabled', true).text('Verifying…');

        var postData = {
            action: 'cmi_verify_portal_otp',
            nonce: cmiPP.nonce,
            type: portalType,
            mobile: mobile,
            otp: otp,
            name: name
        };

        if (portalType === 'partner' && $('#cmi-reg-partner-type').length) {
            postData.partner_type = $('#cmi-reg-partner-type').val();
        }

        $.post(cmiPP.ajaxurl, postData, function(r){
            if (r.success) {
                showMsg($msg, r.data.message, 'success');
                setTimeout(function(){
                    reloadWithCacheBuster();
                }, 1000);
            } else {
                $btn.prop('disabled', false).text('Verify & Log In');
                showMsg($msg, getAjaxErrorMessage(r, 'Verification failed.'), 'error');
            }
        }).fail(function(){
            $btn.prop('disabled', false).text('Verify & Log In');
            showMsg($msg, 'Connection error.', 'error');
        });
    });

    // Handle Email & Password Login Submit
    $(document).on('submit', '#cmi-email-login-form', function(e){
        e.preventDefault();
        var $msg = $('#cmi-auth-msg').hide();

        var email = $('#cmi-login-email').val().trim();
        var password = $('#cmi-login-password').val();

        var $btn = $(this).find('.cmi-auth-submit-btn').prop('disabled', true).text('Logging in…');

        $.post(cmiPP.ajaxurl, {
            action: 'cmi_portal_login',
            nonce: cmiPP.nonce,
            email: email,
            password: password
        }, function(r){
            if (r.success) {
                showMsg($msg, r.data.message, 'success');
                setTimeout(function(){
                    reloadWithCacheBuster();
                }, 1000);
            } else {
                $btn.prop('disabled', false).text('Log In with Password');
                showMsg($msg, getAjaxErrorMessage(r, 'Login failed.'), 'error');
            }
        }).fail(function(){
            $btn.prop('disabled', false).text('Log In with Password');
            showMsg($msg, 'Connection error.', 'error');
        });
    });

    // Handle Register Submit
    $(document).on('submit', '#cmi-register-form', function(e){
        e.preventDefault();
        var $msg = $('#cmi-auth-msg').hide();
        var $container = $('#cmi-auth-container');
        var portalType = $container.data('type'); // 'partner' or 'patient'

        var name     = $('#cmi-reg-name').val().trim();
        var email    = $('#cmi-reg-email').val().trim();
        var mobile   = $('#cmi-reg-mobile').val().trim();
        var password = $('#cmi-reg-password').val();

        var $btn = $(this).find('.cmi-auth-submit-btn').prop('disabled', true).text('Registering…');

        var postData = {
            action: 'cmi_portal_register',
            nonce: cmiPP.nonce,
            type: portalType,
            name: name,
            email: email,
            mobile: mobile,
            password: password
        };

        if (portalType === 'partner') {
            postData.partner_type = $('#cmi-reg-partner-type').val();
            postData.org = $('#cmi-reg-org').val().trim();
            postData.license = $('#cmi-reg-license').val().trim();
        }

        $.post(cmiPP.ajaxurl, postData, function(r){
            if (r.success) {
                showMsg($msg, r.data.message, 'success');
                setTimeout(function(){
                    reloadWithCacheBuster();
                }, 1000);
            } else {
                $btn.prop('disabled', false).text('Register');
                showMsg($msg, getAjaxErrorMessage(r, 'Registration failed.'), 'error');
            }
        }).fail(function(){
            $btn.prop('disabled', false).text('Register');
            showMsg($msg, 'Connection error.', 'error');
        });
    });

    // ── Instant Live Search Filters Event Delegations ────────────────────────
    
    // Live search filter for Prescription list
    $(document).on('keyup', '#cmi-rx-search-input', function () {
        var query = $(this).val().toLowerCase().trim();
        $('#cmi-rx-history-tbody tr.cmi-rx-row').each(function () {
            var pname = $(this).find('.cmi-rx-pname').text().toLowerCase();
            var contact = $(this).find('.cmi-rx-contact').text().toLowerCase();
            if (pname.indexOf(query) > -1 || contact.indexOf(query) > -1) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

    // Live search filter for Patient list (partner dashboard)
    $(document).on('keyup', '#cmi-patient-search-input', function(){
        var q = $(this).val().toLowerCase().trim();
        $('#cmi-patients-tbody tr.cmi-patient-row').each(function(){
            var name = $(this).find('.cmi-p-name').text().toLowerCase();
            var mobile = $(this).find('.cmi-p-mobile').text().toLowerCase();
            var email = $(this).find('.cmi-p-email').text().toLowerCase();
            var uid = $(this).find('.cmi-p-uid').text().toLowerCase();
            
            if (name.indexOf(q) > -1 || mobile.indexOf(q) > -1 || email.indexOf(q) > -1 || uid.indexOf(q) > -1) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

    // Live search filter for Patient history list (partner dashboard)
    $(document).on('keyup', '#cmi-history-search-input', function(){
        var q = $(this).val().toLowerCase().trim();
        $('#cmi-history-tbody tr.cmi-history-row').each(function(){
            var title = $(this).find('.cmi-history-title').text().toLowerCase();
            var type = $(this).find('.cmi-history-type').text().toLowerCase();
            if(title.indexOf(q) > -1 || type.indexOf(q) > -1) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

    // ── Patient Secure OTP Download Modal Event Delegations ───────────────────
    
    var pendingReportId = null;
    var $modal = $('#cmi-patient-otp-modal');

    function openPatientOtpModal(reportId, emailHint) {
        pendingReportId = reportId;
        $('#cmi-modal-otp-input').val('');
        $('#cmi-modal-error').hide();
        $('#cmi-otp-modal-msg').text('An OTP has been sent to ' + emailHint + '. Enter it below to download your report.');
        $('#cmi-patient-otp-modal').css('display', 'flex');
        setTimeout(function(){ $('#cmi-modal-otp-input').focus(); }, 100);
    }

    function closePatientOtpModal() {
        $('#cmi-patient-otp-modal').hide();
        pendingReportId = null;
    }

    function sendPatientOtp(reportId, successCallback, errorCallback) {
        $.post(cmiPP.ajaxurl, {
            action:    'cmi_patient_send_email_otp',
            nonce:     cmiPP.nonce,
            report_id: reportId
        }, function(r){
            if (r.success) {
                successCallback(r.data.email);
            } else {
                errorCallback(getAjaxErrorMessage(r, 'Could not send OTP. Please try again.'));
            }
        }).fail(function(){
            errorCallback('Connection error. Please try again.');
        });
    }

    $(document).on('click', '.cmi-patient-download-btn', function(){
        var $btn = $(this).prop('disabled', true).text('Processing...');
        var reportId = $(this).data('id');

        $.ajax({
            url: cmiPP.ajaxurl,
            type: 'POST',
            data: {
                action: 'cmi_get_download_link',
                report_id: reportId,
                nonce: cmiPP.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false).text('Download PDF');
                if (response.success) {
                    window.location.href = response.data.url;
                } else {
                    alert(getAjaxErrorMessage(response, 'Access denied.'));
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Download PDF');
                alert('Connection error. Please try again.');
            }
        });
    });


    $(document).on('click', '#cmi-modal-cancel-btn', closePatientOtpModal);

    $(document).on('click', '#cmi-modal-resend-btn', function(e){
        e.preventDefault();
        if (!pendingReportId) return;
        $(this).text('Sending…');
        var $self = $(this);
        sendPatientOtp(pendingReportId, 
            function(emailHint){
                $self.text('Resend OTP');
                $('#cmi-otp-modal-msg').text('A new OTP has been sent to ' + emailHint + '.');
                $('#cmi-modal-otp-input').val('').focus();
            },
            function(errorMsg){
                $self.text('Resend OTP');
                alert(errorMsg);
            }
        );
    });

    $(document).on('click', '#cmi-modal-verify-btn', function(){
        var otp = $('#cmi-modal-otp-input').val().replace(/\D/g,'');
        if (otp.length !== 6) {
            $('#cmi-modal-error').text('Please enter the 6-digit OTP.').show();
            return;
        }
        var $btn = $(this).prop('disabled', true).text('Verifying…');

        $.post(cmiPP.ajaxurl, {
            action:    'cmi_patient_verify_email_otp',
            nonce:     cmiPP.nonce,
            otp:       otp,
            report_id: pendingReportId
        }, function(r){
            $btn.prop('disabled', false).text('Verify & Download');
            if (r.success) {
                closePatientOtpModal();
                window.location.href = r.data.url;
            } else {
                $('#cmi-modal-error').text(getAjaxErrorMessage(r, 'Invalid OTP.')).show();
                $('#cmi-modal-otp-input').val('').focus();
            }
        }).fail(function(){
            $btn.prop('disabled', false).text('Verify & Download');
            $('#cmi-modal-error').text('Connection error. Please try again.').show();
        });
    });

    // Close on backdrop click
    $(document).on('click', '#cmi-patient-otp-modal', function(e){
        if ($(e.target).is('#cmi-patient-otp-modal')) closePatientOtpModal();
    });

    // Close on Escape key
    $(document).on('keydown', function(e){
        if (e.key === 'Escape' && $('#cmi-patient-otp-modal').is(':visible')) closePatientOtpModal();
    });

    // ── Guest Access OTP / Download Event Delegations ────────────────────────
    
    var guestMobile = '';
    var guestPatientId = '';
    var guestOtpContext = 'mobile'; // 'mobile' or 'uid'

    function resetGuestDownload() {
        $('.cmi-tab-content').hide();
        $('#cmi-step-otp').hide();
        $('#cmi-step-reports').hide();
        $('.cmi-tabs').show();
        $('#cmi-guest-mobile').val('');
        $('#cmi-guest-patient-id').val('');
        $('#cmi-guest-email').val('');
        $('#cmi-otp-input').val('');
        $('#cmi-guest-msg').hide();
        guestMobile = '';
        guestPatientId = '';
        guestOtpContext = 'mobile';
        $('#cmi-send-otp-btn').prop('disabled', false).text('Send OTP');
        $('#cmi-send-uid-otp-btn').prop('disabled', false).text('Send OTP');
        $('#cmi-view-reports-btn').prop('disabled', false).text('Get Access Link');
    }

    // Check on load if Magic Token is present in URL
    var urlParamsGuest = new URLSearchParams(window.location.search);
    var magicToken = urlParamsGuest.get('cmi_magic_token');

    if (magicToken) {
        $('.cmi-tabs').hide();
        $('.cmi-tab-content').hide();
        var $guestMsg = $('#cmi-guest-msg');
        showMsg($guestMsg, 'Verifying secure access link...', 'success');

        $.post(cmiPP.ajaxurl, {
            action: 'cmi_guest_verify_magic_token',
            nonce: cmiPP.nonce,
            token: magicToken
        }, function(r){
            if (r.success) {
                renderGuestReports(r.data.reports);
                $guestMsg.hide();
                $('#cmi-step-reports').show();
            } else {
                showMsg($guestMsg, getAjaxErrorMessage(r, 'Invalid or expired secure access link.'), 'error');
                setTimeout(function(){
                    window.location.href = window.location.pathname;
                }, 4000);
            }
        }).fail(function(){
            showMsg($guestMsg, 'Connection error verification failed.', 'error');
        });
    }

    // Send SMS OTP for mobile number
    $(document).on('click', '#cmi-send-otp-btn, #cmi-resend-otp-btn', function(){
        if (guestOtpContext === 'uid') {
            sendGuestUidOtp();
            return;
        }

        guestMobile = $('#cmi-guest-mobile').val().replace(/\D/g,'');
        var $guestMsg = $('#cmi-guest-msg');
        if(guestMobile.length < 10){ showMsg($guestMsg, 'Please enter a valid 10-digit mobile number.', 'error'); return; }

        var $btn = $(this).prop('disabled',true).text('Sending…');
        $.post(cmiPP.ajaxurl, {action:'cmi_guest_send_otp', nonce:cmiPP.nonce, mobile:guestMobile}, function(r){
            $btn.prop('disabled',false).text('Resend OTP');
            if(r.success){
                guestOtpContext = 'mobile';
                $('#cmi-otp-sent-status').text(r.data.message);
                $('.cmi-tabs').hide();
                $('#cmi-step-mobile').hide();
                $('#cmi-step-otp').show();
                $guestMsg.hide();
            } else {
                showMsg($guestMsg, getAjaxErrorMessage(r, 'Could not send OTP.'), 'error');
            }
        }).fail(function(){
            $btn.prop('disabled',false).text('Resend OTP');
            showMsg($guestMsg, 'Connection error.', 'error');
        });
    });

    // Send OTP for Patient ID
    $(document).on('click', '#cmi-send-uid-otp-btn', function(){
        sendGuestUidOtp();
    });

    function sendGuestUidOtp() {
        guestPatientId = $('#cmi-guest-patient-id').val().trim();
        var $guestMsg = $('#cmi-guest-msg');
        if(!guestPatientId){ showMsg($guestMsg, 'Please enter a Patient ID.', 'error'); return; }

        var $btn = $('#cmi-send-uid-otp-btn').prop('disabled',true).text('Sending…');
        $.post(cmiPP.ajaxurl, {action:'cmi_guest_uid_send_otp', nonce:cmiPP.nonce, uid:guestPatientId}, function(r){
            $btn.prop('disabled',false).text('Send OTP');
            if(r.success){
                guestOtpContext = 'uid';
                $('#cmi-otp-sent-status').text(r.data.message);
                $('.cmi-tabs').hide();
                $('#cmi-step-patient_id').hide();
                $('#cmi-step-otp').show();
                $guestMsg.hide();
            } else {
                showMsg($guestMsg, getAjaxErrorMessage(r, 'Could not send OTP.'), 'error');
            }
        }).fail(function(){
            $btn.prop('disabled',false).text('Send OTP');
            showMsg($guestMsg, 'Connection error.', 'error');
        });
    }

    // Verify OTP (Works for both mobile and uid contexts)
    $(document).on('click', '#cmi-verify-otp-btn', function(){
        var otp = $('#cmi-otp-input').val().replace(/\D/g,'');
        var $guestMsg = $('#cmi-guest-msg');
        if(otp.length !== 6){ showMsg($guestMsg, 'Please enter the 6-digit OTP.', 'error'); return; }

        var $btn = $(this).prop('disabled',true).text('Verifying…');
        var actionName = (guestOtpContext === 'uid') ? 'cmi_guest_uid_verify_otp' : 'cmi_guest_verify_otp';
        var postData = {action: actionName, nonce: cmiPP.nonce, otp: otp};

        if (guestOtpContext === 'uid') {
            postData.uid = guestPatientId;
        } else {
            postData.mobile = guestMobile;
        }

        $.post(cmiPP.ajaxurl, postData, function(r){
            $btn.prop('disabled',false).text('Verify & View Reports');
            if(r.success){
                renderGuestReports(r.data.reports);
                $('#cmi-step-otp').hide();
                $('#cmi-step-reports').show();
            } else {
                showMsg($guestMsg, getAjaxErrorMessage(r, 'Invalid OTP.'), 'error');
            }
        }).fail(function(){
            $btn.prop('disabled',false).text('Verify & View Reports');
            showMsg($guestMsg, 'Connection error.', 'error');
        });
    });

    // Request Email Magic Link (Without OTP)
    $(document).on('click', '#cmi-view-reports-btn', function(){
        var email = $('#cmi-guest-email').val().trim();
        var $guestMsg = $('#cmi-guest-msg');
        if(!email || email.indexOf('@') === -1){ showMsg($guestMsg, 'Please enter a valid email address.', 'error'); return; }

        var $btn = $(this).prop('disabled',true).text('Sending Link…');
        var redirectUrl = window.location.origin + window.location.pathname;

        $.post(cmiPP.ajaxurl, {
            action: 'cmi_guest_email_access',
            nonce: cmiPP.nonce,
            email: email,
            redirect_url: redirectUrl
        }, function(r){
            $btn.prop('disabled',false).text('Get Access Link');
            if(r.success){
                showMsg($guestMsg, r.data.message, 'success');
                $('#cmi-guest-email').val('');
            } else {
                showMsg($guestMsg, getAjaxErrorMessage(r, 'Could not request access link.'), 'error');
            }
        }).fail(function(){
            $btn.prop('disabled',false).text('Get Access Link');
            showMsg($guestMsg, 'Connection error.', 'error');
        });
    });

    function renderGuestReports(reports){
        var $tbody = $('#cmi-guest-reports-table tbody').empty();
        reports.forEach(function(rep){
            var $row = $('<tr>').append(
                $('<td>').text(rep.title),
                $('<td>').text(rep.type),
                $('<td>').text(rep.date),
                $('<td>').append(
                    $('<button class="button">Download</button>').on('click', function(){
                        var $b = $(this).prop('disabled',true).text('Preparing…');
                        $.post(cmiPP.ajaxurl, {action:'cmi_guest_download', nonce:cmiPP.nonce, token:rep.token}, function(res){
                            $b.prop('disabled',false).text('Download');
                            if(res.success){ window.location.href = res.data.url; }
                            else { alert(getAjaxErrorMessage(res, 'Could not download report.')); }
                        }).fail(function(){
                            $b.prop('disabled',false).text('Download');
                            alert('Connection error.');
                        });
                    })
                )
            );
            $tbody.append($row);
        });
    }

    $(document).on('click', '#cmi-restart-btn', function(e){
        e.preventDefault();
        resetGuestDownload();
        var activeTab = $('.cmi-guest-tab-container .cmi-tab-btn.active').data('tab');
        $('#cmi-step-' + activeTab).show();
    });

    // ── Helpers ────────────────────────────────────────────────────────────
    function showMsg($el, msg, type) {
        $el.removeClass('cmi-msg-error cmi-msg-success')
           .addClass(type === 'error' ? 'cmi-msg-error' : 'cmi-msg-success')
           .text(msg).show();
    }

    // ── Patient / Member Selection in Checkout ──────────────────────────────
    $(document).on('change', '#cmi_patient_member_id', function() {
        if ($(this).val() === 'new') {
            $('#cmi_new_patient_form_wrapper').slideDown();
        } else {
            $('#cmi_new_patient_form_wrapper').slideUp();
        }
    });

    // Run once on load to set initial state if needed
    if ($('#cmi_patient_member_id').length) {
        if ($('#cmi_patient_member_id').val() === 'new') {
            $('#cmi_new_patient_form_wrapper').show();
        } else {
            $('#cmi_new_patient_form_wrapper').hide();
        }
    }

    $(document).on('click', '#cmi_add_patient_ajax_btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $msg = $('#cmi_ajax_member_msg');

        var name = $('#cmi_new_patient_name').val().trim();
        var gender = $('#cmi_new_patient_gender').val();
        var dob = $('#cmi_new_patient_dob').val();
        var relationship = $('#cmi_new_patient_relationship').val();
        var mobile = $('#cmi_new_patient_mobile').val().trim();

        if (!name || !gender || !dob || !relationship) {
            $msg.css('color', 'red').text('Please fill in all required fields (*).');
            return;
        }

        $btn.prop('disabled', true).text('Saving...');
        $msg.css('color', '#3182ce').text('Saving member...');

        $.ajax({
            url: cmiPP.ajaxurl,
            type: 'POST',
            data: {
                action: 'cmi_add_family_member',
                nonce: cmiPP.nonce,
                name: name,
                gender: gender,
                dob: dob,
                relationship: relationship,
                mobile: mobile
            },
            success: function(response) {
                $btn.prop('disabled', false).text('Save & Select Member');
                if (response.success) {
                    $msg.css('color', 'green').text(response.data.message);
                    
                    // Add new option to select dropdown
                    var newOptionText = response.data.name + ' (' + response.data.relationship + ')';
                    var newOptionVal = response.data.member_id;
                    
                    // Append option and select it
                    var $select = $('#cmi_patient_member_id');
                    $select.find('option[value="new"]').before('<option value="' + newOptionVal + '">' + newOptionText + '</option>');
                    $select.val(newOptionVal).trigger('change');
                    
                    // Clear inputs
                    $('#cmi_new_patient_name').val('');
                    $('#cmi_new_patient_dob').val('');
                    $('#cmi_new_patient_mobile').val('');
                    
                    // Hide form container
                    $('#cmi_new_patient_form_wrapper').slideUp();
                    $msg.text('');
                } else {
                    $msg.css('color', 'red').text(getAjaxErrorMessage(response, 'Failed to save member.'));
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Save & Select Member');
                $msg.css('color', 'red').text('Connection error. Please try again.');
            }
        });
    });

    // Global variable to store active Jitsi API instance and polling state
    let jitsiApiInstance = null;
    let patientPollInterval = null;
    let activeConsultId = null;

    // Start or Join Video Consultation Call
    $(document).on('click', '.cmi-start-video-btn, .cmi-join-video-btn', function(e) {
        e.preventDefault();
        const btn = $(this);
        const consultId = btn.data('id');
        activeConsultId = consultId;

        if (!consultId) {
            alert('Invalid consultation reference.');
            return;
        }

        // Show modal immediately so the user sees feedback while the AJAX runs.
        $('#cmi-jitsi-overlay').css('display', 'flex');
        $('#cmi-jitsi-loading').show();
        $('#cmi-jitsi-meeting-iframe').html('');

        // Clear any active polling first
        if (patientPollInterval) {
            clearInterval(patientPollInterval);
            patientPollInterval = null;
        }

        // ── Step 1: Server-side access gate ──────────────────────────────────
        // cmi_validate_meeting_access checks ownership and consultation status.
        // On success it returns { room_name, is_moderator, status, doctor_name, domain }
        const checkAccessAndLaunch = (isPoll) => {
            $.ajax({
                url: cmiPP.ajaxurl,
                type: 'POST',
                data: {
                    action: 'cmi_validate_meeting_access',
                    id: consultId,
                    nonce: cmiPP.nonce
                },
                success: function(response) {
                    if (!response.success) {
                        if (patientPollInterval) {
                            clearInterval(patientPollInterval);
                            patientPollInterval = null;
                        }
                        $('#cmi-jitsi-loading').hide();
                        const errMsg = (response.data && response.data.message) ? response.data.message : 'Access denied. This meeting is no longer available.';
                        $('#cmi-jitsi-meeting-iframe').html(
                            '<div id="cmi-meeting-error-screen" style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; color:#fff; gap:16px; padding:20px; text-align:center; font-family:system-ui, -apple-system, sans-serif;">' +
                            '  <div style="width:64px; height:64px; background:rgba(239, 68, 68, 0.15); border:1px solid rgba(239, 68, 68, 0.3); border-radius:50%; display:flex; align-items:center; justify-content:center; margin-bottom:8px;">' +
                            '    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>' +
                            '  </div>' +
                            '  <h3 style="color:#fff; margin:0; font-size:18px; font-weight:600;">Access Restricted</h3>' +
                            '  <p style="color:#a1a1aa; margin:0; font-size:14px; max-width:400px; line-height: 1.5;">' + errMsg + '</p>' +
                            '  <button type="button" class="cmi-close-error-modal-btn" style="margin-top: 10px; background:#27272a; border:1px solid #3f3f46; color:#e4e4e7; font-size:14px; font-weight:600; padding:10px 24px; border-radius:8px; cursor:pointer; transition:all 0.15s;" onmouseover="this.style.background=\'#3f3f46\'" onmouseout="this.style.background=\'#27272a\'">Go Back</button>' +
                            '</div>'
                        );
                        return;
                    }

                    const roomName = response.data.room_name;
                    if (!roomName) {
                        if (patientPollInterval) {
                            clearInterval(patientPollInterval);
                            patientPollInterval = null;
                        }
                        $('#cmi-jitsi-loading').hide();
                        $('#cmi-jitsi-meeting-iframe').html(
                            '<div id="cmi-meeting-error-screen" style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; color:#fff; gap:16px; padding:20px; text-align:center; font-family:system-ui, -apple-system, sans-serif;">' +
                            '  <div style="width:64px; height:64px; background:rgba(239, 68, 68, 0.15); border:1px solid rgba(239, 68, 68, 0.3); border-radius:50%; display:flex; align-items:center; justify-content:center; margin-bottom:8px;">' +
                            '    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>' +
                            '  </div>' +
                            '  <h3 style="color:#fff; margin:0; font-size:18px; font-weight:600;">Meeting Not Initialised</h3>' +
                            '  <p style="color:#a1a1aa; margin:0; font-size:14px; max-width:400px; line-height: 1.5;">Meeting room has not been initialised yet. Please contact support.</p>' +
                            '  <button type="button" class="cmi-close-error-modal-btn" style="margin-top: 10px; background:#27272a; border:1px solid #3f3f46; color:#e4e4e7; font-size:14px; font-weight:600; padding:10px 24px; border-radius:8px; cursor:pointer; transition:all 0.15s;" onmouseover="this.style.background=\'#3f3f46\'" onmouseout="this.style.background=\'#27272a\'">Go Back</button>' +
                            '</div>'
                        );
                        return;
                    }

                    const isModerator = !!response.data.is_moderator;
                    const meetingStatus = response.data.status;

                    // If user is a patient (not doctor/moderator) and the meeting is still 'assigned' or 'scheduled' (doctor has not joined),
                    // show a waiting screen and keep polling.
                    if (!isModerator && (meetingStatus === 'scheduled' || meetingStatus === 'assigned')) {
                        $('#cmi-jitsi-loading').hide();
                        
                        // Render waiting screen if not already done
                        if ($('#cmi-patient-waiting-screen').length === 0) {
                            $('#cmi-jitsi-meeting-iframe').html(
                                '<div id="cmi-patient-waiting-screen" style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; color:#fff; gap:16px; padding:20px; text-align:center; font-family:system-ui, -apple-system, sans-serif;">' +
                                '  <div class="cmi-spinner" style="width:48px; height:48px; border:3px solid #27272a; border-top-color:#3b82f6; border-radius:50%; animation: cmi-spin 1s linear infinite;"></div>' +
                                '  <h3 id="cmi-waiting-title" style="color:#fff; margin:0; font-size:18px; font-weight:600;">Waiting for ' + response.data.doctor_name + ' to join...</h3>' +
                                '  <div style="background:#18181b; border:1px solid #27272a; padding:12px 20px; border-radius:8px; margin:8px 0; display:flex; flex-direction:column; gap:4px; min-width:280px; align-items:center;">' +
                                '    <span style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:#71717a;">Scheduled Time</span>' +
                                '    <span style="font-size:15px; font-weight:600; color:#f4f4f5;">' + (response.data.preferred_date || 'N/A') + '</span>' +
                                '    <span style="font-size:13px; color:#a1a1aa;">' + (response.data.preferred_time || '') + '</span>' +
                                '  </div>' +
                                '  <p id="cmi-waiting-desc" style="color:#a1a1aa; margin:0; font-size:14px; max-width:380px; line-height:1.5;">The video consultation room will open automatically as soon as your doctor starts the meeting.</p>' +
                                '</div>'
                            );
                        }

                        // Update waiting screen text dynamically based on doctor status
                        if (response.data.doctor_busy) {
                            $('#cmi-waiting-title').text('Doctor is finishing the previous consultation.');
                            $('#cmi-waiting-desc').text('Please stay on this page. You will automatically join the call as soon as the doctor is available.');
                        } else {
                            $('#cmi-waiting-title').text('Waiting for ' + response.data.doctor_name + ' to join...');
                            $('#cmi-waiting-desc').text('The video consultation room will open automatically as soon as your doctor starts the meeting.');
                        }

                        // Start polling if not already started
                        if (!patientPollInterval) {
                            patientPollInterval = setInterval(function() {
                                checkAccessAndLaunch(true);
                            }, 10000);
                        }
                        return;
                    }

                    // Otherwise (if doctor, or if meeting is in_progress), stop polling and launch Jitsi iframe
                    if (patientPollInterval) {
                        clearInterval(patientPollInterval);
                        patientPollInterval = null;
                    }
                    $('#cmi-jitsi-loading').show();
                    $('#cmi-jitsi-meeting-iframe').html('');

                    // Populate consultation details sidebar
                    let sidebarHtml = '';
                    if (isModerator) {
                        // Current user is doctor, viewing patient info
                        sidebarHtml = `
                            <div style="display:flex; flex-direction:column; gap:16px;">
                                <div>
                                    <div style="font-size:11px; text-transform:uppercase; color:#71717a; font-weight:600; letter-spacing:0.05em; margin-bottom:4px;">Patient Name</div>
                                    <div style="font-size:15px; font-weight:600; color:#f4f4f5;">${response.data.patient_name || 'N/A'}</div>
                                </div>
                                <div style="display:flex; gap:16px;">
                                    <div style="flex:1;">
                                        <div style="font-size:11px; text-transform:uppercase; color:#71717a; font-weight:600; letter-spacing:0.05em; margin-bottom:4px;">Gender</div>
                                        <div style="font-size:14px; color:#e4e4e7;">${response.data.patient_gender || 'N/A'}</div>
                                    </div>
                                    <div style="flex:1;">
                                        <div style="font-size:11px; text-transform:uppercase; color:#71717a; font-weight:600; letter-spacing:0.05em; margin-bottom:4px;">DOB</div>
                                        <div style="font-size:14px; color:#e4e4e7;">${response.data.patient_dob || 'N/A'}</div>
                                    </div>
                                </div>
                                <div style="display:flex; gap:16px;">
                                    <div style="flex:1;">
                                        <div style="font-size:11px; text-transform:uppercase; color:#71717a; font-weight:600; letter-spacing:0.05em; margin-bottom:4px;">Mobile</div>
                                        <div style="font-size:14px; color:#e4e4e7;">${response.data.patient_mobile || 'N/A'}</div>
                                    </div>
                                    <div style="flex:1;">
                                        <div style="font-size:11px; text-transform:uppercase; color:#71717a; font-weight:600; letter-spacing:0.05em; margin-bottom:4px;">Relationship</div>
                                        <div style="font-size:14px; color:#e4e4e7;">${response.data.patient_relationship || 'Self'}</div>
                                    </div>
                                </div>
                                <div>
                                    <div style="font-size:11px; text-transform:uppercase; color:#71717a; font-weight:600; letter-spacing:0.05em; margin-bottom:4px;">Consultation Type</div>
                                    <div style="font-size:14px; color:#e4e4e7; font-weight:500;">${response.data.consultation_type}</div>
                                </div>
                                <div style="border-top:1px solid #27272a; padding-top:12px;">
                                    <div style="font-size:11px; text-transform:uppercase; color:#71717a; font-weight:600; letter-spacing:0.05em; margin-bottom:6px;">Symptoms / Reason</div>
                                    <div style="font-size:13px; color:#d4d4d8; background:#09090b; padding:10px; border-radius:6px; border:1px solid #27272a; max-height:180px; overflow-y:auto; line-height:1.5; white-space:pre-line;">${response.data.symptoms || 'No symptoms provided.'}</div>
                                </div>
                            </div>
                        `;
                    } else {
                        // Current user is patient, viewing doctor info
                        sidebarHtml = `
                            <div style="display:flex; flex-direction:column; gap:16px;">
                                <div>
                                    <div style="font-size:11px; text-transform:uppercase; color:#71717a; font-weight:600; letter-spacing:0.05em; margin-bottom:4px;">Consulting Doctor</div>
                                    <div style="font-size:15px; font-weight:600; color:#f4f4f5;">${response.data.doctor_name}</div>
                                </div>
                                <div>
                                    <div style="font-size:11px; text-transform:uppercase; color:#71717a; font-weight:600; letter-spacing:0.05em; margin-bottom:4px;">Specialty / Department</div>
                                    <div style="font-size:14px; color:#e4e4e7;">${response.data.doctor_specialty}</div>
                                </div>
                                <div>
                                    <div style="font-size:11px; text-transform:uppercase; color:#71717a; font-weight:600; letter-spacing:0.05em; margin-bottom:4px;">Consultation Fee</div>
                                    <div style="font-size:14px; color:#22c55e; font-weight:600;">₹${response.data.doctor_fee}</div>
                                </div>
                                <div style="border-top:1px solid #27272a; padding-top:12px;">
                                    <div style="font-size:11px; text-transform:uppercase; color:#71717a; font-weight:600; letter-spacing:0.05em; margin-bottom:4px;">Appointment Date</div>
                                    <div style="font-size:14px; color:#e4e4e7;">${response.data.preferred_date}</div>
                                </div>
                                <div>
                                    <div style="font-size:11px; text-transform:uppercase; color:#71717a; font-weight:600; letter-spacing:0.05em; margin-bottom:4px;">Time Slot</div>
                                    <div style="font-size:14px; color:#e4e4e7;">${response.data.preferred_time}</div>
                                </div>
                                <div>
                                    <div style="font-size:11px; text-transform:uppercase; color:#71717a; font-weight:600; letter-spacing:0.05em; margin-bottom:4px;">Consultation Type</div>
                                    <div style="font-size:14px; color:#e4e4e7;">${response.data.consultation_type}</div>
                                </div>
                            </div>
                        `;
                    }
                    $('#cmi-consultation-sidebar-content').html(sidebarHtml);

                    // ── Step 2: Launch Jitsi only after the server grants access ──
                    const launchMeeting = () => {
                        try {
                            const domain = response.data.domain || '8x8.vc';
                            const appId = response.data.app_id || '';

                            // For JaaS (8x8.vc), roomName must be formatted as "<AppID>/<RoomName>"
                            let targetRoom = roomName;
                            if (appId && !targetRoom.startsWith(appId + '/')) {
                                targetRoom = appId + '/' + targetRoom;
                            }

                            const toolbarButtons = [
                                'microphone', 'camera', 'closedcaptions', 'desktop', 'fullscreen',
                                'fodeviceselection', 'hangup', 'profile', 'chat', 'settings', 'videoquality'
                            ];

                            if (isModerator) {
                                toolbarButtons.push('participants-pane', 'mute-everyone', 'mute-video-everyone', 'security');
                            }

                            const options = {
                                roomName: targetRoom,
                                width: '100%',
                                height: '100%',
                                parentNode: document.querySelector('#cmi-jitsi-meeting-iframe'),
                                userInfo: {
                                    displayName: cmiPP.isDoctor ? response.data.doctor_name : response.data.patient_name
                                },
                                configOverwrite: {
                                    startWithAudioMuted: false,
                                    startWithVideoMuted: false,
                                    prejoinPageEnabled: false,
                                    prejoinConfig: {
                                        enabled: false
                                    },
                                    p2p: {
                                        enabled: true
                                    }
                                },
                                interfaceConfigOverwrite: {
                                    TOOLBAR_BUTTONS: toolbarButtons
                                }
                            };

                            if (response.data.jwt) {
                                options.jwt = response.data.jwt;
                            }

                            jitsiApiInstance = new JitsiMeetExternalAPI(domain, options);

                            // Safe fallback: fade out loader after 2.5 seconds in case the prejoin screen
                            // is still rendered by Jitsi (so the loader doesn't block the view).
                            setTimeout(function() {
                                $('#cmi-jitsi-loading').fadeOut(200);
                            }, 2500);

                            jitsiApiInstance.addEventListener('videoConferenceJoined', function() {
                                $('#cmi-jitsi-loading').hide();

                                $.ajax({
                                    url: cmiPP.ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'cmi_update_meeting_status',
                                        id: consultId,
                                        status: 'in_progress',
                                        nonce: cmiPP.nonce
                                    }
                                });
                            });

                            jitsiApiInstance.addEventListener('videoConferenceLeft', function() {
                                if (cmiPP.isDoctor && activeConsultId) {
                                    $.ajax({
                                        url: cmiPP.ajaxurl,
                                        type: 'POST',
                                        data: {
                                            action: 'cmi_update_meeting_status',
                                            id: activeConsultId,
                                            status: 'awaiting_prescription',
                                            nonce: cmiPP.nonce
                                        },
                                        complete: function() {
                                            closeJitsiMeeting();
                                        }
                                    });
                                } else {
                                    closeJitsiMeeting();
                                }
                            });

                            jitsiApiInstance.addEventListener('readyToClose', function() {
                                if (cmiPP.isDoctor && activeConsultId) {
                                    $.ajax({
                                        url: cmiPP.ajaxurl,
                                        type: 'POST',
                                        data: {
                                            action: 'cmi_update_meeting_status',
                                            id: activeConsultId,
                                            status: 'awaiting_prescription',
                                            nonce: cmiPP.nonce
                                        },
                                        complete: function() {
                                            closeJitsiMeeting();
                                        }
                                    });
                                } else {
                                    closeJitsiMeeting();
                                }
                            });
                        } catch (err) {
                            console.error('Jitsi initialization error:', err);
                            $('#cmi-jitsi-loading').hide();
                            $('#cmi-jitsi-meeting-iframe').html('<div style="color:#ef4444; padding:20px; text-align:center; font-weight:600;">Failed to connect to Jitsi Server. Try refreshing the page.</div>');
                        }
                    };

                    const domain = response.data.domain || '8x8.vc';
                    const appId = response.data.app_id || '';

                    // Load Jitsi script dynamically from the configured domain if not already present.
                    if (typeof JitsiMeetExternalAPI === 'undefined') {
                        const script = document.createElement('script');
                        let scriptSrc = 'https://' + domain + '/external_api.js';
                        if (appId && (domain === '8x8.vc' || domain.indexOf('8x8.vc') > -1)) {
                            scriptSrc = 'https://8x8.vc/' + appId + '/external_api.js';
                        }
                        script.src = scriptSrc;
                        script.onload = launchMeeting;
                        script.onerror = function() {
                            $('#cmi-jitsi-loading').hide();
                            $('#cmi-jitsi-meeting-iframe').html('<div style="color:#ef4444; padding:20px; text-align:center; font-weight:600;">Failed to load Jitsi API script. Please check your internet connection.</div>');
                        };
                        document.head.appendChild(script);
                    } else {
                        launchMeeting();
                    }
                },
                error: function() {
                    if (!isPoll) {
                        $('#cmi-jitsi-overlay').hide();
                        $('#cmi-jitsi-loading').hide();
                        alert('Connection error. Could not verify meeting access. Please try again.');
                    }
                }
            });
        };

        // Run the access check immediately
        checkAccessAndLaunch(false);
    });

    // Close Jitsi Overlay & Hangup
    function closeJitsiMeeting() {
        if (patientPollInterval) {
            clearInterval(patientPollInterval);
            patientPollInterval = null;
        }
        if (jitsiApiInstance) {
            jitsiApiInstance.dispose();
            jitsiApiInstance = null;
        }
        $('#cmi-jitsi-overlay').hide();
        $('#cmi-jitsi-meeting-iframe').html('');
        // Cache-busting reload ensures the latest consultation status is shown after the call ends
        reloadWithCacheBuster();
    }

    $(document).on('click', '#cmi-close-jitsi-btn', function() {
        if (!jitsiApiInstance || confirm('Are you sure you want to leave the consultation room?')) {
            if (cmiPP.isDoctor && activeConsultId) {
                $.ajax({
                    url: cmiPP.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cmi_update_meeting_status',
                        id: activeConsultId,
                        status: 'awaiting_prescription',
                        nonce: cmiPP.nonce
                    },
                    complete: function() {
                        closeJitsiMeeting();
                    }
                });
            } else {
                closeJitsiMeeting();
            }
        }
    });

    $(document).on('click', '.cmi-close-error-modal-btn', function() {
        closeJitsiMeeting();
    });

    // Request Reschedule (Consultation)
    $(document).on('click', '.cmi-request-reschedule-btn', function(e) {
        e.preventDefault();
        var btn = $(this);
        var id = btn.data('id');

        if (!confirm('Are you sure you want to request the care team to reschedule this missed consultation?')) {
            return;
        }

        btn.prop('disabled', true).text('Submitting...');

        $.ajax({
            url: cmiPP.ajaxurl,
            type: 'POST',
            data: {
                action: 'cmi_request_admin_reschedule',
                id: id,
                nonce: cmiPP.nonce
            },
            success: function(response) {
                if (response.success) {
                    btn.text('Request Received').css('background-color', '#22c55e').css('color', '#fff');
                    setTimeout(function() { reloadWithCacheBuster(); }, 1500);
                } else {
                    btn.prop('disabled', false).text('Request Reschedule');
                    alert(getAjaxErrorMessage(response, 'Could not submit your reschedule request. Please try again.'));
                }
            },
            error: function() {
                btn.prop('disabled', false).text('Request Reschedule');
                alert('Connection error. Please check your internet and try again.');
            }
        });
    });
});
