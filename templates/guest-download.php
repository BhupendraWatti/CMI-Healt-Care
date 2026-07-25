<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="cmi-guest-download">
    <div class="cmi-guest-box">
        <h2>Download Your Report</h2>
        <p>Access your patient reports quickly. Choose your preferred verification method below.</p>

        <div class="cmi-tabs">
            <button type="button" class="cmi-tab-btn active" data-tab="mobile">Mobile Number (OTP)</button>
            <button type="button" class="cmi-tab-btn" data-tab="patient_id">Unique Patient ID (OTP)</button>
            <button type="button" class="cmi-tab-btn" data-tab="email">Email Address (Magic Link)</button>
        </div>

        <div id="cmi-guest-msg" class="cmi-msg" style="display:none"></div>

        <!-- Step 1: Enter mobile -->
        <div id="cmi-step-mobile" class="cmi-tab-content">
            <div class="cmi-form-row">
                <label>Registered Mobile Number</label>
                <input type="tel" id="cmi-guest-mobile" placeholder="10-digit mobile number" maxlength="10" />
            </div>
            <button id="cmi-send-otp-btn" class="button button-primary">Send OTP</button>
        </div>

        <!-- Step 1b: Enter patient ID -->
        <div id="cmi-step-patient_id" class="cmi-tab-content" style="display:none">
            <div class="cmi-form-row">
                <label>Unique Patient ID</label>
                <input type="text" id="cmi-guest-patient-id" placeholder="e.g. CMI12345" />
            </div>
            <button id="cmi-send-uid-otp-btn" class="button button-primary">Send OTP</button>
        </div>

        <!-- Step 1c: Enter email -->
        <div id="cmi-step-email" class="cmi-tab-content" style="display:none">
            <div class="cmi-form-row">
                <label>Registered Email Address</label>
                <input type="email" id="cmi-guest-email" placeholder="patient@example.com" />
            </div>
            <button id="cmi-view-reports-btn" class="button button-primary">Get Access Link</button>
        </div>

        <!-- Step 2: Enter OTP -->
        <div id="cmi-step-otp" style="display:none">
            <p id="cmi-otp-sent-status" class="cmi-success-msg">OTP sent! Please check your mobile/email.</p>
            <div class="cmi-form-row">
                <label>Enter OTP</label>
                <input type="text" id="cmi-otp-input" placeholder="6-digit OTP" maxlength="6" />
            </div>
            <button id="cmi-verify-otp-btn" class="button button-primary">Verify &amp; View Reports</button>
            <button id="cmi-resend-otp-btn" class="button" style="margin-left:8px">Resend OTP</button>
        </div>

        <!-- Step 3: Show reports -->
        <div id="cmi-step-reports" style="display:none">
            <h3>Your Reports</h3>
            <table class="cmi-reports-table" id="cmi-guest-reports-table">
                <thead><tr><th>Report Name</th><th>Type</th><th>Date</th><th>Download</th></tr></thead>
                <tbody></tbody>
            </table>
            <p style="margin-top:1em"><a href="#" id="cmi-restart-btn">← Check another number / email / ID</a></p>
        </div>
    </div>
</div>

