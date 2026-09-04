<?php
defined('BASEPATH') or exit('No direct script access allowed');

// ---------------------------------------------------------------------------
// Source website extraction + asset classification
// Provider-independent: both Anthropic and Manus receive the same normalized
// source package via clickfuzz_web_fetch_source(). Multi-page crawl collects up
// to 15 pages from the same domain before classifying and normalizing.
// ---------------------------------------------------------------------------

/**
 * Fetch a single URL; return raw HTML string or false on failure.
 */
function clickfuzz_web_fetch_page_html($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept-Language: en-US,en;q=0.9', 'Accept: text/html,application/xhtml+xml,*/*;q=0.8'],
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$html || $code < 200 || $code >= 400) return false;
    return $html;
}

/**
 * Resolve a href value to an absolute URL given a base URL.
 * Returns null if the href is not a crawlable HTTP URL.
 */
function clickfuzz_web_resolve_url($href, $base_url)
{
    $href = trim($href);
    if (!$href) return null;
    if (preg_match('/^(javascript|mailto|tel|fax|sms|data|#)/i', $href)) return null;

    if (preg_match('/^https?:\/\//i', $href)) return $href;

    $p = parse_url($base_url);
    if (!$p || empty($p['host'])) return null;
    $scheme = $p['scheme'] ?? 'https';
    $host   = $p['host'];
    $port   = !empty($p['port']) ? ':' . $p['port'] : '';
    $origin = $scheme . '://' . $host . $port;

    if (strncmp($href, '//', 2) === 0) return $scheme . ':' . $href;
    if ($href[0] === '/') return $origin . $href;

    $base_path = preg_replace('/[^\/]*$/', '', $p['path'] ?? '/');
    return $origin . $base_path . $href;
}

/**
 * Returns true if a URL should be excluded from crawling.
 * Uses path-segment matching to avoid false positives on
 * pages whose names merely contain excluded words.
 */
function clickfuzz_web_should_exclude_url($url)
{
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return true;

    $path  = strtolower($p['path'] ?? '/');
    $query = $p['query'] ?? '';
    $ext   = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    $skip_ext = ['pdf','jpg','jpeg','png','gif','webp','svg','mp4','mov','avi',
                 'zip','gz','tar','doc','docx','xls','xlsx','ppt','pptx','csv',
                 'xml','json','txt','ico','woff','woff2','ttf','eot','mp3','ogg'];
    if (in_array($ext, $skip_ext, true)) return true;

    // Exclude query strings with pagination/filter/search params
    if ($query) {
        if (preg_match('/\b(page|paged|p|s|search|q|filter|sort|order|offset|start|limit)\s*=/i', $query)) return true;
        // More than one query param = likely a filter/variant
        if (substr_count($query, '&') >= 1) return true;
    }

    $segments = array_values(array_filter(explode('/', $path), 'strlen'));

    $excluded_segs = [
        'blog','news','archive','archives',
        'author','tag','tags','category','categories',
        'feed','feeds','rss',
        'search','login','signin','signup','register','logout',
        'account','my-account','cart','checkout','order','orders',
        'privacy','privacy-policy','terms','terms-of-service','terms-conditions',
        'cookies','cookie-policy','cookie-notice','legal','disclaimer',
        'wp-admin','wp-login','wp-json','wp-cron','wp-content','wp-includes',
        'sitemap','robots.txt','cdn-cgi','api','ajax',
    ];

    foreach ($segments as $seg) {
        if (in_array($seg, $excluded_segs, true)) return true;
        // Pure numeric segment after root = pagination (/page/2/, etc.)
        if (ctype_digit($seg) && count($segments) > 1) return true;
        // page-2, page2, p2
        if (preg_match('/^p(?:age)?-?\d+$/', $seg)) return true;
    }

    return false;
}

/**
 * Extract all internal links from HTML with nav/footer context flags.
 * Returns array of ['url', 'in_nav', 'in_footer'].
 */
function clickfuzz_web_discover_links($html, $page_url, $base_host)
{
    $nav_urls    = [];
    $footer_urls = [];

    if (preg_match_all('/<(?:header|nav)\b[^>]*>(.*?)<\/(?:header|nav)>/si', $html, $nav_m)) {
        foreach ($nav_m[1] as $block) {
            preg_match_all('/href=["\']([^"\']+)["\']/', $block, $ms);
            foreach ($ms[1] as $h) {
                $abs = clickfuzz_web_resolve_url($h, $page_url);
                if ($abs) {
                    $clean = explode('#', $abs, 2)[0];
                    $nav_urls[rtrim($clean, '/')] = true;
                }
            }
        }
    }
    if (preg_match_all('/<footer\b[^>]*>(.*?)<\/footer>/si', $html, $ft_m)) {
        foreach ($ft_m[1] as $block) {
            preg_match_all('/href=["\']([^"\']+)["\']/', $block, $ms);
            foreach ($ms[1] as $h) {
                $abs = clickfuzz_web_resolve_url($h, $page_url);
                if ($abs) {
                    $clean = explode('#', $abs, 2)[0];
                    $footer_urls[rtrim($clean, '/')] = true;
                }
            }
        }
    }

    $links = [];
    $seen  = [];

    preg_match_all('/href=["\']([^"\']+)["\']/', $html, $all_m);
    foreach ($all_m[1] as $href) {
        $abs = clickfuzz_web_resolve_url($href, $page_url);
        if (!$abs) continue;

        $parsed_link = parse_url($abs);
        if (!$parsed_link || strtolower($parsed_link['host'] ?? '') !== $base_host) continue;

        $clean    = explode('#', $abs, 2)[0];
        $canon    = rtrim($clean, '/');
        // Preserve bare origin
        $bare_origin = ($parsed_link['scheme'] ?? 'https') . '://' . $base_host;
        if ($canon === '') $canon = $bare_origin;

        if (isset($seen[$canon])) continue;
        $seen[$canon] = true;

        $links[] = [
            'url'       => $canon,
            'in_nav'    => isset($nav_urls[$canon]),
            'in_footer' => isset($footer_urls[$canon]),
        ];
    }

    return $links;
}

/**
 * Score and filter link candidates for crawling priority.
 * Returns filtered array sorted highest-priority first.
 */
function clickfuzz_web_score_filter_links(array $links, $homepage_url)
{
    $hp_norm = rtrim($homepage_url, '/');
    $scored  = [];

    foreach ($links as $link) {
        $url = $link['url'];
        if (clickfuzz_web_should_exclude_url($url)) continue;
        if (rtrim($url, '/') === $hp_norm) continue;

        $score = 50;
        if ($link['in_nav'])                        $score += 40;
        if ($link['in_footer'] && !$link['in_nav']) $score -= 15;

        $path  = parse_url($url, PHP_URL_PATH) ?: '/';
        $depth = count(array_filter(explode('/', $path), 'strlen'));
        $score -= $depth * 8;

        $link['score'] = $score;
        $scored[] = $link;
    }

    usort($scored, function($a, $b) { return $b['score'] - $a['score']; });
    return $scored;
}

/**
 * Fetch homepage plus up to $max_pages - 1 internal pages from the same domain.
 * Returns array of ['url', 'html'], homepage first.
 */
function clickfuzz_web_crawl_pages($start_url, $max_pages = 15)
{
    $parsed = parse_url($start_url);
    if (!$parsed || empty($parsed['host'])) return [];
    $base_host = strtolower($parsed['host']);

    $homepage_html = clickfuzz_web_fetch_page_html($start_url);
    if (!$homepage_html) return [];

    $pages   = [['url' => $start_url, 'html' => $homepage_html]];
    $fetched = [rtrim($start_url, '/') => true];

    $candidates = clickfuzz_web_discover_links($homepage_html, $start_url, $base_host);
    $candidates = clickfuzz_web_score_filter_links($candidates, $start_url);

    $remaining = $max_pages - 1;
    foreach ($candidates as $link) {
        if ($remaining <= 0) break;
        $canon = rtrim($link['url'], '/');
        if (isset($fetched[$canon])) continue;
        $fetched[$canon] = true;

        $html = clickfuzz_web_fetch_page_html($link['url']);
        if ($html) {
            $pages[] = ['url' => $link['url'], 'html' => $html];
        }
        $remaining--;
    }

    return $pages;
}

/**
 * Extract body text from a single page HTML.
 * Strips scripts, styles, and navigation/header/footer boilerplate.
 */
function clickfuzz_web_extract_page_text($html)
{
    $t = preg_replace('/<(script|style|noscript|iframe|svg|canvas)[^>]*>.*?<\/\1>/si', ' ', $html);
    $t = preg_replace('/<(header|nav|footer)[^>]*>.*?<\/\1>/si', ' ', $t);
    $t = strip_tags($t);
    return trim(preg_replace('/\s+/', ' ', $t));
}

/**
 * Merge text from multiple pages into a single deduplicated block.
 * Deduplicates repeated sentences/chunks (nav boilerplate, repeated CTAs, etc.).
 * Stays within a conservative character budget.
 */
function clickfuzz_web_merge_page_texts(array $page_texts)
{
    $seen_hashes = [];
    $output      = '';
    $remaining   = 6500;

    foreach ($page_texts as $i => $text) {
        if (!$text || $remaining <= 0) continue;

        $page_budget = ($i === 0) ? 3500 : 1200;
        $page_budget = min($page_budget, $remaining);

        // Split on sentence-ending punctuation followed by whitespace + uppercase
        $chunks = preg_split('/(?<=[.!?:])\s+(?=[A-Z"\'])/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $contribution = '';
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if (strlen($chunk) < 15) continue;
            $hash = md5(preg_replace('/\s+/', ' ', strtolower($chunk)));
            if (isset($seen_hashes[$hash])) continue;
            $seen_hashes[$hash] = true;
            $contribution .= $chunk . ' ';
            if (strlen($contribution) >= $page_budget) break;
        }

        $contribution = trim($contribution);
        if ($contribution) {
            $output    .= ($output ? "\n\n" : '') . $contribution;
            $remaining -= strlen($contribution);
        }
    }

    return trim($output);
}

/**
 * Build the provider-independent normalized source package string
 * from classified images and merged page text.
 */
/**
 * Derive a short human-readable page label from image metadata.
 * Used in the normalized source package output.
 */
function clickfuzz_web_page_label($img)
{
    // Prefer page_title (already stripped of "| Business" suffix)
    $title = trim($img['page_title'] ?? '');
    if ($title && strlen($title) < 60) return $title;
    // Fall back to page_h1
    $h1 = trim($img['page_h1'] ?? '');
    if ($h1 && strlen($h1) < 60) return $h1;
    // Derive from URL path
    $url = $img['source_page'] ?? '';
    if ($url) {
        $path = trim(parse_url($url, PHP_URL_PATH) ?: '', '/');
        if (!$path) return 'Home';
        $segs = explode('/', $path);
        $last = end($segs);
        if ($last) return ucwords(str_replace(['-', '_'], ' ', rawurldecode($last)));
    }
    return '';
}

/**
 * Extract ordered person records (name + role) from a team/people page HTML.
 * Returns array of ['name' => string, 'role' => string] in source order.
 * Only includes entries where the heading passes clickfuzz_web_looks_like_person_name().
 */
function clickfuzz_web_extract_team_person_records($html)
{
    $records = [];
    if (!preg_match_all('/<(h3|h4)[^>]*>(.*?)<\/\1>/si', $html, $hm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return [];
    }
    foreach ($hm as $match) {
        $name = html_entity_decode(trim(strip_tags($match[2][0])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!clickfuzz_web_looks_like_person_name($name)) continue;

        // Look for a <p> role within 600 chars after this heading, with no intervening h3/h4
        $after_offset = $match[0][1] + strlen($match[0][0]);
        $after        = substr($html, $after_offset, 600);
        $role         = '';
        if (preg_match('/<p[^>]*>(.*?)<\/p>/si', $after, $pm, PREG_OFFSET_CAPTURE)) {
            $p_pos   = $pm[0][1];
            $between = substr($after, 0, $p_pos);
            if (!preg_match('/<(h3|h4)[^>]*>/si', $between)) {
                $r = html_entity_decode(trim(strip_tags($pm[1][0])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (strlen($r) >= 2 && strlen($r) <= 120) $role = $r;
            }
        }
        $records[] = ['name' => $name, 'role' => $role];
    }
    return $records;
}

/**
 * Apply medium-confidence ordered association between team_context images and
 * person records from the same page HTML.
 *
 * Conditions (all must hold for a given page's image set):
 *   - image count from that page >= 2
 *   - clickfuzz_web_extract_team_person_records() count == image count (exact match)
 *   - no image in the set has a filename-extension alt (decorative placeholder signal)
 *
 * When conditions are met each image gets:
 *   'team_assoc_confidence' => 'medium'
 *   'person_name'           => string
 *   'person_role'           => string
 *
 * Falls back silently — unmatched pages remain as anonymous team_context.
 */
function clickfuzz_web_apply_team_ordered_association(array $images, array $pages)
{
    $html_by_url = [];
    foreach ($pages as $page) {
        $html_by_url[$page['url']] = $page['html'];
    }

    $by_page = [];
    foreach ($images as $idx => $img) {
        if (($img['category'] ?? '') !== 'team_context') continue;
        $sp = $img['source_page'] ?? '';
        if (!$sp || !isset($html_by_url[$sp])) continue;
        $by_page[$sp][] = $idx;
    }

    foreach ($by_page as $page_url => $indices) {
        $n = count($indices);
        if ($n < 2) continue;

        // Decorative-image guard
        foreach ($indices as $idx) {
            if (preg_match('/\.(jpe?g|png|webp|gif|svg)$/i', $images[$idx]['alt'] ?? '')) {
                continue 2;
            }
        }

        $persons = clickfuzz_web_extract_team_person_records($html_by_url[$page_url]);
        if (count($persons) !== $n) continue;

        foreach ($indices as $pos => $idx) {
            $images[$idx]['team_assoc_confidence'] = 'medium';
            $images[$idx]['person_name']           = $persons[$pos]['name'];
            $images[$idx]['person_role']           = $persons[$pos]['role'];
        }
    }

    return $images;
}

/**
 * Count distinct person-name headings (h2–h4) in a raw HTML fragment.
 * Used to disambiguate whether a card-size window contains exactly one person.
 */
function clickfuzz_web_count_person_names_in_window($html_fragment)
{
    if (!preg_match_all('/<(h[2-4])[^>]*>(.*?)<\/\1>/si', $html_fragment, $m)) return 0;
    $names = [];
    foreach ($m[2] as $raw) {
        $text = html_entity_decode(trim(strip_tags($raw)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (clickfuzz_web_looks_like_person_name($text)) $names[$text] = true;
    }
    return count($names);
}

/**
 * HIGH-confidence direct team-member association.
 *
 * Runs BEFORE the medium-confidence ordered fallback.  Two evidence tiers:
 *
 *  Type 1 — intrinsic image evidence (no page-HTML scan needed):
 *    a. alt-text "Name — Role" pattern where name passes looks_like_person_name()
 *    b. figcaption text that passes looks_like_person_name()
 *
 *  Type 2 — direct container (card_heading + disambiguation):
 *    card_heading passes looks_like_person_name(), AND
 *    exactly one person-name heading exists within ±800 chars of the image in the page HTML.
 *    If >1 person found in that window: marks team_assoc_confidence = 'unknown'
 *    (suppresses person assignment; image stays in owner_or_team but unnamed).
 *
 * team_context images with figcaption person evidence are upgraded to owner_or_team.
 * All other team_context images are left untouched for the ordered fallback.
 * Images already carrying team_assoc_confidence are skipped.
 */
function clickfuzz_web_apply_team_direct_association(array $images, array $pages)
{
    $html_by_url = [];
    foreach ($pages as $page) {
        $html_by_url[$page['url']] = $page['html'];
    }

    foreach ($images as &$img) {
        $cat = $img['category'] ?? 'other';
        if ($cat !== 'owner_or_team' && $cat !== 'team_context') continue;
        if (!empty($img['team_assoc_confidence'])) continue; // already resolved

        $alt         = trim($img['alt']          ?? '');
        $figcaption  = trim($img['figcaption']   ?? '');
        $card_hdg    = trim($img['card_heading'] ?? '');
        $card_role   = trim($img['card_role']    ?? '');
        $img_url     = $img['url']               ?? '';
        $source_page = $img['source_page']       ?? '';

        // ── Type 1a: alt-text naming pattern ─────────────────────────────────
        if ($alt && preg_match('/^(.+?)\s*[-—,|]\s*(.+)$/', $alt, $nm)) {
            $pname = trim($nm[1]);
            if (clickfuzz_web_looks_like_person_name($pname)) {
                $img['team_assoc_confidence'] = 'high';
                $img['assoc_evidence']        = 'alt-text';
                $img['person_name']           = $pname;
                $img['person_role']           = trim($nm[2]);
                if ($cat === 'team_context') $img['category'] = 'owner_or_team';
                continue;
            }
        }

        // ── Type 1b: figcaption names a person ───────────────────────────────
        if ($figcaption && clickfuzz_web_looks_like_person_name($figcaption)) {
            $img['team_assoc_confidence'] = 'high';
            $img['assoc_evidence']        = 'figcaption';
            $img['person_name']           = $figcaption;
            $img['person_role']           = $card_role;
            if ($cat === 'team_context') $img['category'] = 'owner_or_team';
            continue;
        }

        // ── Type 2: card_heading + disambiguation (owner_or_team only) ────────
        // team_context images without intrinsic evidence stay anonymous for the ordered fallback
        if ($cat !== 'owner_or_team') continue;
        if (!$card_hdg || !clickfuzz_web_looks_like_person_name($card_hdg)) continue;

        $html    = $html_by_url[$source_page] ?? '';
        $img_pos = false;
        if ($html && $img_url) {
            $url_path = parse_url($img_url, PHP_URL_PATH) ?: '';
            if ($url_path) {
                $img_pos = strpos($html, $url_path);
                if ($img_pos === false) {
                    $basename = basename($url_path);
                    if ($basename) $img_pos = strpos($html, $basename);
                }
            }
        }
        if ($img_pos === false) continue; // can't locate image in HTML — leave unresolved

        $win_start = max(0, $img_pos - 800);
        $window    = substr($html, $win_start, 1600);
        $n_persons = clickfuzz_web_count_person_names_in_window($window);

        if ($n_persons === 1) {
            $img['team_assoc_confidence'] = 'high';
            $img['assoc_evidence']        = 'direct-container';
            $img['person_name']           = $card_hdg;
            $img['person_role']           = $card_role;
        } elseif ($n_persons > 1) {
            $img['team_assoc_confidence'] = 'unknown'; // ambiguous — suppress person assignment
        }
        // n_persons == 0: leave unresolved; existing rendering uses card_heading as-is
    }
    unset($img);

    return $images;
}

function clickfuzz_web_guardrail_profile($provider)
{
    $guardrails = ['logo_usage','image_selection','team_placement','team_association','anonymous_team','gallery_usage','credential_usage','owner_story','visual_readability','brand_color_preservation'];
    $base       = array_fill_keys($guardrails, false);

    // raw is always all-OFF and is never stored or exposed in settings
    if ($provider === 'raw') {
        return $base;
    }

    // Hardcoded defaults: anthropic all ON, manus all OFF except brand_color_preservation
    // Brand Color Preservation is a business requirement (not Anthropic-specific), so both providers default ON.
    $hardcoded = [
        'anthropic' => array_fill_keys($guardrails, true),
        'manus'     => array_merge($base, ['brand_color_preservation' => true]),
    ];
    $default = $hardcoded[$provider] ?? $base;

    // Saved settings override hardcoded defaults; fall back to default when unsaved
    $gr = [];
    foreach ($guardrails as $name) {
        $saved = get_option('pitchsnap_guardrail_' . $provider . '_' . $name);
        $gr[$name] = ($saved === false || $saved === '') ? $default[$name] : (bool)(int)$saved;
    }
    return $gr;
}

function clickfuzz_web_guardrail(array $gr, $name)
{
    return !empty($gr[$name]);
}

function clickfuzz_web_build_source_package($logo_discovery, array $images, $text, array $gr = [], $brand_colors = null)
{
    $buckets = [
        'company_logo'            => [],
        'brand_graphic'           => [],
        'owner_or_team'           => [],
        'company_vehicle'         => [],
        'project_or_real_work'    => [],
        'team_context'            => [],
        'gallery_context'         => [],
        'authentic_business_image'=> [],
        'credential_or_badge'     => [],
        'other'                   => [],
    ];
    $caps = [
        'company_logo'            => 3,
        'brand_graphic'           => 4,
        'owner_or_team'           => 6,
        'company_vehicle'         => 5,
        'project_or_real_work'    => 5,
        'team_context'            => 5,
        'gallery_context'         => 5,
        'authentic_business_image'=> 3,
        'credential_or_badge'     => 6,
        'other'                   => 0,
    ];
    // Extract medium-confidence ordered team sets before cap loop (no cap applied to these)
    $medium_team_sets = [];
    $medium_team_urls = [];
    foreach ($images as $img) {
        if (($img['category'] ?? '') === 'team_context' && !empty($img['team_assoc_confidence'])) {
            $sp = $img['source_page'] ?? 'team';
            $medium_team_sets[$sp][] = $img;
            $medium_team_urls[$img['url']] = true;
        }
    }

    foreach ($images as $img) {
        $cat = $img['category'] ?? 'other';
        // Skip medium-confidence ordered team images — rendered separately with no cap
        if ($cat === 'team_context' && isset($medium_team_urls[$img['url']])) continue;
        if (isset($buckets[$cat]) && count($buckets[$cat]) < $caps[$cat]) {
            $buckets[$cat][] = $img;
        }
    }
    // Smart fill for authentic_business_image: if cap not reached, pull from
    // remaining 'other' images using alt-text dedup across already-added ones
    if (count($buckets['authentic_business_image']) < $caps['authentic_business_image']) {
        $abi_alts = [];
        foreach ($buckets['authentic_business_image'] as $im) {
            $a = strtolower(trim($im['alt'] ?? ''));
            if ($a) $abi_alts[$a] = true;
        }
        foreach ($images as $img) {
            if (count($buckets['authentic_business_image']) >= $caps['authentic_business_image']) break;
            if (($img['category'] ?? '') !== 'other') continue;
            $a = strtolower(trim($img['alt'] ?? ''));
            if ($a && isset($abi_alts[$a])) continue;
            $buckets['authentic_business_image'][] = $img;
            if ($a) $abi_alts[$a] = true;
        }
    }

    $output = '';

    if (clickfuzz_web_guardrail($gr, 'visual_readability')) {
        $output .= "DESIGN REQUIREMENTS — VISUAL READABILITY:\n"
                 . "All meaningful text (headings, body copy, service descriptions, contact information, labels, CTAs) must be clearly readable in the initial rendered page state.\n"
                 . "- Strong text/background contrast is required. Do not place light or white text on light backgrounds without a deliberate contrast treatment (overlay, gradient, solid backing, or text shadow).\n"
                 . "- Do not initialize any meaningful text at opacity: 0 or near-zero as a load or reveal effect.\n"
                 . "- Do not make text legibility dependent on JavaScript, scroll-reveal animations, hover states, blend modes, or background images loading.\n"
                 . "- When text overlays an image, ensure sufficient contrast through the composition: a darkened overlay, gradient, solid backing panel, or text shadow as appropriate.\n"
                 . "- Decorative typography may be expressive. Factual, service, and contact content must be immediately legible regardless of styling choices.\n\n";
    }

    // Brand color preservation guardrail — three levels by confidence and visual evidence
    if (clickfuzz_web_guardrail($gr, 'brand_color_preservation') && !empty($brand_colors)) {
        $bc_conf = $brand_colors['confidence']     ?? 'low';
        $bc_char = $brand_colors['visual_character'] ?? null;

        if (in_array($bc_conf, ['high', 'medium'])) {
            // ── Level A: Exact established palette ──────────────────────────────────
            $output .= "DESIGN REQUIREMENT — BRAND COLOR PRESERVATION:\n"
                     . "This company has an established color identity. Keep it recognizable in the redesign.\n\n"
                     . "Established palette:\n";
            foreach (($brand_colors['primary'] ?? []) as $hex) {
                $output .= "  Primary: " . strtoupper($hex) . "\n";
            }
            foreach (($brand_colors['accent'] ?? []) as $hex) {
                $output .= "  Accent:  " . strtoupper($hex) . "\n";
            }
            $output .= "\nYOU MAY:\n"
                     . "- Add complementary supporting colors and neutrals\n"
                     . "- Use lighter, darker, or muted shades of established colors\n"
                     . "- Modernize how colors are distributed across sections\n"
                     . "- Improve contrast ratios\n"
                     . "- Completely change typography, layout, composition, spacing, and visual style\n\n"
                     . "YOU MUST NOT:\n"
                     . "- Replace this palette with unrelated colors\n"
                     . "- Choose a completely different color scheme simply because another palette looks fashionable\n\n"
                     . "Goal: same recognizable company, dramatically improved website.\n"
                     . "This applies to COLORS ONLY — full creative freedom over typography, layout, and design style.\n\n";

        } elseif ($bc_conf === 'low' && !empty($bc_char)) {
            // ── Level B: Visual character preservation ──────────────────────────────
            // Exact hex values are unreliable but the site's broad visual character was detected.
            $char_parts = [];
            if (!empty($bc_char['tone']))        $char_parts[] = ucfirst($bc_char['tone']);
            if (!empty($bc_char['temperature'])) $char_parts[] = ucfirst($bc_char['temperature']);
            if (!empty($bc_char['saturation']))  $char_parts[] = ucfirst($bc_char['saturation']);

            $output .= "DESIGN REQUIREMENT — VISUAL IDENTITY PRESERVATION:\n"
                     . "Exact brand hex values could not be reliably determined, but the source site's\n"
                     . "existing visual character has been detected from its CSS and design elements.\n\n"
                     . "Detected character: " . implode(', ', $char_parts) . "\n\n"
                     . "The existing visual character forms the dominant color foundation of this brand.\n"
                     . "Preserve it as the basis of the redesign. Do not replace it.\n\n"
                     . "Dominance matters:\n"
                     . "— Foundation/dominant colors (headers, large backgrounds, primary sections) must\n"
                     . "  remain consistent with the detected visual character.\n"
                     . "— Supporting/accent colors (CTAs, highlights, icons, borders) may be introduced\n"
                     . "  more freely, but must not overpower the established source character.\n\n"
                     . "YOU MAY:\n"
                     . "— Introduce complementary supporting colors compatible with the detected character\n"
                     . "— Introduce a restrained accent color for hierarchy or CTA visibility\n"
                     . "— Modernize the palette while maintaining the detected character\n"
                     . "— Completely change typography, layout, composition, spacing, and visual style\n\n"
                     . "YOU MUST NOT:\n"
                     . "— Allow newly invented colors to become more visually dominant than the established\n"
                     . "  source color character\n"
                     . "— Substantially invert the detected visual character without source evidence\n";

            // Add dimension-specific constraints based on what was actually detected
            if (!empty($bc_char['tone'])) {
                if ($bc_char['tone'] === 'light') {
                    $output .= "— Transform a predominantly light identity into a predominantly dark identity\n";
                } elseif ($bc_char['tone'] === 'dark') {
                    $output .= "— Transform a predominantly dark identity into a predominantly light identity\n";
                }
            }
            if (!empty($bc_char['temperature'])) {
                if ($bc_char['temperature'] === 'warm') {
                    $output .= "— Transform a predominantly warm identity into a predominantly cool or blue-dominant identity\n";
                } elseif ($bc_char['temperature'] === 'cool') {
                    $output .= "— Transform a predominantly cool identity into a predominantly warm or orange-dominant identity\n";
                }
            }
            if (!empty($bc_char['saturation'])) {
                if ($bc_char['saturation'] === 'muted') {
                    $output .= "— Transform a predominantly muted/neutral identity into a predominantly vivid or\n"
                             . "  high-chroma identity\n";
                } elseif ($bc_char['saturation'] === 'vivid') {
                    $output .= "— Substantially desaturate a predominantly vivid identity without source evidence\n";
                }
            }

            $output .= "\n";
        }
        // Level C: truly unknown — no guardrail block; designer chooses freely
    }

    if (!empty($images) || $logo_discovery) {
        $output .= "IMAGES FROM SOURCE SITE:\n";
        if (clickfuzz_web_guardrail($gr, 'image_selection')) {
            $output .= "NOTE: This is a curated selection of available assets discovered across the source website. "
                     . "The designer should select only the images that best serve the design concept. Not every supplied image needs to appear on the page. "
                     . "More photography means more choice — not a mandate to build a larger gallery.\n\n";
        }

        // Resolve logo URL/source for BRAND ASSETS block
        $logo_url      = null;
        $logo_source   = null;
        $logo_conflicts = [];
        if ($logo_discovery) {
            $logo_url      = $logo_discovery['url'];
            $logo_source   = $logo_discovery['source'];
            $logo_conflicts = $logo_discovery['conflicts'] ?? [];
        } elseif (!empty($buckets['company_logo'])) {
            $lg          = $buckets['company_logo'][0];
            $logo_url    = $lg['url'];
            $logo_source = $lg['alt'] ?: 'discovered';
        }

        $output .= "\nBRAND ASSETS:\n\n";
        $output .= "  COMPANY LOGO\n";
        $output .= "  Purpose: site identity and branding only\n";
        $output .= "  Permitted: header branding, navigation logo, footer branding\n";
        if ($logo_url) {
            $output .= "  URL: $logo_url  [source: $logo_source]\n";
            foreach ($logo_conflicts as $c) {
                $cf = basename(parse_url($c['url'], PHP_URL_PATH) ?: $c['url']);
                $output .= "  # QA conflict: kept $logo_source, discarded {$c['source']} ($cf)\n";
            }
        } else {
            $output .= "  URL: unavailable — do not invent one\n";
        }
        if (clickfuzz_web_guardrail($gr, 'logo_usage')) {
            $output .= "  CONSTRAINT: This asset is NOT photography or content imagery."
                     . " Do NOT use as: hero imagery, section imagery, background imagery, gallery/work imagery,"
                     . " split-layout photography, decorative imagery, oversized or full-width element."
                     . " Render only at normal logo scale as part of site identity."
                     . " Keep visually separate from all photography sections.\n";
        }

        if (!empty($buckets['brand_graphic'])) {
            $output .= "\n  SECONDARY BRAND GRAPHICS — visually identified as brand or logo-like graphics:\n";
            $output .= "  These are NOT confirmed as the canonical site logo and are NOT photography.\n";
            $output .= "  They may be alternate logo lockups, brand illustrations, or visual brand extensions.\n";
            $output .= "  Do NOT use as hero imagery, section photography, gallery content, or general imagery.\n";
            foreach ($buckets['brand_graphic'] as $img) {
                $line = "  URL: " . $img['url'];
                if (!empty($img['vision_description'])) $line .= "  [visual: " . $img['vision_description'] . "]";
                if (!empty($img['vision_resembles_brand'])) $line .= "  [resembles primary branding]";
                $output .= $line . "\n";
            }
        }

        // ── Owner / team: rich person + source metadata ───────────────────────
        if (!empty($buckets['owner_or_team'])) {
            $output .= "\nOWNER / TEAM PHOTOS — confirmed person associations (explicit source evidence):\n";
            if (clickfuzz_web_guardrail($gr, 'team_placement')) {
                $output .= "These are PEOPLE photographs with direct evidence (alt-text, card markup, or structured data). Do NOT place in work/project galleries or general photography sections.\n";
            }
            foreach ($buckets['owner_or_team'] as $img) {
                $alt          = trim($img['alt'] ?? '');
                $url          = $img['url'];
                $card_heading = trim($img['card_heading'] ?? '');
                $card_role    = trim($img['card_role']    ?? '');
                $figcaption   = trim($img['figcaption']   ?? '');
                $page_lbl     = clickfuzz_web_page_label($img);
                $source_url   = $img['source_page'] ?? '';

                // Resolve person name and confidence level
                $confidence  = $img['team_assoc_confidence'] ?? '';
                $person_name = '';
                $person_role = '';

                if ($confidence === 'high' && !empty($img['person_name'])) {
                    // Pre-computed by clickfuzz_web_apply_team_direct_association()
                    $person_name = $img['person_name'];
                    $person_role = $img['person_role'] ?? '';
                } elseif ($confidence !== 'unknown') {
                    // Existing derivation (backward compat / no-confidence images)
                    if ($alt && preg_match('/^(.+?)\s*[-—,|]\s*(.+)$/', $alt, $nm)) {
                        $person_name = trim($nm[1]);
                        $person_role = trim($nm[2]);
                    } elseif ($card_heading && clickfuzz_web_looks_like_person_name($card_heading)) {
                        $person_name = $card_heading;
                        $person_role = $card_role ?: ($figcaption ?: '');
                    } elseif ($card_role) {
                        $person_role = $card_role;
                    }
                    if (!$person_name && $alt && !preg_match('/\.(jpe?g|png|webp|gif)$/i', $alt)) {
                        $person_name = $alt;
                    }
                }
                // $confidence === 'unknown': person_name/role stay empty — person suppressed

                $is_principal = preg_match(
                    '/\b(owner|founder|operator|principal|partner|president|ceo|director)\b/i',
                    $person_name . ' ' . $person_role
                );
                $ev_labels = [
                    'alt-text'         => 'ALT-TEXT',
                    'figcaption'       => 'FIGCAPTION',
                    'direct-container' => 'DIRECT-CONTAINER',
                ];
                $ev = $ev_labels[$img['assoc_evidence'] ?? ''] ?? '';
                if ($confidence === 'high') {
                    $role_tag = ($is_principal ? '[OWNER/PRINCIPAL' : '[TEAM MEMBER')
                              . ' — HIGH CONFIDENCE' . ($ev ? ': ' . $ev : '') . ']';
                } elseif ($confidence === 'unknown') {
                    $role_tag = '[TEAM MEMBER — UNRESOLVED]';
                } else {
                    $role_tag = $is_principal ? '[OWNER/PRINCIPAL]' : '[team member]';
                }

                $output .= "\n  $role_tag\n";
                if ($person_name && $person_role) {
                    $output .= "  Person: $person_name — $person_role\n";
                } elseif ($person_name) {
                    $output .= "  Person: $person_name\n";
                } elseif ($person_role) {
                    $output .= "  Role: $person_role\n";
                }
                if ($page_lbl || $source_url) {
                    $src_line = '  Source: ';
                    if ($page_lbl) $src_line .= $page_lbl;
                    if ($source_url) $src_line .= ($page_lbl ? ' (' . $source_url . ')' : $source_url);
                    $output .= $src_line . "\n";
                }
                $output .= "  Image: $url\n";
            }
        }

        // ── Owner story creative suggestion (guardrail-gated) ─────────────────
        // Emitted only when a HIGH-confidence owner/founder/principal image exists.
        // The factual data (person, role, image URL) is already CORE output above.
        if (clickfuzz_web_guardrail($gr, 'owner_story') && !empty($buckets['owner_or_team'])) {
            foreach ($buckets['owner_or_team'] as $img) {
                if (($img['team_assoc_confidence'] ?? '') === 'high') {
                    $pn = $img['person_name'] ?? '';
                    $pr = $img['person_role'] ?? '';
                    if (preg_match('/\b(owner|founder|operator|principal|partner|president|ceo|director)\b/i', $pn . ' ' . $pr)) {
                        $output .= "\nCREATIVE OPPORTUNITY — OWNER STORY:\n"
                                 . "A confirmed owner/founder/principal photograph is available."
                                 . " When it strengthens the design, consider featuring this person prominently"
                                 . " in an About, Our Story, Meet the Owner, Family-Owned, Why Choose Us,"
                                 . " What Makes Us Different, or similar story-driven section."
                                 . " This is optional — choose the concept and composition that best fits the business and overall design."
                                 . " Do not force an owner section if another approach is stronger."
                                 . " Do not invent family ownership, company history, biography, personal claims,"
                                 . " or other facts not supported by the source content.\n";
                        break;
                    }
                }
            }
        }

        // ── Medium-confidence ordered team association ─────────────────────────
        if (!empty($medium_team_sets)) {
            foreach ($medium_team_sets as $set_imgs) {
                $n = count($set_imgs);
                $output .= "\nTEAM / PEOPLE CONTEXT — ordered association from team page:\n";
                $output .= "Association evidence: parallel ordered records ($n images, $n person records, counts match).\n";
                $output .= "Identity confidence: MEDIUM — do not treat these as confirmed beyond what the source states.\n";
                if (clickfuzz_web_guardrail($gr, 'team_association')) {
                    $output .= "Do NOT shift or reorder these associations. These portraits are independent of any separately identified owner photos above — do not substitute or merge the two sets.\n";
                }
                foreach ($set_imgs as $img) {
                    $person_name = $img['person_name'] ?? '';
                    $person_role = $img['person_role'] ?? '';
                    $url         = $img['url'];
                    $page_lbl    = clickfuzz_web_page_label($img);
                    $source_url  = $img['source_page'] ?? '';
                    $is_principal = $person_name && preg_match(
                        '/\b(owner|founder|operator|principal|partner|president|ceo|director)\b/i',
                        $person_name . ' ' . $person_role
                    );
                    $role_tag = $is_principal ? '[TEAM MEMBER — PRINCIPAL, MEDIUM CONFIDENCE]' : '[TEAM MEMBER — MEDIUM CONFIDENCE]';
                    $output .= "\n  $role_tag\n";
                    if ($person_name && $person_role) {
                        $output .= "  Person: $person_name — $person_role\n";
                    } elseif ($person_name) {
                        $output .= "  Person: $person_name\n";
                    }
                    if ($page_lbl || $source_url) {
                        $src_line = '  Source: ';
                        if ($page_lbl) $src_line .= $page_lbl;
                        if ($source_url) $src_line .= ($page_lbl ? ' (' . $source_url . ')' : $source_url);
                        $output .= $src_line . "\n";
                    }
                    $output .= "  Image: $url\n";
                }
            }
        }

        // ── Vehicle and project/work photos — with section context ────────────
        $work_cats = ['company_vehicle' => 'company vehicle', 'project_or_real_work' => 'real work/project'];
        $has_work  = !empty($buckets['company_vehicle']) || !empty($buckets['project_or_real_work']);
        if ($has_work) {
            $output .= "\nREAL BUSINESS PHOTOGRAPHY — authentic photos of actual work, completed projects, and equipment:\n";
            foreach ($work_cats as $cat => $label) {
                foreach ($buckets[$cat] as $img) {
                    $page_lbl   = clickfuzz_web_page_label($img);
                    $source_url = $img['source_page'] ?? '';
                    $context    = trim($img['section_heading'] ?? '') ?: trim($img['card_heading'] ?? '');
                    $figcap     = trim($img['figcaption'] ?? '');
                    $output .= "\n  [$label]\n";
                    if ($context) $output .= "  Context: $context\n";
                    if ($figcap && $figcap !== $context) $output .= "  Caption: $figcap\n";
                    if ($page_lbl || $source_url) {
                        $src_line = '  Source: ';
                        if ($page_lbl) $src_line .= $page_lbl;
                        if ($source_url) $src_line .= ($page_lbl ? ' (' . $source_url . ')' : $source_url);
                        $output .= $src_line . "\n";
                    }
                    $output .= "  Image: " . $img['url'] . "\n";
                }
            }
        }

        // ── Team / People context ─────────────────────────────────────────
        if (!empty($buckets['team_context'])) {
            $output .= "\nTEAM / PEOPLE CONTEXT IMAGES — images from the source site's team/people section:\n";
            if (clickfuzz_web_guardrail($gr, 'anonymous_team')) {
                $output .= "These images were published in the source site's team/people context, but ClickFuzz Web could not reliably associate them with a specific person. Use only for people/team/company-story contexts. Do not assign names or roles to these images and do not use them as work/project/gallery imagery.\n";
            }
            foreach ($buckets['team_context'] as $img) {
                $page_lbl   = clickfuzz_web_page_label($img);
                $source_url = $img['source_page'] ?? '';
                $output .= "\n  [team context]\n";
                if ($page_lbl || $source_url) {
                    $src_line = '  Source: ';
                    if ($page_lbl) $src_line .= $page_lbl;
                    if ($source_url) $src_line .= ($page_lbl ? ' (' . $source_url . ')' : $source_url);
                    $output .= $src_line . "\n";
                }
                $output .= "  Image: " . $img['url'] . "\n";
            }
        }

        // ── Gallery / Portfolio context ────────────────────────────────────
        if (!empty($buckets['gallery_context'])) {
            $output .= "\nGALLERY / PORTFOLIO CONTEXT IMAGES — images from the source site's gallery or portfolio section:\n";
            if (clickfuzz_web_guardrail($gr, 'gallery_usage')) {
                $output .= "These images were published by the business in a gallery/portfolio/work-style context. They are available as visual showcase assets, but their exact subject is not known unless separately supported by source content. Use selectively. Do not invent descriptions, project details, locations, services, or claims based on the images. You are not expected to use every supplied image or build a large gallery.\n";
            }
            foreach ($buckets['gallery_context'] as $img) {
                $page_lbl   = clickfuzz_web_page_label($img);
                $source_url = $img['source_page'] ?? '';
                $context    = trim($img['section_heading'] ?? '') ?: trim($img['card_heading'] ?? '');
                $figcap     = trim($img['figcaption'] ?? '');
                $output .= "\n  [gallery/portfolio]\n";
                if ($context) $output .= "  Context: $context\n";
                if ($figcap && $figcap !== $context) $output .= "  Caption: $figcap\n";
                if ($page_lbl || $source_url) {
                    $src_line = '  Source: ';
                    if ($page_lbl) $src_line .= $page_lbl;
                    if ($source_url) $src_line .= ($page_lbl ? ' (' . $source_url . ')' : $source_url);
                    $output .= $src_line . "\n";
                }
                $output .= "  Image: " . $img['url'] . "\n";
            }
        }

        if (!empty($buckets['authentic_business_image'])) {
            $output .= "\nAUTHENTIC BUSINESS PHOTOGRAPHY — genuine imagery with no stronger source context. Use selectively where the design calls for imagery:\n";
            foreach ($buckets['authentic_business_image'] as $img) {
                $page_lbl   = clickfuzz_web_page_label($img);
                $source_url = $img['source_page'] ?? '';
                $context    = trim($img['section_heading'] ?? '') ?: trim($img['card_heading'] ?? '');
                $figcap     = trim($img['figcaption'] ?? '');
                $output .= "\n  [photo]\n";
                if ($context) $output .= "  Context: $context\n";
                if ($figcap && $figcap !== $context) $output .= "  Caption: $figcap\n";
                if ($page_lbl || $source_url) {
                    $src_line = '  Source: ';
                    if ($page_lbl) $src_line .= $page_lbl;
                    if ($source_url) $src_line .= ($page_lbl ? ' (' . $source_url . ')' : $source_url);
                    $output .= $src_line . "\n";
                }
                $output .= "  Image: " . $img['url'] . "\n";
            }
        }

        if (!empty($buckets['credential_or_badge'])) {
            $cred_suffix = clickfuzz_web_guardrail($gr, 'credential_usage')
                ? ' — use only as small supporting trust elements, never as primary imagery'
                : '';
            $output .= "\nCREDENTIAL / TRUST BADGES{$cred_suffix}:\n";
            foreach ($buckets['credential_or_badge'] as $img) {
                $line = '  ' . $img['url'];
                if ($img['alt'] !== '') $line .= '  [alt: ' . $img['alt'] . ']';
                $output .= $line . "\n";
            }
        }

        if (!empty($buckets['other'])) {
            $output .= "\nOTHER IMAGES:\n";
            foreach ($buckets['other'] as $img) {
                $line = '  ' . $img['url'];
                if ($img['alt'] !== '') $line .= '  [alt: ' . $img['alt'] . ']';
                $output .= $line . "\n";
            }
        }

        $output .= "\n";
    }

    // CORE brand color block — always emitted when brand_colors is provided, regardless of guardrail
    if (!empty($brand_colors)) {
        $bc_conf = $brand_colors['confidence']      ?? 'low';
        $bc_primary = $brand_colors['primary']      ?? [];
        $bc_accent  = $brand_colors['accent']       ?? [];
        $bc_ev      = $brand_colors['evidence']     ?? '';
        $bc_char    = $brand_colors['visual_character'] ?? null;

        $output .= "\nBRAND COLOR PALETTE\n";
        $output .= "Confidence: " . strtoupper($bc_conf) . "\n";

        if (in_array($bc_conf, ['high', 'medium']) && (!empty($bc_primary) || !empty($bc_accent))) {
            // Level A: reliable exact colors
            foreach ($bc_primary as $hex) {
                $output .= "Primary: " . strtoupper($hex) . "\n";
            }
            foreach ($bc_accent as $hex) {
                $output .= "Accent: " . strtoupper($hex) . "\n";
            }
            if ($bc_ev) {
                $output .= "Evidence: " . $bc_ev . "\n";
            }
        } elseif ($bc_conf === 'low' && !empty($bc_char)) {
            // Level B: visual character detected — no hex values (unreliable)
            $char_parts = [];
            if (!empty($bc_char['tone']))        $char_parts[] = ucfirst($bc_char['tone']);
            if (!empty($bc_char['temperature'])) $char_parts[] = ucfirst($bc_char['temperature']);
            if (!empty($bc_char['saturation']))  $char_parts[] = ucfirst($bc_char['saturation']);
            $output .= "Visual character: " . implode(', ', $char_parts) . "\n"
                     . "Exact palette undetermined — preserve the detected visual character above.\n"
                     . "Do not substantially invert the detected tone, temperature, or saturation.\n";
        } else {
            // Level C: truly unknown
            $output .= "No visual color evidence detected — choose an appropriate palette for this business type.\n";
        }
        $output .= "\n";
    }

    $output .= "PAGE TEXT CONTENT:\n" . $text;
    return $output;
}

/**
 * Fetch a business website (crawling up to 15 pages on the same domain)
 * and return a normalized provider-independent source package.
 * No external API calls — all heuristic and deterministic.
 */
function clickfuzz_web_fetch_source($url, array $guardrails = [])
{
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }

    $pages = clickfuzz_web_crawl_pages($url, 15);
    if (empty($pages)) {
        return '';
    }

    // Logo discovery — check all pages; homepage signals are strongest
    $logo_discovery = null;
    foreach ($pages as $page) {
        $logo_discovery = clickfuzz_web_discover_logo($page['html'], $url);
        if ($logo_discovery) break;
    }

    // Log any logo conflicts (best-effort; log_activity only available in Perfex context)
    if ($logo_discovery && !empty($logo_discovery['conflicts']) && function_exists('log_activity')) {
        $clog = implode('; ', array_map(function($c) {
            return $c['source'] . ':' . basename(parse_url($c['url'], PHP_URL_PATH) ?: '');
        }, $logo_discovery['conflicts']));
        log_activity('ClickFuzz Web logo conflict [' . $url . ']: kept '
            . $logo_discovery['source'] . ' over ' . $clog);
    }

    // Collect images site-wide, deduplicating by URL across all pages
    $global_seen_urls = [];
    $all_images       = [];
    foreach ($pages as $page) {
        $page_meta = clickfuzz_web_extract_page_meta($page['html']);
        $page_imgs = clickfuzz_web_extract_classified_images($page['html'], $page_meta);
        foreach ($page_imgs as $img) {
            if (isset($global_seen_urls[$img['url']])) continue;
            $global_seen_urls[$img['url']] = true;
            $img['source_page'] = $page['url'];
            $all_images[] = $img;
        }
    }

    // Remove discovered logo from image results to avoid duplication
    if ($logo_discovery) {
        $logo_base  = strtolower(rawurldecode(basename(parse_url($logo_discovery['url'], PHP_URL_PATH) ?: '')));
        $all_images = array_values(array_filter($all_images, function($img) use ($logo_base) {
            return strtolower(rawurldecode(basename(parse_url($img['url'], PHP_URL_PATH) ?: ''))) !== $logo_base;
        }));
    }

    // Post-process: upgrade other→authentic_business_image, dedup CDN variants
    $all_images = clickfuzz_web_apply_source_page_context($all_images);

    // HIGH-confidence direct association (intrinsic evidence + direct-container)
    $all_images = clickfuzz_web_apply_team_direct_association($all_images, $pages);

    // MEDIUM-confidence ordered fallback (only fires for unresolved team_context images)
    $all_images = clickfuzz_web_apply_team_ordered_association($all_images, $pages);

    // Vision classification — CORE, provider-independent.
    // Selectively sends ambiguous images to a vision model to detect brand graphics
    // that deterministic HTML parsing cannot identify (e.g. generic-filename logo variants).
    // Fails gracefully: missing API key or API failure leaves pipeline unchanged.
    $_vision_api_key = function_exists('get_option') ? get_option('pitchsnap_anthropic_api_key') : '';
    if ($_vision_api_key) {
        $_vision_candidates = clickfuzz_web_select_vision_candidates($all_images, $logo_discovery);
        if (!empty($_vision_candidates)) {
            $_logo_ref    = $logo_discovery ? $logo_discovery['url'] : null;
            $_vision_data = clickfuzz_web_vision_classify($_vision_candidates, $_logo_ref, $_vision_api_key);
            if ($_vision_data) {
                $all_images = clickfuzz_web_apply_vision_classifications($all_images, $_vision_candidates, $_vision_data);
            }
        }
    }

    // Brand color extraction — deterministic CSS/theme signals first; confirmed logo as fallback.
    // Runs on raw HTML pages (before text extraction strips styles).
    // Independent of the ambiguous-image vision pipeline.
    $_brand_api_key = function_exists('get_option') ? (get_option('pitchsnap_anthropic_api_key') ?: '') : '';
    $_brand_logo    = $logo_discovery ? $logo_discovery['url'] : null;
    $brand_colors   = clickfuzz_web_extract_brand_colors($pages, $_brand_logo, $_brand_api_key);

    // Extract and merge text from all pages
    $page_texts = [];
    foreach ($pages as $page) {
        $page_texts[] = clickfuzz_web_extract_page_text($page['html']);
    }
    $merged_text = clickfuzz_web_merge_page_texts($page_texts);

    return clickfuzz_web_build_source_package($logo_discovery, $all_images, $merged_text, $guardrails, $brand_colors);
}

// ---------------------------------------------------------------------------
// Vision classification — CORE provider-independent preprocessing
// ---------------------------------------------------------------------------

/**
 * Select images from the pool that are candidates for visual classification.
 * Only selects images with weak/ambiguous deterministic classification.
 * Strong categorical signals (confirmed logo, HIGH-confidence team, badge) are excluded.
 * Caps at 8 images to keep cost and latency minimal.
 *
 * Returns a flat array of image arrays (subset of $images).
 */
function clickfuzz_web_select_vision_candidates(array $images, $logo_discovery)
{
    $eligible_cats = ['authentic_business_image', 'other'];

    $eligible = [];
    foreach ($images as $img) {
        $cat = $img['category'] ?? 'other';

        // Only images with weak/ambiguous deterministic category
        if (!in_array($cat, $eligible_cats, true)) continue;

        // Must be substantial enough to be meaningful imagery
        if (!clickfuzz_web_is_substantial_image($img)) continue;

        // Evaluate metadata strength
        $alt    = trim($img['alt']       ?? '');
        $figcap = trim($img['figcaption'] ?? '');

        // Alt is "weak" when it's empty, a raw filename, purely numeric/underscore slug, or very short
        $alt_is_weak = ($alt === ''
            || preg_match('/\.(jpe?g|png|webp|gif|svg)$/i', $alt)
            || preg_match('/^[\w\-_]+\d[\w\-_\d]*$/', $alt)
            || strlen($alt) < 4);

        // When both alt is descriptive AND there's a figcaption, deterministic evidence is sufficient
        if (!$alt_is_weak && $figcap !== '') continue;

        $eligible[] = $img;
    }

    // Sort: weak/no alt first (highest ambiguity), then by pixel area descending
    usort($eligible, function($a, $b) {
        $alt_a = trim($a['alt'] ?? '');
        $alt_b = trim($b['alt'] ?? '');
        $a_weak = ($alt_a === '' || preg_match('/\.(jpe?g|png|webp|gif|svg)$/i', $alt_a));
        $b_weak = ($alt_b === '' || preg_match('/\.(jpe?g|png|webp|gif|svg)$/i', $alt_b));
        if ($a_weak !== $b_weak) return (int)$b_weak - (int)$a_weak;
        $area_a = (int)($a['width'] ?? 0) * (int)($a['height'] ?? 0);
        $area_b = (int)($b['width'] ?? 0) * (int)($b['height'] ?? 0);
        return $area_b - $area_a;
    });

    return array_slice($eligible, 0, 8);
}

/**
 * Send candidate images to claude-haiku for visual classification.
 * Includes the confirmed logo as a reference brand asset when available.
 *
 * Returns ['results' => [...], 'usage' => [...]] or null on any failure.
 * Never throws — all errors degrade to null (pipeline continues unchanged).
 */
function clickfuzz_web_vision_classify(array $candidates, $logo_ref_url, $api_key)
{
    if (empty($candidates)) return null;

    $has_ref = !empty($logo_ref_url);

    $instruction = "Classify each numbered candidate image from a business website.\n"
                 . "Respond with ONLY a JSON object — no markdown, no explanation.\n"
                 . "Schema: {\"results\":[{\"index\":N,\"visual_type\":\"...\",\"confidence\":\"...\",\"description\":\"...\""
                 . ($has_ref ? ",\"resembles_known_brand\":true" : "")
                 . "}]}\n"
                 . "visual_type values (choose exactly one):\n"
                 . "  logo_or_brand_graphic — designed wordmark, logo, brand illustration, or graphic identity asset\n"
                 . "  person_or_team — photograph of one or more people\n"
                 . "  real_work_or_project — photo of completed work, service, or project\n"
                 . "  building_or_location — exterior/interior of a building, storefront, or location\n"
                 . "  product_or_equipment — product, tool, vehicle, or equipment photo\n"
                 . "  general_business_photo — authentic business photography not fitting other types\n"
                 . "  decorative_graphic — abstract/decorative non-brand graphic or pattern\n"
                 . "  unknown — cannot determine\n"
                 . "confidence values: high | medium | low\n"
                 . "description: ≤12 words describing what you see"
                 . ($has_ref ? "\nremembles_known_brand: true if this image visually resembles, derives from, or is a variant of the reference brand asset" : "");

    $content = [['type' => 'text', 'text' => $instruction]];

    if ($has_ref) {
        $content[] = ['type' => 'text', 'text' => "REFERENCE BRAND ASSET (primary logo for resemblance comparison):"];
        $content[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $logo_ref_url]];
    }

    foreach ($candidates as $i => $img) {
        $content[] = ['type' => 'text', 'text' => "CANDIDATE " . ($i + 1) . ":"];
        $content[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $img['url']]];
    }

    $payload = json_encode([
        'model'    => 'claude-haiku-4-5-20251001',
        'max_tokens' => 400,
        'messages' => [['role' => 'user', 'content' => $content]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . trim($api_key),
            'anthropic-version: 2023-06-01',
        ],
    ]);

    $raw       = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if (!$raw || $http_code !== 200) {
        if (function_exists('log_activity')) {
            log_activity('ClickFuzz Web vision classify: HTTP ' . $http_code
                . ($curl_err ? ' — ' . $curl_err : '')
                . (!$raw ? ' (empty response)' : ''));
        }
        return null;
    }

    $data = json_decode($raw, true);
    if (empty($data['content'][0]['text'])) return null;

    $text = trim($data['content'][0]['text']);
    // Strip ```json ... ``` wrapper if model adds one
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
        $text = $m[1];
    } elseif (preg_match('/(\{[^{}]*"results".*\})/s', $text, $m)) {
        $text = $m[1];
    }

    $parsed = json_decode($text, true);
    if (empty($parsed['results'])) return null;

    return [
        'results' => $parsed['results'],
        'usage'   => $data['usage'] ?? [],
    ];
}

/**
 * Merge vision classification results into the image array.
 * Always stores visual evidence metadata on classified images.
 * Only upgrades category (to brand_graphic) for HIGH-confidence logo_or_brand_graphic
 * when the current deterministic category is weak (authentic_business_image or other).
 * Never overwrites strong deterministic signals.
 *
 * @param array $images    Full image array (by reference semantics via return)
 * @param array $candidates  Images that were sent to vision (subset of $images)
 * @param array $vision_data Return value from clickfuzz_web_vision_classify()
 * @return array Updated image array
 */
function clickfuzz_web_apply_vision_classifications(array $images, array $candidates, array $vision_data)
{
    if (empty($vision_data['results'])) return $images;

    // Index vision results by 1-based candidate position
    $results_by_idx = [];
    foreach ($vision_data['results'] as $r) {
        if (isset($r['index'])) $results_by_idx[(int)$r['index']] = $r;
    }

    // Map candidate URL → vision result
    $by_url = [];
    foreach ($candidates as $i => $cand) {
        $vi = $i + 1;
        if (isset($results_by_idx[$vi])) {
            $by_url[$cand['url']] = $results_by_idx[$vi];
        }
    }

    $weak_cats = ['authentic_business_image', 'other'];

    foreach ($images as &$img) {
        if (!isset($by_url[$img['url']])) continue;
        $r = $by_url[$img['url']];

        // Store visual evidence regardless of whether category changes
        $img['vision_type']            = $r['visual_type']          ?? 'unknown';
        $img['vision_confidence']      = $r['confidence']           ?? 'low';
        $img['vision_description']     = $r['description']          ?? '';
        $img['vision_resembles_brand'] = !empty($r['resembles_known_brand']);

        // Conservative merge: only upgrade weak categories to brand_graphic
        $current_cat = $img['category'] ?? 'other';
        if (in_array($current_cat, $weak_cats, true)
            && ($img['vision_type'] === 'logo_or_brand_graphic')
            && ($img['vision_confidence'] === 'high')
        ) {
            $img['category'] = 'brand_graphic';
        }
    }
    unset($img);

    return $images;
}

/**
 * Extract page title and first H1 from raw HTML.
 * Returns ['title' => string, 'h1' => string].
 */
function clickfuzz_web_extract_page_meta($html)
{
    $title = '';
    $h1    = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m))
        $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/si', $html, $m))
        $h1 = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Strip " | Business Name" suffixes common in page titles
    $title = trim(preg_replace('/\s*[\|\-\x{2014}]\s*.+$/u', '', $title));
    if (strlen($title) > 120) $title = substr($title, 0, 117) . '...';
    if (strlen($h1)    > 120) $h1    = substr($h1,    0, 117) . '...';
    return ['title' => $title, 'h1' => $h1];
}

/**
 * Returns true when the string looks like a person's proper name
 * (2–3 Title-Case words, no digits, reasonable length).
 */
function clickfuzz_web_looks_like_person_name($str)
{
    $str = trim($str);
    if (!$str || strlen($str) > 45 || strlen($str) < 5) return false;
    return (bool) preg_match("/^[A-Z][a-zA-Z'\\-]+(?:\\s+[A-Z][a-zA-Z'\\-]+){1,2}$/", $str);
}

/**
 * Extract local section/card context for an image at $offset in $html.
 *
 * Returns:
 *   section_heading — nearest h1 or h2 in the 3 000 chars before this img
 *   card_heading    — nearest h3/h4 within ±600 chars (potential person name or sub-section title)
 *   card_role       — short role/title text in the same ±600-char window
 *   figcaption      — figcaption text within 500 chars after the img
 */
function clickfuzz_web_find_image_context($html, $offset)
{
    $ctx = ['section_heading' => '', 'card_heading' => '', 'card_role' => '', 'figcaption' => ''];

    // Section heading: nearest h1/h2 in 3 000 chars before the img
    $back = substr($html, max(0, $offset - 3000), min($offset, 3000));
    if (preg_match_all('/<h[12][^>]*>(.*?)<\/h[12]>/si', $back, $hm)) {
        $last = trim(strip_tags((string) end($hm[1])));
        if ($last && strlen($last) < 120) $ctx['section_heading'] = $last;
    }

    // Card window ±600 chars: find nearest h3/h4 to the img position
    $c_start  = max(0, $offset - 600);
    $card_win = substr($html, $c_start, 1200);
    $img_rel  = min($offset - $c_start, strlen($card_win));

    if (preg_match_all('/<h[34][^>]*>(.*?)<\/h[34]>/si', $card_win, $hm, PREG_OFFSET_CAPTURE)) {
        $best_text = '';
        $best_dist = PHP_INT_MAX;
        foreach ($hm[0] as $i => $match_info) {
            $dist = abs((int)$match_info[1] - $img_rel);
            if ($dist < $best_dist) {
                $best_dist = $dist;
                $best_text = trim(strip_tags((string) $hm[1][$i][0]));
            }
        }
        if ($best_text && strlen($best_text) < 80) $ctx['card_heading'] = $best_text;
    }

    // Role/title: short text block containing role keywords in the card window
    static $role_kws = null;
    if ($role_kws === null) {
        $role_kws = ['owner','founder','operator','technician','manager','director',
                     'coordinator','specialist','engineer','supervisor','president',
                     'ceo','partner','plumber','electrician','mechanic','installer',
                     'hvac','tech','service','lead','senior','principal','rep','dispatcher'];
    }
    if (preg_match_all('/<(?:p|span|small|em|strong)[^>]*>(.*?)<\/(?:p|span|small|em|strong)>/si',
                        $card_win, $pm)) {
        foreach ($pm[1] as $block) {
            $clean = trim(strip_tags((string) $block));
            if (strlen($clean) < 3 || strlen($clean) > 80) continue;
            if ($clean === $ctx['card_heading']) continue;
            $low = strtolower($clean);
            foreach ($role_kws as $kw) {
                if (strpos($low, $kw) !== false) { $ctx['card_role'] = $clean; break 2; }
            }
        }
    }

    // figcaption within 500 chars after img
    $fwd = substr($html, $offset, 500);
    if (preg_match('/<figcaption[^>]*>(.*?)<\/figcaption>/si', $fwd, $m)) {
        $fc = trim(strip_tags((string) $m[1]));
        if ($fc) $ctx['figcaption'] = $fc;
    }

    return $ctx;
}

/**
 * Extract <img> tags from raw HTML and classify each using structural heuristics.
 * Operates on raw HTML (before any tag stripping) so parent element context is
 * available: header/nav location, link anchor, CSS class/id, dimensions.
 * Returns 'other' when confidence is low rather than guessing.
 */
function clickfuzz_web_extract_classified_images($html, $page_meta = [])
{
    preg_match_all('/<img([^>]+)>/i', $html, $matches, PREG_OFFSET_CAPTURE);

    $seen    = [];
    $results = [];
    $count   = count($matches[0]);

    $page_title = $page_meta['title'] ?? '';
    $page_h1    = $page_meta['h1']    ?? '';

    for ($i = 0; $i < $count; $i++) {
        [$full_tag, $offset] = $matches[0][$i];
        $attrs = $matches[1][$i][0];

        if (!preg_match('/\bsrc=["\']([^"\']+)["\']/', $attrs, $sm)) continue;
        $src = trim($sm[1]);
        if (strncmp($src, 'http', 4) !== 0) continue;
        if (isset($seen[$src])) continue;
        $seen[$src] = true;

        $alt    = preg_match('/\balt=["\']([^"\']*)["\']/',   $attrs, $m) ? trim($m[1]) : '';
        $width  = preg_match('/\bwidth=["\'](\d+)["\']/',     $attrs, $m) ? (int) $m[1] : 0;
        $height = preg_match('/\bheight=["\'](\d+)["\']/',    $attrs, $m) ? (int) $m[1] : 0;
        $class  = preg_match('/\bclass=["\']([^"\']*)["\']/', $attrs, $m) ? strtolower($m[1]) : '';
        $id_a   = preg_match('/\bid=["\']([^"\']*)["\']/',    $attrs, $m) ? strtolower($m[1]) : '';

        // Up to 600 bytes of raw HTML immediately before this img tag
        $ctx_start = max(0, $offset - 600);
        $ctx       = strtolower(substr($html, $ctx_start, $offset - $ctx_start));

        // Richer section/card context for semantic metadata
        $img_ctx = clickfuzz_web_find_image_context($html, $offset);

        $results[] = [
            'url'             => $src,
            'alt'             => $alt,
            'category'        => clickfuzz_web_image_category($src, $alt, $class, $id_a, $width, $height, $ctx),
            'width'           => $width,
            'height'          => $height,
            'page_title'      => $page_title,
            'page_h1'         => $page_h1,
            'section_heading' => $img_ctx['section_heading'],
            'card_heading'    => $img_ctx['card_heading'],
            'card_role'       => $img_ctx['card_role'],
            'figcaption'      => $img_ctx['figcaption'],
        ];

        if (count($results) >= 40) break;
    }

    return $results;
}

/**
 * Returns true when the given page URL looks like a gallery/portfolio/work/
 * showcase/project/photos style page — i.e. a page whose primary purpose is
 * displaying business imagery.  Vertical-agnostic: does NOT assume any
 * specific business type.
 */
function clickfuzz_web_is_gallery_style_page($page_url)
{
    if (!$page_url) return false;
    $path = strtolower(parse_url($page_url, PHP_URL_PATH) ?: '');
    // Split into path segments, ignore empty ones
    $segs = array_filter(explode('/', $path), 'strlen');
    $gallery_keywords = [
        'gallery', 'galleries', 'portfolio', 'portfolios',
        'showcase', 'showcases', 'project', 'projects',
        'work', 'works', 'photos', 'photo', 'images', 'image',
        'media', 'lookbook', 'lookbooks', 'collection', 'collections',
        'case-study', 'case-studies', 'case_study', 'case_studies',
        'before-after', 'before_after', 'results', 'examples',
        'our-work', 'our_work', 'our-projects', 'our_projects',
    ];
    foreach ($segs as $seg) {
        if (in_array($seg, $gallery_keywords, true)) return true;
    }
    return false;
}

/**
 * Returns true when the image appears to be a substantial real photograph
 * rather than a UI element, icon, or decoration.
 * Uses available src/alt/dimension signals — no AI/vision.
 */
function clickfuzz_web_is_substantial_image($img)
{
    $src  = strtolower($img['url']  ?? '');
    $alt  = strtolower($img['alt']  ?? '');
    $w    = (int)($img['width']  ?? 0);
    $h    = (int)($img['height'] ?? 0);

    // Explicit dimension check: must be at least 200×200 when present
    if ($w > 0 && $h > 0 && ($w < 200 || $h < 200)) return false;

    // Icon/logo/sprite/button/arrow patterns in src
    $exclude_src = ['icon', 'sprite', 'arrow', 'bullet', 'chevron', 'logo',
                    'avatar', 'placeholder', 'spinner', 'loader', 'favicon',
                    'badge', 'seal', 'star-', 'rating'];
    foreach ($exclude_src as $kw) {
        if (strpos($src, $kw) !== false) return false;
    }

    // Camera/source filename pattern in alt (e.g. PXL_20221109_165128994.jpg)
    // These are real photos from a camera — positive signal
    if (preg_match('/\b(img|dsc|pxl|img|photo|pic|_mg_|raw)\d+/i', $alt)) return true;
    if (preg_match('/\b\d{8,}\b/', $alt)) return true; // long numeric component

    // CDN path patterns common to photo-hosting platforms (Squarespace, etc.)
    if (preg_match('#/images?/[a-f0-9]{8,}#i', $src)) return true;
    if (preg_match('#/(media|photos?|uploads?|gallery)/#i', $src)) return true;

    // If we have generous dimensions, assume substantial
    if ($w >= 400 && $h >= 400) return true;
    if ($w >= 600 || $h >= 600) return true;

    // Fall back to: non-empty alt that isn't a generic label
    $generic_alts = ['image', 'photo', 'picture', 'img', '', 'undefined', 'null'];
    if ($alt && !in_array(trim($alt), $generic_alts, true) && strlen($alt) > 3) return true;

    return false;
}

/**
 * Post-processing step: upgrade 'other' images to 'authentic_business_image'
 * when (a) they came from a gallery-style page AND (b) they look substantial.
 *
 * Also deduplicates CDN crop variants by alt-text: when Squarespace (or
 * similar) serves the same photo at multiple CDN URLs but with the same
 * original filename in the alt attribute, keep only the first occurrence.
 *
 * Input:  $images — flat array of image arrays, each with at least:
 *           'url', 'category', 'source_page', and optionally 'alt'
 * Returns upgraded + deduped array (preserves order).
 */
function clickfuzz_web_apply_source_page_context(array $images)
{
    $seen_alts = []; // alt-text dedup for CDN crop variants
    $result    = [];

    // Keywords that suggest a section/page is about people
    $team_ctx_kws = ['team','staff','crew','about us','meet the','who we are',
                     'our people','employees','personnel','leadership','our staff'];
    // URL path segments that suggest a team/about page
    $team_url_kws = ['team','staff','crew','about','meet','people','employees',
                     'personnel','leadership','founders','partners'];

    foreach ($images as $img) {
        // Alt-text CDN-variant dedup: same source filename in alt → skip dupe
        $alt = trim($img['alt'] ?? '');
        if ($alt && preg_match('/\.(jpe?g|png|webp|gif)$/i', $alt)) {
            $alt_key = strtolower($alt);
            if (isset($seen_alts[$alt_key])) continue;
            $seen_alts[$alt_key] = true;
        }

        $cat          = $img['category'] ?? 'other';
        $section_hdg  = strtolower($img['section_heading'] ?? '');
        $page_h1      = strtolower($img['page_h1']         ?? '');
        $page_title   = strtolower($img['page_title']      ?? '');
        $card_heading = $img['card_heading'] ?? '';
        $card_role    = $img['card_role']    ?? '';
        $from_gallery = clickfuzz_web_is_gallery_style_page($img['source_page'] ?? '');
        $substantial  = clickfuzz_web_is_substantial_image($img);

        // Determine if the image's surrounding context is team/people-focused
        $context_str = $section_hdg . ' ' . $page_h1 . ' ' . $page_title;
        $is_team_ctx = false;
        foreach ($team_ctx_kws as $kw) {
            if (strpos($context_str, $kw) !== false) { $is_team_ctx = true; break; }
        }
        // Also check the source page URL path segments
        if (!$is_team_ctx) {
            $sp_path = strtolower(parse_url($img['source_page'] ?? '', PHP_URL_PATH) ?: '');
            foreach ($team_url_kws as $kw) {
                if (strpos($sp_path, '/' . $kw) !== false) { $is_team_ctx = true; break; }
            }
        }

        // Signals of a specific person in this image (no dimension signals)
        $has_person_alt  = ($alt !== ''
                            && !preg_match('/\.(jpe?g|png|webp|gif)$/i', $alt)
                            && strlen($alt) >= 5);
        $has_person_card = (($card_heading !== '' && clickfuzz_web_looks_like_person_name($card_heading))
                            || $card_role !== '');
        $has_person_evidence = $has_person_alt || $has_person_card;

        if ($is_team_ctx && $substantial) {
            if ($has_person_evidence) {
                // Explicit person evidence on a team/about page
                $img['category'] = 'owner_or_team';
                $cat = 'owner_or_team';
            } elseif ($cat === 'other' || $cat === 'project_or_real_work') {
                // Team-page image, no deterministic person evidence → team context pool
                $img['category'] = 'team_context';
                $cat = 'team_context';
            }
            // already team_context or owner_or_team: leave as-is
        } elseif ($cat === 'other' && $substantial) {
            // Route by source-page provenance so pools stay separate
            $img['category'] = $from_gallery ? 'gallery_context' : 'authentic_business_image';
            $cat = $img['category'];
        }

        $result[] = $img;
    }

    return $result;
}
/**
 * Classify a single image into one of:
 * company_logo | owner_or_team | company_vehicle | project_or_real_work |
 * credential_or_badge | other
 *
 * Priority order: logo → badge → team → vehicle → project → other.
 * Returns 'other' when no signal is strong enough to commit.
 */
function clickfuzz_web_image_category($src, $alt, $class, $id_a, $width, $height, $ctx)
{
    $filename = strtolower(basename(parse_url($src, PHP_URL_PATH) ?: ''));
    $path     = strtolower(parse_url($src, PHP_URL_PATH) ?: '');
    $alt_low  = strtolower($alt);
    $combined = $filename . ' ' . $alt_low . ' ' . $class . ' ' . $id_a;

    // ── Logo ─────────────────────────────────────────────────────────────
    foreach (['logo', 'brand', 'wordmark', 'logotype'] as $kw) {
        if (strpos($combined, $kw) !== false) {
            return 'company_logo';
        }
    }
    $in_header  = strpos($ctx, '<header') !== false || strpos($ctx, '<nav') !== false;
    $links_home = strpos($ctx, 'href=') !== false;
    if ($in_header && $links_home) return 'company_logo';
    if ($links_home && $height > 0 && $height <= 80) return 'company_logo';
    if ($in_header && $width > 0 && $height > 0 && $width <= 400 && $height <= 180 && $width > $height * 1.2) {
        return 'company_logo';
    }

    // ── Credential / badge ────────────────────────────────────────────────
    foreach (['badge', 'cert', 'award', 'accred', 'member', 'partner', 'seal',
              'bbb', 'angi', 'yelp', 'houzz', 'nate', 'acca',
              'trust', 'guarantee', 'verified', 'licens', 'insur'] as $kw) {
        if (strpos($combined, $kw) !== false) return 'credential_or_badge';
    }
    if ($width > 0 && $height > 0 && $width <= 140 && $height <= 140) return 'credential_or_badge';

    // ── Team / owner ──────────────────────────────────────────────────────
    foreach (['team', 'staff', 'owner', 'founder', 'headshot', 'portrait',
              'employee', 'crew', 'meet-', 'technician', 'plumber',
              'electrician', 'mechanic', 'contractor'] as $kw) {
        if (strpos($combined, $kw) !== false || strpos($path, '/' . $kw) !== false) {
            return 'owner_or_team';
        }
    }
    // ── Vehicle ───────────────────────────────────────────────────────────
    foreach (['truck', 'van', 'fleet', 'vehicle', 'trailer', 'wrapped'] as $kw) {
        if (strpos($combined, $kw) !== false) return 'company_vehicle';
    }

    // ── Real work / project ───────────────────────────────────────────────
    foreach (['gallery', 'project', 'portfolio', '/work/', '/job/', 'install', '/service'] as $kw) {
        if (strpos($path, $kw) !== false || strpos($class, ltrim($kw, '/')) !== false) {
            return 'project_or_real_work';
        }
    }
    foreach (['before', 'after', 'complet', 'repair', 'replac', 'gallery'] as $kw) {
        if (strpos($combined, $kw) !== false) return 'project_or_real_work';
    }

    return 'other';
}

// ---------------------------------------------------------------------------
// Logo discovery — semantic fallback for sites with JS-loaded logos
// ---------------------------------------------------------------------------

/**
 * Attempt to identify the company logo from semantic HTML signals in the
 * static page source without a headless browser or AI call.
 *
 * Priority (strongest → weakest):
 *   1. Explicit platform logo key  (Squarespace socialLogoImageUrl, Wix logoUrl)
 *   2. JSON-LD Organization/LocalBusiness logo property
 *   3. Header/nav <img> with logo keyword in filename, alt, or class
 *   4. og:image / twitter:image where filename matches logo keywords
 *
 * If multiple candidates are found at different priority levels, the highest-
 * priority one wins. If two candidates at the same priority level have
 * materially different filenames, the conflict is logged and the first is kept.
 *
 * Returns null when no high-confidence signal exists — never guesses.
 *
 * @param  string $html      Raw fetched HTML
 * @param  string $page_url  Original URL (for conflict logging)
 * @return array|null  ['url', 'source', 'conflicts'] or null
 */
function clickfuzz_web_discover_logo($html, $page_url)
{
    $candidates = [];

    // ── Priority 1: Explicit platform logo key ────────────────────────────
    // Squarespace embeds logo URL in Static.SQUARESPACE_CONTEXT JSON
    foreach (['socialLogoImageUrl', 'siteLogoImageUrl'] as $ss_key) {
        if (preg_match('/"' . $ss_key . '"\s*:\s*"(\/\/[^"]+)"/', $html, $m)) {
            $candidates[] = ['url' => 'https:' . $m[1], 'priority' => 1, 'source' => 'platform-' . strtolower($ss_key)];
            break;
        }
    }
    // Wix: logoUrl in page data JSON
    if (preg_match('/"logoUrl"\s*:\s*"(https?:\/\/[^"]+\.(png|jpg|jpeg|svg|webp)[^"]*)"/', $html, $m)) {
        $candidates[] = ['url' => $m[1], 'priority' => 1, 'source' => 'platform-logourl'];
    }

    // ── Priority 2: JSON-LD Organization/LocalBusiness logo ───────────────
    preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $jld);
    foreach ($jld[1] as $block) {
        $data = json_decode(trim($block), true);
        if (!$data) continue;
        $types = array_map('strtolower', (array)($data['@type'] ?? []));
        if (!array_intersect($types, ['organization', 'localbusiness', 'corporation', 'store', 'service'])) continue;
        $logo = $data['logo'] ?? null;
        if (is_string($logo) && strncmp($logo, 'http', 4) === 0) {
            $candidates[] = ['url' => $logo, 'priority' => 2, 'source' => 'jsonld-organization'];
        } elseif (is_array($logo) && !empty($logo['url']) && strncmp($logo['url'], 'http', 4) === 0) {
            $candidates[] = ['url' => $logo['url'], 'priority' => 2, 'source' => 'jsonld-organization'];
        }
    }

    // ── Priority 3: Header/nav logo detection ────────────────────────────────
    // 3a: explicit logo/brand/wordmark keyword in filename, alt, or class.
    // 3b: structural — single image in a homepage-pointing <a> within a sparse header.
    //     Catches builder-generated sites where the identity image has no logo keyword
    //     but occupies the canonical brand slot (link-wrapped, few siblings in header).
    if (preg_match('/<(?:header|nav)\b[^>]*>([\s\S]+?)<\/(?:header|nav)>/i', $html, $hm_hdr)) {
        $hdr = $hm_hdr[1];

        // 3a: keyword check (unchanged logic)
        $found_3a = false;
        preg_match_all('/<img([^>]+)>/i', $hdr, $imgs_hdr);
        foreach ($imgs_hdr[1] as $attrs) {
            if (!preg_match('/\bsrc=["\']([^"\']+)["\']/', $attrs, $sm)) continue;
            $src = trim($sm[1]);
            if (strncmp($src, 'http', 4) !== 0) continue;
            $filename = strtolower(basename(parse_url($src, PHP_URL_PATH) ?: ''));
            $alt      = preg_match('/\balt=["\']([^"\']*)["\']/', $attrs, $m2) ? strtolower($m2[1]) : '';
            $class    = preg_match('/\bclass=["\']([^"\']*)["\']/', $attrs, $m2) ? strtolower($m2[1]) : '';
            if (preg_match('/logo|brand|wordmark/', $filename . ' ' . $alt . ' ' . $class)) {
                $candidates[] = ['url' => $src, 'priority' => 3, 'source' => 'header-nav-img'];
                $found_3a = true;
                break;
            }
        }

        // 3b: structural fallback — only when header is not image-crowded
        if (!$found_3a && preg_match_all('/<img\b/i', $hdr) <= 5) {
            $parsed_base = parse_url($page_url);
            $base_host   = strtolower($parsed_base['host'] ?? '');
            $base_scheme = $parsed_base['scheme'] ?? 'https';
            $alt_host    = $base_host
                ? (strpos($base_host, 'www.') === 0 ? substr($base_host, 4) : 'www.' . $base_host)
                : '';

            preg_match_all('/<a\b([^>]*?)>([\s\S]{0,600}?)<\/a>/i', $hdr, $links_m, PREG_SET_ORDER);
            foreach ($links_m as $lm) {
                if (!preg_match('/\bhref=["\']([^"\']*)["\']/', $lm[1], $hrefm)) continue;
                $href = trim($hrefm[1]);

                $is_home = false;
                if ($href === '/' || $href === './' || preg_match('/^index\.(html?|php)$/i', $href)) {
                    $is_home = true;
                } elseif ($base_host) {
                    if (preg_match('#^https?://' . preg_quote($base_host, '#') . '/?$#i', $href)) {
                        $is_home = true;
                    }
                    if ($alt_host && preg_match('#^https?://' . preg_quote($alt_host, '#') . '/?$#i', $href)) {
                        $is_home = true;
                    }
                }
                if (!$is_home) continue;

                // The link must wrap exactly one <img>
                if (preg_match_all('/<img\b([^>]+)>/i', $lm[2], $li) !== 1) continue;
                $iattrs = $li[1][0];
                if (!preg_match('/\bsrc=["\']([^"\']*)["\']/', $iattrs, $sm)) continue;
                $src = trim($sm[1]);

                // Resolve to absolute URL
                if (strncmp($src, 'http', 4) !== 0) {
                    $src = strncmp($src, '//', 2) === 0
                        ? $base_scheme . ':' . $src
                        : $base_scheme . '://' . $base_host . '/' . ltrim($src, '/');
                }

                $candidates[] = ['url' => $src, 'priority' => 3, 'source' => 'header-homepage-link'];
                break;
            }
        }
    }

    // ── Priority 4: og:image / twitter:image with logo filename ──────────
    $og_patterns = [
        '/property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/',
        '/content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/',
        '/name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/',
        '/content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\']/',
    ];
    foreach ($og_patterns as $pat) {
        if (!preg_match($pat, $html, $m)) continue;
        $og_url = $m[1];
        if (strncmp($og_url, 'http', 4) !== 0) continue;
        $og_file = strtolower(rawurldecode(basename(parse_url($og_url, PHP_URL_PATH) ?: '')));
        if (preg_match('/\b(logo|brand|wordmark|logotype)\b/', $og_file)) {
            $candidates[] = ['url' => $og_url, 'priority' => 4, 'source' => 'og-image'];
        }
        break;
    }

    if (empty($candidates)) return null;

    // Sort by priority — lowest number wins
    usort($candidates, function($a, $b) { return $a['priority'] - $b['priority']; });

    $best = $candidates[0];

    // Detect material disagreement: different base filenames across candidates
    $best_file = strtolower(rawurldecode(basename(parse_url($best['url'], PHP_URL_PATH) ?: '')));
    $conflicts = [];
    foreach (array_slice($candidates, 1) as $c) {
        $c_file = strtolower(rawurldecode(basename(parse_url($c['url'], PHP_URL_PATH) ?: '')));
        if ($c_file !== '' && $best_file !== '' && $c_file !== $best_file) {
            $conflicts[] = $c;
        }
    }

    return [
        'url'       => $best['url'],
        'source'    => $best['source'],
        'conflicts' => $conflicts,
    ];
}

