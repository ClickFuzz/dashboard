# ClickFuzz Web — WordPress Conversion Contract

This document defines the contract a ClickFuzz-generated site must satisfy
for deterministic conversion to a classic WordPress theme, and the rules the
converter must follow.

---

## Generated-site conversion contract

A ClickFuzz-generated site is a **single-file HTML document** stored in the
`generation_result` column of `tbl_pitchsnap_redesigns`. The converter reads
this column directly; no deployed files are required.

### Expected HTML structure

```
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" ...>
  <title>...</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?...">
  <style>/* all CSS inline */</style>
</head>
<body>
  <header>...</header>
  <!-- one or more <section> or <main> blocks -->
  <footer>...</footer>
  <script src="https://clickfuzz.com/dashboard/pitchsnap/runtime.js" ...></script>
</body>
</html>
```

The converter requires at minimum:

- A `<head>` section containing at least one `<style>` block.
- A `<header>` element in the body (visual page header / nav).
- A `<footer>` element in the body (visual page footer).
- At least 500 bytes of HTML content.

### Known ClickFuzz runtime elements

| Element | Converter behaviour |
|---|---|
| `<span data-pitchsnap-current-year></span>` | Replaced with `<?php echo esc_html(date('Y')); ?>` |
| `<script src=".../pitchsnap/runtime.js" ...>` | Stripped; recorded as `runtime_dependency` + warning |
| `<meta name="robots" content="noindex...">` | Stripped (preview-only artefact; WordPress controls indexing) |
| Google Fonts `<link>` tags | Enqueued via `wp_enqueue_style()` in `functions.php` |
| Inline `<style>` block(s) | Extracted to `assets/css/theme.css`; enqueued in `functions.php` |

---

## Conversion guardrails

- Preserve the exact visual design.
- Preserve existing HTML structure unless WordPress integration requires a change.
- Never replace the design with a generic WordPress theme.
- Never introduce Bootstrap, Tailwind, Elementor, Gutenberg builders, jQuery, or
  other frameworks solely for conversion.
- Do not introduce new third-party dependencies unless the original site already
  depends on them and they are required.
- Preserve responsive behaviour.
- Preserve animations and JavaScript behaviour.
- Preserve SVGs.
- Preserve fonts where legally/localistically available.
- Preserve SEO metadata.
- Preserve structured data.
- Preserve accessibility attributes.
- Preserve form markup unless ClickFuzz Web has an explicit WordPress form
  replacement.
- Never invent business information.
- Never rewrite marketing copy during conversion.
- Never replace real images with placeholders.
- Never hotlink images that are already local.
- Conversion must be repeatable/idempotent.
- Generated PHP must contain no syntax errors (`php -l` passes on every file).
- WordPress paths must never be hard-coded to a specific installation directory
  or domain.
- Escape dynamic WordPress output appropriately (`esc_url()`, `esc_html()`, etc.).
- Do not modify unrelated ClickFuzz Web source files.

---

## Architecture principle

**ClickFuzz = presentation. WordPress = CMS infrastructure.**

- WordPress owns: URLs/slugs, page records, menus, blog, SEO plugins, Gutenberg, Elementor (optional).
- ClickFuzz owns: generated homepage HTML, generated service/landing page HTML, page-specific CSS/assets.
- The AI retains full creative freedom — no Gutenberg blocks, no Elementor schemas, no predefined section types.
- Elementor is NOT required and NOT installed by the exporter. It works normally for any page that doesn't use the ClickFuzz template.

---

## Theme output structure

