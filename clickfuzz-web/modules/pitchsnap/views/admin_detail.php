<?php defined('BASEPATH') or exit('No direct script access allowed'); $redesign = $website ?? null; ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">

        <!-- ── Page header ─────────────────────────────────────────────── -->
        <div class="row" style="margin-bottom:16px; border-bottom:1px solid #eee; padding-bottom:14px;">
            <div class="col-md-8">
                <a href="<?php echo admin_url('pitchsnap/websites'); ?>" class="btn btn-default btn-sm" style="margin-bottom:8px;">
                    <i class="fa fa-arrow-left"></i> All Websites
                </a>
                <h4 class="tw-font-semibold" style="margin:0 0 4px;">
                    <?php
                    if ($lead && (!empty($lead->company) || !empty($lead->name))) {
                        echo e(!empty($lead->company) ? $lead->company : $lead->name);
                    } else {
                        echo 'Website #' . (int) $redesign->id;
                    }
                    ?>
                </h4>
                <div style="font-size:13px;">
                    <span class="text-muted">Website #<?php echo (int) $redesign->id; ?></span>
                    &nbsp;<?php echo ps_badge($redesign->status); ?>
                    <?php if (!empty($redesign->is_primary)) { ?>
                    &nbsp;<span class="label label-default"><i class="fa fa-star"></i> Primary</span>
                    <?php } ?>
                    <?php if (!empty($site) && !empty($site->domain)) { ?>
                    &nbsp;<span class="text-muted"><i class="fa fa-globe"></i> <?php echo e($site->domain); ?></span>
                    <?php } ?>
                </div>
            </div>
            <div class="col-md-4 text-right" style="padding-top:28px;">
                <?php if (!empty($site) && !empty($site->domain)) { ?>
                <a href="https://<?php echo e($site->domain); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-default btn-sm">
                    <i class="fa fa-external-link"></i> View Site
                </a>
                <?php } ?>
                <?php if (!empty($redesign->preview_url)) { ?>
                <a href="<?php echo e($redesign->preview_url); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-success btn-sm">
                    <i class="fa fa-globe"></i> Open Preview
                </a>
                <?php } ?>
                <?php if (!empty($redesign->generation_result)) { ?>
                <a href="<?php echo admin_url('pitchsnap/edit_html/' . (int) $redesign->id); ?>" class="btn btn-default btn-sm">
                    <i class="fa fa-code"></i> Edit HTML
                </a>
                <?php } ?>
            </div>
        </div>

        <!-- ── Tabs ──────────────────────────────────────────────────── -->
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation" class="active">
                <a href="#tab-overview" role="tab" data-toggle="tab">Overview</a>
            </li>
            <li role="presentation">
                <a href="#tab-pages" role="tab" data-toggle="tab">Website</a>
            </li>
            <li role="presentation">
                <a href="#tab-forms" role="tab" data-toggle="tab">Forms</a>
            </li>
            <li role="presentation">
                <a href="#tab-media" role="tab" data-toggle="tab">Media</a>
            </li>
            <li role="presentation">
                <a href="#tab-customer" role="tab" data-toggle="tab">Customer</a>
            </li>
            <li role="presentation">
                <a href="#tab-data" role="tab" data-toggle="tab">Data</a>
            </li>
            <li role="presentation">
                <a href="#tab-onboarding" role="tab" data-toggle="tab">Onboarding</a>
            </li>
            <li role="presentation">
                <a href="#tab-settings" role="tab" data-toggle="tab">Settings</a>
            </li>
        </ul>

        <div class="tab-content" style="padding-top:20px;">

            <?php include __DIR__ . '/admin_detail/tab_overview.php'; ?>

            <?php include __DIR__ . '/admin_detail/tab_pages.php'; ?>

            <?php include __DIR__ . '/admin_detail/tab_forms.php'; ?>

            <?php include __DIR__ . '/admin_detail/tab_media.php'; ?>

            <?php include __DIR__ . '/admin_detail/tab_customer.php'; ?>

            <?php include __DIR__ . '/admin_detail/tab_data.php'; ?>

            <?php include __DIR__ . '/admin_detail/tab_onboarding.php'; ?>

            <!-- Settings: Publishing / Integrations / Activity -->
            <div role="tabpanel" class="tab-pane" id="tab-settings">
                <ul class="nav nav-pills" role="tablist" style="margin-bottom:0;">
                    <li role="presentation" class="active">
                        <a href="#tab-publishing" role="tab" data-toggle="tab">Publishing</a>
                    </li>
                    <li role="presentation">
                        <a href="#tab-ghl" role="tab" data-toggle="tab">Integrations</a>
                    </li>
                    <li role="presentation">
                        <a href="#tab-history" role="tab" data-toggle="tab">Activity</a>
                    </li>
                </ul>
                <div class="tab-content" style="padding-top:20px;">
                    <?php include __DIR__ . '/admin_detail/tab_publishing.php'; ?>
                    <?php include __DIR__ . '/admin_detail/tab_ghl.php'; ?>
                    <?php include __DIR__ . '/admin_detail/tab_history.php'; ?>
                </div>
            </div>

        </div><!-- /.tab-content -->
    </div><!-- /.content -->
