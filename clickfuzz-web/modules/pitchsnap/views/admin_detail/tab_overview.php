<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
            <!-- ══════════════════════════════════════════════════════════
                 TAB 1 — OVERVIEW
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane active" id="tab-overview">

                <div class="row">

                    <!-- Website summary -->
                    <div class="col-md-6">
                        <div class="panel_s">
                            <div class="panel-body">
                                <h5 class="tw-font-semibold mbot10">Website</h5>
                                <table class="table table-condensed" style="margin:0; font-size:13px;">
                                    <tr>
                                        <th width="40%">Version</th>
                                        <td>
                                            #<?php echo (int) $redesign->id; ?>
                                            <?php if (!empty($redesign->is_primary)) { ?>
                                            &nbsp;<i class="fa fa-star text-muted" title="Primary"></i>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                    <tr><th>Status</th><td><?php echo ps_badge($redesign->status); ?></td></tr>
                                    <tr>
                                        <th>Provider</th>
                                        <td><?php echo !empty($redesign->provider) ? e($redesign->provider) : '<span class="text-muted">—</span>'; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Generated</th>
                                        <td><?php echo $redesign->generated_at ? _dt($redesign->generated_at) : '<span class="text-muted">—</span>'; ?></td>
                                    </tr>
                                    <?php if (!empty($redesign->preview_url)) { ?>
                                    <tr>
                                        <th>Preview</th>
                                        <td>
                                            <a href="<?php echo e($redesign->preview_url); ?>" target="_blank" rel="noopener noreferrer">
                                                Open <i class="fa fa-external-link" style="font-size:11px;"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Customer summary -->
                    <div class="col-md-6">
                        <div class="panel_s">
                            <div class="panel-body">
                                <h5 class="tw-font-semibold mbot10">Customer</h5>
                                <?php if ($lead) { ?>
                                <table class="table table-condensed" style="margin:0; font-size:13px;">
                                    <tr>
                                        <th width="40%">Name</th>
                                        <td>
                                            <a href="<?php echo admin_url('leads/index/' . $lead->id); ?>#leadid=<?php echo (int) $lead->id; ?>">
                                                <?php echo e(!empty($lead->company) ? $lead->company : $lead->name); ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php if (!empty($lead->email)) { ?>
                                    <tr><th>Email</th><td><?php echo e($lead->email); ?></td></tr>
                                    <?php } ?>
                                    <?php if (!empty($lead->status_name)) { ?>
                                    <tr><th>Lead Status</th><td><?php echo e($lead->status_name); ?></td></tr>
                                    <?php } ?>
                                    <tr>
                                        <th>Agreement</th>
                                        <td>
                                            <?php if (!empty($agreement) && !empty($agreement->accepted_at)) { ?>
                                            <span class="label label-success">Accepted</span>
                                            <span class="text-muted" style="font-size:11px; margin-left:4px;"><?php echo _dt($agreement->accepted_at); ?></span>
                                            <?php } else { ?>
                                            <span class="label label-default">Not accepted</span>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                </table>
                                <?php } else { ?>
                                <p class="text-muted">No lead linked.</p>
                                <?php } ?>
                            </div>
                        </div>
                    </div>

                </div><!-- /.row -->

                <div class="row">

                    <!-- Publishing summary -->
                    <div class="col-md-6">
                        <div class="panel_s">
                            <div class="panel-body">
                                <h5 class="tw-font-semibold mbot10">Publishing</h5>
                                <table class="table table-condensed" style="margin:0; font-size:13px;">
                                    <?php if (!empty($site)) { ?>
                                    <tr>
                                        <th width="40%">Status</th>
                                        <td><?php echo e($site->status ?? '—'); ?></td>
                                    </tr>
                                    <?php if (!empty($site->domain)) { ?>
                                    <tr>
                                        <th>URL</th>
                                        <td style="word-break:break-all;">
                                            <a href="https://<?php echo e($site->domain); ?>" target="_blank" rel="noopener noreferrer">
                                                <?php echo e($site->domain); ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                    <?php } else { ?>
                                    <tr>
                                        <th width="40%">Status</th>
                                        <td><span class="text-muted">Not published</span></td>
                                    </tr>
                                    <?php } ?>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- GHL summary -->
                    <div class="col-md-6">
                        <div class="panel_s">
                            <div class="panel-body">
                                <h5 class="tw-font-semibold mbot10">GoHighLevel</h5>
                                <table class="table table-condensed" style="margin:0; font-size:13px;">
                                    <tr><th width="40%">Status</th><td><span class="text-muted">Not configured</span></td></tr>
                                    <tr><th>Location</th><td><span class="text-muted">—</span></td></tr>
                                    <tr><th>Last sync</th><td><span class="text-muted">—</span></td></tr>
                                </table>
                            </div>
                        </div>
                    </div>

                </div><!-- /.row -->

                <?php
                $attention = [];
                $s_ov = $redesign->status;
                if (in_array($s_ov, ['new', 'pending'])) {
                    $attention[] = ['warning', 'Website has not been generated yet.'];
                } elseif ($s_ov === 'failed') {
                    $msg = 'Generation failed.';
                    if (!empty($redesign->generation_error)) {
                        $msg .= ' Error: ' . e($redesign->generation_error);
                    }
                    $msg .= ' Retry from the Website tab.';
                    $attention[] = ['danger', $msg];
                } elseif ($s_ov === 'review_required') {
                    $attention[] = ['info', 'Website is ready for review — approve or regenerate from the Website tab.'];
                }
                if (empty($site) || empty($site->domain)) {
                    $attention[] = ['warning', 'Site has not been published yet.'];
                }
                if (!empty($site) && (empty($agreement) || empty($agreement->accepted_at))) {
                    $attention[] = ['warning', 'Agreement not yet accepted by customer.'];
                }
                ?>
                <?php if (!empty($attention)) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Attention</h5>
                        <?php foreach ($attention as $item) { ?>
                        <div class="alert alert-<?php echo $item[0]; ?>" style="margin-bottom:6px; padding:8px 12px; font-size:13px;">
                            <?php if ($item[0] === 'danger') { ?>
                            <i class="fa fa-times-circle"></i>
                            <?php } elseif ($item[0] === 'info') { ?>
                            <i class="fa fa-info-circle"></i>
                            <?php } else { ?>
                            <i class="fa fa-exclamation-triangle"></i>
                            <?php } ?>
                            <?php echo $item[1]; ?>
                        </div>
                        <?php } ?>
                    </div>
                </div>
                <?php } ?>

            </div><!-- #tab-overview -->
