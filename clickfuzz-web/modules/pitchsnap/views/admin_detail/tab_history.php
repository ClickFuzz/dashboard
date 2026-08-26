<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
            <!-- ══════════════════════════════════════════════════════════
                 TAB 6 — HISTORY
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane" id="tab-history">

                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Sales Chat</h5>
                        <?php if (!empty($conversations)) { ?>
                        <table class="table table-bordered table-condensed" style="margin-bottom:0;">
                            <thead>
                                <tr>
                                    <th width="30%">Date</th>
                                    <th>Response</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $reply_labels = [
                                'like'       => '<span class="label label-success">I like it</span>',
                                'change'     => '<span class="label label-warning">I\'d change a few things</span>',
                                'not_for_me' => '<span class="label label-default">Not for me</span>',
                            ];
                            foreach ($conversations as $conv) { ?>
                            <tr>
                                <td style="white-space:nowrap; vertical-align:top; font-size:12px;"><?php echo _dt($conv['created_at']); ?></td>
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
                        <?php } else { ?>
                        <p class="text-muted" style="margin:0;">No conversation history yet.</p>
                        <?php } ?>
                    </div>
                </div>

            </div><!-- #tab-history -->
