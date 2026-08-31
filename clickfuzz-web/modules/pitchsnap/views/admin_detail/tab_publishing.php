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
                        $_pf_domain = $platform_domain ?? null;
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
                $_cd = $custom_domain ?? null;
                $_cd_verification = $_cd ? ($_cd->verification_status ?? 'pending') : null;
                $_cd_ssl          = $_cd ? ($_cd->ssl_status          ?? 'pending') : null;

                $_cd_dns_records = [];
                if ($_cd) {
                    $_cd_is_apex = (substr_count($_cd->hostname, '.') === 1);
                    if ($_cd_is_apex) {
                        $_cd_dns_records = [
                            ['type' => 'A',     'host' => '@',   'value' => '164.90.255.122',           'note' => 'Points your root domain to ClickFuzz'],
                            ['type' => 'CNAME', 'host' => 'www', 'value' => 'customers.clickfuzz.com',  'note' => 'www → ClickFuzz via Cloudflare (required)'],
                        ];
                    } else {
                        $_cd_parts = explode('.', $_cd->hostname);
                        $_cd_label = $_cd_parts[0];
                        $_cd_dns_records = [
                            ['type' => 'CNAME', 'host' => $_cd_label, 'value' => 'customers.clickfuzz.com', 'note' => 'Points your subdomain to ClickFuzz via Cloudflare'],
                        ];
                    }
                }
                ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Custom Domain</h5>

                        <?php if ($_cd) { ?>
                        <?php $_cd_cf_status = $_cd->cf_status ?? null; ?>
                        <div style="display:flex; align-items:center; gap:6px; max-width:560px; margin-bottom:14px; flex-wrap:wrap;">
                            <form method="POST" action="<?php echo admin_url('pitchsnap/save_custom_domain/' . (int) $redesign->id); ?>" style="flex:1; min-width:200px;">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <div class="input-group">
                                    <input type="text" name="custom_domain" class="form-control input-sm"
                                           value="<?php echo e($_cd->hostname); ?>" autocomplete="off">
                                    <span class="input-group-btn">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="fa fa-save"></i> Update
                                        </button>
                                    </span>
                                </div>
                            </form>
                            <form method="POST" action="<?php echo admin_url('pitchsnap/remove_custom_domain/' . (int) $redesign->id); ?>" style="flex-shrink:0;">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <button type="submit" class="btn btn-danger btn-sm"
                                        onclick="return confirm('Remove custom domain <?php echo e($_cd->hostname); ?>?');">
                                    <i class="fa fa-times"></i> Remove
                                </button>
                            </form>
                        </div>
                        <?php
                        $_dns_s = $dns_status ?? null;
                        $_ssl_s = $ssl_status ?? null;
                        ?>
                        <table class="table table-bordered table-condensed mbot15" style="max-width:520px;">
                            <tbody>
                                <tr>
                                    <th width="35%">DNS</th>
                                    <td>
                                        <?php if ($_dns_s === 'connected') { ?>
                                        <span class="label label-success">Connected</span>
                                        <?php } elseif ($_dns_s === '@_invalid') { ?>
                                        <span class="label label-warning">@ invalid</span>
                                        <?php } elseif ($_dns_s === 'www_invalid') { ?>
                                        <span class="label label-warning">www invalid</span>
                                        <?php } elseif ($_dns_s === '@/www_invalid') { ?>
                                        <span class="label label-danger">@/www invalid</span>
                                        <?php } else { ?>
                                        <span class="label label-default">Not checked</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>SSL</th>
                                    <td>
                                        <?php if ($_ssl_s === 'connected') { ?>
                                        <span class="label label-success">Connected</span>
                                        <?php } elseif ($_ssl_s === '@_invalid') { ?>
                                        <span class="label label-warning">@ invalid</span>
                                        <?php } elseif ($_ssl_s === 'www_invalid') { ?>
                                        <span class="label label-warning">www invalid</span>
                                        <?php } elseif ($_ssl_s === '@/www_invalid') { ?>
                                        <span class="label label-danger">@/www invalid</span>
                                        <?php } else { ?>
                                        <span class="label label-default">Pending</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Cloudflare</th>
                                    <td>
                                        <?php if ($_cd_cf_status === 'connected') { ?>
                                        <span class="label label-success">Connected</span>
                                        <?php } elseif ($_cd_cf_status === 'failed') { ?>
                                        <span class="label label-danger">Failed</span>
                                        <?php } elseif ($_cd_cf_status) { ?>
                                        <span class="label label-warning">Waiting for SSL</span>
                                        <?php } else { ?>
                                        <span class="label label-default">Not provisioned</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <form method="POST" action="<?php echo admin_url('pitchsnap/refresh_domain_status/' . (int) $redesign->id); ?>" style="margin-bottom:12px;">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <button type="submit" class="btn btn-default btn-xs">
                                <i class="fa fa-refresh"></i> Refresh Status
                            </button>
                        </form>

                        <?php if ($_dns_s !== 'connected') { ?>
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
                            <p style="margin:8px 0 0; color:#666;">DNS changes can take up to 48 hours to propagate.</p>
                        </div>
                        <?php } ?>

                        <?php } else { ?>
                        <p class="text-muted" style="font-size:13px; margin-bottom:14px;">
                            No custom domain configured. Enter a domain below to begin setup.
                        </p>
                        <form method="POST" action="<?php echo admin_url('pitchsnap/save_custom_domain/' . (int) $redesign->id); ?>" style="max-width:480px;">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <div class="input-group" style="margin-bottom:10px;">
                                <input type="text" name="custom_domain" class="form-control input-sm"
                                       placeholder="yourdomain.com" autocomplete="off">
                                <span class="input-group-btn">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="fa fa-save"></i> Save Domain
                                    </button>
                                </span>
                            </div>
                        </form>
                        <?php } ?>

                        <p class="text-muted" style="font-size:12px; margin-top:12px; margin-bottom:0;">
                            <i class="fa fa-info-circle"></i> Your ClickFuzz platform URL remains active. To use a different domain, remove the current one first.
                        </p>
                    </div>
                </div>

                <?php } elseif ($_publish_type === 'wordpress') { ?>
                <!-- ── WordPress Connector ──────────────────────────────── -->
                <?php
                $_wp_id         = (int) $redesign->id;
                $_wp_token      = !empty($site->wp_pairing_token) ? $site->wp_pairing_token : '';
                $_wp_connected  = !empty($site->wp_api_key) && !empty($site->wp_connected_at);
                $_wp_url        = !empty($site->wp_site_url)         ? $site->wp_site_url         : '';
                $_wp_conn_ver   = !empty($site->wp_connector_version) ? $site->wp_connector_version : '';
                $_wp_version    = !empty($site->wp_wp_version)        ? $site->wp_wp_version        : '';
                $_wp_theme_slug = !empty($site->wp_active_theme_slug) ? $site->wp_active_theme_slug : '';
                $_wp_has_html   = !empty($redesign->generation_result);
                $_wp_deploy_raw = $this->session->flashdata('wp_deploy_result');
                $_wp_deploy     = $_wp_deploy_raw ? json_decode($_wp_deploy_raw, true) : null;
                $_wp_zips       = glob(dirname(FCPATH) . '/exports/wordpress/' . $_wp_id . '/*.zip') ?: [];
                $_wp_has_export = !empty($_wp_zips);
                ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10"><i class="fa fa-wordpress" style="color:#21759b;"></i> WordPress Connector</h5>

                        <?php if ($_wp_connected) { ?>
                        <!-- ── State 3: Connected ─────────────────────────────── -->
                        <div style="margin-bottom:12px;">
                            <span class="text-success"><i class="fa fa-check-circle"></i> Connected</span>
                            <?php if ($_wp_url) { ?>
                            <span class="text-muted" style="font-size:12px; margin-left:8px;"><i class="fa fa-globe"></i> <?php echo e($_wp_url); ?></span>
                            <?php } ?>
                            <?php if ($_wp_conn_ver || $_wp_version || $_wp_theme_slug) { ?>
                            <br><span class="text-muted" style="font-size:12px;">
                                <?php
                                $parts = [];
                                if ($_wp_conn_ver)  { $parts[] = 'Connector v' . e($_wp_conn_ver); }
                                if ($_wp_version)    { $parts[] = 'WP ' . e($_wp_version); }
                                if ($_wp_theme_slug) { $parts[] = 'Theme: ' . e($_wp_theme_slug); }
                                echo implode(' &middot; ', $parts);
                                ?>
                                <?php if (!empty($site->wp_connected_at)) { ?>
                                &middot; last verified <?php echo _dt($site->wp_connected_at); ?>
                                <?php } ?>
                            </span>
                            <?php } ?>
                        </div>

                        <div id="wp-test-result" style="margin-bottom:10px; font-size:13px;"></div>

                        <button type="button" class="btn btn-default btn-sm mright5" id="wp-test-btn"
                                onclick="ps_test_wp_connection(<?php echo $_wp_id; ?>)">
                            <i class="fa fa-plug"></i> Test Connection
                        </button>
                        <?php if ($_wp_url) { ?>
                        <a href="<?php echo e($_wp_url); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-default btn-sm">
                            <i class="fa fa-external-link"></i> View WordPress Site
                        </a>
                        <?php } ?>

                        <?php if ($_wp_has_html) { ?>
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
                            <form method="POST" action="<?php echo admin_url('pitchsnap/redeploy_wp_theme/' . $_wp_id); ?>" style="display:inline;">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <button type="submit" class="btn btn-default btn-sm mright5"
                                        onclick="return confirm('Redeploy theme only?');">
                                    <i class="fa fa-refresh"></i> Redeploy Theme
                                </button>
                            </form>
                            <form method="POST" action="<?php echo admin_url('pitchsnap/reimport_wp_content/' . $_wp_id); ?>" style="display:inline;">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <button type="submit" class="btn btn-default btn-sm"
                                        onclick="return confirm('Reimport WXR content? Existing ClickFuzz-owned pages and menus will be updated.');">
                                    <i class="fa fa-cloud-upload"></i> Reimport Content
                                </button>
                            </form>
                        </div>
                        <?php } ?>

                        <div style="margin-top:14px; padding-top:12px; border-top:1px solid #eee;">
                            <?php if ($_wp_has_export) { ?>
                            <a href="<?php echo admin_url('pitchsnap/download_wordpress/' . $_wp_id); ?>" class="btn btn-default btn-xs mright5">
                                <i class="fa fa-download"></i> Download WordPress Package
                            </a>
                            <form method="POST" action="<?php echo admin_url('pitchsnap/export_wordpress/' . $_wp_id); ?>" style="display:inline;">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <button type="submit" class="btn btn-default btn-xs"
                                        onclick="return confirm('Regenerate the WordPress package?');">
                                    <i class="fa fa-refresh"></i> Regenerate Package
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

                        <?php
                        $_wp_update_raw = $this->session->flashdata('wp_update_result');
                        $_wp_update     = $_wp_update_raw ? json_decode($_wp_update_raw, true) : null;
                        ?>
                        <div style="margin-top:14px; padding-top:12px; border-top:1px solid #eee;">
                            <form method="POST" action="<?php echo admin_url('pitchsnap/update_wp_plugin/' . $_wp_id); ?>" style="display:inline;">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <button type="submit" class="btn btn-default btn-sm"
                                        onclick="return confirm('Push the latest ClickFuzz Connector plugin to WordPress and update it automatically?');">
                                    <i class="fa fa-arrow-circle-up"></i> Update WP Connector Plugin
                                </button>
                            </form>
                            <?php if ($_wp_update) { ?>
                            <span class="<?php echo $_wp_update['success'] ? 'text-success' : 'text-danger'; ?>" style="font-size:12px; margin-left:10px;">
                                <i class="fa fa-<?php echo $_wp_update['success'] ? 'check' : 'times'; ?>"></i>
                                <?php if ($_wp_update['success']) { ?>
                                Plugin updated<?php echo !empty($_wp_update['version']) ? ' to v' . e($_wp_update['version']) : ''; ?>.
                                <?php } else { ?>
                                <?php echo e($_wp_update['error'] ?? 'Update failed.'); ?>
                                <?php } ?>
                            </span>
                            <?php } ?>
                        </div>

                        <div style="margin-top:18px; padding-top:12px; border-top:1px solid #eee;">
                            <form method="POST" action="<?php echo admin_url('pitchsnap/generate_wp_token/' . $_wp_id); ?>" style="display:inline;">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <button type="submit" class="btn btn-warning btn-sm"
                                        onclick="return confirm('Get a new token? This disconnects the current WordPress site. You will need to enter the new token in the plugin settings to reconnect.');">
                                    <i class="fa fa-key"></i> Get New Token
                                </button>
                            </form>
                        </div>

                        <?php } elseif ($_wp_token) { ?>
                        <!-- ── State 2: Token ready, waiting for plugin ────────── -->
                        <div class="well well-sm" style="font-size:13px; margin-bottom:16px; background:#f8f8f8;">
                            <p class="mbot5"><strong>Setup Instructions</strong></p>
                            <ol style="margin:0 0 0 16px; padding:0;">
                                <li>Click <strong>Download Plugin</strong> below and install it on the WordPress site.</li>
                                <li>Activate the plugin, then go to <strong>WP Settings → ClickFuzz Connector</strong>.</li>
                                <li>Paste the token below into the plugin and click <strong>Connect</strong>.</li>
                            </ol>
                        </div>

                        <div style="margin-bottom:16px;">
                            <label style="font-size:13px; font-weight:600; display:block; margin-bottom:6px;">Connection Token</label>
                            <div class="input-group" style="max-width:420px;">
                                <input type="text" id="wp-token-display" class="form-control input-sm"
                                       value="<?php echo e($_wp_token); ?>"
                                       readonly
                                       onclick="this.select()"
                                       style="font-family:monospace; font-size:15px; letter-spacing:1px; background:#fff;">
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-default btn-sm"
                                            onclick="var el=document.getElementById('wp-token-display'); el.select(); document.execCommand('copy'); this.innerHTML='<i class=\'fa fa-check\'></i> Copied';">
                                        <i class="fa fa-copy"></i> Copy
                                    </button>
                                </span>
                            </div>
                            <p class="text-muted" style="font-size:11px; margin:4px 0 0;">
                                Once the plugin connects, a new token is required to reconnect.
                            </p>
                        </div>

                        <a href="<?php echo admin_url('pitchsnap/download_wp_plugin/' . $_wp_id); ?>" class="btn btn-primary btn-sm">
                            <i class="fa fa-download"></i> Download Plugin
                        </a>

                        <div style="margin-top:18px; padding-top:12px; border-top:1px solid #eee;">
                            <form method="POST" action="<?php echo admin_url('pitchsnap/generate_wp_token/' . $_wp_id); ?>" style="display:inline;">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <button type="submit" class="btn btn-link btn-xs text-muted"
                                        style="padding:0; font-size:11px;"
                                        onclick="return confirm('Regenerate token? The current token will be invalidated.');">
                                    <i class="fa fa-refresh"></i> Regenerate Token
                                </button>
                            </form>
                        </div>

                        <?php } else { ?>
                        <!-- ── State 1: No token yet ──────────────────────────── -->
                        <p class="text-muted" style="font-size:13px; margin-bottom:14px;">
                            Generate a connection token to pair this website with a WordPress installation.
                        </p>
                        <form method="POST" action="<?php echo admin_url('pitchsnap/generate_wp_token/' . $_wp_id); ?>">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <button type="submit" class="btn btn-default btn-sm">
                                <i class="fa fa-key"></i> Generate Token
                            </button>
                        </form>
                        <?php } ?>

                    </div>
                </div><!-- /.panel_s WordPress Connector -->

                <?php } ?>
                <?php } /* end site/publish_type block */ ?>

            </div><!-- #tab-publishing -->
