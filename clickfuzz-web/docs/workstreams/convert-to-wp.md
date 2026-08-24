# Workstream: Convert to WordPress

## Summary

Converts a ClickFuzz-generated static HTML website into an installable WordPress package
(classic theme ZIP + WXR content XML + manifest). Deterministic PHP transformation — no AI
required at conversion time.

---

## Confirmed Working

*(nothing deployed to production yet — Phase 1+2 validated on server, pending manual UI test)*

---

## Implemented / Needs Testing

### Phase 1 (2026-08-24)

- **`docs/WORDPRESS_CONVERSION.md`** — Conversion contract and guardrail reference.
- **`modules/pitchsnap/helpers/pitchsnap_wordpress_helper.php`** — Complete backend helper.
  - `clickfuzz_web_export_wordpress_site($website_id)` — main orchestrator.
  - `_cfw_wp_parse_html($html)` — nesting-aware header/footer/main split, CSS extraction, font link capture, dynamic-year conversion, noindex stripping, runtime dependency recording.
  - `_cfw_wp_build_theme(...)` — writes all theme files: style.css, functions.php, header.php, footer.php, front-page.php, index.php, single.php, archive.php, page.php, 404.php, assets/css/theme.css.
  - `_cfw_wp_generate_wxr(...)` — minimal valid WXR with DOMDocument validation.
  - `_cfw_wp_validate(...)` — checks required files, WP theme header, wp_head/wp_footer/body_class presence, PHP lint (when PHP CLI available), no leaked server paths.
  - `_cfw_wp_zip_dir(...)` — Zip Slip-protected ZIP with top-level directory verification.
  - Pure utility functions: `_cfw_wp_make_slug`, `_cfw_wp_is_external_url`, `_cfw_wp_convert_internal_links`, `_cfw_wp_convert_dynamic_year`, `_cfw_wp_write_file`, `_cfw_wp_rm_dir`.
- **`tests/pitchsnap_wordpress_helper_test.php`** — Standalone CLI test runner (60 assertions, 60/60 pass on PHP 8.3.33).

### Phase 2 (2026-08-24)

- **`modules/pitchsnap/controllers/Pitchsnap.php`** — Two new actions:
  - `export_wordpress($id)` — admin-only POST; loads helper, calls `clickfuzz_web_export_wordpress_site`, flash alert, redirect to detail.
  - `download_wordpress($id)` — admin-only GET; glob-finds ZIP, realpath-confines to `exports/wordpress/{id}/`, streams with `Content-Disposition: attachment`.
- **`modules/pitchsnap/views/admin_detail.php`** — WordPress Export section in the Preview & HTML panel (below Publish Site button):
  - No export: `[Convert to WordPress]` POST button.
  - Export exists: `[Download WordPress Package]` link + `[Regenerate]` POST button.
  - Gated on `$redesign->generation_result`. Export state detected via `glob(exports/wordpress/{id}/*.zip)`.
- **`tests/pitchsnap_wp_phase2_test.php`** — Phase 2 test runner (30 assertions, 30/30 pass on PHP 8.3.33). Tests controller structure, view UI patterns, download path security.

---

## In Progress

*(nothing — Phase 2 complete, awaiting manual UI test)*

---

## Known Issues / Risks

- **PHP CLI not available on local dev machine** — `_cfw_wp_validate` PHP lint falls back to a warning. PHP lint will run correctly on the production server (PHP 8.3). Run the test file on the server before production deploy.
- **Google Fonts enqueue** — uses `_cfw_wp_esc_js_url()` (local utility, not WP `esc_js()`). Safe for font URLs which never contain single quotes.
- **Blog template styling** — `index.php`, `single.php`, `archive.php`, `page.php` use minimal inline styles. They inherit the extracted `theme.css` and the site's typography, but visual integration should be verified on a real WordPress install.
- **Internal links** — Only `href="/"` is converted to `home_url()`; other internal paths (e.g. `/about`, `/contact`) remain as-is. These would be broken links until Phase 2 addresses page import + slug resolution.
- **Empty `media/` directory** — Not added to the outer ZIP (RecursiveIterator skips empty dirs). No functional issue for V1.
- **Runtime dependencies** — ClickFuzz `runtime.js` is stripped from the theme and recorded as a `runtime_dependency` in the manifest. Lead capture must be re-integrated separately on WordPress.
- **Export path cleanup** — Only the previous ZIP for the same `website_id` is deleted on re-export. Old workspace directories from interrupted exports are left if `_cfw_wp_rm_dir` was not reached (e.g. interrupted mid-run). Manual cleanup may be needed in `/exports/wordpress/` on the server.

---

## Architecture Notes

### Export storage
```
{server_root}/exports/wordpress/{website_id}/clickfuzz-{slug}-wordpress.zip
```
Mirrors the `previews/` and `sites/` sibling-directory pattern. Each re-export overwrites the previous ZIP.

### Theme structure inside ZIP
```
clickfuzz-generated-{slug}/
├── style.css              (WP theme header only)
├── functions.php          (enqueue styles, theme support, nav menu)
├── header.php             (doctype → wp_head() → body_class() → visual <header>)
├── footer.php             (visual <footer> → wp_footer() → /body /html)
├── front-page.php         (get_header + main sections + get_footer)
├── index.php              (blog list — WordPress Loop)
├── single.php
├── archive.php
├── page.php
├── 404.php
└── assets/css/theme.css   (extracted from generated <style> block)
```

### HTML transformation pipeline (inside `_cfw_wp_parse_html`)
1. Strip noindex meta (preview-only artefact)
2. Strip ClickFuzz runtime script → record as runtime_dependency
3. Convert `<span data-pitchsnap-current-year>` → `<?php echo esc_html(date('Y')); ?>`
4. Extract `<head>` → pull CSS, Google Fonts hrefs, site title
5. Strip `<style>` blocks from full HTML
6. Extract `<body>` → nesting-aware split into header / main / footer sections
7. Convert `href="/"` → `<?php echo esc_url(home_url('/')); ?>` in all three sections

### Security
- `_cfw_wp_zip_dir`: Zip Slip guard (realpath check before `addFile`), top-level directory verification.
- `_cfw_wp_rm_dir`: Path allowlist check (must contain `/exports/` or `/tmp/`).
- Generated HTML treated as input (not server-side PHP): no arbitrary user-controlled code paths into the theme.
- Export download URL should require admin authentication in Phase 2.

---

## Next

1. **Manual UI test**: Visit a website detail page with generated HTML on the live server, click "Convert to WordPress", verify flash alert + "Download WordPress Package" button appears. Click download and verify ZIP is valid.
2. **Test on real generated site**: Confirm `clickfuzz_web_export_wordpress_site($website_id)` succeeds end-to-end against a live ClickFuzz-generated site HTML.
3. **WordPress install verification**: Install exported theme on a test WordPress, verify header/footer/front-page render, run blog post to verify blog templates.
4. **Internal link resolution**: Phase 3 — import actual pages (About, Contact, etc.) into WXR and resolve `/about` → WordPress page slug in `_cfw_wp_convert_internal_links`.

---

## History

| Date | Event |
|---|---|
| 2026-08-24 | Phase 1 complete: helper, conversion contract doc, test runner. No admin UI yet. |
| 2026-08-24 | Phase 2 complete: `export_wordpress` + `download_wordpress` controller actions, WordPress Export UI in admin_detail.php, Phase 2 tests (30/30). All 90 tests pass on PHP 8.3.33. |
