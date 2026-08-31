<?php
defined('BASEPATH') or exit('No direct script access allowed');

// ---------------------------------------------------------------------------
// Readiness check
// ---------------------------------------------------------------------------

/**
 * Returns true if the page has the minimum fields required to generate.
 * Requirements: title, slug, page_type, and at least one of primary_keyword or instructions.
 */
function clickfuzz_web_page_is_ready_to_generate($page)
{
    if (empty($page->title))     return false;
    if (empty($page->slug))      return false;
    if (empty($page->page_type)) return false;
    if (empty($page->primary_keyword) && empty($page->instructions)) return false;
    return true;
}

// ---------------------------------------------------------------------------
// Queue (called by controller when admin clicks Generate)
// ---------------------------------------------------------------------------

/**
 * Validates readiness and atomically sets generation_status='generating'.
 * Returns ['success'=>true] or ['success'=>false, 'error'=>string].
 */
function clickfuzz_web_queue_page_generation($page_id)
{
    $CI =& get_instance();

    $page = $CI->pitchsnap_model->get_page((int) $page_id);
    if (!$page) {
        return ['success' => false, 'error' => 'Page not found.'];
    }
    if ($page->status === 'trash') {
        return ['success' => false, 'error' => 'Cannot generate a trashed page.'];
    }
    if ($page->generation_status === 'generating') {
        return ['success' => false, 'error' => 'Page generation is already in progress.'];
    }
    if (!clickfuzz_web_page_is_ready_to_generate($page)) {
        return ['success' => false, 'error' => 'Page is missing required fields (title, slug, type, and keyword or instructions).'];
    }

    $claimed = $CI->pitchsnap_model->queue_page_for_generation((int) $page_id);
    if (!$claimed) {
        return ['success' => false, 'error' => 'Could not queue page — it may already be generating or in an invalid state.'];
    }

    return ['success' => true];
}

// ---------------------------------------------------------------------------
// Cron entry point — generate a single page (Anthropic sync)
// ---------------------------------------------------------------------------

/**
 * Generates a page and stores the result in tblpitchsnap_page_generations.
 * Called by the cron runner for each page with generation_status='generating'.
 *
 * @param  object $page  Row from tblpitchsnap_pages
 */
