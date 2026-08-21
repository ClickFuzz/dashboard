<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">

        <div class="row">
            <div class="col-md-12">
                <a href="<?php echo admin_url('pitchsnap/websites'); ?>" class="btn btn-default btn-sm mbot10">
                    <i class="fa fa-arrow-left"></i> All Leads
                </a>
                <h4 class="tw-font-semibold" style="display:inline-block; margin-left:10px;">
                    <?php echo e($lead->name); ?>
                    <?php if ($latest) { echo ps_lead_badge($latest->status); } ?>
                </h4>
            </div>
        </div>

        <div class="row">

            <!-- Left: current version + actions -->
            <div class="col-md-8">

                <?php if (!$latest) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <p class="text-muted">No website versions yet for this lead.</p>
                    </div>
                </div>
                <?php } else { ?>

                <!-- Latest website panel -->
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Current Website</h5>

                        <?php if (!empty($latest->preview_url)) { ?>
                        <div class="mbot15">
                            <a href="<?php echo e($latest->preview_url); ?>"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="btn btn-success btn-lg">
                                <i class="fa fa-globe"></i> View New Website
                            </a>
                        </div>
                        <?php } ?>

                        <table class="table table-bordered table-condensed mbot15">
                            <tbody>
                                <tr>
                                    <th width="35%">Status</th>
                                    <td><?php echo ps_lead_badge($latest->status); ?></td>
                                </tr>
                                <tr>
                                    <th>Provider</th>
                                    <td><?php echo !empty($latest->provider) ? e(ucfirst($latest->provider)) : '<span class="text-muted">—</span>'; ?></td>
                                </tr>
                                <?php if (!empty($latest->model_used)) { ?>
                                <tr>
                                    <th>Model</th>
                                    <td><?php echo e($latest->model_used); ?></td>
                                </tr>
                                <?php } ?>
                                <tr>
                                    <th>Generated At</th>
                                    <td><?php echo $latest->generated_at ? _dt($latest->generated_at) : '<span class="text-muted">—</span>'; ?></td>
                                </tr>
                                <?php if (!empty($latest->generation_error)) { ?>
                                <tr>
                                    <th>Error</th>
                                    <td class="text-danger"><code><?php echo e($latest->generation_error); ?></code></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>

                        <!-- Action buttons -->
                        <?php $s = $latest->status; ?>

                        <?php if (in_array($s, ['new', 'pending'])) { ?>
                        <button class="btn btn-primary"
                                onclick="ps_queue_generate(<?php echo (int) $latest->id; ?>)">
                            <i class="fa fa-bolt"></i> Generate Website
                        </button>

                        <?php } elseif ($s === 'failed') { ?>
                        <button class="btn btn-primary mright5"
                                onclick="ps_queue_generate(<?php echo (int) $latest->id; ?>)">
                            <i class="fa fa-refresh"></i> Retry Generation
                        </button>
                        <a href="<?php echo admin_url('pitchsnap/regenerate/' . (int) $latest->id); ?>"
                           class="btn btn-default mright5"
                           onclick="return confirm('Create a new version for this lead?');">
                            <i class="fa fa-copy"></i> New Version
                        </a>
                        <?php if (!empty($latest->provider) && $latest->provider === 'manus' && get_option('pitchsnap_anthropic_api_key')) { ?>
                        <a href="<?php echo admin_url('pitchsnap/retry_anthropic/' . (int) $latest->id); ?>"
                           class="btn btn-warning"
                           onclick="return confirm('Retry using Anthropic instead of Manus?');">
                            <i class="fa fa-refresh"></i> Retry with Anthropic
                        </a>
                        <?php } ?>

                        <?php } elseif (in_array($s, ['pending_generation', 'generating', 'publishing'])) { ?>
                        <p class="text-muted">
                            <i class="fa fa-spinner fa-spin"></i>
                            <?php
                            $msgs = [
                                'pending_generation' => 'Queued — awaiting next cron run.',
                                'generating'         => 'Building the website...',
                                'publishing'         => 'Publishing live...',
                            ];
                            echo $msgs[$s] ?? 'Processing...';
                            ?>
                        </p>

                        <?php } elseif (in_array($s, ['review_required', 'approved', 'sent', 'viewed'])) { ?>
                        <a href="<?php echo admin_url('pitchsnap/regenerate/' . (int) $latest->id); ?>"
                           class="btn btn-default"
                           onclick="return confirm('Create a new version for this lead?');">
                            <i class="fa fa-copy"></i> Regenerate (New Version)
                        </a>
                        <?php } ?>

                    </div>
                </div>

                <!-- Prospect Conversation (from latest version) -->
                <?php if (!empty($conversations)) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Prospect Feedback</h5>
                        <table class="table table-bordered table-condensed">
                            <thead>
                                <tr>
                                    <th width="30%">Date</th>
                                    <th>Response</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($conversations as $conv) {
                                $reply_labels = [
                                    'like'       => '<span class="label label-success">I like it</span>',
                                    'change'     => '<span class="label label-warning">I\'d change a few things</span>',
                                    'not_for_me' => '<span class="label label-default">Not for me</span>',
                                ];
                            ?>
                            <tr>
                                <td style="white-space:nowrap; vertical-align:top;"><?php echo _dt($conv['created_at']); ?></td>
                                <td>
                                    <?php echo $reply_labels[$conv['quick_reply']] ?? e($conv['quick_reply']); ?>
                                    <?php if (!empty($conv['change_request'])) { ?>
                                    <br><span class="text-muted" style="font-size:12px; font-style:italic;">"<?php echo e($conv['change_request']); ?>"</span>
                                    <?php } ?>
                                </td>
                            </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php } ?>

                <!-- Version history -->
                <?php if (count($history) > 1) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Version History</h5>
                        <table class="table table-bordered table-condensed">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Provider</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($history as $i => $r) { ?>
                            <tr<?php echo $i === 0 ? ' class="active"' : ''; ?>>
                                <td><?php echo (int) $r['id']; ?><?php echo $i === 0 ? ' <span class="label label-primary" style="font-size:10px;">latest</span>' : ''; ?></td>
                                <td><?php echo ps_lead_badge($r['status']); ?></td>
                                <td><?php echo _dt($r['dateadded']); ?></td>
                                <td><?php echo !empty($r['provider']) ? e(ucfirst($r['provider'])) : '<span class="text-muted">—</span>'; ?></td>
                                <td class="text-right" style="white-space:nowrap;">
                                    <?php if (!empty($r['preview_url'])) { ?>
                                    <a href="<?php echo e($r['preview_url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-success btn-xs mright5">
                                        <i class="fa fa-globe"></i> View
                                    </a>
                                    <?php } ?>
                                    <?php if (!empty($r['generation_result'])) { ?>
                                    <a href="<?php echo admin_url('pitchsnap/edit_html/' . (int) $r['id']); ?>" class="btn btn-default btn-xs mright5">
                                        <i class="fa fa-code"></i> Edit HTML
                                    </a>
                                    <?php } ?>
                                    <?php if (!empty($r['preview_url'])) { ?>
                                    <form method="post"
                                          action="<?php echo admin_url('pitchsnap/delete_preview/' . (int) $r['id']); ?>"
                                          style="display:inline-block; margin-right:4px;">
                                        <input type="hidden"
                                               name="<?php echo $this->security->get_csrf_token_name(); ?>"
                                               value="<?php echo $this->security->get_csrf_hash(); ?>">
                                        <button type="submit" class="btn btn-danger btn-xs"
                                                onclick="return confirm('Delete preview for website #<?php echo (int) $r['id']; ?>?');">
                                            <i class="fa fa-trash"></i> Delete
                                        </button>
                                    </form>
                                    <?php } ?>
                                    <a href="<?php echo admin_url('pitchsnap/detail/' . (int) $r['id']); ?>" class="btn btn-default btn-xs">Detail</a>
                                </td>
                            </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php } ?>

                <?php } // end if $latest ?>
            </div>

            <!-- Right: lead info -->
            <div class="col-md-4">
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Lead</h5>
                        <p>
                            <a href="<?php echo admin_url('leads/index/' . $lead->id); ?>#leadid=<?php echo (int) $lead->id; ?>">
                                <strong>#<?php echo (int) $lead->id; ?> — <?php echo e($lead->name); ?></strong>
                            </a>
                        </p>
                        <?php if (!empty($lead->company)) { ?><p><span class="text-muted">Company:</span> <?php echo e($lead->company); ?></p><?php } ?>
                        <?php if (!empty($lead->email)) { ?><p><?php echo e($lead->email); ?></p><?php } ?>
                        <?php if (!empty($lead->phonenumber)) { ?><p><?php echo e($lead->phonenumber); ?></p><?php } ?>
                        <?php if (!empty($lead->website)) { ?>
                        <p>
                            <a href="<?php echo e($lead->website); ?>" target="_blank" rel="noopener noreferrer" style="word-break:break-all;">
                                <?php echo e($lead->website); ?>
                            </a>
                        </p>
                        <?php } ?>
                        <?php if (!empty($lead->source_name)) { ?><p><span class="text-muted">Source:</span> <?php echo e($lead->source_name); ?></p><?php } ?>
                        <?php if (!empty($lead->status_name)) { ?><p><span class="text-muted">Status:</span> <?php echo e($lead->status_name); ?></p><?php } ?>
                        <?php if (!empty($lead->position)) { ?><p><span class="text-muted">Position:</span> <?php echo e($lead->position); ?></p><?php } ?>
                    </div>
                </div>

                <?php if ($latest && !empty($latest->preview_token)) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot5">Widget Embed</h5>
                        <textarea class="form-control" readonly rows="3"
                                  onclick="this.select();"
                                  style="font-family:monospace; font-size:11px; resize:none; cursor:text;"
                        ><?php echo e('<script src="' . base_url('pitchsnap/runtime.js') . '" data-redesign-token="' . $latest->preview_token . '"></script>'); ?></textarea>
                    </div>
                </div>
                <?php } ?>
            </div>

        </div>
    </div>
