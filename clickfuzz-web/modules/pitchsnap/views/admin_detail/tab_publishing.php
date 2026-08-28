<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
            <!-- ══════════════════════════════════════════════════════════
                 TAB 3 — DOMAIN & PUBLISHING
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane active" id="tab-publishing">

                <?php
                $_publish_type = !empty($site) ? ($site->publish_type ?? null) : null;
                $_is_published = !empty($site) && $site->status === 'published';
                ?>

                <?php if (empty($site)) { ?>
                <!-- ── No site record yet ───────────────────────────────── -->
                <div class="panel_s">
                    <div class="panel-body">
                        <p class="text-muted" style="font-size:13px; margin:0;">
                            <i class="fa fa-info-circle"></i>
                            <?php if (!empty($redesign->preview_url) || !empty($redesign->generation_result)) { ?>
                            No site record exists yet. Trigger a generation first so ClickFuzz Web can create the site record.
                            <?php } else { ?>
                            Generate the website first before publishing.
                            <?php } ?>
                        </p>
                    </div>
                </div>

                <?php } else { ?>
                <?php if (!$_is_published) { ?>
                <!-- ── Choose publishing method ─────────────────────────── -->
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Choose Publishing Method</h5>
                        <p class="text-muted" style="font-size:13px; margin-bottom:12px;">
                            Choose how this site will be published. This cannot be changed after publishing.
                        </p>
                        <form method="POST" action="<?php echo admin_url('pitchsnap/save_publish_type/' . (int) $redesign->id); ?>">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <div style="margin-bottom:14px;">
                                <label style="font-size:13px; font-weight:400; cursor:pointer; display:block; margin-bottom:8px;">
                                    <input type="radio" name="publish_type" value="html" <?php echo ($_publish_type === 'html') ? 'checked' : ''; ?> style="margin-right:5px;">
                                    <strong>HTML</strong> — publish as a static HTML page on ClickFuzz infrastructure
                                </label>
                                <label style="font-size:13px; font-weight:400; cursor:pointer; display:block;">
                                    <input type="radio" name="publish_type" value="wordpress" <?php echo ($_publish_type === 'wordpress') ? 'checked' : ''; ?> style="margin-right:5px;">
                                    <strong>WordPress</strong> — deploy to a WordPress site via the ClickFuzz Connector
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fa fa-arrow-right"></i> Continue
                            </button>
                        </form>
                    </div>
                </div>

                <?php } ?>
                <?php if ($_publish_type === 'html') { ?>
                <!-- ── HTML Hosting ─────────────────────────────────────── -->
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">HTML Hosting</h5>
                        <?php
                        $_pf_domain = null;
                        if (isset($this->pitchsnap_model)) {
                            $_pf_domain = $this->pitchsnap_model->get_platform_domain_for_site($site->id);
                        }
                        $_pub_url = $_pf_domain ? 'https://' . $_pf_domain->hostname . '/' : null;
                        ?>
                        <table class="table table-bordered table-condensed mbot15" style="max-width:520px;">
                            <tbody>
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
                            </tbody>
                        </table>

                        <?php if ($_pub_url) { ?>
                        <a href="<?php echo e($_pub_url); ?>" target="_blank" rel="noopener noreferrer"
                           class="btn btn-success btn-sm mright5">
                            <i class="fa fa-globe"></i> Open Site
                        </a>
                        <?php } ?>
                        <form method="POST" action="<?php echo admin_url('pitchsnap/publish_site/' . (int) $redesign->id); ?>" style="display:inline;">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <input type="hidden" name="confirm_publish" value="1">
                            <button type="submit" class="btn btn-primary btn-sm"
                                    onclick="return confirm('<?php echo $_is_published ? 'Republish this site (overwrites current content)?' : 'Publish this site to its permanent URL?'; ?>');">
                                <i class="fa fa-<?php echo $_is_published ? 'refresh' : 'upload'; ?>"></i>
                                <?php echo $_is_published ? 'Republish' : 'Publish Site'; ?>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- ── Custom Domain ─────────────────────────────────────── -->
                <?php
                $_cd = null;
                if (isset($this->pitchsnap_model)) {
                    $_cd = $this->pitchsnap_model->get_custom_domain_for_site($site->id);
                }
                $_cd_verification = $_cd ? ($_cd->verification_status ?? 'pending') : null;
                $_cd_ssl          = $_cd ? ($_cd->ssl_status          ?? 'pending') : null;

                $_cd_dns_records = [];
                if ($_cd) {
                    $_cd_is_apex = (substr_count($_cd->hostname, '.') === 1);
                    if ($_cd_is_apex) {
                        $_cd_dns_records = [
                            ['type' => 'A',     'host' => '@',   'value' => '104.152.168.38',      'note' => 'Points your root domain to ClickFuzz'],
                            ['type' => 'CNAME', 'host' => 'www', 'value' => 'sites.clickfuzz.com', 'note' => 'www → ClickFuzz (required)'],
                        ];
                    } else {
                        $_cd_parts = explode('.', $_cd->hostname);
                        $_cd_label = $_cd_parts[0];
                        $_cd_dns_records = [
                            ['type' => 'CNAME', 'host' => $_cd_label, 'value' => 'sites.clickfuzz.com', 'note' => 'Points your subdomain to ClickFuzz'],
                        ];
                    }
                }
                ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Custom Domain</h5>

                        <?php if ($_cd) { ?>
                        <table class="table table-bordered table-condensed mbot15" style="max-width:520px;">
                            <tbody>
                                <tr>
                                    <th width="35%">Domain</th>
                                    <td><strong><?php echo e($_cd->hostname); ?></strong></td>
                                </tr>
                                <tr>
                                    <th>Verification</th>
                                    <td>
                                        <?php if ($_cd_verification === 'verified') { ?>
                                        <span class="label label-success">Verified</span>
                                        <?php } elseif ($_cd_verification === 'failed') { ?>
                                        <span class="label label-danger">DNS Misconfigured</span>
                                        <?php } else { ?>
                                        <span class="label label-warning">Pending DNS setup</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php if (!empty($_cd->verified_at) && $_cd_verification === 'verified') { ?>
                                <tr>
                                    <th>Verified at</th>
                                    <td><?php echo _dt($_cd->verified_at); ?></td>
                                </tr>
                                <?php } ?>
                                <tr>
                                    <th>SSL</th>
                                    <td>
                                        <?php if ($_cd_ssl === 'active') { ?>
                                        <span class="label label-success">Active</span>
                                        <?php } elseif ($_cd_ssl === 'failed') { ?>
                                        <span class="label label-danger">Failed</span>
                                        <?php } else { ?>
                                        <span class="label label-default">Pending (after verification)</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <?php if ($_cd_verification !== 'verified') { ?>
                        <div class="well well-sm" style="font-size:12px; margin-bottom:14px; background:#f8fbff; border-color:#c4daf5;">
                            <p style="margin:0 0 8px; font-weight:600;">Add these DNS records at your domain registrar:</p>
                            <p style="margin:0 0 8px; color:#555;"><i class="fa fa-shield"></i> Keep your existing nameservers and email/DNS records. Only add or update the records shown below.</p>
                            <table class="table table-condensed" style="margin:0; font-size:12px; font-family:monospace;">
                                <thead><tr><th>Type</th><th>Host</th><th>Value</th><th style="font-family:sans-serif; font-weight:400;">Purpose</th></tr></thead>
                                <tbody>
                                    <?php foreach ($_cd_dns_records as $_r) { ?>
                                    <tr>
                                        <td><strong><?php echo e($_r['type']); ?></strong></td>
                                        <td><?php echo e($_r['host']); ?></td>
                                        <td><?php echo e($_r['value']); ?></td>
                                        <td style="font-family:sans-serif; color:#666;"><?php echo e($_r['note']); ?></td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                            <p style="margin:8px 0 0; color:#666;">DNS changes can take up to 48 hours to propagate. Click <strong>Verify DNS</strong> after adding the records.</p>
                        </div>
                        <?php } ?>

                        <form method="POST" action="<?php echo admin_url('pitchsnap/verify_custom_domain/' . (int) $redesign->id); ?>" style="display:inline; margin-right:6px;">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <button type="submit" class="btn btn-<?php echo $_cd_verification === 'verified' ? 'default' : 'info'; ?> btn-sm">
                                <i class="fa fa-refresh"></i> Verify DNS
                            </button>
                        </form>

                        <?php } else { ?>
                        <p class="text-muted" style="font-size:13px; margin-bottom:14px;">
                            No custom domain configured. Enter a domain below to begin setup.
                        </p>
                        <?php } ?>

                        <form method="POST" action="<?php echo admin_url('pitchsnap/save_custom_domain/' . (int) $redesign->id); ?>" style="max-width:480px; margin-top:<?php echo $_cd ? '10px' : '0'; ?>;">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <div class="input-group" style="margin-bottom:10px;">
                                <input type="text" name="custom_domain" class="form-control input-sm"
                                       value="<?php echo $_cd ? e($_cd->hostname) : ''; ?>"
                                       placeholder="yourdomain.com">
                                <span class="input-group-btn">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="fa fa-save"></i> <?php echo $_cd ? 'Update Domain' : 'Save Domain'; ?>
                                    </button>
                                </span>
                            </div>
                        </form>
                        <?php if ($_cd) { ?>
                        <form method="POST" action="<?php echo admin_url('pitchsnap/remove_custom_domain/' . (int) $redesign->id); ?>" style="display:inline;">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <button type="submit" class="btn btn-default btn-xs"
                                    onclick="return confirm('Remove custom domain <?php echo e($_cd->hostname); ?>?');">
                                <i class="fa fa-times"></i> Remove Domain
                            </button>
                        </form>
                        <?php } ?>

                        <p class="text-muted" style="font-size:12px; margin-top:12px; margin-bottom:0;">
                            <i class="fa fa-info-circle"></i> Your ClickFuzz platform URL remains active throughout. SSL provisioning follows after DNS is verified.
                        </p>
                    </div>
                </div>

                <?php } elseif ($_publish_type === 'wordpress') { ?>
                <!-- ── WordPress REST API Publish ───────────────────────── -->
                <?php
                $_wp1_url      = !empty($site->wp_site_url)     ? $site->wp_site_url     : '';
                $_wp1_user     = !empty($site->wp_username)      ? $site->wp_username     : '';
                $_wp1_has_pass = !empty($site->wp_app_password);
                $_wp1_page_id  = !empty($site->wp_page_id)       ? (int) $site->wp_page_id : 0;
                $_wp1_ready    = $_wp1_url && $_wp1_user && $_wp1_has_pass;
                ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">WordPress REST API Publish</h5>
                        <?php if ($_is_published && $_wp1_page_id) { ?>
                        <div class="alert alert-success" style="font-size:13px; margin-bottom:14px;">
                            <i class="fa fa-check-circle"></i>
                            Published to WordPress as page ID <?php echo $_wp1_page_id; ?>.
                            <?php if ($_wp1_url) { ?>
                            <a href="<?php echo e(rtrim($_wp1_url, '/')); ?>" target="_blank" rel="noopener noreferrer" style="margin-left:6px;">
                                <i class="fa fa-external-link"></i> View Site
                            </a>
                            <?php } ?>
                        </div>
                        <?php } ?>

                        <form method="POST" action="<?php echo admin_url('pitchsnap/save_wp_connection/' . (int) $redesign->id); ?>" style="max-width:480px; margin-bottom:14px;">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <div class="form-group" style="margin-bottom:10px;">
                                <label style="font-size:13px; font-weight:600;">WordPress Site URL</label>
                                <input type="url" name="wp_site_url" class="form-control input-sm"
                                       value="<?php echo e($_wp1_url); ?>"
                                       placeholder="https://client-site.com">
                            </div>
                            <div class="form-group" style="margin-bottom:10px;">
                                <label style="font-size:13px; font-weight:600;">WordPress Username</label>
                                <input type="text" name="wp_username" class="form-control input-sm"
                                       value="<?php echo e($_wp1_user); ?>"
                                       placeholder="admin"
                                       autocomplete="off">
                            </div>
                            <div class="form-group" style="margin-bottom:6px;">
                                <label style="font-size:13px; font-weight:600;">Application Password</label>
                                <input type="password" name="wp_app_password" class="form-control input-sm"
                                       value=""
                                       placeholder="<?php echo $_wp1_has_pass ? 'Leave blank to keep existing password' : 'xxxx xxxx xxxx xxxx xxxx xxxx'; ?>"
                                       autocomplete="new-password">
                            </div>
                            <div class="well well-sm" style="font-size:12px; padding:8px 12px; margin-bottom:12px; background:#fff8e1; border-color:#ffc107;">
                                <i class="fa fa-exclamation-triangle" style="color:#e6a817;"></i>
                                <strong>Administrator access required.</strong>
                                The WordPress user must be an <strong>Administrator</strong>.
                                Publishing automatically sets this page as the WordPress front page
                                using the Settings API (<code>manage_options</code> capability).
                                A non-administrator Application Password will cause front-page assignment to fail.
                                <?php if ($_wp1_has_pass) { ?><br>A password is currently saved. Leave blank to keep it.<?php } ?>
                            </div>
                            <button type="submit" class="btn btn-default btn-sm">
                                <i class="fa fa-save"></i> Save Connection
                            </button>
                        </form>

                        <?php if ($_wp1_ready && !$_is_published && !empty($redesign->generation_result)) { ?>
                        <form method="POST" action="<?php echo admin_url('pitchsnap/publish_site_wp/' . (int) $redesign->id); ?>" style="display:inline;">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <button type="submit" class="btn btn-primary btn-sm"
                                    onclick="return confirm('Publish to WordPress? The generated page will be set as the WordPress front page.');">
                                <i class="fa fa-wordpress"></i> Publish to WordPress
                            </button>
                        </form>
                        <?php } elseif (!$_wp1_ready && !$_is_published) { ?>
                        <p class="text-muted" style="font-size:13px; margin:0;">
                            <i class="fa fa-info-circle"></i> Save the WordPress connection details above before publishing.
                        </p>
                        <?php } elseif (empty($redesign->generation_result) && !$_is_published) { ?>
                        <p class="text-muted" style="font-size:13px; margin:0;">
                            <i class="fa fa-info-circle"></i> Generate the website first, then return here to publish.
                        </p>
                        <?php } ?>
                    </div>
                </div>

                <!-- ── WordPress Connector ──────────────────────────────── -->
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

                        <div id="wp-test-result" style="margin-top:10px; font-size:13px;"></div>

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

                <?php } ?>
                <?php } /* end site/publish_type block */ ?>

            </div><!-- #tab-publishing -->