```
clickfuzz-generated-{site-slug}/
├── style.css                       # WordPress theme header only
├── functions.php                   # Enqueue styles/scripts, nav menus, page asset hook
├── header.php                      # Doctype → wp_head() → body_class() → visual header
│                                     (wp_nav_menu primary falls back to static nav)
├── footer.php                      # Visual footer → wp_footer() → /body /html
│                                     (optional footer wp_nav_menu when source has footer nav)
├── front-page.php                  # Homepage: get_header + AI main content + get_footer
├── page.php                        # Dual-mode: AI page (via _clickfuzz_generated_page meta)
│                                     OR normal Gutenberg page (the_title + the_content)
├── index.php                       # Blog list (WordPress Loop)
├── single.php                      # Single blog post
├── archive.php                     # Category / date archives
├── 404.php                         # Not-found template
└── assets/
    └── css/
        └── theme.css               # Extracted from <style> block in generated HTML
```

---

## Homepage content strategy

Homepage design lives inside `front-page.php` as static PHP/HTML — theme-managed, AI-authored.
WordPress provides blogging, admin, plugins, and SEO tooling.
The homepage is NOT converted to Gutenberg blocks or Elementor. This is intentional.
The AI can modify the homepage HTML in future without being constrained by a page-builder schema.

---

## WordPress navigation

The theme registers menu locations via `register_nav_menus()`:
- **`primary`** — always registered; nav links extracted from the header HTML and imported as a WXR nav_menu.
- **`footer`** — registered only when the source site's footer contains a distinct navigation list.

`header.php` uses:
```php
if (has_nav_menu('primary')) {
    wp_nav_menu(['theme_location' => 'primary', ...]);
} else {
    // static generated nav (fallback until WP menu is assigned)
}
```

After import, the user assigns the imported "Primary Menu" to the Primary location in Appearance → Menus.

---

## AI-generated WordPress Pages

Future ClickFuzz-generated pages (service pages, city pages, landing pages) use the
**standard `page.php`** template with a post meta marker — no special template file required.

Each generated page is a normal WordPress `Page` with:
- Standard post ID, slug, permalink, status, SEO meta (works with Yoast, RankMath, etc.)
- Default page template (no `_wp_page_template` override needed)
- Marker meta `_clickfuzz_generated_page = 1` — triggers the AI rendering path in `page.php`
- Generated body HTML stored in post meta `_clickfuzz_generated_html` (body sections only)
- Generated page CSS stored in post meta `_clickfuzz_generated_css` (optional, inline)
- Generated page JS stored in post meta `_clickfuzz_generated_js` (optional, inline)
- File assets (optional) at `uploads/clickfuzz/pages/{page_id}/style.css|script.js`

The `page.php` template detects the marker and:
1. Calls `get_header()` — inherits shared nav, branding, global CSS.
2. Strips PHP tags from HTML/CSS/JS (security boundary — generated content is HTML only).
3. Outputs page-specific inline CSS from `_clickfuzz_generated_css` meta.
4. Outputs AI HTML from `_clickfuzz_generated_html` meta inside `<main class="cfw-generated-page">`.
5. Outputs optional inline JS from `_clickfuzz_generated_js` meta.
6. Calls `get_footer()` — inherits shared footer.

Without the marker, `page.php` renders via the normal WordPress Loop (`the_title` + `the_content`),
preserving full Gutenberg/Elementor compatibility for all non-ClickFuzz pages.

The page template also auto-enqueues `uploads/clickfuzz/pages/{id}/style.css` and `script.js`
via `cfw_enqueue_generated_page_assets()` in `functions.php` (file-based assets only loaded
for pages with the `_clickfuzz_generated_page` marker).

**Normal WordPress Pages** (`page.php`) continue to use the Gutenberg editor as normal.
Only pages explicitly assigned the ClickFuzz Generated Page template use the AI renderer.

---

## Page template routing

```
front-page.php  ← WordPress front page setting + theme controls (AI homepage)
page.php        ← All WordPress Pages:
                   • _clickfuzz_generated_page = 1  → AI rendering path
                   • (no marker)                    → Normal Gutenberg/Loop path
single.php      ← Blog posts
index.php / archive.php  ← Blog archives
```

