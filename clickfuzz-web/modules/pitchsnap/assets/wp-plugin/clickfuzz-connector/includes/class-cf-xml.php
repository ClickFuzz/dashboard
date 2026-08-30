<?php
defined('ABSPATH') || exit;

/**
 * ClickFuzz WXR parser — validates and parses ClickFuzz-generated WXR files.
 *
 * Rejects DOCTYPE declarations (XXE prevention) and files that do not carry
 * the ClickFuzz generator marker. Only WXR 1.2 produced by ClickFuzz Web
 * WordPress Exporter is accepted.
 */
class CF_Xml
{
    const GENERATOR_MARKER = 'ClickFuzz Web WordPress Exporter';
    const WXR_VERSION      = '1.2';
    const WP_NS            = 'http://wordpress.org/export/1.2/';

    /**
     * Validate and parse a ClickFuzz WXR string.
     *
     * @return array{menus: list<array>, pages: list<array>, menu_items: list<array>}|WP_Error
     */
    public static function parse(string $xml_content)
    {
        // XXE prevention: reject any DOCTYPE declaration
        if (stripos($xml_content, '<!DOCTYPE') !== false) {
            return new WP_Error(
                'cf_xml_doctype',
                'DOCTYPE declarations are not permitted.',
                ['status' => 422]
            );
        }

        // Identity check: must carry the ClickFuzz generator marker
        if (strpos($xml_content, self::GENERATOR_MARKER) === false) {
            return new WP_Error(
                'cf_xml_not_cf',
                'This does not appear to be a ClickFuzz WXR export.',
                ['status' => 422]
            );
        }

        libxml_use_internal_errors(true);
        $doc    = new DOMDocument();
        $loaded = $doc->loadXML($xml_content, LIBXML_NONET);
        libxml_clear_errors();

        if (!$loaded) {
            return new WP_Error(
                'cf_xml_malformed',
                'WXR file could not be parsed as XML.',
                ['status' => 422]
            );
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('wp', self::WP_NS);

        // WXR version check
        $ver_nodes = $xpath->query('/rss/channel/wp:wxr_version');
        if (!$ver_nodes || $ver_nodes->length === 0) {
            return new WP_Error('cf_xml_no_version', 'WXR version element not found.', ['status' => 422]);
        }
        $wxr_ver = trim($ver_nodes->item(0)->textContent);
        if ($wxr_ver !== self::WXR_VERSION) {
            return new WP_Error(
                'cf_xml_version',
                sprintf('Unsupported WXR version "%s". Expected "1.2".', $wxr_ver),
                ['status' => 422]
            );
        }

        return [
            'menus'      => self::parse_menus($xpath),
            'pages'      => self::parse_pages($xpath),
            'menu_items' => self::parse_menu_items($xpath),
        ];
    }

    // ── Internal parsers ──────────────────────────────────────────────────────

    /** @return list<array{source_term_id: int, slug: string, name: string}> */
    private static function parse_menus(DOMXPath $xpath): array
    {
        $menus = [];
        $nodes = $xpath->query('/rss/channel/wp:term[wp:term_taxonomy="nav_menu"]');
        if (!$nodes) return [];

        foreach ($nodes as $node) {
            $menus[] = [
                'source_term_id' => (int) self::node_text($xpath, 'wp:term_id', $node),
                'slug'           => self::node_text($xpath, 'wp:term_slug', $node),
                'name'           => self::node_text($xpath, 'wp:term_name', $node),
            ];
        }
        return $menus;
    }

    /** @return list<array{source_id: int, title: string, slug: string, status: string}> */
    private static function parse_pages(DOMXPath $xpath): array
    {
        $pages = [];
        $nodes = $xpath->query('/rss/channel/item[wp:post_type="page"]');
        if (!$nodes) return [];

        foreach ($nodes as $node) {
            $pages[] = [
                'source_id' => (int) self::node_text($xpath, 'wp:post_id', $node),
                'title'     => self::node_text($xpath, 'title', $node),
                'slug'      => self::node_text($xpath, 'wp:post_name', $node),
                'status'    => self::node_text($xpath, 'wp:status', $node),
            ];
        }
        return $pages;
    }

    /**
     * @return list<array{
     *   source_id: int, title: string, menu_order: int, menu_nicename: string,
     *   type: string, url: string, parent_source_id: int, target: string, xfn: string
     * }>
     */
    private static function parse_menu_items(DOMXPath $xpath): array
    {
        $items = [];
        $nodes = $xpath->query('/rss/channel/item[wp:post_type="nav_menu_item"]');
        if (!$nodes) return [];

        foreach ($nodes as $node) {
            $meta     = self::parse_postmeta($xpath, $node);
            $cat_els  = $xpath->query('category[@domain="nav_menu"]', $node);
            $nicename = ($cat_els && $cat_els->length > 0)
                ? $cat_els->item(0)->getAttribute('nicename')
                : '';

            $items[] = [
                'source_id'        => (int) self::node_text($xpath, 'wp:post_id', $node),
                'title'            => self::node_text($xpath, 'title', $node),
                'menu_order'       => (int) self::node_text($xpath, 'wp:menu_order', $node),
                'menu_nicename'    => $nicename,
                'type'             => $meta['_menu_item_type'] ?? 'custom',
                'url'              => $meta['_menu_item_url'] ?? '',
                'parent_source_id' => (int) ($meta['_menu_item_menu_item_parent'] ?? '0'),
                'target'           => $meta['_menu_item_target'] ?? '',
                'xfn'              => $meta['_menu_item_xfn'] ?? '',
            ];
        }
        return $items;
    }

    /** Extract all wp:postmeta key/value pairs from an item node. */
    private static function parse_postmeta(DOMXPath $xpath, DOMNode $context): array
    {
        $meta  = [];
        $nodes = $xpath->query('wp:postmeta', $context);
        if (!$nodes) return [];

        foreach ($nodes as $node) {
            $key        = self::node_text($xpath, 'wp:meta_key', $node);
            $meta[$key] = self::node_text($xpath, 'wp:meta_value', $node);
        }
        return $meta;
    }

    /** Return trimmed text content of the first node matching $query relative to $context. */
    private static function node_text(DOMXPath $xpath, string $query, DOMNode $context): string
    {
        $nodes = $xpath->query($query, $context);
        if (!$nodes || $nodes->length === 0) return '';
        return trim($nodes->item(0)->textContent);
    }
}
