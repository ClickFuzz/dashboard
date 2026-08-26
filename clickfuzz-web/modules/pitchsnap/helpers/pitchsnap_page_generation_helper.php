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

    // Store generation record
    $gen_id = $CI->pitchsnap_model->create_page_generation($page->id, $page->site_id, [
        'html_content'            => $parsed['body_html'],
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

    // Main site HTML for design reference (strip scripts/heavy tags, take first 8000 chars)
    $main_html_snippet = '';
    if ($redesign && !empty($redesign->generated_html)) {
        $stripped = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $redesign->generated_html);
        $stripped = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $stripped);
        $main_html_snippet = substr(trim($stripped), 0, 8000);
    }

    // Parent page context
    $parent_context = '';
    foreach ($parent_pages as $pp) {
        $parent_context .= '- ' . $pp->title . ' (/' . $pp->slug . ")\n";
    }

    // Media context
    $media_lines = [];
    foreach ($page_media as $m) {
        $url  = rtrim(base_url(), '/') . '/media/' . (int) $m->site_id . '/' . rawurlencode($m->filename);
        $desc = $m->alt_text ?: ($m->title ?: $m->original_filename);
        $media_lines[] = '- ' . $url . ' (' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . ')';
    }
    $media_context = implode("\n", $media_lines);

    // Type-specific instructions
    $type_instructions = clickfuzz_web_get_page_type_instructions($page->page_type);

    // Supporting keywords
    $supporting_kw = !empty($page->supporting_keywords) ? $page->supporting_keywords : 'None';

    // Navigation context
    $nav_notes = [];
    if (!empty($page->menu_primary)) $nav_notes[] = 'This page appears in the primary navigation as "' . ($page->menu_label ?: $page->title) . '".';
    if (!empty($page->menu_footer))  $nav_notes[] = 'This page appears in the footer navigation.';
    $nav_context = $nav_notes ? implode(' ', $nav_notes) : 'This page is not in any navigation menu.';

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

## Available Media (use these image URLs in the HTML)
{$media_context}

## Design Reference — Main Website HTML Excerpt
Match the design, color palette, typography, and component style of the main website:

```html
{$main_html_snippet}
```

## Output Format

Respond ONLY with the following XML-like delimited sections. Do not include any other text, explanation, or markdown outside these tags.

<body_html>
The complete HTML for the <body> content of this page. Include all sections, navigation (matching the main site's nav), and footer. Do NOT include <html>, <head>, or <body> tags — only the inner content that would go inside <body>. Use inline styles or a <style> tag at the top if needed. Optimize for the primary keyword. Include the phone number and business name prominently.
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