</div><!-- #wrapper -->

<!-- Hidden form for delete_profile -->
<form id="ps_delete_profile_form" method="POST" action="" style="display:none;">
    <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
    <input type="hidden" name="confirm_delete" value="1">
</form>
<!-- Hidden form for bulk version delete -->
<form id="ps_bulk_delete_form" method="POST" action="<?php echo admin_url('pitchsnap/delete_versions'); ?>" style="display:none;">
    <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
    <input type="hidden" name="redirect_id" value="<?php echo (int) $redesign->id; ?>">
</form>

<?php init_tail(); ?>
<script>
$(function() {
    // Restore active tab from URL hash on load (handles outer tabs and nested pills)
    var hash = window.location.hash;
    if (hash) {
        var $target = $('[data-toggle="tab"][href="' + hash + '"]');
        if ($target.length) {
            var $pane = $(hash);
            var $parentPane = $pane.parent().closest('.tab-pane');
            if ($parentPane.length) {
                $('[data-toggle="tab"][href="#' + $parentPane.attr('id') + '"]').tab('show');
            }
            $target.tab('show');
        }
    }

    // Keep URL hash in sync whenever any tab or pill is shown
    $('[data-toggle="tab"]').on('shown.bs.tab', function(e) {
        if (history.replaceState) {
            history.replaceState(null, null, e.target.hash);
        } else {
            window.location.hash = e.target.hash;
        }
    });
});
function ps_test_wp_connection(id) {
    var btn = document.getElementById('wp-test-btn');
    var out = document.getElementById('wp-test-result');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Testing…';
    out.innerHTML = '';

    $.ajax({
        url:      admin_url + 'pitchsnap/test_wp_connection/' + id,
        type:     'POST',
        dataType: 'json',
        data:     {<?php echo $this->security->get_csrf_token_name(); ?>: '<?php echo $this->security->get_csrf_hash(); ?>'},
        success: function(r) {
            if (r.success) {
                var info = [];
                if (r.connector_version) { info.push('Connector v' + r.connector_version); }
                if (r.wp_version)        { info.push('WP ' + r.wp_version); }
                if (r.active_theme_name) { info.push('Theme: ' + r.active_theme_name); }
                out.innerHTML = '<span class="text-success"><i class="fa fa-check-circle"></i> Connected</span>'
                    + (info.length ? ' &mdash; <span class="text-muted" style="font-size:12px;">' + info.join(' &middot; ') + '</span>' : '');
                // Refresh page after short delay so status row updates
                setTimeout(function() { location.hash = '#tab-publishing'; location.reload(); }, 1200);
            } else {
                out.innerHTML = '<span class="text-danger"><i class="fa fa-times-circle"></i> ' + (r.error || 'Connection failed.') + '</span>';
                btn.disabled  = false;
                btn.innerHTML = '<i class="fa fa-plug"></i> Test Connection';
            }
        },
        error: function() {
            out.innerHTML = '<span class="text-danger"><i class="fa fa-times-circle"></i> Request failed.</span>';
            btn.disabled  = false;
            btn.innerHTML = '<i class="fa fa-plug"></i> Test Connection';
        }
    });
}