Elementor overrides `page.php` for Elementor-built pages when the plugin is active.
ClickFuzz Generated Pages (those with `_clickfuzz_generated_page = 1`) are unaffected by Elementor
because Elementor only activates when its own page builder data is present.

---

## Future AI page generation workflow

A future ClickFuzz Web action will:
1. Take a page brief (purpose, topic, city, conversion goal).
2. Pass the brief + site context (business info, brand colors, typography) to Anthropic/Manus.
3. Receive back: HTML body + page-specific CSS (no doctype, no `<html>`, no `<head>`, no global header/footer).
4. Create a WordPress Page via REST API or direct DB insert.
5. Store the HTML in `_cfw_generated_html`, CSS in `_cfw_generated_css`.
6. Set `_wp_page_template = page-clickfuzz-generated.php` and `_cfw_generated = 1`.
7. Upload any page file assets to `uploads/clickfuzz/pages/{page_id}/`.
8. Publish or save as draft.

**AI page output contract**: The AI receives brand/style context from the existing theme/homepage
and returns only the page body (sections + content). It must NOT generate:
- `<!DOCTYPE html>`, `<html>`, `<head>`
- `<header>`, `<nav>`, `<footer>` — these are inherited from the theme
- The ClickFuzz runtime script tag (managed by a future ClickFuzz plugin)

Page-specific CSS is stored in `_clickfuzz_generated_css` and page-specific JS in `_clickfuzz_generated_js`.
Both are stripped of PHP tags before output. The AI has full creative freedom over layout, section
structure, and visual design within these constraints.

The AI retains full freedom over layout, section structure, and visual design within those constraints.

---

## Blog support

Blog templates (`index.php`, `single.php`, `archive.php`) inherit the shared header/footer
and theme CSS. They use normal WordPress Loop patterns and `the_content()`.

---

## WXR content file

`clickfuzz-content.xml` is a valid WordPress WXR 1.2 file containing:
- A published Home Page record
- A `nav_menu` term for the Primary Menu
- `nav_menu_item` posts for each link extracted from the header nav
- Anchor links (`#services`), tel:, mailto:, external, and internal links are preserved as custom links
- Optional Footer Menu term + items when the source has a distinct footer nav
- No invented Pages for anchor-linked sections

---

## Runtime dependencies

If the generated HTML references ClickFuzz runtime scripts or features that
cannot be made portable, the converter records them in `manifest.json` under
`runtime_dependencies` and adds a human-readable `warning`. It never silently
drops them.

---

## Export package structure

```
clickfuzz-{slug}-wordpress.zip
└── clickfuzz-{slug}-wordpress/
    ├── theme/
    │   └── clickfuzz-generated-{slug}.zip   # Install via WP Appearance → Themes
    ├── content/
    │   └── clickfuzz-content.xml            # Import via WP Tools → Import
    ├── media/                               # Reserved; empty in V1
    ├── manifest.json
    └── README.txt
```

---

## Export storage

Exports are stored at:

```
{server_root}/exports/wordpress/{website_id}/clickfuzz-{slug}-wordpress.zip
```

This mirrors the `previews/` and `sites/` sibling-directory pattern. Each new
successful export overwrites the previous ZIP for that `website_id`.

---

## Phase notes

- **Phase 1**: Helper library, HTML parser, theme build, ZIP packaging, validation.
- **Phase 2**: Admin UI (export/download buttons), controller actions, CSRF handling.
- **Phase 3**: Nav menu registration and WXR, generated page template, page asset architecture, improved manifest, updated documentation.
- **Phase C (current)**: Replaced `page-clickfuzz-generated.php` with conditional `page.php`; renamed meta keys to `_clickfuzz_` prefix; added `_clickfuzz_generated_js`; updated manifest `wordpress` block.
- **Future**: WordPress REST API integration for remote page creation, ClickFuzz plugin for runtime.js replacement, media import automation, Gutenberg block converter (optional), remote deploy workflow.
