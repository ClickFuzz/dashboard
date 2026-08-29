<?php
defined('ABSPATH') || exit;

/**
 * REST API registration and route callbacks.
 *
 * Namespace: clickfuzz/v1
 *
 * Auth model:
 *   - /setup  — unauthenticated, single-use. ClickFuzz sends its generated API key
 *               along with the one-time setup token shown in WP Settings → ClickFuzz.
 *               Token is deleted after use.
 *   - all other routes — X-CF-Key header validated against cf_connector_api_key option.
 */
class CF_API
{
    const NAMESPACE = 'clickfuzz/v1';

    public static function register_routes(): void
    {
        // ── Initial pairing (unauthenticated, single-use) ─────────────────────
        register_rest_route(self::NAMESPACE, '/setup', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'setup'],
            'permission_callback' => '__return_true',
            'args'                => [
                'setup_token' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'api_key'     => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

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
                'methods'             => WP_REST_Server::EDITABLE,
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
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    public static function check_key(WP_REST_Request $request): bool|WP_Error
    {
        $key    = $request->get_header('X-CF-Key');
        $stored = get_option('cf_connector_api_key', '');

        if (empty($key) || empty($stored)) {
            return new WP_Error('cf_unauthorized', 'Not paired with ClickFuzz.', ['status' => 401]);
        }
        if (!hash_equals($stored, $key)) {
            return new WP_Error('cf_unauthorized', 'Invalid API key.', ['status' => 401]);
        }
        return true;
    }

    // ── Setup (pairing) ───────────────────────────────────────────────────────

    public static function setup(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Reject if already paired — must reset first from WP Settings → ClickFuzz
        if (get_option('cf_connector_api_key')) {
            return new WP_Error('cf_already_paired', 'Already paired. Reset pairing first from WP Settings → ClickFuzz.', ['status' => 409]);
        }

        $token  = $request->get_param('setup_token');
        $stored = get_option('cf_setup_token', '');

        if (empty($stored) || !hash_equals($stored, $token)) {
            return new WP_Error('cf_invalid_token', 'Invalid or expired setup token.', ['status' => 403]);
        }

        $api_key = $request->get_param('api_key');
        if (strlen($api_key) < 16) {
            return new WP_Error('cf_bad_request', 'API key too short.', ['status' => 400]);
        }

        update_option('cf_connector_api_key', $api_key);
        delete_option('cf_setup_token');

        $theme = wp_get_theme();
        return new WP_REST_Response([
            'paired'            => true,
            'version'           => CF_CONNECTOR_VERSION,
            'wp'                => get_bloginfo('version'),
            'active_theme_slug' => get_stylesheet(),
            'active_theme_name' => $theme->get('Name'),
        ], 200);
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
        $files = $request->get_file_params();
        if (empty($files['theme_zip']['tmp_name'])) {
            return new WP_Error('cf_bad_request', 'theme_zip file required.', ['status' => 400]);
        }
        $result = CF_Theme::install($files['theme_zip']['tmp_name']);
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
        $result = CF_Import::run($request->get_file_params(), $request->get_body_params());
        if (is_wp_error($result)) { return $result; }
        return new WP_REST_Response($result, 200);
    }

    // ── Arg schemas ──────────────────────────────────────────────────────────

    private static function page_args(bool $all_optional): array
    {
        $req = !$all_optional;
        return [
            'title'        => ['type' => 'string',  'required' => $req, 'sanitize_callback' => 'sanitize_text_field'],
            'content'      => ['type' => 'string',  'required' => $req],
            'slug'         => ['type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_title'],
            'status'       => ['type' => 'string',  'required' => false, 'enum' => ['publish', 'draft', 'private'], 'default' => 'publish'],
            'set_as_front' => ['type' => 'boolean', 'required' => false, 'default' => false],
        ];
    }
}
