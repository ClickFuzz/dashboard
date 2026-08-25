<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
            <!-- ══════════════════════════════════════════════════════════
                 TAB 3 — DOMAIN & PUBLISHING
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane" id="tab-publishing">

                <!-- ── HTML Hosting ─────────────────────────────────────── -->
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">HTML Hosting</h5>
                        <?php
                        $_pf_domain    = null;
                        $_is_published = !empty($site) && $site->status === 'published';
                        if (!empty($site) && isset($this->pitchsnap_model)) {
                            $_pf_domain = $this->pitchsnap_model->get_platform_domain_for_site($site->id);
                        }
                        $_pub_url = $_pf_domain ? 'https://' . $_pf_domain->hostname . '/' : null;
                        ?>
                        <table class="table table-bordered table-condensed mbot15" style="max-width:520px;">
                            <tbody>
                                <?php if (!empty($site)) { ?>
                                <tr>
                                    <th width="35%">Status</th>
                                    <td>
                                        <?php if ($_is_published) { ?>
                                        <span class="label label-success">Published</span>
                                        <?php } else { ?>
                                        <span class="label label-default"><?php echo e(ucfirst($site->status ?? 'draft')); ?></span>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php if ($_pub_url) { ?>
                                <tr>
                                    <th>Live URL</th>
                                    <td style="word-break:break-all;">
                                        <a href="<?php echo e($_pub_url); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php echo e($_pub_url); ?>
                                        </a>
                                    </td>
                                </tr>
                                <?php } ?>
                                <?php if (!empty($site->site_token)) { ?>
                                <tr>
                                    <th>Token</th>
                                    <td><code style="font-size:11px;"><?php echo substr(e($site->site_token), 0, 12); ?>…</code></td>
                                </tr>
                                <?php } ?>
                                <?php } else { ?>
                                <tr>
                                    <th width="35%">Status</th>
                                    <td><span class="text-muted">Not published</span></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>

                        <?php if (!empty($site)) { ?>
                        <?php if ($_pub_url) { ?>
                        <a href="<?php echo e($_pub_url); ?>" target="_blank" rel="noopener noreferrer"
                           class="btn btn-success btn-sm mright5">
                            <i class="fa fa-globe"></i> Open Site
                        </a>
                        <?php } ?>
                        <form method="POST" action="<?php echo admin_url('pitchsnap/publish_site/' . (int) $redesign->id); ?>" style="display:inline;">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <button type="submit" class="btn btn-primary btn-sm"
                                    onclick="return confirm('<?php echo $_is_published ? 'Republish this site (overwrites current content)?' : 'Publish this site to its permanent URL?'; ?>');">
                                <i class="fa fa-<?php echo $_is_published ? 'refresh' : 'upload'; ?>"></i>
                                <?php echo $_is_published ? 'Republish' : 'Publish Site'; ?>
                            </button>
                        </form>
                        <?php } elseif (!empty($redesign->preview_url) || !empty($redesign->generation_result)) { ?>
                        <p class="text-muted" style="font-size:13px; margin:0;">
                            <i class="fa fa-info-circle"></i> No site record exists yet. Trigger a generation first so ClickFuzz Web can create the site record.
                        </p>
                        <?php } else { ?>
                        <p class="text-muted" style="font-size:13px; margin:0;">
                            <i class="fa fa-info-circle"></i> Generate the website first before publishing.
                        </p>
                        <?php } ?>
                    </div>
                </div>

                <!-- ── WordPress ─────────────────────────────────────────── -->
                <?php
                $_wp_id          = (int) $redesign->id;
                $_wp_url         = !empty($redesign->wp_url)         ? $redesign->wp_url         : '';
                $_wp_user        = !empty($redesign->wp_username)     ? $redesign->wp_username    : '';
                $_wp_has_pass    = !empty($redesign->wp_app_password);
                $_wp_connected   = !empty($redesign->wp_connected_at);
                $_wp_conn_ver    = !empty($redesign->wp_connector_version) ? $redesign->wp_connector_version : '';
                $_wp_version     = !empty($redesign->wp_wp_version)        ? $redesign->wp_wp_version        : '';
                $_wp_theme_slug  = !empty($redesign->wp_active_theme_slug) ? $redesign->wp_active_theme_slug : '';
                $_wp_has_html    = !empty($redesign->generation_result);
                $_wp_deploy_raw  = $this->session->flashdata('wp_deploy_result');
                $_wp_deploy      = $_wp_deploy_raw ? json_decode($_wp_deploy_raw, true) : null;
                $_wp_zips        = glob(dirname(FCPATH) . '/exports/wordpress/' . $_wp_id . '/*.zip') ?: [];
                $_wp_has_export  = !empty($_wp_zips);
                $_wp_configured  = $_wp_url && $_wp_user && $_wp_has_pass;
                ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10"><i class="fa fa-wordpress" style="color:#21759b;"></i> WordPress</h5>

                        <?php if (!$_wp_configured) { ?>
                        <!-- Setup instructions -->
                        <div class="well well-sm" style="font-size:13px; margin-bottom:16px; background:#f8f8f8;">
                            <p class="mbot5"><strong>Setup Instructions</strong></p>
                            <ol style="margin:0 0 0 16px; padding:0;">
                                <li>Install the <strong>ClickFuzz Connector</strong> plugin on the WordPress site.</li>
                                <li>In WordPress, go to <em>Users → Profile</em> and create a new <strong>Application Password</strong> (not your account password).</li>
                                <li>Enter the WordPress site URL, your WordPress username, and the Application Password below, then save.</li>
                                <li>Click <strong>Test Connection</strong> to verify.</li>
                            </ol>
                        </div>
                        <?php } ?>

                        <!-- Connection form -->
                        <form method="POST" action="<?php echo admin_url('pitchsnap/save_wp_connection/' . $_wp_id); ?>" style="max-width:480px;">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <div class="form-group" style="margin-bottom:10px;">
                                <label style="font-size:13px; font-weight:600;">WordPress Site URL</label>
                                <input type="url" name="wp_url" class="form-control input-sm"
                                       value="<?php echo e($_wp_url); ?>"
                                       placeholder="https://client-site.com">
                            </div>
                            <div class="form-group" style="margin-bottom:10px;">
                                <label style="font-size:13px; font-weight:600;">WordPress Username</label>
                                <input type="text" name="wp_username" class="form-control input-sm"
                                       value="<?php echo e($_wp_user); ?>"
                                       placeholder="admin"
                                       autocomplete="off">
                            </div>
                            <div class="form-group" style="margin-bottom:14px;">
                                <label style="font-size:13px; font-weight:600;">Application Password</label>
                                <input type="password" name="wp_app_password" class="form-control input-sm"
                                       value=""
                                       placeholder="<?php echo $_wp_has_pass ? 'Leave blank to keep existing password' : 'xxxx xxxx xxxx xxxx xxxx xxxx'; ?>"
                                       autocomplete="new-password">
                                <p class="text-muted" style="font-size:11px; margin:4px 0 0;">
                                    Use an <strong>Application Password</strong> from WordPress → Users → Profile. Not your account login password.
                                    <?php if ($_wp_has_pass) { ?><br>A password is currently saved. Leave blank to keep it.<?php } ?>
                                </p>
                            </div>
                            <button type="submit" class="btn btn-default btn-sm mright5">
                                <i class="fa fa-save"></i> Save Connection
                            </button>
                            <?php if ($_wp_configured) { ?>
                            <button type="button" class="btn btn-info btn-sm" id="wp-test-btn"
                                    onclick="ps_test_wp_connection(<?php echo $_wp_id; ?>)">
                                <i class="fa fa-plug"></i> Test Connection
                            </button>
                            <?php } ?>
                        </form>

                        <!-- Test result (populated by JS) -->
                        <div id="wp-test-result" style="margin-top:10px; font-size:13px;"></div>

                        <!-- Connection status -->
                        <?php if ($_wp_connected) { ?>
                        <div style="margin-top:14px; padding-top:12px; border-top:1px solid #eee;">
                            <span class="text-success"><i class="fa fa-circle"></i> Connected</span>
                            <span class="text-muted" style="font-size:12px; margin-left:6px;">as of <?php echo _dt($redesign->wp_connected_at); ?></span>
                            <?php if ($_wp_conn_ver || $_wp_version || $_wp_theme_slug) { ?>
                            <br><span class="text-muted" style="font-size:12px;">
                                <?php
                                $parts = [];
                                if ($_wp_conn_ver)  { $parts[] = 'Connector v' . e($_wp_conn_ver); }
                                if ($_wp_version)    { $parts[] = 'WP ' . e($_wp_version); }
                                if ($_wp_theme_slug) { $parts[] = 'Theme: ' . e($_wp_theme_slug); }
                                echo implode(' &middot; ', $parts);
                                ?>
                            </span>
                            <?php } ?>
                        </div>
                        <?php } elseif ($_wp_configured) { ?>
                        <div style="margin-top:10px; font-size:13px;">
                            <span class="text-muted"><i class="fa fa-circle-o"></i> Not tested yet — click Test Connection to verify.</span>
                        </div>
                        <?php } ?>

                        <?php if ($_wp_has_html && $_wp_configured) { ?>
                        <!-- Deploy section -->
                        <div style="margin-top:18px; padding-top:14px; border-top:1px solid #eee;">
                            <p class="text-muted" style="font-size:12px; margin-bottom:10px;">
                                Deployment uploads the theme and imports content into WordPress.
                            </p>
                            <form method="POST" action="<?php echo admin_url('pitchsnap/deploy_to_wordpress/' . $_wp_id); ?>" style="display:inline;">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <button type="submit" class="btn btn-primary btn-sm mright5"
                                        onclick="return confirm('Deploy to WordPress? This uploads the theme and imports content.');">
                                    <i class="fa fa-rocket"></i> Deploy to WordPress
                                </button>
                            </form>
                            <?php if ($_wp_connected) { ?>
                            <form method="POST" action="<?php echo admin_url('pitchsnap/redeploy_wp_theme/' . $_wp_id); ?>" style="display:inline;">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <button type="submit" class="btn btn-default btn-sm mright5"
                                        onclick="return confirm('Redeploy theme only (content unchanged)?');">
                                    <i class="fa fa-refresh"></i> Redeploy Theme
                                </button>
                            </form>
                            <form method="POST" action="<?php echo admin_url('pitchsnap/reimport_wp_content/' . $_wp_id); ?>" style="display:inline;">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <button type="submit" class="btn btn-default btn-sm mright5"
                                        onclick="return confirm('Reimport WXR content? Existing ClickFuzz-owned pages and menus will be updated.');">
                                    <i class="fa fa-cloud-upload"></i> Reimport Content
                                </button>
                            </form>
                            <?php if ($_wp_url) { ?>
                            <a href="<?php echo e($_wp_url); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-default btn-sm">
                                <i class="fa fa-external-link"></i> View WordPress Site
                            </a>
                            <?php } ?>
                            <?php } ?>
                        </div>
                        <?php } ?>

                        <?php if ($_wp_has_html) { ?>
                        <!-- Download package -->
                        <div style="margin-top:14px; padding-top:12px; border-top:1px solid #eee;">
                            <?php if ($_wp_has_export) { ?>
                            <a href="<?php echo admin_url('pitchsnap/download_wordpress/' . $_wp_id); ?>" class="btn btn-default btn-xs mright5">
                                <i class="fa fa-download"></i> Download WordPress Package
                            </a>
                            <form method="POST" action="<?php echo admin_url('pitchsnap/export_wordpress/' . $_wp_id); ?>" style="display:inline;">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <button type="submit" class="btn btn-default btn-xs"
                                        onclick="return confirm('Regenerate the WordPress package?');">
                                    <i class="fa fa-refresh"></i> Regenerate
                                </button>
                            </form>
                            <?php } else { ?>
                            <form method="POST" action="<?php echo admin_url('pitchsnap/export_wordpress/' . $_wp_id); ?>" style="display:inline;">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <button type="submit" class="btn btn-default btn-xs">
                                    <i class="fa fa-wordpress"></i> Build WordPress Package
                                </button>
                            </form>
                            <?php } ?>
                        </div>
                        <?php } ?>

                        <?php if ($_wp_deploy) { ?>
                        <!-- Deployment result -->
                        <div style="margin-top:14px; padding-top:12px; border-top:1px solid #eee;">
                            <p style="font-size:12px; font-weight:600; margin-bottom:6px;">Last deployment result:</p>
                            <table class="table table-condensed" style="font-size:12px; margin:0; max-width:400px;">
                                <?php foreach ($_wp_deploy['steps'] as $_step) { ?>
                                <tr>
                                    <td style="padding:3px 6px;">
                                        <?php if ($_step['ok']) { ?>
                                        <span class="text-success"><i class="fa fa-check"></i></span>
                                        <?php } else { ?>
                                        <span class="text-danger"><i class="fa fa-times"></i></span>
                                        <?php } ?>
                                    </td>
                                    <td style="padding:3px 6px;"><?php echo e($_step['label']); ?></td>
                                    <td style="padding:3px 6px; color:#888;"><?php echo e($_step['message']); ?></td>
                                </tr>
                                <?php } ?>
                            </table>
                        </div>
                        <?php } ?>

                    </div>
                </div><!-- /.panel_s WordPress -->

                <!-- Custom Domain placeholder -->
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Custom Domain</h5>
                        <table class="table table-bordered table-condensed mbot15" style="max-width:520px;">
                            <tbody>
                                <tr><th width="35%">Custom domain</th><td><span class="text-muted">Not configured</span></td></tr>
                                <tr><th>DNS</th><td><span class="text-muted">Not configured</span></td></tr>
                                <tr><th>SSL</th><td><span class="text-muted">Not configured</span></td></tr>
                            </tbody>
                        </table>
                        <p class="text-muted" style="font-size:12px; margin:0;">
                            <i class="fa fa-clock-o"></i> Custom domain provisioning is coming in a future update.
                        </p>
                    </div>
                </div>

            </div><!-- #tab-publishing -->