function clickfuzz_web_generate_page($page)
{
    $CI =& get_instance();

    // Load required libraries
    if (!isset($CI->pitchsnap_anthropic)) {
        require_once FCPATH . 'modules/pitchsnap/libraries/Pitchsnap_anthropic.php';
        $CI->pitchsnap_anthropic = new Pitchsnap_anthropic();
    }

    // Gather context
    $site     = $CI->pitchsnap_model->get_site_by_id($page->site_id);
    $lead     = null;
    $redesign = null;

    if ($site) {
        if (!empty($site->source_lead_id)) {
            $lead = $CI->db->where('id', (int) $site->source_lead_id)
                ->get(db_prefix() . 'leads')->row();
        }
        if (!empty($site->source_website_id)) {
            $redesign = $CI->pitchsnap_model->get((int) $site->source_website_id);
        }
    }

    $parent_pages = [];
    if (!empty($page->parent_page_id)) {
        $parent = $CI->pitchsnap_model->get_page((int) $page->parent_page_id);
        if ($parent) {
            $parent_pages[] = $parent;
        }
    }

    $page_media = $CI->pitchsnap_model->get_media_for_page((int) $page->id);

    // Build prompt
    $prompt = clickfuzz_web_build_page_prompt($page, $site, $lead, $redesign, $parent_pages, $page_media);

    // Call Anthropic
    $result = $CI->pitchsnap_anthropic->generate($prompt);

    if (!$result['success']) {
        $CI->pitchsnap_model->mark_page_generation_failed($page->id, $result['error'] ?? 'Anthropic returned an error.');
        log_activity('ClickFuzz Web: Page generation failed [Page #' . $page->id . '] ' . ($result['error'] ?? ''));
        return;
    }

    // Extract structured output from the AI response
    $parsed = clickfuzz_web_extract_page_output($result['result']);

    if (empty($parsed['body_html'])) {
        $CI->pitchsnap_model->mark_page_generation_failed($page->id, 'AI response did not contain a <body_html> block.');
        log_activity('ClickFuzz Web: Page generation parse failed [Page #' . $page->id . ']');
        return;
    }

    // Strip document wrappers, then remove the site chrome the AI was asked to include.
    // Storing only the page-specific middle content prevents double chrome at render time.
    $normalized_body = clickfuzz_web_normalize_page_body_html($parsed['body_html']);
    $normalized_body = clickfuzz_web_strip_page_chrome($normalized_body);
    if (empty($normalized_body)) {
        $CI->pitchsnap_model->mark_page_generation_failed($page->id, 'Body content was empty after normalization.');
        log_activity('ClickFuzz Web: Page generation normalization produced empty body [Page #' . $page->id . ']');
        return;
    }

    // Store generation record
    $gen_id = $CI->pitchsnap_model->create_page_generation($page->id, $page->site_id, [
        'html_content'            => $normalized_body,
        'css_content'             => $parsed['page_css'] ?? '',
        'js_content'              => $parsed['page_js'] ?? '',
        'meta_title_generated'    => $parsed['meta_title'] ?? '',
        'meta_description_generated' => $parsed['meta_description'] ?? '',
        'prompt_snapshot'         => $prompt,
        'is_current'              => 0,
        'status'                  => 'draft',
    ]);

    if (!$gen_id) {
        $CI->pitchsnap_model->mark_page_generation_failed($page->id, 'Failed to save generation record to database.');
        return;
    }

    // Mark this version as current
    $CI->pitchsnap_model->set_current_page_generation($page->id, $gen_id);

    // Update page status
    $CI->pitchsnap_model->mark_page_generation_success($page->id);

    log_activity('ClickFuzz Web: Page generated [Page #' . $page->id . ', Generation #' . $gen_id . ']');
}

// ---------------------------------------------------------------------------
// Site chrome extractor (Phase 3)
// ---------------------------------------------------------------------------

/**
 * Extracts the canonical site chrome (header, footer, design rules, color palette) from the
 * main generated HTML so page builders can match it without re-reading the whole file.
 *
 * @param  string $site_html  Full generated HTML of the main site
 * @return array  ['header_html', 'footer_html', 'design_rules', 'color_palette']
 */
function clickfuzz_web_page_builder_rules($site_html)
{
    $header_html   = '';
    $footer_html   = '';
    $design_rules  = '';
    $color_palette = '';

    if (empty($site_html)) {
        return compact('header_html', 'footer_html', 'design_rules', 'color_palette');
    }

    if (preg_match('/<header\b[^>]*>[\s\S]*?<\/header>/i', $site_html, $m)) {
        $header_html = $m[0];
    }

    if (preg_match('/<footer\b[^>]*>[\s\S]*?<\/footer>/i', $site_html, $m)) {
        $footer_html = $m[0];
    }

    $css_chunks = [];
    if (preg_match_all('/<style\b[^>]*>([\s\S]*?)<\/style>/i', $site_html, $m)) {
        foreach ($m[1] as $css) {
            $trimmed = trim($css);
            if ($trimmed !== '') {
                $css_chunks[] = $trimmed;
            }
        }
    }
    $all_css      = implode("\n\n", $css_chunks);
    $design_rules = substr($all_css, 0, 4000);

    $color_palette = _cfw_extract_color_palette($all_css, $header_html . $footer_html);

    return compact('header_html', 'footer_html', 'design_rules', 'color_palette');
}

/**
 * Extracts the most-used hex colors from CSS + key HTML chrome.
 * Returns a comma-separated string of up to 12 colors sorted by frequency.
 */
