<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-4">
                            <h4 class="tw-font-semibold tw-mb-0">ClickFuzz Web Redesigns</h4>
                            <a href="<?php echo site_url('pitchsnap/intake'); ?>" target="_blank" class="btn btn-primary btn-sm">
                                <i class="fa fa-plus"></i> New Intake
                            </a>
                        </div>

                        <?php if (empty($redesigns)) { ?>
                        <p class="text-muted">No redesign requests yet.</p>
                        <?php } else { ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover dt-table" data-order-col="5" data-order-type="desc">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Company</th>
                                        <th>Original URL</th>
                                        <th>Vertical</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($redesigns as $r) { ?>
                                    <tr>
                                        <td><?php echo (int) $r['id']; ?></td>
                                        <td>
                                            <?php if ($r['lead_id']) { ?>
                                            <?php $display_company = !empty($r['lead_company']) ? $r['lead_company'] : (!empty($r['lead_name']) ? $r['lead_name'] : 'Lead #' . $r['lead_id']); ?>
                                            <a href="<?php echo admin_url('leads/index/' . $r['lead_id']); ?>#leadid=<?php echo (int) $r['lead_id']; ?>">
                                                <strong><?php echo e($display_company); ?></strong>
                                            </a>
                                            <?php if (!empty($r['lead_name']) && $r['lead_name'] !== $display_company) { ?>
                                            <br><small class="text-muted"><?php echo e($r['lead_name']); ?></small>
                                            <?php } ?>
                                            <?php } else { ?>—<?php } ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($r['original_url'])) { ?>
                                            <a href="<?php echo e($r['original_url']); ?>" target="_blank" rel="noopener noreferrer">
                                                <?php echo e($r['original_url']); ?>
                                            </a>
                                            <?php } else { ?><span class="text-muted">—</span><?php } ?>
                                        </td>
                                        <td><?php echo e($r['vertical']); ?></td>
                                        <td><?php echo clickfuzz_web_status_badge($r['status']); ?></td>
                                        <td><?php echo _dt($r['dateadded']); ?></td>
                                        <td class="text-right">
                                            <a href="<?php echo admin_url('pitchsnap/detail/' . $r['id']); ?>" class="btn btn-default btn-sm">View</a>
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
function clickfuzz_web_status_badge($status) {
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