function ps_queue_generate(id) {
    var btn = event.target;
    btn.disabled = true;
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Queuing…';

    $.ajax({
        url:      admin_url + 'pitchsnap/queue_generate/' + id,
        type:     'POST',
        dataType: 'json',
        data:     {<?php echo $this->security->get_csrf_token_name(); ?>: '<?php echo $this->security->get_csrf_hash(); ?>'},
        success: function(resp) {
            if (resp.success) {
                alert_float('success', resp.message);
                setTimeout(function() { window.location.reload(); }, 1200);
            } else {
                alert_float('danger', resp.message);
                btn.disabled  = false;
                btn.innerHTML = orig;
            }
        },
        error: function() {
            alert_float('danger', 'Request failed. Please try again.');
            btn.disabled  = false;
            btn.innerHTML = orig;
        }
    });
}

function ps_modify_html(id) {
    var request = $('#ps_modify_request').val().trim();
    if (!request) { alert_float('warning', 'Please describe the changes you want.'); return; }

    var btn = $(event.target);
    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Applying…');
    $('#ps_modify_status').text('');

    $.ajax({
        url:      admin_url + 'pitchsnap/modify_html/' + id,
        type:     'POST',
        dataType: 'json',
        timeout:  360000,
        data: {
            modification_request: request,
            <?php echo $this->security->get_csrf_token_name(); ?>: '<?php echo $this->security->get_csrf_hash(); ?>'
        },
        success: function(resp) {
            if (resp.success) {
                alert_float('success', resp.message);
                setTimeout(function() { window.location.reload(); }, 1500);
            } else {
                alert_float('danger', resp.message);
                btn.prop('disabled', false).html('<i class="fa fa-magic"></i> Apply Changes');
            }
        },
        error: function() {
            alert_float('danger', 'Request failed or timed out.');
            btn.prop('disabled', false).html('<i class="fa fa-magic"></i> Apply Changes');
        }
    });
}

function ps_delete_profile(lead_id) {
    if (!confirm('Delete ALL ClickFuzz Web data for this lead?\n\nThis removes all versions, previews, and site records. The lead itself stays in Perfex.')) return;
    var f = document.getElementById('ps_delete_profile_form');
    f.action = admin_url + 'pitchsnap/delete_profile/' + lead_id;
    f.submit();
}

function ps_select_all(master) {
    document.querySelectorAll('.ps-version-cb').forEach(function(cb) {
        cb.checked = master.checked;
    });
}

function ps_bulk_delete() {
    var cbs = document.querySelectorAll('.ps-version-cb:checked');
    if (!cbs.length) { alert_float('warning', 'Select at least one version to delete.'); return; }
    if (!confirm('Delete ' + cbs.length + ' version(s)? This cannot be undone.')) return;
    var f = document.getElementById('ps_bulk_delete_form');
    f.querySelectorAll('input[name="ids[]"]').forEach(function(el) { el.remove(); });
    cbs.forEach(function(cb) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'ids[]';
        inp.value = cb.value;
        f.appendChild(inp);
    });
    f.submit();
}
</script>
<?php
function ps_badge($status) {
    $map = [
        'new'                => ['label-default', 'Draft'],
        'pending'            => ['label-default', 'Draft'],
        'pending_generation' => ['label-info',    'Generating'],
        'generating'         => ['label-info',    'Generating'],
        'publishing'         => ['label-info',    'Publishing'],
        'modifying'          => ['label-info',    'AI Modifying'],
        'review_required'    => ['label-primary', 'Review Required'],
        'approved'           => ['label-warning', 'Awaiting Client Approval'],
        'sent'               => ['label-warning', 'Awaiting Client Approval'],
        'viewed'             => ['label-info',    'Client Viewed'],
        'published'          => ['label-success', 'Published'],
        'failed'             => ['label-danger',  'Failed'],
        'declined'           => ['label-default', 'Declined'],
    ];
    [$cls, $label] = $map[$status] ?? ['label-default', e($status)];
    return '<span class="label ' . $cls . '">' . $label . '</span>';
}
?>
