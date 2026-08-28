<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
            <!-- ══════════════════════════════════════════════════════════
                 TAB 5 — GHL
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane" id="tab-ghl">
                <div class="panel_s" style="max-width:540px;">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">GoHighLevel</h5>

                        <?php $ghl_configured = (bool) get_option('pitchsnap_ghl_api_key'); ?>

                        <p class="text-muted" style="font-size:12px; margin-bottom:10px;">
                            Agency connection:
                            <?php if ($ghl_configured): ?>
                                <span class="label label-success">Configured</span>
                            <?php else: ?>
                                <span class="label label-default">Not configured</span>
                                &nbsp;<a href="<?php echo admin_url('pitchsnap/settings?tab=ghl'); ?>" style="font-size:11px;">Add token</a>
                            <?php endif; ?>
                        </p>

                        <?php if (!empty($site)): ?>

                        <?php if (!empty($ghl_link)): ?>
                        <table class="table table-condensed" style="margin-bottom:10px; font-size:13px;">
                            <tr>
                                <th width="40%">Location ID</th>
                                <td><code style="font-size:11px;"><?php echo e($ghl_link->ghl_location_id); ?></code></td>
                            </tr>
                            <?php if (!empty($ghl_link->ghl_location_name)): ?>
                            <tr>
                                <th>Location</th>
                                <td><?php echo e($ghl_link->ghl_location_name); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Status</th>
                                <td>
                                    <?php
                                    $ghl_cls = $ghl_link->status === 'connected' ? 'label-success' : 'label-warning';
                                    echo '<span class="label ' . $ghl_cls . '">' . e($ghl_link->status) . '</span>';
                                    ?>
                                </td>
                            </tr>
                            <?php if (!empty($ghl_link->last_verified_at)): ?>
                            <tr>
                                <th>Verified</th>
                                <td style="font-size:11px;"><?php echo _dt($ghl_link->last_verified_at); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($ghl_link->last_error)): ?>
                            <tr>
                                <th>Last Error</th>
                                <td class="text-danger" style="font-size:11px;"><?php echo e($ghl_link->last_error); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                        <?php endif; ?>

                        <div class="input-group input-group-sm mbot8" style="max-width:360px;">
                            <input type="text"
                                   id="ps_ghl_location_id"
                                   class="form-control"
                                   placeholder="GHL Location ID"
                                   value="<?php echo !empty($ghl_link) ? e($ghl_link->ghl_location_id) : ''; ?>">
                            <span class="input-group-btn">
                                <button class="btn btn-primary btn-sm"
                                        onclick="ps_ghl_link(<?php echo (int) $site->id; ?>)">
                                    <?php echo empty($ghl_link) ? 'Link' : 'Update'; ?>
                                </button>
                            </span>
                        </div>

                        <?php if (!empty($ghl_link) && $ghl_link->status === 'connected'): ?>
                        <button class="btn btn-default btn-xs" id="ps_ghl_test_btn"
                                onclick="ps_ghl_test(<?php echo (int) $site->id; ?>)">
                            <i class="fa fa-plug"></i> Test Connection
                        </button>
                        <?php endif; ?>

                        <div id="ps_ghl_status" style="margin-top:8px; font-size:12px;"></div>

                        <?php else: ?>
                        <p class="text-muted" style="font-size:13px;">
                            <i class="fa fa-info-circle"></i>
                            No site record found for this website. A site record is created when the customer completes checkout.
                        </p>
                        <?php endif; ?>

                    </div>
                </div>
            </div><!-- #tab-ghl -->

<script>
function ps_ghl_link(site_id) {
    var loc_id = $('#ps_ghl_location_id').val().trim();
    if (!loc_id) { alert_float('warning', 'Enter a GHL Location ID.'); return; }
    $('#ps_ghl_status').html('<i class="fa fa-spinner fa-spin"></i> Verifying…');
    $.ajax({
        url:      admin_url + 'pitchsnap/ghl_link_location/' + site_id,
        type:     'POST',
        dataType: 'json',
        data: {
            ghl_location_id: loc_id,
            <?php echo $this->security->get_csrf_token_name(); ?>: '<?php echo $this->security->get_csrf_hash(); ?>'
        },
        success: function(r) {
            if (r.success) {
                alert_float('success', r.message);
                setTimeout(function() { window.location.reload(); }, 1200);
            } else {
                $('#ps_ghl_status').html('<span class="text-danger">' + r.message + '</span>');
            }
        },
        error: function() {
            $('#ps_ghl_status').html('<span class="text-danger">Request failed. Please try again.</span>');
        }
    });
}

function ps_ghl_test(site_id) {
    var btn = $('#ps_ghl_test_btn');
    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Testing…');
    $('#ps_ghl_status').html('');
    $.ajax({
        url:      admin_url + 'pitchsnap/ghl_test_connection/' + site_id,
        type:     'POST',
        dataType: 'json',
        data: {
            <?php echo $this->security->get_csrf_token_name(); ?>: '<?php echo $this->security->get_csrf_hash(); ?>'
        },
        success: function(r) {
            if (r.success) {
                alert_float('success', r.message);
                setTimeout(function() { window.location.reload(); }, 1200);
            } else {
                $('#ps_ghl_status').html('<span class="text-danger">' + r.message + '</span>');
                btn.prop('disabled', false).html('<i class="fa fa-plug"></i> Test Connection');
            }
        },
        error: function() {
            $('#ps_ghl_status').html('<span class="text-danger">Request failed. Please try again.</span>');
            btn.prop('disabled', false).html('<i class="fa fa-plug"></i> Test Connection');
        }
    });
}
</script>
