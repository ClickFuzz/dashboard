<?php
defined('ABSPATH') || exit;

/**
 * Settings → ClickFuzz admin screen.
 *
 * Flow:
 *   Not connected: token input + Connect button.
 *   Connected:     shows WP site URL sent to ClickFuzz + Disconnect button.
 *
 * Connection is established by the user entering the token generated in
 * ClickFuzz Web (Publishing tab → WordPress Connector card). On submit,
 * the plugin POSTs {token, site_url} to CF_DASHBOARD_CALLBACK_URL.
 * ClickFuzz validates the token, stores the site URL, and returns success.
 * The plugin then stores the token as cf_connector_api_key for validating
 * incoming ClickFuzz API calls (X-CF-Key header).
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

        $connected = (bool) get_option('cf_connector_api_key', '');
        $site_url  = get_bloginfo('url');
        $version   = CF_CONNECTOR_VERSION;
        $wp_ver    = get_bloginfo('version');
        $rest_base = get_rest_url(null, 'clickfuzz/v1/');

        $notice = '';
        if (isset($_GET['cf_status'])) {
            if ($_GET['cf_status'] === 'connected') {
                $notice = '<div class="notice notice-success is-dismissible"><p><strong>Connected!</strong> This site is now paired with ClickFuzz Web.</p></div>';
            } elseif ($_GET['cf_status'] === 'disconnected') {
                $notice = '<div class="notice notice-info is-dismissible"><p>Disconnected from ClickFuzz Web.</p></div>';
            } elseif ($_GET['cf_status'] === 'error') {
                $msg = isset($_GET['cf_msg']) ? esc_html(urldecode($_GET['cf_msg'])) : 'Connection failed.';
                $notice = '<div class="notice notice-error is-dismissible"><p>' . $msg . '</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h1>ClickFuzz Connector</h1>

            <?php echo $notice; ?>

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
                    <th scope="row">Connection status</th>
                    <td>
                        <?php if ($connected) { ?>
                        <span style="color:#00a32a; font-weight:600;">&#10003; Connected to ClickFuzz Web</span>
                        <?php } else { ?>
                        <span style="color:#d63638; font-weight:600;">&#10007; Not connected</span>
                        <?php } ?>
                    </td>
                </tr>
            </table>

            <?php if ($connected) { ?>

            <h2>Disconnect</h2>
            <p>Disconnecting removes the stored API key from this plugin. ClickFuzz Web will no longer be able to deploy to this site until you reconnect with a new token.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('cf_disconnect'); ?>
                <input type="hidden" name="action" value="cf_disconnect">
                <button type="submit" class="button button-secondary"
                        onclick="return confirm('Disconnect from ClickFuzz Web? You will need a new token to reconnect.');">
                    Disconnect
                </button>
            </form>

            <?php } else { ?>

            <h2>Connect to ClickFuzz Web</h2>
            <p>
                Go to <strong>ClickFuzz Web → Website → Publishing tab → WordPress Connector</strong>
                and click <strong>Generate Token</strong>. Copy the token shown and paste it below.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('cf_save_token'); ?>
                <input type="hidden" name="action" value="cf_save_token">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cf_token_input">Connection Token</label></th>
                        <td>
                            <input type="text" id="cf_token_input" name="cf_token"
                                   class="regular-text code"
                                   placeholder="Paste token from ClickFuzz Web"
                                   autocomplete="off"
                                   style="font-family:monospace; font-size:15px; letter-spacing:1px;"
                                   required>
                            <p class="description">
                                The token is generated in ClickFuzz Web. This site's URL
                                (<code><?php echo esc_html($site_url); ?></code>) will be sent to ClickFuzz automatically.
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Connect'); ?>
            </form>

            <?php } ?>

            <h2>Endpoints</h2>
            <table class="widefat striped" style="max-width:780px">
                <thead>
                    <tr><th>Method</th><th>Path</th><th>Auth</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <tr><td>GET</td>      <td><code>/clickfuzz/v1/status</code></td>          <td>API key</td> <td>Connection health check</td></tr>
                    <tr><td>POST</td>     <td><code>/clickfuzz/v1/theme</code></td>           <td>API key</td> <td>Install theme ZIP</td></tr>
                    <tr><td>POST</td>     <td><code>/clickfuzz/v1/theme/activate</code></td>  <td>API key</td> <td>Activate installed theme</td></tr>
                    <tr><td>POST</td>     <td><code>/clickfuzz/v1/import</code></td>          <td>API key</td> <td>Import WXR content + logo</td></tr>
                    <tr><td>GET/POST</td> <td><code>/clickfuzz/v1/pages</code></td>           <td>API key</td> <td>List / create pages</td></tr>
                    <tr><td>GET/PUT/DELETE</td><td><code>/clickfuzz/v1/pages/{id}</code></td> <td>API key</td> <td>Read / update / delete page</td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function handle_save_token(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('cf_save_token');

        $token = isset($_POST['cf_token']) ? sanitize_text_field(wp_unslash($_POST['cf_token'])) : '';
        if (strlen($token) < 16) {
            wp_redirect(add_query_arg([
                'page'     => 'clickfuzz-connector',
                'cf_status' => 'error',
                'cf_msg'   => urlencode('Token too short. Copy it from ClickFuzz Web.'),
            ], admin_url('options-general.php')));
            exit;
        }

        // POST token + this site's URL back to ClickFuzz Web.
        $response = wp_remote_post(CF_DASHBOARD_CALLBACK_URL, [
            'timeout'     => 20,
            'headers'     => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body'        => wp_json_encode(['token' => $token, 'site_url' => get_bloginfo('url')]),
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            wp_redirect(add_query_arg([
                'page'      => 'clickfuzz-connector',
                'cf_status' => 'error',
                'cf_msg'    => urlencode('Could not reach ClickFuzz Web: ' . $response->get_error_message()),
            ], admin_url('options-general.php')));
            exit;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['success'])) {
            $msg = is_array($body) && isset($body['error']) ? $body['error'] : ('HTTP ' . $code);
            wp_redirect(add_query_arg([
                'page'      => 'clickfuzz-connector',
                'cf_status' => 'error',
                'cf_msg'    => urlencode('Connection failed: ' . $msg),
            ], admin_url('options-general.php')));
            exit;
        }

        // Store the token — ClickFuzz sends it as X-CF-Key on all subsequent API calls.
        update_option('cf_connector_api_key', $token);

        wp_redirect(add_query_arg([
            'page'      => 'clickfuzz-connector',
            'cf_status' => 'connected',
        ], admin_url('options-general.php')));
        exit;
    }

    public static function handle_disconnect(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('cf_disconnect');

        delete_option('cf_connector_api_key');

        wp_redirect(add_query_arg([
            'page'      => 'clickfuzz-connector',
            'cf_status' => 'disconnected',
        ], admin_url('options-general.php')));
        exit;
    }
}