function _cfw_extract_color_palette($css, $chrome_html)
{
    $counts = [];
    if (preg_match_all('/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', $css . ' ' . $chrome_html, $m)) {
        foreach ($m[0] as $color) {
            $norm           = strtolower($color);
            $counts[$norm]  = ($counts[$norm] ?? 0) + 1;
        }
    }
    arsort($counts);
    $palette = [];
    foreach ($counts as $color => $freq) {
        if ($freq < 2) break;
        $palette[] = $color;
        if (count($palette) >= 12) break;
    }
    return $palette ? implode(', ', $palette) : '';
}

// ---------------------------------------------------------------------------
// Prompt construction
// ---------------------------------------------------------------------------

/**
 * Builds the full Anthropic prompt for a single internal page.
 *
 * @param  object      $page         tblpitchsnap_pages row
 * @param  object|null $site         tblpitchsnap_sites row
 * @param  object|null $lead         Perfex leads row
 * @param  object|null $redesign     tblpitchsnap_redesigns row (main website)
 * @param  array       $parent_pages Array of parent page rows
 * @param  array       $page_media   Array of media rows attached to this page
 * @return string
 */
function clickfuzz_web_build_page_prompt($page, $site, $lead, $redesign, $parent_pages, $page_media)
{
    $business_name = '';
    $phone         = '';
    $email         = '';
    $website_url   = '';
    $vertical      = '';

    if ($lead) {
        $business_name = !empty($lead->company) ? $lead->company : (isset($lead->name) ? $lead->name : '');
        $phone         = $lead->phonenumber ?? '';
        $email         = $lead->email ?? '';
    }
    if ($redesign) {
        $website_url = $redesign->source_url ?? '';
        $vertical    = $redesign->vertical ?? '';
    }

    // Extract canonical site chrome from the main generated HTML
    $chrome = ['header_html' => '', 'footer_html' => '', 'design_rules' => '', 'color_palette' => ''];
    if ($redesign && !empty($redesign->generation_result)) {
        $chrome = clickfuzz_web_page_builder_rules($redesign->generation_result);
    }

    // Parent page context
    $parent_context = '';
    foreach ($parent_pages as $pp) {
        $parent_context .= '- ' . $pp->title . ' (/' . $pp->slug . ")\n";
    }

    // Media context — numbered so prompt can reference "Image 1", "Image 2", etc.
    $media_lines = [];
    foreach (array_values($page_media) as $idx => $m) {
        $num  = $idx + 1;
        $url  = base_url('uploads/pitchsnap/media/' . (int) $m->site_id . '/' . rawurlencode($m->filename));
        $desc = $m->alt_text ?: ($m->title ?: $m->original_filename);
        $media_lines[] = 'Image ' . $num . ': ' . $url . ' (' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . ')';
    }
    $media_context = $media_lines ? implode("\n", $media_lines) : 'None provided.';

    // Video URL
    $video_url = !empty($page->video_url) ? $page->video_url : '';

    // Type-specific instructions
    $type_instructions = clickfuzz_web_get_page_type_instructions($page->page_type);

    // Supporting keywords
    $supporting_kw = !empty($page->supporting_keywords) ? $page->supporting_keywords : 'None';

    // Navigation context
    $nav_notes = [];
    if (!empty($page->menu_primary)) $nav_notes[] = 'This page appears in the primary navigation as "' . ($page->menu_label ?: $page->title) . '".';
    if (!empty($page->menu_footer))  $nav_notes[] = 'This page appears in the footer navigation.';
    $nav_context = $nav_notes ? implode(' ', $nav_notes) : 'This page is not in any navigation menu.';

    $header_html   = $chrome['header_html'];
    $footer_html   = $chrome['footer_html'];
    $design_rules  = $chrome['design_rules'];
    $color_palette = $chrome['color_palette'];

    $prompt = <<<PROMPT
You are an expert web designer creating an internal page for a local service business website.

## Business Information
- Business Name: {$business_name}
- Industry/Vertical: {$vertical}
- Website URL: {$website_url}
- Phone: {$phone}
- Email: {$email}

## Page Details
- Page Title: {$page->title}
- Page Slug: /{$page->slug}
- Page Type: {$page->page_type}
- Primary SEO Keyword: {$page->primary_keyword}
- Supporting Keywords: {$supporting_kw}
- Navigation: {$nav_context}

## Custom Instructions
{$page->instructions}

## Page Type Instructions
{$type_instructions}

## Parent Page Context
{$parent_context}

## Available Media
Reference images by number (e.g. "Image 1") in the content description, and use their URLs directly in <img> src attributes.
{$media_context}

## Video
{$video_url}

## Site Chrome — DO NOT RECREATE
The following header and footer are the canonical site chrome rendered on every page. Your body_html output will be placed BETWEEN them inside <main class="cf-page-content">. Match their design, colors, fonts, and component patterns exactly. Do NOT include them in your output.

### Site Header (rendered above your content)
```html
{$header_html}
```

### Site Footer (rendered below your content)
```html
{$footer_html}
```

## Brand Color Palette (extracted from the main site — use ONLY these colors)
{$color_palette}

CRITICAL: Do NOT introduce colors outside this palette for any new content you generate. Every new section background, text color, border, button, and accent must come from this palette.

## Design System (CSS from the main site — match these rules)
```css
{$design_rules}
```

## Output Format

Respond ONLY with the following XML-like delimited sections. Do not include any other text, explanation, or markdown outside these tags.

<body_html>
The page-specific content ONLY. This content will be placed inside <main class="cf-page-content"> between the site header and footer shown above.

IMPORTANT — DO NOT include:
- The site header or any <header> element
- The primary site navigation or any site-level <nav> element
- The site footer or any <footer> element
- <html>, <head>, or <body> tags

Generate only the unique sections for this specific page: hero/banner, content sections, feature lists, testimonials, FAQs, CTAs, etc. Use the design system CSS above to match the visual style (colors, typography, component patterns). Optimize for the primary keyword. Include the phone number and business name prominently.
</body_html>

<page_css>
Any additional CSS specific to this page (can be empty if styles are inlined above).
</page_css>

<page_js>
Any additional JavaScript specific to this page (can be empty).
</page_js>

<meta_title>
SEO-optimized page title (50-60 characters, include primary keyword and business name).
</meta_title>

<meta_description>
SEO meta description (150-160 characters, include primary keyword and a call to action).
</meta_description>
PROMPT;

    return $prompt;
}

