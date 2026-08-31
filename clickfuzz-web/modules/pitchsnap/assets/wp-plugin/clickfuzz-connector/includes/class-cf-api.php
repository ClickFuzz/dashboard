<?php
defined('ABSPATH') || exit;

/**
 * REST API registration and route callbacks.
 *
 * Namespace: clickfuzz/v1
 *
 * Auth model — all routes require X-CF-Key header matching the stored cf_connector_api_key.
 * The API key is the connection token the user entered in WP Settings → ClickFuzz Connector,
 * which ClickFuzz Web sends back on every subsequent API call (deploy, import, etc.).
 */
class CF_API
{
    const NAMESPACE = 'clickfuzz/v1';

    public static function register_routes(): void
    {
        // ── Status ────────────────────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'status'],
            'permission_callback' => [self::class, 'check_key'],
        ]);

        // ── Generated-page CRUD ───────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/pages', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [self::class, 'list_pages'],
                'permission_callback' => [self::class, 'check_key'],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'create_page'],
                'permission_callback' => [self::class, 'check_key'],
                'args'                => self::page_args(false),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/pages/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [self::class, 'get_page'],
                'permission_callback' => [self::class, 'check_key'],
                'args'                => ['id' => ['type' => 'integer']],
            ],
            [
                'methods'             => ['POST', 'PUT', 'PATCH'],
                'callback'            => [self::class, 'update_page'],
                'permission_callback' => [self::class, 'check_key'],
                'args'                => array_merge(
                    ['id' => ['type' => 'integer']],
                    self::page_args(true)
                ),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [self::class, 'delete_page'],
                'permission_callback' => [self::class, 'check_key'],
                'args'                => ['id' => ['type' => 'integer']],
            ],
        ]);

        // ── Theme deployment ──────────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/theme', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'deploy_theme'],
            'permission_callback' => [self::class, 'check_key'],
        ]);

        register_rest_route(self::NAMESPACE, '/theme/activate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'activate_theme'],
            'permission_callback' => [self::class, 'check_key'],
            'args'                => [
                'slug' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // ── Full content import ───────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/import', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'import_content'],
            'permission_callback' => [self::class, 'check_key'],
        ]);

        // ── Plugin self-update ────────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/plugin/update', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'update_plugin'],
            'permission_callback' => [self::class, 'check_key'],
        ]);
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    public static function check_key(WP_REST_Request $request): bool|WP_Error
    {
        $key    = $request->get_header('X-CF-Key');
        $stored = get_option('cf_connector_api_key', '');

        if (empty($key) || empty($stored)) {
            return new WP_Error('cf_unauthorized', 'Not connected to ClickFuzz.', ['status' => 401]);
        }
        if (!hash_equals($stored, $key)) {
            return new WP_Error('cf_unauthorized', 'Invalid API key.', ['status' => 401]);
        }
        return true;
    }

    // ── Callbacks ────────────────────────────────────────────────────────────

    public static function status(WP_REST_Request $request): WP_REST_Response
    {
        $theme = wp_get_theme();
        return new WP_REST_Response([
            'status'            => 'ok',
            'version'           => CF_CONNECTOR_VERSION,
            'wp'                => get_bloginfo('version'),
            'active_theme_slug' => get_stylesheet(),
            'active_theme_name' => $theme->get('Name'),
            'capabilities'      => ['theme', 'import', 'pages'],
        ], 200);
    }

    public static function list_pages(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(CF_Pages::list(), 200);
    }

    public static function get_page(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $page = CF_Pages::get((int) $request['id']);
        if (!$page) {
            return new WP_Error('cf_not_found', 'Page not found.', ['status' => 404]);
        }
        return new WP_REST_Response($page, 200);
    }

    public static function create_page(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = CF_Pages::create($request->get_params());
        if (is_wp_error($result)) { return $result; }
        return new WP_REST_Response($result, 201);
    }

    public static function update_page(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = CF_Pages::update((int) $request['id'], $request->get_params());
        if (is_wp_error($result)) { return $result; }
        return new WP_REST_Response($result, 200);
    }

    public static function delete_page(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = CF_Pages::delete((int) $request['id']);
        if (is_wp_error($result)) { return $result; }
        return new WP_REST_Response(['deleted' => true], 200);
    }

    public static function deploy_theme(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $files     = $request->get_file_params();
        $theme_url = $request->get_param('theme_url');
        $cleanup   = false;

        if (!empty($files['theme_zip']['tmp_name'])) {
            // Push model: ZIP uploaded directly as multipart
            $zip_path = $files['theme_zip']['tmp_name'];
        } elseif (!empty($theme_url)) {
            // Pull model: fetch ZIP from signed ClickFuzz URL
            $response = wp_remote_get(esc_url_raw($theme_url), [
                'timeout'   => 120,
                'sslverify' => true,
            ]);
            if (is_wp_error($response)) {
                return new WP_Error('cf_download_failed', 'Could not fetch theme: ' . $response->get_error_message(), ['status' => 500]);
            }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                return new WP_Error('cf_download_failed', 'Theme download returned HTTP ' . $code . '.', ['status' => 500]);
            }
            if (!function_exists('wp_tempnam')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $zip_path = wp_tempnam('cf-theme-') . '.zip';
            file_put_contents($zip_path, wp_remote_retrieve_body($response));
            $cleanup = true;
        } else {
            return new WP_Error('cf_bad_request', 'theme_zip file or theme_url required.', ['status' => 400]);
        }

        try {
            $result = CF_Theme::deploy($zip_path);
        } catch (\Throwable $e) {
            if ($cleanup && file_exists($zip_path)) { @unlink($zip_path); }
            return new WP_Error('cf_fatal', $e->getMessage(), ['status' => 500]);
        }
        if ($cleanup && file_exists($zip_path)) {
            @unlink($zip_path);
        }
        if (is_wp_error($result)) { return $result; }
        return new WP_REST_Response($result, 200);
    }

    public static function activate_theme(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = CF_Theme::activate(sanitize_text_field($request->get_param('slug')));
        if (is_wp_error($result)) { return $result; }
        return new WP_REST_Response($result, 200);
    }

    public static function import_content(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $files = $request->get_file_params();

        if (empty($files['xml']['tmp_name']) || !is_readable($files['xml']['tmp_name'])) {
            return new WP_Error('cf_missing_file', 'WXR file is required.', ['status' => 400]);
        }

        $xml_content = file_get_contents($files['xml']['tmp_name']);
        if ($xml_content === false) {
            return new WP_Error('cf_file_read', 'Could not read WXR file.', ['status' => 500]);
        }

        $replace_existing = filter_var($request->get_param('replace_existing'), FILTER_VALIDATE_BOOLEAN);
        $logo_file_path   = !empty($files['logo']['tmp_name']) && is_readable($files['logo']['tmp_name'])
                            ? $files['logo']['tmp_name']
                            : null;
        $site_slug     = sanitize_text_field((string) $request->get_param('site_slug'));
        $business_name = sanitize_text_field((string) $request->get_param('business_name'));

        $result = CF_Import::run($xml_content, $replace_existing, $logo_file_path, $site_slug, $business_name);
        if (is_wp_error($result)) { return $result; }
        return new WP_REST_Response($result, 200);
    }

    public static function update_plugin(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $files      = $request->get_file_params();
        $plugin_url = $request->get_param('plugin_url');
        $cleanup    = false;

        if (!empty($files['plugin_zip']['tmp_name'])) {
            $zip_path = $files['plugin_zip']['tmp_name'];
        } elseif (!empty($plugin_url)) {
            $response = wp_remote_get(esc_url_raw($plugin_url), [
                'timeout'   => 120,
                'sslverify' => true,
            ]);
            if (is_wp_error($response)) {
                return new WP_Error('cf_download_failed', 'Could not fetch plugin: ' . $response->get_error_message(), ['status' => 500]);
            }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                return new WP_Error('cf_download_failed', 'Plugin download returned HTTP ' . $code . '.', ['status' => 500]);
            }
            if (!function_exists('wp_tempnam')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $zip_path = wp_tempnam('cf-plugin-') . '.zip';
            file_put_contents($zip_path, wp_remote_retrieve_body($response));
            $cleanup = true;
        } else {
            return new WP_Error('cf_bad_request', 'plugin_zip file or plugin_url required.', ['status' => 400]);
        }

        $result = CF_Plugin::update($zip_path);
        if ($cleanup && file_exists($zip_path)) {
            @unlink($zip_path);
        }
        if (is_wp_error($result)) { return $result; }
        return new WP_REST_Response($result, 200);
    }

    // ── Arg schemas ──────────────────────────────────────────────────────────

    private static function page_args(bool $all_optional): array
    {
        $req = !$all_optional;
        return [
            'title'            => ['type' => 'string',  'required' => $req, 'sanitize_callback' => 'sanitize_text_field'],
            'slug'             => ['type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_title'],
            'status'           => ['type' => 'string',  'required' => false, 'enum' => ['publish', 'draft', 'private'], 'default' => 'publish'],
            'html'             => ['type' => 'string',  'required' => false],
            'css'              => ['type' => 'string',  'required' => false],
            'js'               => ['type' => 'string',  'required' => false],
            'meta_title'       => ['type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field'],
            'meta_description' => ['type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field'],
            'noindex'          => ['type' => 'boolean', 'required' => false, 'default' => false],
            'parent_wp_id'     => ['type' => 'integer', 'required' => false, 'default' => 0],
            'set_as_front'     => ['type' => 'boolean', 'required' => false, 'default' => false],
        ];
    }
}
