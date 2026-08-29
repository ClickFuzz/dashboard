<?php
defined('ABSPATH') || exit;

/**
 * Settings → ClickFuzz admin screen.
 *
 * Before pairing: shows the one-time setup token the user enters in ClickFuzz.
 * After pairing:  shows paired status. No credentials are visible.
 */
class CF_Settings
{
    public static function register_menu(): void
    {
        add_options_page(
            'ClickFuzz Connector',
            'ClickFuzz',
            'manage_options',
            'clickfuzz-connector',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $paired      = (bool) get_option('cf_connector_api_key', '');
        $setup_token = get_option('cf_setup_token', '');
        $rest_base   = get_rest_url(null, 'clickfuzz/v1/');
        $version     = CF_CONNECTOR_VERSION;
        $wp_ver      = get_bloginfo('version');
        ?>
        <div class="wrap">
            <h1>ClickFuzz Connector</h1>

            <h2>Status</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Plugin version</th>
                    <td><?php echo esc_html($version); ?></td>
                </tr>
                <tr>
                    <th scope="row">WordPress version</th>
                    <td><?php echo esc_html($wp_ver); ?></td>
                </tr>
                <tr>
                    <th scope="row">REST base URL</th>
                    <td><code><?php echo esc_html($rest_base); ?></code></td>
                </tr>
                <tr>
                    <th scope="row">Pairing status</th>
                    <td>
                        <?php if ($paired) { ?>
                        <span style="color:#00a32a;font-weight:600;">&#10003; Paired with ClickFuzz</span>
                        <?php } else { ?>
                        <span style="color:#d63638;font-weight:600;">&#10007; Not paired</span>
                        <?php } ?>
                    </td>
                </tr>
            </table>

            <?php if ($paired) { ?>

            <h2>Reset Pairing</h2>
            <p>If you need to re-pair this site (e.g. after moving to a new ClickFuzz account), reset the pairing below. This will generate a new setup token and disconnect the current ClickFuzz connection.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('cf_reset_pairing'); ?>
                <input type="hidden" name="action" value="cf_reset_pairing">
                <button type="submit" class="button button-secondary"
                        onclick="return confirm('Reset pairing? ClickFuzz will lose access until you re-pair.');">
                    Reset Pairing
                </button>
            </form>

            <?php } else { ?>

            <h2>Setup Token</h2>
            <p>Enter this token in <strong>ClickFuzz Web → Website → Publishing tab → WordPress Connector</strong> along with this site's URL to complete the pairing. The token is single-use and will be deleted once paired.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Setup Token</th>
                    <td>
                        <?php if ($setup_token) { ?>
                        <input type="text" class="regular-text code"
                               value="<?php echo esc_attr($setup_token); ?>"
                               readonly
                               onclick="this.select()"
                               style="font-family:monospace; font-size:18px; letter-spacing:2px;">
                        <p class="description">Click to select, then copy into ClickFuzz Web.</p>
                        <?php } else { ?>
                        <p class="description">No setup token available. Deactivate and reactivate the plugin to generate one.</p>
                        <?php } ?>
                    </td>
                </tr>
            </table>

            <?php } ?>

            <h2>Endpoints</h2>
            <table class="widefat striped" style="max-width:780px">
                <thead>
                    <tr><th>Method</th><th>Path</th><th>Auth</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <tr><td>POST</td>     <td><code>/clickfuzz/v1/setup</code></td>          <td>Setup token</td> <td>Initial pairing — single use</td></tr>
                    <tr><td>GET</td>      <td><code>/clickfuzz/v1/status</code></td>          <td>API key</td>     <td>Connection health check</td></tr>
                    <tr><td>POST</td>     <td><code>/clickfuzz/v1/theme</code></td>           <td>API key</td>     <td>Install theme ZIP</td></tr>
                    <tr><td>POST</td>     <td><code>/clickfuzz/v1/theme/activate</code></td>  <td>API key</td>     <td>Activate installed theme</td></tr>
                    <tr><td>POST</td>     <td><code>/clickfuzz/v1/import</code></td>          <td>API key</td>     <td>Import WXR content + logo</td></tr>
                    <tr><td>GET/POST</td> <td><code>/clickfuzz/v1/pages</code></td>           <td>API key</td>     <td>List / create pages</td></tr>
                    <tr><td>GET/PUT/DELETE</td><td><code>/clickfuzz/v1/pages/{id}</code></td> <td>API key</td>     <td>Read / update / delete page</td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function handle_reset_pairing(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('cf_reset_pairing');
        delete_option('cf_connector_api_key');
        update_option('cf_setup_token', wp_generate_password(12, false));
        wp_redirect(add_query_arg('page', 'clickfuzz-connector', admin_url('options-general.php')));
        exit;
    }
}