// ---------------------------------------------------------------------------
// Page type instructions
// ---------------------------------------------------------------------------

function clickfuzz_web_get_page_type_instructions($page_type)
{
    $instructions = [
        'homepage' => 'Create a high-converting homepage for a local service business. Include: a bold hero section with headline, subheadline, and primary CTA button with the phone number; a services overview section highlighting 3-6 key services; a trust/about section with the business story and credentials; a prominent CTA strip with the phone number; and a contact section. The hero headline must contain the primary keyword. Optimize for local SEO and conversions. This is the most important page — make it distinctive and compelling.',

        'about' => 'Create a compelling About page that builds trust and tells the business story. Include: company history and founding story, mission and values, team introduction (use placeholder names if no media provided), service area, years of experience, and certifications or awards. End with a clear call-to-action.',

        'service' => 'Create a detailed Service page for a specific service offering. Include: a clear headline with the service name and primary keyword, service description (what it is, how it works, what\'s included), benefits to the customer, process/steps, pricing guidance (if applicable), FAQ section with 3-5 common questions, and a prominent call-to-action with the business phone number.',

        'service_area' => 'Create a local SEO-optimized Service Area page. Include: an introduction mentioning the city/area and primary service, why the business serves this area, local landmarks or neighborhoods (use the location from the keyword), service list for this area, response time or availability, a Google Maps embed placeholder (use a div with a comment), and a call-to-action. Optimize heavily for local keywords.',

        'contact' => 'Create a Contact page that converts visitors. Include: multiple contact methods (phone prominent at top, email, address if applicable), business hours, a simple contact form (name, email, phone, message fields), response time promise, service area mention, and a map embed placeholder. Make the phone number clickable with tel: links.',

        'gallery' => 'Create a Gallery or Portfolio page showcasing the business\'s work. Include: an intro section explaining what visitors will see, a responsive image grid using any provided media URLs (create placeholder divs if no media), project captions or descriptions, before/after sections if applicable, a call-to-action after the gallery. Organize images into logical categories if multiple media items are provided.',

        'financing' => 'Create a Financing Options page that removes purchase barriers. Include: headline emphasizing affordability, financing options description (monthly payments, no-interest periods, easy approval), how the process works (3-4 steps), benefits of financing, eligibility requirements (general), an application CTA, and a disclaimer that financing is subject to credit approval. Use trust signals like partner logos (placeholder).',

        'faq' => 'Create a comprehensive FAQ page. Include: 8-12 frequently asked questions relevant to the page type and primary keyword, organized into logical sections (e.g., About Our Services, Pricing & Payment, Scheduling, Results). Use an accordion-style layout with CSS. Include schema.org FAQPage markup in a <script type="application/ld+json"> block. End with a CTA to contact for more questions.',

        'custom' => 'Create a professional, well-structured custom page based on the page title and custom instructions provided. Follow the design style of the main website. Include appropriate sections, clear headings, body copy that incorporates the primary keyword naturally, and a call-to-action.',
    ];

    return $instructions[$page_type] ?? $instructions['custom'];
}

