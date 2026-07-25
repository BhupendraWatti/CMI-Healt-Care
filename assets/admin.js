jQuery(document).ready(function($) {

    // Helper to reload with a cache-buster query parameter to bypass page caches
    function reloadWithCacheBuster() {
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

    // 1. Admin Assign Partner Dropdown Change
    $('.cmi-partner-assign-select').on('change', function() {
        var select = $(this);
        var id = select.data('id');
        var partner_id = select.val();

        if (!partner_id) {
            return; // No partner selected
        }

        if (!confirm('Are you sure you want to assign this partner to the home testing booking?')) {
            reloadWithCacheBuster();
            return;
        }

        select.prop('disabled', true);

        $.ajax({
            url: cmiHTAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'cmi_ht_assign_partner',
                id: id,
                partner_id: partner_id,
                nonce: cmiHTAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    reloadWithCacheBuster();
                } else {
                    alert(response.data.message || 'Failed to assign partner.');
                    select.prop('disabled', false);
                }
            },
            error: function() {
                alert('Connection error.');
                select.prop('disabled', false);
            }
        });
    });

    // 2. Admin Approve Reschedule Request
    $('.cmi-approve-reschedule').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var id = btn.data('id');

        if (!confirm('Are you sure you want to approve this reschedule request?')) {
            return;
        }

        btn.prop('disabled', true);

        $.ajax({
            url: cmiHTAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'cmi_ht_approve_reschedule',
                id: id,
                nonce: cmiHTAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    reloadWithCacheBuster();
                } else {
                    alert(response.data.message || 'Failed to approve reschedule.');
                    btn.prop('disabled', false);
                }
            },
            error: function() {
                alert('Connection error.');
                btn.prop('disabled', false);
            }
        });
    });

    // 3. Admin Deny Reschedule Request
    $('.cmi-deny-reschedule').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var id = btn.data('id');

        if (!confirm('Are you sure you want to deny this reschedule request?')) {
            return;
        }

        btn.prop('disabled', true);

        $.ajax({
            url: cmiHTAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'cmi_ht_deny_reschedule',
                id: id,
                nonce: cmiHTAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    reloadWithCacheBuster();
                } else {
                    alert(response.data.message || 'Failed to deny reschedule.');
                    btn.prop('disabled', false);
                }
            },
            error: function() {
                alert('Connection error.');
                btn.prop('disabled', false);
            }
        });
    });

    // 4. Admin Approve Partner Registration
    $(document).on('click', '.cmi-approve-btn', function() {
        var id = $(this).data('id');
        var btn = $(this).prop('disabled', true);
        $.post(cmiHTAdmin.ajaxurl, {
            action: 'cmi_approve_partner',
            user_id: id,
            nonce: cmiHTAdmin.nonce
        }, function(r) {
            if (r.success) {
                reloadWithCacheBuster();
            } else {
                alert((r && r.data && r.data.message) ? r.data.message : 'Error approving partner.');
                btn.prop('disabled', false);
            }
        });
    });

    // 5. Admin Reject Partner Registration
    $(document).on('click', '.cmi-reject-btn', function() {
        if (!confirm('Reject this partner registration?')) return;
        var id = $(this).data('id'), row = $(this).closest('tr');
        var btn = $(this).prop('disabled', true);
        $.post(cmiHTAdmin.ajaxurl, {
            action: 'cmi_reject_partner',
            user_id: id,
            nonce: cmiHTAdmin.nonce
        }, function(r) {
            if (r.success) {
                row.fadeOut();
            } else {
                alert((r && r.data && r.data.message) ? r.data.message : 'Error rejecting partner.');
                btn.prop('disabled', false);
            }
        });
    });

    // 6. Admin Disable Partner Account
    $(document).on('click', '.cmi-disable-btn', function() {
        if (!confirm('Disable this partner account? This will set them back to pending and remove their upload privileges.')) return;
        var id = $(this).data('id');
        var btn = $(this).prop('disabled', true);
        $.post(cmiHTAdmin.ajaxurl, {
            action: 'cmi_disable_partner',
            user_id: id,
            nonce: cmiHTAdmin.nonce
        }, function(r) {
            if (r.success) {
                reloadWithCacheBuster();
            } else {
                alert((r && r.data && r.data.message) ? r.data.message : 'Error disabling partner.');
                btn.prop('disabled', false);
            }
        });
    });
});
