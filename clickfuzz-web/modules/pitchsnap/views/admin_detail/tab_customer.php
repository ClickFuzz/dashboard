<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
            <!-- ══════════════════════════════════════════════════════════
                 TAB 4 — CUSTOMER
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane" id="tab-customer">

                <div class="row">

                    <div class="col-md-6">

                        <!-- Business -->
                        <div class="panel_s">
                            <div class="panel-body">
                                <h5 class="tw-font-semibold mbot10">Business</h5>
                                <table class="table table-condensed" style="margin:0; font-size:13px;">
                                    <tr>
                                        <th width="40%">Original Website</th>
                                        <td>
                                            <?php if (!empty($redesign->original_url)) { ?>
                                            <a href="<?php echo e($redesign->original_url); ?>" target="_blank" rel="noopener noreferrer" style="word-break:break-all;">
                                                <?php echo e($redesign->original_url); ?>
                                            </a>
                                            <?php } else { ?><span class="text-muted">Not provided</span><?php } ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Business Type</th>
                                        <td><?php echo !empty($redesign->vertical) ? e($redesign->vertical) : '<span class="text-muted">—</span>'; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Role</th>
                                        <td><?php echo !empty($redesign->intake_role) ? e($redesign->intake_role) : '<span class="text-muted">—</span>'; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Company Size</th>
                                        <td><?php echo !empty($redesign->intake_company_size) ? e($redesign->intake_company_size) : '<span class="text-muted">—</span>'; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Desired Improvement</th>
                                        <td><?php echo !empty($redesign->intake_improvement) ? e($redesign->intake_improvement) : '<span class="text-muted">—</span>'; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Created</th>
                                        <td><?php echo _dt($redesign->dateadded); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <!-- Contact -->
                        <div class="panel_s">
                            <div class="panel-body">
                                <h5 class="tw-font-semibold mbot10">Contact</h5>
                                <?php if ($lead) { ?>
                                <table class="table table-condensed" style="margin:0; font-size:13px;">
                                    <tr>
                                        <th width="35%">Name</th>
                                        <td><?php echo e($lead->name); ?></td>
                                    </tr>
                                    <?php if (!empty($lead->company)) { ?>
                                    <tr>
                                        <th>Company</th>
                                        <td><?php echo e($lead->company); ?></td>
                                    </tr>
                                    <?php } ?>
                                    <?php if (!empty($lead->email)) { ?>
                                    <tr>
                                        <th>Email</th>
                                        <td><a href="mailto:<?php echo e($lead->email); ?>"><?php echo e($lead->email); ?></a></td>
                                    </tr>
                                    <?php } ?>
                                    <?php if (!empty($lead->phonenumber)) { ?>
                                    <tr>
                                        <th>Phone</th>
                                        <td><?php echo e($lead->phonenumber); ?></td>
                                    </tr>
                                    <?php } ?>
                                    <?php if (!empty($lead->source_name)) { ?>
                                    <tr>
                                        <th>Source</th>
                                        <td><?php echo e($lead->source_name); ?></td>
                                    </tr>
                                    <?php } ?>
                                </table>
                                <?php } else { ?>
                                <p class="text-muted">No lead linked.</p>
                                <?php } ?>
                            </div>
                        </div>

                        <!-- Account -->
                        <div class="panel_s">
                            <div class="panel-body">
                                <h5 class="tw-font-semibold mbot10">Account</h5>
                                <table class="table table-condensed" style="margin:0; font-size:13px;">
                                    <?php if ($lead) { ?>
                                    <tr>
                                        <th width="40%">Lead</th>
                                        <td>
                                            <a href="<?php echo admin_url('leads/index/' . $lead->id); ?>#leadid=<?php echo (int) $lead->id; ?>">
                                                #<?php echo (int) $lead->id; ?> — <?php echo e($lead->name); ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php if (!empty($lead->status_name)) { ?>
                                    <tr>
                                        <th>Lead Status</th>
                                        <td><?php echo e($lead->status_name); ?></td>
                                    </tr>
                                    <?php } ?>
                                    <?php } ?>
                                    <?php if (!empty($site)) { ?>
                                    <tr>
                                        <th>Site Status</th>
                                        <td><?php echo e($site->status ?? '—'); ?></td>
                                    </tr>
                                    <?php } ?>
                                    <tr>
                                        <th>Agreement</th>
                                        <td>
                                            <?php if (!empty($agreement) && !empty($agreement->accepted_at)) { ?>
                                            <span class="label label-success">Accepted</span>
                                            <?php echo _dt($agreement->accepted_at); ?>
                                            <?php if (!empty($agreement->version)) { ?>
                                            <span class="text-muted" style="font-size:11px;">(v<?php echo e($agreement->version); ?>)</span>
                                            <?php } ?>
                                            <?php } else { ?>
                                            <span class="label label-default">Not accepted</span>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                    </div>

                </div><!-- /.row -->

                <!-- Danger Zone -->
                <?php if ($lead) { ?>
                <div class="panel_s" style="border-top:3px solid #e74c3c;">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold text-danger mbot5">Danger Zone</h5>
                        <p class="text-muted" style="font-size:12px; margin-bottom:10px;">
                            Permanently removes all ClickFuzz Web data for this customer — all website versions, previews, and site records.
                            The lead record itself is preserved in Perfex.
                        </p>
                        <button class="btn btn-danger btn-sm" onclick="ps_delete_profile(<?php echo (int) $lead->id; ?>)">
                            <i class="fa fa-trash"></i> Delete All ClickFuzz Web Data
                        </button>
                    </div>
                </div>
                <?php } ?>

            </div><!-- #tab-customer -->