// ---------------------------------------------------------------------------
// Body-only normalization — safety net for incorrectly structured AI output
// ---------------------------------------------------------------------------

/**
 * Strips document wrappers and site-level chrome from AI-generated page content.
 *
 * Conservative rules:
 *  - Document wrappers (DOCTYPE / html / head / body) are always stripped.
 *  - If AI correctly used <main class="cf-page-content"> wrapper, extract only its inner content.
 *  - Leading <header> or <nav> block is stripped only when it is the very first element.
 *  - Trailing <footer> block is stripped only when it is the very last element.
 *  - Elements in the middle of the content are NEVER touched.
 *
 * @param  string $html  Raw AI-extracted body_html
 * @return string        Normalised page body content
 */
function clickfuzz_web_normalize_page_body_html($html)
{
    if (empty($html)) { return $html; }

    // 1. Strip document-level wrappers — always invalid in page body content
    $html = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);
    $html = preg_replace('/<html[^>]*>/i',      '', $html);
    $html = preg_replace('/<\/html\s*>/i',       '', $html);
    $html = preg_replace('/<head[^>]*>[\s\S]*?<\/head>/i', '', $html);
    $html = preg_replace('/<body[^>]*>/i',       '', $html);
    $html = preg_replace('/<\/body\s*>/i',        '', $html);
    $html = trim($html);

    if (empty($html)) { return $html; }

    // 2. If AI correctly wrapped content in <main class="cf-page-content">, extract inner content.
    //    This is the clean path — the prompt specifically requests this wrapper.
    if (preg_match('/<main[^>]+class=["\'][^"\']*cf-page-content[^"\']*["\'][^>]*>([\s\S]*?)<\/main>/i', $html, $m)) {
        return trim($m[1]);
    }

    // 3. Strip leading <header>...</header> (site-level header at position 0 only)
    if (preg_match('/^\s*<header[\s>]/i', $html)) {
        $html = preg_replace('/^\s*<header[\s\S]*?<\/header>/i', '', $html, 1);
        $html = trim($html);
    }

    // 4. Strip leading CF nav block or <nav> at position 0 (site primary nav only)
    $cf_nav_start = '<!-- cf-nav-start -->';
    $cf_nav_end   = '<!-- cf-nav-end -->';
    $trimmed      = ltrim($html);
    if (strncmp($trimmed, $cf_nav_start, strlen($cf_nav_start)) === 0) {
        $html = preg_replace(
            '/' . preg_quote($cf_nav_start, '/') . '[\s\S]*?' . preg_quote($cf_nav_end, '/') . '/s',
            '', $trimmed, 1
        );
        $html = trim($html);
    } elseif (preg_match('/^\s*<nav[\s>]/i', $html)) {
        $html = preg_replace('/^\s*<nav[\s\S]*?<\/nav>/i', '', $html, 1);
        $html = trim($html);
    }

    // 5. Strip trailing <footer>...</footer> (site footer at position-end only)
    if (preg_match('/<\/footer>\s*$/i', $html)) {
        $pos = 0;
        $last_footer = false;
        while (($found = stripos($html, '<footer', $pos)) !== false) {
            $next = isset($html[$found + 7]) ? $html[$found + 7] : '';
            if ($next === '>' || ctype_space($next)) {
                $last_footer = $found;
            }
            $pos = $found + 1;
        }
        if ($last_footer !== false) {
            $html = trim(substr($html, 0, $last_footer));
        }
    }

    return $html;
}

