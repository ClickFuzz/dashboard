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

## Theme output structure

```
clickfuzz-generated-{site-slug}/
├── style.css            # WordPress theme header only; CSS lives in assets/
├── functions.php        # Enqueue styles/scripts, theme support
├── header.php           # Doctype → <body> open + visual <header>
├── footer.php           # Visual <footer> + wp_footer() + </body></html>
├── front-page.php       # Homepage content between header and footer
├── index.php            # Blog list (WordPress Loop)
├── single.php           # Single blog post
├── archive.php          # Category / date archives
├── page.php             # Generic WordPress pages
├── 404.php              # Not-found template
└── assets/
    └── css/
        └── theme.css    # Extracted from <style> block in generated HTML
```

---

## Homepage content strategy (V1)

Homepage design/content lives inside `front-page.php` as static PHP/HTML.
WordPress gives the client blogging, the admin, plugins, SEO tools, and future
content management. Progressive editability can be added in later versions.

---

## Blog support

Blog templates (`index.php`, `single.php`, `archive.php`) use the site's
header/footer and typography. They contain no generated business content.

---

## WXR content file

`clickfuzz-content.xml` is a minimal valid WordPress WXR file. For a
single-page generated site with no blog, it contains only enough metadata
for the WordPress Importer to run without errors.

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

## Phase 2 notes

- Admin UI button, controller action, and download link are implemented in Phase 2.
- WordPress credential / hosting integration is a separate future phase.
- Progressive Gutenberg block conversion is a future phase.
- Media import automation is a future phase.