<script>
jQuery(function($){
    var mobile = '';
    var patientId = '';
    var otpContext = 'mobile'; // 'mobile' or 'uid'

    // Tab switching – fully reset all steps when switching tabs
    $('.cmi-tab-btn').on('click', function() {
        resetAll();
        $('.cmi-tab-btn').removeClass('active');
        $(this).addClass('active');
        var tab = $(this).data('tab');
        $('#cmi-step-' + tab).show();
    });

    function resetAll() {
        $('.cmi-tab-content').hide();
        $('#cmi-step-otp').hide();
        $('#cmi-step-reports').show().hide(); // resets visual states
        $('#cmi-step-reports').hide();
        $('.cmi-tabs').show();
        $('#cmi-guest-mobile').val('');
        $('#cmi-guest-patient-id').val('');
        $('#cmi-guest-email').val('');
        $('#cmi-otp-input').val('');
        $('#cmi-guest-msg').hide();
        mobile = '';
        patientId = '';
        otpContext = 'mobile';
        $('#cmi-send-otp-btn').prop('disabled', false).text('Send OTP');
        $('#cmi-send-uid-otp-btn').prop('disabled', false).text('Send OTP');
        $('#cmi-view-reports-btn').prop('disabled', false).text('Get Access Link');
    }

    // Check on load if Magic Token is present in URL
    var urlParams = new URLSearchParams(window.location.search);
    var magicToken = urlParams.get('cmi_magic_token');

    if (magicToken) {
        $('.cmi-tabs').hide();
        $('.cmi-tab-content').hide();
        showMsg('Verifying secure access link...', 'success');

        $.post(cmiPP.ajaxurl, {
            action: 'cmi_guest_verify_magic_token',
            nonce: cmiPP.nonce,
            token: magicToken
        }, function(r){
            if (r.success) {
                renderReports(r.data.reports);
                $('#cmi-guest-msg').hide();
                $('#cmi-step-reports').show();
            } else {
                showMsg(r.data.message, 'error');
                setTimeout(function(){
                    window.location.href = window.location.pathname;
                }, 4000);
            }
        });
    }

    // Send SMS OTP for mobile number
    $('#cmi-send-otp-btn, #cmi-resend-otp-btn').on('click', function(){
        if (otpContext === 'uid') {
            sendUidOtp();
            return;
        }

        mobile = $('#cmi-guest-mobile').val().replace(/\D/g,'');
        if(mobile.length < 10){ showMsg('Please enter a valid 10-digit mobile number.', 'error'); return; }

        var $btn = $(this).prop('disabled',true).text('Sending…');
        $.post(cmiPP.ajaxurl, {action:'cmi_guest_send_otp', nonce:cmiPP.nonce, mobile:mobile}, function(r){
            $btn.prop('disabled',false).text('Resend OTP');
            if(r.success){
                otpContext = 'mobile';
                $('#cmi-otp-sent-status').text(r.data.message);
                $('.cmi-tabs').hide();
                $('#cmi-step-mobile').hide();
                $('#cmi-step-otp').show();
                $('#cmi-guest-msg').hide();
            } else {
                showMsg(r.data.message, 'error');
            }
        });
    });

    // Send OTP for Patient ID
    $('#cmi-send-uid-otp-btn').on('click', function(){
        sendUidOtp();
    });

    function sendUidOtp() {
        patientId = $('#cmi-guest-patient-id').val().trim();
        if(!patientId){ showMsg('Please enter a Patient ID.', 'error'); return; }

        var $btn = $('#cmi-send-uid-otp-btn').prop('disabled',true).text('Sending…');
        $.post(cmiPP.ajaxurl, {action:'cmi_guest_uid_send_otp', nonce:cmiPP.nonce, uid:patientId}, function(r){
            $btn.prop('disabled',false).text('Send OTP');
            if(r.success){
                otpContext = 'uid';
                $('#cmi-otp-sent-status').text(r.data.message);
                $('.cmi-tabs').hide();
                $('#cmi-step-patient_id').hide();
                $('#cmi-step-otp').show();
                $('#cmi-guest-msg').hide();
            } else {
                showMsg(r.data.message, 'error');
            }
        });
    }

    // Verify OTP (Works for both mobile and uid contexts)
    $('#cmi-verify-otp-btn').on('click', function(){
        var otp = $('#cmi-otp-input').val().replace(/\D/g,'');
        if(otp.length !== 6){ showMsg('Please enter the 6-digit OTP.', 'error'); return; }

        var $btn = $(this).prop('disabled',true).text('Verifying…');
        var actionName = (otpContext === 'uid') ? 'cmi_guest_uid_verify_otp' : 'cmi_guest_verify_otp';
        var postData = {action: actionName, nonce: cmiPP.nonce, otp: otp};

        if (otpContext === 'uid') {
            postData.uid = patientId;
        } else {
            postData.mobile = mobile;
        }

        $.post(cmiPP.ajaxurl, postData, function(r){
            $btn.prop('disabled',false).text('Verify & View Reports');
            if(r.success){
                renderReports(r.data.reports);
                $('#cmi-step-otp').hide();
                $('#cmi-step-reports').show();
            } else {
                showMsg(r.data.message, 'error');
            }
        });
    });

    // Request Email Magic Link (Without OTP)
    $('#cmi-view-reports-btn').on('click', function(){
        var email = $('#cmi-guest-email').val().trim();
        if(!email || email.indexOf('@') === -1){ showMsg('Please enter a valid email address.', 'error'); return; }

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
                showMsg(r.data.message, 'success');
                $('#cmi-guest-email').val('');
            } else {
                showMsg(r.data.message, 'error');
            }
        });
    });

    function renderReports(reports){
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
                            else { alert(res.data.message); }
                        });
                    })
                )
            );
            $tbody.append($row);
        });
    }

    $('#cmi-restart-btn').on('click', function(e){
        e.preventDefault();
        resetAll();
        var activeTab = $('.cmi-tab-btn.active').data('tab');
        $('#cmi-step-' + activeTab).show();
    });

    function showMsg(msg, type){
        $('#cmi-guest-msg').removeClass('cmi-msg-error cmi-msg-success')
            .addClass(type==='error'?'cmi-msg-error':'cmi-msg-success')
            .text(msg).show();
    }
});
</script>