/**
 * Strips site chrome (header/footer elements) from AI-generated page body content.
 *
 * The page generation prompt instructs the AI to include the site header and footer
 * verbatim so it can match the design. This function removes those elements before
 * storage so render_full_page_html() can add the correct chrome (baked-in or SSI)
 * at publish/preview time without duplication.
 *
 * Uses element boundaries rather than exact string matching, so minor AI whitespace
 * variations do not break the strip.
 *
 * @param  string $body_html  Normalized body content from the AI (no doc wrappers)
 * @return string             Page-specific content only, with site chrome removed
 */
function clickfuzz_web_strip_page_chrome($body_html)
{
    $result = trim($body_html);
    $lower  = strtolower($result);

    // Strip site header: everything up to and including the first </header>
    if (($pos = strpos($lower, '</header>')) !== false) {
        $result = substr($result, $pos + strlen('</header>'));
        $lower  = strtolower($result);
    }

    // Strip site footer: everything from the last <footer to the end
    if (($pos = strrpos($lower, '<footer')) !== false) {
        $result = substr($result, 0, $pos);
    }

    return trim($result);
}

// ---------------------------------------------------------------------------
// Output extraction
// ---------------------------------------------------------------------------

/**
 * Extracts structured sections from the AI's response.
 * Expects XML-like delimiter blocks: <body_html>, <page_css>, <page_js>, <meta_title>, <meta_description>.
 *
 * @param  string $raw  Raw AI response text
 * @return array  Keys: body_html, page_css, page_js, meta_title, meta_description
 */
function clickfuzz_web_extract_page_output($raw)
{
    $result = [
        'body_html'        => '',
        'page_css'         => '',
        'page_js'          => '',
        'meta_title'       => '',
        'meta_description' => '',
    ];

    $tags = ['body_html', 'page_css', 'page_js', 'meta_title', 'meta_description'];
    foreach ($tags as $tag) {
        if (preg_match('/<' . $tag . '>([\s\S]*?)<\/' . $tag . '>/i', $raw, $m)) {
            $result[$tag] = trim($m[1]);
        }
    }

    // Fallback: if no body_html delimiter, treat the entire response as body content
    if (empty($result['body_html']) && strlen(trim($raw)) > 200) {
        // Strip markdown fences if present
        $stripped = trim($raw);
        if (preg_match('/^```(?:html)?\s*([\s\S]+?)\s*```$/i', $stripped, $m)) {
            $stripped = $m[1];
        }
        $result['body_html'] = $stripped;
    }

    return $result;
}
