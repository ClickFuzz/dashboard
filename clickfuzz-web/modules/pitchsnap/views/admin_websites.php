<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-4">
                            <h4 class="tw-font-semibold tw-mb-0">PitchSnap Websites</h4>
                            <a href="<?php echo site_url('pitchsnap/intake'); ?>" target="_blank" class="btn btn-primary btn-sm">
                                <i class="fa fa-plus"></i> New Intake
                            </a>
                        </div>

                        <?php if (empty($websites)) { ?>
                        <p class="text-muted">No website requests yet.</p>
                        <?php } else { ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover dt-table" data-order-col="5" data-order-type="desc">
                                <thead>
                                    <tr>
                                        <th>Lead / Name</th>
                                        <th>Website</th>
                                        <th>Industry</th>
                                        <th>Status</th>
                                        <th>Last Updated</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($websites as $r) { ?>
                                    <tr>
                                        <td>
                                            <?php if ($r['lead_id']) { ?>
                                            <a href="<?php echo admin_url('leads/index/' . $r['lead_id']); ?>#leadid=<?php echo (int) $r['lead_id']; ?>">
                                                <?php echo e(!empty($r['lead_name']) ? $r['lead_name'] : 'Lead #' . $r['lead_id']); ?>
                                            </a>
                                            <?php } else { ?>—<?php } ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($r['original_url'])) { ?>
                                            <a href="<?php echo e($r['original_url']); ?>" target="_blank" rel="noopener noreferrer" style="word-break:break-all;">
                                                <?php echo e($r['original_url']); ?>
                                            </a>
                                            <?php } else { ?><span class="text-muted">—</span><?php } ?>
                                        </td>
                                        <td><?php echo e($r['vertical']); ?></td>
                                        <td><?php echo pitchsnap_status_badge($r['status']); ?></td>
                                        <td><?php echo _dt($r['dateupdated'] ?: $r['dateadded']); ?></td>
                                        <td class="text-right" style="white-space:nowrap;">
                                            <?php if (!empty($r['preview_url'])) { ?>
                                            <a href="<?php echo e($r['preview_url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-success btn-sm" style="margin-right:4px;">
                                                <i class="fa fa-globe"></i> View Website
                                            </a>
                                            <?php } ?>
                                            <a href="<?php echo admin_url('pitchsnap/detail/' . (int) $r['id']); ?>" class="btn btn-default btn-sm">Detail</a>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
<?php
function pitchsnap_status_badge($status) {
    $map = [
        'new'                => 'label-default',
        'pending'            => 'label-default',
        'pending_generation' => 'label-info',
        'generating'         => 'label-info',
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