</div>
<?php init_tail(); ?>
<script>
function ps_queue_generate(id) {
    var btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Queuing...';

    $.ajax({
        url: admin_url + 'pitchsnap/queue_generate/' + id,
        type: 'POST',
        dataType: 'json',
        data: {<?php echo $this->security->get_csrf_token_name(); ?>: '<?php echo $this->security->get_csrf_hash(); ?>'},
        success: function(resp) {
            if (resp.success) {
                alert_float('success', resp.message);
                setTimeout(function() { window.location.reload(); }, 1200);
            } else {
                alert_float('danger', resp.message);
                btn.disabled = false;
                btn.innerHTML = btn.innerHTML.replace('fa-spinner fa-spin', 'fa-bolt').replace('Queuing...', 'Generate Website');
            }
        },
        error: function() {
            alert_float('danger', 'Request failed. Please try again.');
            btn.disabled = false;
        }
    });
}
</script>
<?php
function ps_lead_badge($status) {
    $map = [
        'new'                => 'label-default',
        'pending'            => 'label-default',
        'pending_generation' => 'label-info',
        'generating'         => 'label-info',
        'publishing'         => 'label-info',
        'review_required'    => 'label-primary',
        'approved'           => 'label-success',
        'sent'               => 'label-success',
        'viewed'             => 'label-success',
        'failed'             => 'label-danger',
        'declined'           => 'label-default',
    ];
    $cls = $map[$status] ?? 'label-default';
    return '<span class="label ' . $cls . '">' . e($status) . '</span>';
}
?>