// ---------------------------------------------------------------------------
// Brand color extraction — deterministic first, logo vision as independent fallback
// ---------------------------------------------------------------------------

/**
 * Normalize a CSS color string to lowercase 6-digit hex, or return null.
 * Handles: #RGB, #RRGGBB, rgb(r,g,b), rgba(r,g,b,a), a small set of named colors.
 */
function clickfuzz_web_normalize_color($val)
{
    $val = strtolower(trim((string)$val));
    $val = preg_replace('/\s*!important.*$/', '', $val);
    $val = trim($val);

    static $named = [
        'white'   => '#ffffff', 'black' => '#000000', 'transparent' => null,
        'red'     => '#ff0000', 'blue'  => '#0000ff', 'green'  => '#008000',
        'navy'    => '#000080', 'orange' => '#ffa500', 'yellow' => '#ffff00',
        'gray'    => '#808080', 'grey'  => '#808080', 'silver' => '#c0c0c0',
    ];
    if (array_key_exists($val, $named)) return $named[$val];

    if (preg_match('/^#([0-9a-f]{3})$/', $val, $m)) {
        return '#' . str_repeat($m[1][0], 2) . str_repeat($m[1][1], 2) . str_repeat($m[1][2], 2);
    }
    if (preg_match('/^#([0-9a-f]{6})$/', $val, $m)) {
        return '#' . $m[1];
    }
    if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/', $val, $m)) {
        return sprintf('#%02x%02x%02x', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*([\d.]+)\s*\)$/', $val, $m)) {
        if ((float)$m[4] < 0.15) return null;
        return sprintf('#%02x%02x%02x', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    return null;
}

/**
 * Return [hue(0-360), saturation(0-1), lightness(0-1)] from a 6-digit hex string.
 */
function clickfuzz_web_color_hsl($hex)
{
    $r = hexdec(substr($hex, 1, 2)) / 255;
    $g = hexdec(substr($hex, 3, 2)) / 255;
    $b = hexdec(substr($hex, 5, 2)) / 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l   = ($max + $min) / 2;
    if ($max === $min) return [0, 0, $l];
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    switch ($max) {
        case $r: $h = (($g - $b) / $d) + ($g < $b ? 6 : 0); break;
        case $g: $h = (($b - $r) / $d) + 2; break;
        default: $h = (($r - $g) / $d) + 4; break;
    }
    return [$h * 60, $s, $l];
}

/**
 * Return true when the color is a near-neutral (near-white, near-black, or unsaturated gray).
 * Used to skip utility colors unless they have overwhelming semantic evidence.
 */
function clickfuzz_web_is_neutral_color($hex)
{
    if (!$hex) return true;
    [, $s, $l] = clickfuzz_web_color_hsl($hex);
    return ($l > 0.93 || $l < 0.07 || $s < 0.08);
}

/**
 * Derive broad visual character from raw color evidence collected before brand-color filtering.
 * Used for LOW-confidence sites where exact hex values are unreliable but a visual character
 * can still be inferred from root/surface backgrounds, logo, and CSS signals.
 *
 * Evidence weights: bg_colors (root surfaces) = 3, logo_raw = 2, all_scored (CSS signals) = min(score,3)
 * Requires total_weight >= 3 to commit to any dimension (avoids single-element domination).
 * Temperature is only reported when one direction clearly dominates (1.5× majority by weight).
 * Saturation uses chroma C = max-min (more reliable near white/black than HSL S).
 *
 * @param  array  $bg_colors   Body/html background hex colors — highest significance
 * @param  array  $all_scored  All CSS/HTML scored colors before neutral filter [hex => score]
 * @param  array  $logo_raw    All logo vision hex colors including near-neutrals
 * @return array|null  ['tone'=>..., 'temperature'=>..., 'saturation'=>...] or null if unknown
 */
function clickfuzz_web_visual_character(array $bg_colors, array $all_scored, array $logo_raw)
{
    $samples = [];

    foreach (array_unique($bg_colors) as $hex) {
        if ($hex) $samples[$hex] = ($samples[$hex] ?? 0) + 3;
    }
    foreach (array_unique($logo_raw) as $hex) {
        if ($hex) $samples[$hex] = ($samples[$hex] ?? 0) + 2;
    }
    foreach ($all_scored as $hex => $score) {
        if ($hex) $samples[$hex] = ($samples[$hex] ?? 0) + min((int) $score, 3);
    }

    if (empty($samples)) return null;
    $total_w = array_sum($samples);
    if ($total_w < 3) return null;

    $sum_l  = 0.0;
    $sum_c  = 0.0;
    $warm_w = 0.0;
    $cool_w = 0.0;

    foreach ($samples as $hex => $w) {
        [$hue, , $l] = clickfuzz_web_color_hsl($hex);
        $r      = hexdec(substr($hex, 1, 2)) / 255;
        $g      = hexdec(substr($hex, 3, 2)) / 255;
        $b      = hexdec(substr($hex, 5, 2)) / 255;
        $chroma = max($r, $g, $b) - min($r, $g, $b);

        $sum_l += $l * $w;
        $sum_c += $chroma * $w;

        // Only chromatic colors vote on temperature; achromatic hue values are degenerate.
        // Warm: hue 20–70° (orange/yellow) and 330–360° (red); Cool: 180–300° (blue/violet)
        if ($chroma > 0.08) {
            if (($hue >= 20 && $hue <= 70) || $hue >= 330) {
                $warm_w += $w;
            } elseif ($hue >= 180 && $hue <= 300) {
                $cool_w += $w;
            }
        }
    }

    $avg_l = $sum_l / $total_w;
    $avg_c = $sum_c / $total_w;

    $tone       = $avg_l > 0.65 ? 'light' : ($avg_l < 0.35 ? 'dark' : 'balanced');
    $saturation = $avg_c < 0.10 ? 'muted' : ($avg_c > 0.35 ? 'vivid' : 'moderate');

    // Temperature: only classify when one direction clearly dominates
    $temperature = null;
    $temp_w      = $warm_w + $cool_w;
    if ($temp_w >= 2.0) {
        if ($warm_w >= $cool_w * 1.5) {
            $temperature = 'warm';
        } elseif ($cool_w >= $warm_w * 1.5) {
            $temperature = 'cool';
        }
    }

    $out = ['tone' => $tone, 'saturation' => $saturation];
    if ($temperature !== null) {
        $out['temperature'] = $temperature;
    }
    return $out;
}

/**
 * Extract dominant brand colors from the already-fetched raw HTML pages.
 *
 * Phase A: Deterministic — reads CSS/HTML/theme signals from raw HTML.
 * Phase B: Logo vision fallback — used ONLY if Phase A confidence is low
 *          AND a confirmed canonical logo URL is available.
 *          Completely independent of the ambiguous-image candidate pipeline.
 *
 * Returns:
 *   [
 *     'confidence'       => 'high' | 'medium' | 'low',
 *     'primary'          => [hex, ...],
 *     'accent'           => [hex, ...],
 *     'evidence'         => string,
 *     'logo_vision_used' => bool,
 *   ]
 */
/**
 * Returns true when the last target in a CSS selector is represented in the
 * scraped HTML markup; false only when definitively absent.
 * Conservative: returns true for selectors too complex to parse safely.
 */
function clickfuzz_web_css_target_in_html($selector, $html)
{
    // Strip pseudo-classes/elements: :hover, :nth-child(2), ::before, etc.
    $selector = preg_replace('/::?[\w-]+(\([^)]*\))?/', '', $selector);
    // Strip attribute selectors: [attr], [attr="val"]
    $selector = preg_replace('/\[[^\]]*\]/', '', $selector);
    $selector = trim($selector);
    if (!$selector) return true;

    // Split on CSS combinators (space, >, +, ~) to isolate selector parts
    $parts = preg_split('/\s*[\s>+~]\s*/', $selector, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($parts)) return true;

    // Target = last part (the element the rule actually styles)
    $target = trim(end($parts));
    if (!$target) return true;

    // Extract classes, id, and bare element tag from the target token
    $classes = [];
    if (preg_match_all('/\.(-?[a-zA-Z][a-zA-Z0-9_-]*)/', $target, $cm)) {
        $classes = $cm[1];
    }
    $id = null;
    if (preg_match('/#([a-zA-Z][a-zA-Z0-9_-]*)/', $target, $im)) {
        $id = $im[1];
    }
    // Bare element tag only when not qualified by class or id
    $tag = null;
    if (!$classes && !$id && preg_match('/^([a-zA-Z][a-zA-Z0-9]*)$/', $target, $tm)) {
        $tag = strtolower($tm[1]);
    }

    // Nothing parseable → conservative
    if (!$classes && !$id && !$tag) return true;

    // Every class in the target must appear in a class attribute value
    foreach ($classes as $cls) {
        $pat = '/class\s*=\s*["\'][^"\']*(?<![a-zA-Z0-9_-])' . preg_quote($cls, '/') . '(?![a-zA-Z0-9_-])/i';
        if (!preg_match($pat, $html)) return false;
    }

    if ($id) {
        if (!preg_match('/\bid\s*=\s*["\']' . preg_quote($id, '/') . '["\']/i', $html)) return false;
    }

    if ($tag) {
        // Skip ubiquitous inline/form elements present on virtually every page
        static $skip_tags = ['a','span','em','strong','b','i','u','small','code',
                             'sub','sup','img','br','hr','input','label','select',
                             'textarea','option','td','th','li','dt','dd','p'];
        if (!in_array($tag, $skip_tags, true)) {
            if (!preg_match('/<' . preg_quote($tag, '/') . '[\s>\/]/i', $html)) return false;
        }
    }

    return true;
}

function clickfuzz_web_extract_brand_colors(array $pages, $logo_url = null, $api_key = '')
{
    $scores  = [];  // normalized_hex => int score
    $context = [];  // normalized_hex => string[]

    $add = function ($raw, $points, $note) use (&$scores, &$context) {
        $hex = clickfuzz_web_normalize_color($raw);
        if (!$hex) return;
        $scores[$hex]    = ($scores[$hex]    ?? 0) + $points;
        $context[$hex][] = $note;
    };

    // Scan homepage only for deterministic signals (first page = homepage).
    // Later pages rarely add brand-color evidence and risk dilution.
    $html = $pages[0]['html'] ?? '';

    // ── 1. theme-color / msapplication-TileColor meta ────────────────────────
    foreach ([
        '/<meta[^>]+name=["\'](?:theme-color|msapplication-TileColor)["\'][^>]*content=["\']([^"\']+)["\']/i',
        '/<meta[^>]+content=["\']([^"\']+)["\'][^>]*name=["\'](?:theme-color|msapplication-TileColor)["\']/i',
    ] as $pat) {
        if (preg_match($pat, $html, $m)) {
            $add($m[1], 5, 'theme-color meta');
            break;
        }
    }

    // ── 2. Platform palette JSON ──────────────────────────────────────────────

    // Squarespace: Static.SQUARESPACE_CONTEXT
    if (preg_match('/Static\.SQUARESPACE_CONTEXT\s*=\s*(\{.+?\});\s*<\/script>/s', $html, $m)) {
        $sq = json_decode($m[1], true) ?: [];
        foreach (['tweakColorPrimary','tweakColorSecondary','tweakColorHeader','tweakColorLink','tweakColorButton','accentColor'] as $k) {
            if (!empty($sq[$k])) $add($sq[$k], 5, 'Squarespace palette');
        }
    }

    // ── 3. Semantic CSS custom properties ─────────────────────────────────────
    // Named --primary, --brand, --accent, etc. in any <style> or inline style.
    preg_match_all(
        '/--(?:primary|brand|accent|main|key|corporate|theme|highlight|company)(?:-color|-bg|-background|-foreground)?\s*:\s*(#[0-9a-fA-F]{3,6}|rgb\([^)]+\))/i',
        $html, $sm
    );
    foreach ($sm[1] as $c) $add($c, 5, 'semantic CSS variable');

    // Elementor global colors
    preg_match_all('/--e-(?:global-)?color-(?:primary|accent|secondary|highlight)\s*:\s*(#[0-9a-fA-F]{3,6})/i', $html, $em);
    foreach ($em[1] as $c) $add($c, 5, 'Elementor color variable');

    // Divi accent colors
    preg_match_all('/--et_(?:accent|primary|secondary)_color\s*:\s*(#[0-9a-fA-F]{3,6})/i', $html, $dm);
    foreach ($dm[1] as $c) $add($c, 5, 'Divi color variable');

    // WordPress primary/secondary presets — may be customized, light supporting evidence only.
    // Cannot reach MEDIUM confidence alone; needs corroboration from page-specific signals.
    preg_match_all('/--wp--preset--color--(?:primary|secondary)\s*:\s*(#[0-9a-fA-F]{3,6})/i', $html, $wm_ps);
    foreach ($wm_ps[1] as $c) $add($c, 2, 'WordPress primary/secondary preset');

    // Generic WordPress named presets (vivid-red, vivid-green-cyan, cyan-bluish-gray, etc.) —
    // weak signal only. These are often default palette entries never modified by the site owner.
    // One occurrence alone cannot establish brand identity; only contributes when corroborated.
    preg_match_all('/--wp--preset--color--(?!(?:primary|secondary)\b)[\w-]+\s*:\s*(#[0-9a-fA-F]{3,6})/i', $html, $wm_gen);
    foreach ($wm_gen[1] as $c) $add($c, 1, 'WordPress color preset (generic)');

    // ── 4. Header/nav colors from inline styles ───────────────────────────────
    preg_match_all('/<(?:header|nav)\b[^>]*\bstyle=["\']([^"\']*)["\'][^>]*>/i', $html, $hm);
    foreach ($hm[1] as $style) {
        if (preg_match('/background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6}|rgb\([^)]+\))/i', $style, $cm)
            && stripos($cm[0], 'url(') === false) {
            $add($cm[1], 4, 'header/nav inline background');
        }
    }

    // ── 5. CSS rules targeting header/nav/button elements ─────────────────────
    preg_match_all('/<style[^>]*>(.*?)<\/style>/si', $html, $sb);
    $all_css = implode("\n", $sb[1]);

    // Header/nav selectors — validate target exists in markup before scoring
    preg_match_all(
        '/(?:^|[}\s])((?:header|nav|\.header|\.nav|\.site-header|\.navbar|\.top-bar|#header|#nav)[\w\s,:.#\[\]()="\'*~+>-]*)\{([^}]+)\}/im',
        $all_css, $nav_rules, PREG_SET_ORDER
    );
    foreach ($nav_rules as $nav_rule) {
        if (!clickfuzz_web_css_target_in_html(trim($nav_rule[1]), $html)) continue;
        $body = $nav_rule[2];
        if (preg_match('/background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6}|rgb\([^)]+\))/i', $body, $cm)
            && stripos($cm[0], 'url(') === false) {
            $add($cm[1], 4, 'CSS header/nav background');
        }
    }

    // Button/CTA selectors — validate target exists in markup before scoring
    preg_match_all(
        '/(?:^|[}\s])((?:\.btn\b|\.button\b|\.cta\b|button\b)[\w\s,:.#\[\]()="\'*~+>-]*)\{([^}]+)\}/im',
        $all_css, $btn_rules, PREG_SET_ORDER
    );
    foreach ($btn_rules as $btn_rule) {
        if (!clickfuzz_web_css_target_in_html(trim($btn_rule[1]), $html)) continue;
        $body = $btn_rule[2];
        if (preg_match('/background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6}|rgb\([^)]+\))/i', $body, $cm)
            && stripos($cm[0], 'url(') === false) {
            $add($cm[1], 4, 'CSS button/CTA background');
        }
    }

    // ── 5b. General CSS rules — background-color and SVG fill with target validation ──
    // Broader than the specific header/nav/button signals above; lower weight (2pt).
    // Only scores when the selector target is confirmed present in the markup.
    preg_match_all(
        '/(?:^|[}\s])([^@{}\s][^{}]*)\{([^}]+)\}/im',
        $all_css, $gen_rules, PREG_SET_ORDER
    );
    foreach ($gen_rules as $gen_rule) {
        $body = $gen_rule[2];
        if (stripos($body, 'background') === false && stripos($body, 'fill') === false) continue;
        $selector = trim($gen_rule[1]);
        if (!clickfuzz_web_css_target_in_html($selector, $html)) continue;
        if (preg_match('/background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6}|rgb\([^)]+\))/i', $body, $cm)
            && stripos($cm[0], 'url(') === false) {
            $_hex = clickfuzz_web_normalize_color($cm[1]);
            if ($_hex && !clickfuzz_web_is_neutral_color($_hex)) $add($_hex, 2, 'CSS validated background');
        }
        if (preg_match('/\bfill\s*:\s*(#[0-9a-fA-F]{3,6})/i', $body, $cm)) {
            $_hex = clickfuzz_web_normalize_color($cm[1]);
            if ($_hex && !clickfuzz_web_is_neutral_color($_hex)) $add($_hex, 2, 'CSS validated fill');
        }
    }

    // ── 6. Body/html background — root surface for visual character ───────────
    // Captured in $bg_colors separately; does NOT feed into brand-color scoring.
    // Root/surface backgrounds carry the most significance for visual character.
    $bg_colors = [];
    preg_match_all(
        '/(?:^|[}\s])(?:html\b|body\b)[\w\s,:.#\[\]()="\'*~+>-]*\{([^}]+)\}/im',
        $all_css, $bg_rules
    );
    foreach ($bg_rules[1] as $rule_body) {
        if (preg_match('/background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6}|rgb\([^)]+\))/i', $rule_body, $cm)
            && stripos($cm[0], 'url(') === false) {
            $hex = clickfuzz_web_normalize_color($cm[1]);
            if ($hex) $bg_colors[] = $hex;
        }
    }
    // Also capture body inline style attribute
    if (preg_match('/<body\b[^>]+\bstyle=["\']([^"\']*)["\'][^>]*>/i', $html, $bm)) {
        if (preg_match('/background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6}|rgb\([^)]+\))/i', $bm[1], $cm)
            && stripos($cm[0], 'url(') === false) {
            $hex = clickfuzz_web_normalize_color($cm[1]);
            if ($hex) $bg_colors[] = $hex;
        }
    }

    // Snapshot all scored colors before neutral filtering — used for visual character analysis.
    $all_scored = $scores;

    // Build char_scored: all_scored minus colors whose ONLY evidence is the WP generic preset.
    // Generic WP presets are default Gutenberg palette entries never reflecting actual site appearance.
    // Colors that also carry real evidence (nav bg, semantic variable, etc.) are kept.
    $char_scored = array_filter($all_scored, static function ($hex) use ($context) {
        foreach ($context[$hex] ?? [] as $note) {
            if ($note !== 'WordPress color preset (generic)') return true;
        }
        return false;
    }, ARRAY_FILTER_USE_KEY);

    // ── Neutral filtering ─────────────────────────────────────────────────────
    // Exclude near-neutrals unless they scored >= 8 (strong semantic evidence for monochrome brand).
    $filtered = [];
    foreach ($scores as $hex => $score) {
        if (clickfuzz_web_is_neutral_color($hex) && $score < 8) continue;
        $filtered[$hex] = $score;
    }
    arsort($filtered);

    // ── Confidence: Phase A ───────────────────────────────────────────────────
    $top = array_values($filtered);
    $confidence = 'low';
    if (!empty($top)) {
        if ($top[0] >= 6)      $confidence = 'high';
        elseif ($top[0] >= 3)  $confidence = 'medium';
    }

    // ── Phase B: Logo vision fallback ─────────────────────────────────────────
    // Only when Phase A is insufficient AND a confirmed logo is available.
    // Completely independent of the ambiguous-image candidate pipeline.
    // $logo_raw receives ALL logo colors (including near-neutrals) for visual character analysis.
    // Only non-neutral logo colors contribute to brand confidence and primary/accent selection.
    $logo_vision_used = false;
    $logo_raw         = [];
    if ($confidence === 'low' && $logo_url && $api_key) {
        $logo_raw = clickfuzz_web_logo_color_vision($logo_url, $api_key);
        foreach ($logo_raw as $hex) {
            if (clickfuzz_web_is_neutral_color($hex)) continue; // skip neutrals for confidence
            $filtered[$hex] = ($filtered[$hex] ?? 0) + 4;
            $context[$hex][] = 'confirmed logo';
        }
        $logo_for_confidence = array_filter($logo_raw, static function ($h) {
            return !clickfuzz_web_is_neutral_color($h);
        });
        if (!empty($logo_for_confidence)) {
            arsort($filtered);
            $top = array_values($filtered);
            if (!empty($top) && $top[0] >= 4) {
                $confidence       = 'medium';
                $logo_vision_used = true;
            }
        }
    }

    // ── Select primary and accent ─────────────────────────────────────────────
    $primary = [];
    $accent  = [];
    $i       = 0;
    foreach (array_keys($filtered) as $hex) {
        if ($i === 0) {
            $primary[] = $hex;
        } elseif ($i === 1) {
            // Only promote to accent if hue differs meaningfully from primary (avoids shade duplicates)
            $ph = clickfuzz_web_color_hsl($primary[0]);
            $ah = clickfuzz_web_color_hsl($hex);
            $diff = abs($ph[0] - $ah[0]);
            if ($diff > 180) $diff = 360 - $diff;
            if ($diff > 20 || ($filtered[$hex] ?? 0) >= 4) {
                $accent[] = $hex;
            }
        }
        $i++;
        if ($i >= 3) break;
    }

    // Per-color evidence gate: strip any color whose ONLY evidence is generic
    // WordPress preset declarations. Such colors may exist in $filtered from
    // supporting a legitimate primary, but cannot independently earn palette inclusion.
    $weak_only = static function ($hex) use ($context) {
        $notes = $context[$hex] ?? [];
        if (empty($notes)) return true;
        foreach ($notes as $note) {
            if ($note !== 'WordPress color preset (generic)') return false;
        }
        return true;
    };
    $primary = array_values(array_filter($primary, static function ($h) use ($weak_only) { return !$weak_only($h); }));
    $accent  = array_values(array_filter($accent,  static function ($h) use ($weak_only) { return !$weak_only($h); }));

    // Build concise evidence string
    $ev_parts = [];
    foreach (array_merge($primary, $accent) as $hex) {
        foreach (array_slice(array_unique($context[$hex] ?? []), 0, 2) as $note) {
            $ev_parts[] = $note;
        }
    }
    $evidence = implode(' + ', array_unique($ev_parts)) ?: 'insufficient evidence';
    if ($logo_vision_used) {
        $evidence .= ($evidence && $evidence !== 'insufficient evidence' ? ' + ' : '') . 'confirmed logo';
    }

    // Derive broad visual character using filtered evidence (WP generic presets excluded).
    $visual_character = clickfuzz_web_visual_character($bg_colors, $char_scored, $logo_raw);

    return [
        'confidence'       => $confidence,
        'primary'          => $primary,
        'accent'           => $accent,
        'evidence'         => $evidence,
        'logo_vision_used' => $logo_vision_used,
        'visual_character' => $visual_character,
    ];
}

/**
 * Call vision API to extract dominant brand colors from a confirmed canonical logo.
 * This is a standalone function, NOT part of the ambiguous-image candidate pipeline.
 * Returns array of normalized hex strings, or [] on failure/no colors found.
 */
function clickfuzz_web_logo_color_vision($logo_url, $api_key)
{
    if (!$logo_url || !$api_key) return [];

    $instruction = "This is a confirmed company logo.\n"
                 . "List the 1-3 most visually dominant non-white, non-black brand colors present.\n"
                 . "Respond with ONLY a JSON array of hex color strings. Example: [\"#1B3A6B\",\"#E8821A\"]\n"
                 . "If the logo is purely black and white, respond: []\n"
                 . "No explanation. No markdown.";

    $payload = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 80,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'text',  'text'   => $instruction],
                ['type' => 'image', 'source' => ['type' => 'url', 'url' => $logo_url]],
            ],
        ]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . trim($api_key),
            'anthropic-version: 2023-06-01',
        ],
    ]);

    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $usage = [];
    $data  = json_decode($raw ?: '{}', true);
    if (!empty($data['usage'])) {
        $usage = $data['usage'];
        if (function_exists('log_activity')) {
            log_activity('ClickFuzz Web logo color vision: input=' . ($usage['input_tokens'] ?? 0)
                . ' output=' . ($usage['output_tokens'] ?? 0) . ' tokens');
        }
    }
    curl_close($ch);

    if (!$raw || $code !== 200) return [];

    $text = trim($data['content'][0]['text'] ?? '');
    if (preg_match('/```(?:json)?\s*(\[.*?\])\s*```/s', $text, $m)) $text = $m[1];
    $colors = json_decode($text, true);
    if (!is_array($colors)) return [];

    // Return all valid normalized hex values — including near-neutrals.
    // The caller (clickfuzz_web_extract_brand_colors) separates neutral colors:
    // they contribute to visual character analysis but not to brand confidence scoring.
    $result = [];
    foreach ($colors as $c) {
        $hex = clickfuzz_web_normalize_color((string)$c);
        if ($hex) $result[] = $hex;
    }
    return $result;
}
