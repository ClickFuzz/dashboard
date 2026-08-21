<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div role="tabpanel" class="tab-pane" id="tab_pitchsnap">
    <div class="mtop10">
        <?php if (empty($websites)) { ?>
        <p class="text-muted">No website requests for this lead.</p>
        <?php } else { ?>
            <?php foreach ($websites as $i => $r) { ?>
            <div class="<?php echo $i > 0 ? 'mtop20 ptop20' : ''; ?>" <?php echo $i > 0 ? 'style="border-top:1px solid #eee"' : ''; ?>>
                <?php if (count($websites) > 1) { ?>
                <small class="text-muted">Website #<?php echo (int) $r['id']; ?></small>
                <?php } ?>

                <table class="table table-condensed no-border mbot0">
                    <tbody>
                        <tr>
                            <td width="40%" class="text-muted">Original Website</td>
                            <td>
                                <?php if (!empty($r['original_url'])) { ?>
                                <a href="<?php echo e($r['original_url']); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo e($r['original_url']); ?>
                                </a>
                                <?php } else { ?>
                                <span class="text-muted">—</span>
                                <?php } ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Status</td>
                            <td>
                                <?php
                                $status_classes = [
                                    'pending'    => 'label-warning',
                                    'generating' => 'label-info',
                                    'ready'      => 'label-primary',
                                    'approved'   => 'label-success',
                                    'sent'       => 'label-success',
                                    'viewed'     => 'label-success',
                                    'failed'     => 'label-danger',
                                    'declined'   => 'label-default',
                                ];
                                $cls = $status_classes[$r['status']] ?? 'label-default';
                                ?>
                                <span class="label <?php echo $cls; ?>"><?php echo e($r['status']); ?></span>
                            </td>
                        </tr>
                        <?php if (!empty($r['preview_url'])) { ?>
                        <tr>
                            <td class="text-muted">Preview URL</td>
                            <td>
                                <a href="<?php echo e($r['preview_url']); ?>" target="_blank">
                                    <?php echo e($r['preview_url']); ?>
                                </a>
                            </td>
                        </tr>
                        <?php } ?>
                        <tr>
                            <td class="text-muted">Created</td>
                            <td><?php echo _dt($r['dateadded']); ?></td>
                        </tr>
                        <?php if (!empty($r['generated_at'])) { ?>
                        <tr>
                            <td class="text-muted">Generated</td>
                            <td><?php echo _dt($r['generated_at']); ?></td>
                        </tr>
                        <?php } ?>
                        <?php if (!empty($r['sent_at'])) { ?>
                        <tr>
                            <td class="text-muted">Sent</td>
                            <td><?php echo _dt($r['sent_at']); ?></td>
                        </tr>
                        <?php } ?>
                        <?php if ($r['view_count'] > 0) { ?>
                        <tr>
                            <td class="text-muted">Views</td>
                            <td><?php echo (int) $r['view_count']; ?></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>

                <a href="<?php echo admin_url('pitchsnap/detail/' . $r['id']); ?>" class="btn btn-default btn-xs">
                    View website record
                </a>
            </div>
            <?php } ?>
        <?php } ?>
    </div>
</div>
