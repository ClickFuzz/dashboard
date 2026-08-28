# Generation

## Scope

Everything from the moment a prospect submits their website URL through the delivery of a completed generated-website HTML artifact.

**Owns:**
- Public intake form (URL capture, Business Type, lead creation)
- `tblpitchsnap_redesigns` record lifecycle: new → pending_generation → generating → review_required / failed
- Source scraping pipeline (crawl, text extraction, image discovery, logo detection)
- Source package assembly (brand colors, visual character, guardrails, source text)
- Brand color extraction — Phase A (CSS scoring) and Phase B (logo vision fallback)
- Visual character detection (tone/saturation/temperature)
- AI generation: Anthropic (sync) and Manus (async, two-phase)
- Provider selection / fallback
- Generation prompt (stored in `tbl_options` and editable in Settings UI)
- Generated HTML post-processing (copyright year, noindex injection, validation)
- Preview deployment (HTML stored locally, served via preview URL)
- Regeneration (new record with `parent_redesign_id`)
- Cron-based async generation runner
- Generation diagnostics (colorprobe endpoint, admin logs)
- Admin generation UI: Websites list, detail page, generate/retry/regenerate actions

**Does not own:**
- Prospect-facing preview experience (→ Sales Flow)
- Purchase/payment (→ Sales Flow)
- Post-purchase site editing / publishing (→ Sales Flow)
- Lead Connect calling/SMS (→ Lead Connect)

---

## Current State

Core pipeline is implemented and confirmed working in production. Both Anthropic (sync) and Manus (async with publish phase) providers are integrated. Brand color preservation system is live and regression-tested. Admin UI covers the full generation lifecycle.

---

## Confirmed Working

- Public intake form creates Perfex lead + `tblpitchsnap_redesigns` record
- Business Type (vertical) dropdown captured and stored
- URL / default Business Type handling
- Status lifecycle: new → pending_generation → generating → review_required / failed
- Cron runner (`before_cron_run` hook → `clickfuzz_web_cron_run`)
- Anthropic (claude-sonnet) synchronous generation
- Manus async generation: `start()` → polling → `publish()` → publish polling
- Provider selection: primary (`pitchsnap_primary_provider`) + fallback (`pitchsnap_fallback_provider`)
- Source scraping: multi-page crawl (up to 15 pages), text extraction, image discovery, link scoring
- Logo discovery (header links, og:image, src attributes)
- Brand color extraction — Phase A (CSS scoring, 10 evidence source types, confidence thresholds)
- Brand color extraction — Phase B (logo vision fallback via Haiku, fires when Phase A = LOW)
- Visual character detection (tone/saturation/temperature, weighted scoring)
- Three-level guardrail: HIGH/MEDIUM → exact brand palette block; LOW + character → visual identity block; LOW + no character → no guardrail
- Generation prompt stored in `tbl_options.pitchsnap_generation_prompt`, editable in Settings
- HTML post-processing: copyright year normalization, noindex injection, HTML validation
- Preview deployment: HTML written to local filesystem path, accessible via `preview_url`
- Runtime widget script tag injected into generated HTML
- Regeneration: creates new record with `parent_redesign_id`; `is_primary` flag updated
- Admin website list (latest per lead, status badges)
- Admin detail page: view/approve/retry/regenerate/edit HTML
- Admin lead page: per-lead website history and versions
- Perfex lead-profile tab: "ClickFuzz Web" tab on lead detail modal (restored 2026-08-23)
- Admin settings: provider selection, API keys, model, prompt, guardrail toggles per provider
- Activity log (`tblpitchsnap_logs`) with admin UI
- `is_primary` versioning: one primary per lead, seeded on DB upgrade
- Stuck generation rescue (`rescue_stuck()`)

---

## Implemented / Needs Retesting

- Test D: Enter real API key → trigger cron → confirm `review_required` + HTML output (was in PASSDOWN as planned, not confirmed since last schema changes)
- Test A: Settings UI — API key not exposed in HTML source
- Test F: Regenerate button → new record with `parent_redesign_id`
- Lenka Craniosacral end-to-end: full Anthropic generation with brand color Level B guardrail → verify output is not navy/orange
- Manus publish phase (provider is available but async polling path less exercised than Anthropic path)
- `edit_html` / `modify_html` admin HTML editor

---

## Phase 1 — Publishing Type + Primary Site Lock (2026-08-26, deployed)

- DB migration v15: `publish_type`, `wp_site_url`, `wp_username`, `wp_app_password`, `wp_page_id` on `tblpitchsnap_sites`; v12–v14 already present (identical to main)
- `save_publish_type` controller: persists `html` or `wordpress`; blocked after publish
- HTML publishing: existing `clickfuzz_web_publish_site` unchanged; calls `clickfuzz_web_cleanup_generation_history` on success
- WordPress publishing: `clickfuzz_web_publish_site_wp` via WP REST API; **two-phase**: page creation → Settings API front-page assignment; `site.status=published` only after both succeed
- WordPress admin requirement: UI note in `tab_publishing.php` — user must be WordPress Administrator (manage_options capability required for settings endpoint)
- Cleanup: `clickfuzz_web_cleanup_generation_history` deletes non-primary redesigns + preview files + conversations after publish
- Canonical lock: `regenerate()` and `queue_generate()` blocked when primary site is `published`
- UI: Phase 1 "Publish Type" panel + "WordPress REST API Publish" panel in `tab_publishing.php`; sits alongside existing HTML Hosting, ClickFuzz Connector, and Custom Domain panels
- Tests: `modules/pitchsnap/tests/test_phase1_publishing.php` — 42 pure-PHP assertions all passing on server (T1-T13), 10 DB/WP-dependent SKIPs documented
- Migration confirmed: ran on server 2026-08-26 — pre-migration v14, post-migration v15; v12-v14 structures intact
- Syntax verified on server for all 5 modified PHP files — no errors

---

## Phase 3 — Pages & Media Admin UI (2026-08-26, committed, server deployment pending)

- **Generate Pages section** in Website tab (visible only when `site.status === 'published'`); shows hierarchical page list with indent, type, status, generation status, slug, Configure/Trash/Restore actions
- **Add Page modal**: name, type, parent (active same-site only), slug (auto-suggested from name); POSTs to `page_add/{site_id}`; redirects to page_edit
- **Page configuration page** (`admin_page_edit.php`): four panels — Page (name/slug/type/parent), SEO (keyword, meta, index), Navigation (primary/footer menus, label, order), Generation Instructions (textarea); plus Page Media picker sidebar
- **Disabled Generate Page button** with readiness checklist: requires title, slug, type, and (primary_keyword OR instructions)
- **Media Library section** in Website tab: upload form (JPEG/PNG/GIF/WebP/SVG, 10MB max, MIME validated via finfo, random hex filename); grid of thumbnails with Edit modal and Delete button
- **Upload security**: `finfo` server-side MIME detection, `is_uploaded_file()` check, site-id directory isolation, path traversal guard in delete
- **Media storage**: `dashboard/media/{site_id}/{filename}` — follows same convention as `dashboard/previews/` and `dashboard/sites/`
- **Media deletion guard**: blocked with error message if media is attached to any pages; unused media removes file + DB record
- **Page media selection**: in page_edit view, site library grid with attach/detach via AJAX; green border = attached, gray = unattached
- **Controller**: 11 new methods — `page_add`, `page_edit`, `page_save`, `page_trash`, `page_restore`, `media_upload`, `media_save`, `media_delete`, `media_json`, `page_media_attach`, `page_media_detach`
- **Routes**: 11 new routes in `config/routes.php` + fallbacks in `my_routes.php`
- **Model**: `page_slug_available()`, `get_active_pages_for_site()` added
- **Helper**: `modules/pitchsnap/helpers/pitchsnap_media_helper.php` — upload, delete, URL, dir functions
- **Tests**: `test_phase3_pages.php` — 50+ pure-PHP assertions (T1-T15); 20 DB-dependent SKIPs documented
- **Server deployment blocked** by DA file manager 500 error — code committed and pushed; deploy when DA recovers or via SSH

---

## Phase 2 — Internal Pages + Media Library Data Foundation (2026-08-26, committed, server deployment pending)

- DB migration v16: 4 new tables created with `CREATE TABLE IF NOT EXISTS` guards; v15 ALTERs preserved for servers still at v14
- `tblpitchsnap_pages`: full schema — site_id, title, slug, page_type, parent_page_id, status (draft/published/trash), generation_status (not_generated/generating/generated/failed), SEO fields (meta_title, meta_description, primary_keyword, supporting_keywords, instructions), menu config (menu_primary, menu_footer, menu_label, menu_order), index_page, current_generation_id, published_path, wp_page_id, published_at
- `tblpitchsnap_site_media`: site-level media library — filename, original_filename, title, description, alt_text, category, mime_type, file_size
- `tblpitchsnap_page_media`: join table (page ↔ media); UNIQUE on (page_id, media_id)
- `tblpitchsnap_page_generations`: version history per page — html_content, css_content, js_content, meta_title_generated, meta_description_generated, prompt_snapshot, is_current, status; site_id denormalized for ownership checks
- Model: 4 new table properties in `Pitchsnap_model` constructor; full CRUD for pages, media, page-media, and page generations
- Page parent validation: rejects self-reference, cross-site parents, trashed parents
- Media attachment: enforces same-site ownership; detach removes join row only (media item preserved)
- `set_current_page_generation`: clears is_current on all siblings, updates pages.current_generation_id atomically; verifies generation belongs to page before acting
- Tests: `modules/pitchsnap/tests/test_phase2_pages.php` — pure-PHP suite (T1-T14, ~40 assertions), 13 DB-dependent SKIPs documented
- **Server deployment blocked by DA file manager 500 error** — code is committed and pushed; deploy when DA recovers or via SSH

---

## Phase 4 — Internal Page AI Generation (2026-08-26, committed, server deployment pending)

- **Security fix**: SVG removed from `PS_MEDIA_ALLOWED_MIMES` in `pitchsnap_media_helper.php`; T6e in `test_phase3_pages.php` updated to assert SVG is rejected
- **New helper**: `modules/pitchsnap/helpers/pitchsnap_page_generation_helper.php`
  - `clickfuzz_web_page_is_ready_to_generate($page)` — readiness check (title, slug, type, keyword or instructions)
  - `clickfuzz_web_queue_page_generation($page_id)` — validates readiness; atomically sets `generation_status='generating'`
  - `clickfuzz_web_generate_page($page)` — cron entry point; calls Anthropic, parses output, stores generation record
  - `clickfuzz_web_build_page_prompt($page, $site, $lead, $redesign, $parent_pages, $page_media)` — full prompt: business context, page details, type instructions, media list, main site HTML excerpt
  - `clickfuzz_web_get_page_type_instructions($page_type)` — 8 type modules: about, service, service_area, contact, gallery, financing, faq, custom
  - `clickfuzz_web_extract_page_output($raw)` — parses `<body_html>`, `<page_css>`, `<page_js>`, `<meta_title>`, `<meta_description>` delimiters; fallback for undelimited HTML
- **Model additions** (Phase 4 section):
  - `get_pages_for_generation($limit=5)` — pages with `generation_status='generating'`, ordered by `dateupdated ASC`
  - `queue_page_for_generation($page_id)` — atomic UPDATE WHERE `generation_status IN ('not_generated','failed','generated')`; returns affected rows > 0; `'generating'` excluded as concurrency lock
  - `mark_page_generation_success($page_id)` — sets `generation_status='generated'`
  - `mark_page_generation_failed($page_id, $error)` — sets `generation_status='failed'`; logs to `tblpitchsnap_logs`; preserves `current_generation_id` so prior successful generation remains usable
- **Cron extended**: Phase 4 block appended to `clickfuzz_web_cron_run()` — loads page generation helper; processes up to 5 pages per cron tick
- **Controller additions** (3 new methods):
  - `page_generate($page_id)` — POST; queues page; flash+redirect to page_edit
  - `page_preview($page_id)` — GET; serves current generation HTML; supports `?gen=` for viewing specific versions; noindex meta injected
  - `page_generation_set_current($generation_id)` — POST; calls `set_current_page_generation`; rejects cross-page IDs
- **Routes**: 3 new routes added to both `modules/pitchsnap/config/routes.php` and `application/config/my_routes.php`
- **`admin_page_edit.php` updated**: Sidebar generation panel now status-aware — generating spinner, generated→preview+regenerate, failed→retry, not_generated→generate (active/disabled based on readiness). Version history panel added (visible when generations exist): table with version number, date, Preview link, Use button for non-current versions. Controller now passes `$generations` and `$current_gen` to view. `page_edit()` passes both to view
- **`page_edit()` updated**: passes `$data['generations']` and `$data['current_gen']` to view
- **Tests**: `test_phase4_pages.php` — 11 test groups, 50+ pure-PHP assertions, 20 DB/API SKIPs
- **Provider**: Anthropic only (no Manus, no fallback)
- **Storage**: `tblpitchsnap_page_generations` DB only; no filesystem preview files
- **Generation lifecycle**: `not_generated → generating → generated / failed`

---

## Phase 5 — Internal Page Publishing (2026-08-26, committed, server deployment pending)

- **New helper**: `modules/pitchsnap/helpers/pitchsnap_page_publish_helper.php` — all HTML and WordPress publishing logic
  - `clickfuzz_web_validate_slug_for_publish($slug)` — regex `^[a-z0-9][a-z0-9\-]*[a-z0-9]$|^[a-z0-9]$`
  - `clickfuzz_web_page_url_path($page, $pages_indexed)` — hierarchical URL path builder; loop/cross-site/traversal protection
  - `clickfuzz_web_page_publish_eligible($page, $site, $gen)` — pre-flight guard (not_generated, no gen, wrong publish_type, WP missing creds, etc.)
  - `clickfuzz_web_build_nav_items($pages_indexed, $base_url)` — nav tree with primary/footer split, draft/trash exclusion
  - `clickfuzz_web_render_primary_nav_html($primary, $home_url)` — HTML `<nav>` with CF markers; 1-level nesting
  - `clickfuzz_web_render_footer_nav_html($footer)` — flat footer nav
  - `clickfuzz_web_update_html_nav($html, $nav_html)` — marker → `<nav>` regex → `<body>` insertion fallback chain
  - `clickfuzz_web_render_full_page_html($page, $site, $gen, $canonical, $nav)` — full HTML document; noindex, CSS, JS, meta title/description
  - `clickfuzz_web_write_sitemap($site_dir, $base_url, $published_pages, $pages_indexed)` — XML sitemap; noindex pages excluded
  - `clickfuzz_web_publish_page_html($page, $site, $gen)` — full HTML publish flow: slug validate → path resolve → realpath guard → nav build → write index.html → verify → update all site navs → sitemap → return `[success, url, published_path, error]`
  - `clickfuzz_web_update_all_site_navs(...)` — updates nav in homepage + all published page HTML files
  - `clickfuzz_web_update_nav_in_file($file, $site_dir, $nav)` — single file nav update with realpath guard
  - `clickfuzz_web_wp_call($site, $method, $endpoint, $body, $timeout)` — WP REST API helper
  - `clickfuzz_web_wp_get_menu_id($site, $location)` — finds WP menu ID by registered location
  - `clickfuzz_web_wp_upsert_menu_item($site, $menu_id, $wp_page_id, $label, $parent_item, $order)` — create/update menu item
  - `clickfuzz_web_publish_page_wp($page, $site, $gen, $parent_wp_page_id)` — full WP publish: create/update page → 5 post meta keys → primary menu → footer menu → return `[success, url, wp_page_id, error]`
- **Publish dispatch**: controller reads `site->publish_type`; routes to HTML or WP path; mixed types not possible per site
- **Success order enforced**: publish target → verify existence/response → then `publish_page()` in model (DB write last)
- **Version cleanup on publish**: `cleanup_page_generations($page_id, $keep_gen_id)` deletes all but the just-published generation
- **Model additions** (Phase 5 section, before Phase 4 section):
  - `get_published_pages_for_site($site_id)` — `status='published'`, `menu_order ASC`
  - `publish_page($page_id, $published_path, $wp_page_id=null)` — sets `status='published'`, `published_path`, `published_at`, optionally `wp_page_id`
  - `cleanup_page_generations($page_id, $keep_gen_id)` — deletes all generation records for page except `keep_gen_id`
- **Controller**: `page_publish($page_id)` — POST; eligibility check; WP parent hierarchy guard (parent must have `wp_page_id`); dispatch; flash with live URL; redirect to page_edit
- **`page_edit()` extended**: passes `live_url` (from platform hostname + `published_path` or WP site URL + slug), `has_newer_gen` (`current_gen->dateadded > page->published_at`)
- **Admin sidebar 6 states** in `admin_page_edit.php`:
  1. **Generating** — spinner; View Current Live (if published)
  2. **Published + up to date** — View Live (green); Regenerate (warning)
  3. **Published + newer generation ready** — View Current Live; Preview Draft (info); Publish Update (primary); Regenerate Again (warning-xs)
  4. **Generated (draft, not yet published)** — Preview (info); Publish Page (primary); Regenerate (warning-xs)
  5. **Failed** — View Live/Preview Previous (if available); Retry Generation (danger)
  6. **Not generated** — Generate (primary, enabled only if ready; disabled + checklist if not)
- **Page Info table** updated: Published date and Live URL link columns added
- **Routes**: `pitchsnap/page_publish/(:num)` added to `modules/pitchsnap/config/routes.php` and `application/config/my_routes.php`
- **HTML internal pages**: `{site_dir}/{url_path}/index.html` served at `https://{hostname}/{url_path}/`; hierarchical paths from parent chain
- **WP pages**: create (`POST /wp-json/wp/v2/pages`) or update (`PUT .../pages/{wp_page_id}`); 5 meta keys: `_clickfuzz_page_css`, `_clickfuzz_page_js`, `_clickfuzz_meta_title`, `_clickfuzz_meta_description`, `_clickfuzz_noindex`; menu managed via `wp/v2/menu-items`
- **No Anthropic calls during publish** — consumes existing `current_generation_id` only
- **Live page preserved until replacement succeeds** — no downtime on republish
- **WP body**: Generation prompt is body-only (prohibits `<header>`, site `<nav>`, `<footer>`). `clickfuzz_web_normalize_page_body_html()` strips any accidental chrome before DB storage and again at WP publish time. WP `content` payload contains normalized body only — WP theme owns header/footer/nav. Chrome duplication resolved in Phase 5 hardening.
- **Tests**: `test_phase5_pages.php` — 14 test groups, 60+ pure-PHP assertions, 30+ DB/API/filesystem SKIPs. Groups: T1 slug validation, T2 URL path building, T3 eligibility, T4 nav building, T5 nav HTML, T6 nav injection, T7 menu exclusion, T8 full HTML doc, T9 sitemap, T10 version cleanup, T11 WP hierarchy, T12 publish type consistency, T13 Phase 4 lifecycle regression, T14 Phase 3 SVG regression

---

## In Progress

- Nothing in progress. Phases 1–5 deployed, tested, and merged to `main` (2026-08-28). Workstream parked pending new Website Details architecture.

---

## Known Issues / Risks

- **WP body chrome duplication**: ~~Resolved in Phase 5 hardening.~~ Generation prompt is body-only; `clickfuzz_web_normalize_page_body_html()` strips any accidental chrome at storage and WP publish time. Not a known issue.
- **Body background in external CSS not captured.** Lenka's cream body background is in an external stylesheet — `$bg_colors` is empty. Visual character derived from white nav background only. Acceptable for now; logo vision adds temperature at generation time if logo has warm colors.
- **`ps_colorprobe.php` lives in server root** (not in admin UI). PASSDOWN flagged: move to ClickFuzz Web admin as Source Diagnostics page.
- **Test 5 scoring anomaly:** `--wp--preset--color--primary` also matches the semantic CSS variable regex, giving 5 pts instead of 2 for that preset. Pre-existing behavior, not a regression.
- **Manus publish phase not fully regression-tested** — no automated checks verify the two-phase async flow end-to-end.

---

## Architecture / Important Decisions

- Module route values must NOT include the module prefix. `Modules::parse_routes()` prepends `$module/` automatically. Both `modules/pitchsnap/config/routes.php` and `application/config/my_routes.php` define all pitchsnap public routes; module routes take precedence, `my_routes.php` is the guaranteed fallback.
- `$CI->load->model()` and `$CI->load->library()` cannot resolve module paths from global hook functions (cron, lead tab). Use `require_once FCPATH . 'modules/pitchsnap/...'` and manually instantiate.
- Status `pending` was renamed to `new` in DB upgrade v5; `pending_generation` is the pre-cron queued state.
- `$all_scored` snapshot taken BEFORE neutral filtering; used as `$char_scored` for visual character. Per-color evidence gate strips colors whose ONLY evidence is `"WordPress color preset (generic)"`.
- Lead profile tab uses `include $view_path` (not `$CI->load->view()`) to avoid module context resolution failure in the Perfex leads controller scope.
- OPcache: reusing same probe filename serves stale PHP — always use fresh filename for diagnostic scripts.

---

## Relevant Files

| File | Purpose |
|---|---|
| `modules/pitchsnap/helpers/pitchsnap_page_publish_helper.php` | Phase 5: HTML + WP page publishing, nav, sitemap |
| `modules/pitchsnap/helpers/pitchsnap_page_generation_helper.php` | Phase 4: readiness, queue, generate, prompt, extract |
| `modules/pitchsnap/helpers/pitchsnap_media_helper.php` | Phase 3: media upload, delete, URL, dir |
| `modules/pitchsnap/views/admin_page_edit.php` | Page configuration view (6-state sidebar) |
| `modules/pitchsnap/tests/test_phase5_pages.php` | Phase 5 test suite |
| `modules/pitchsnap/tests/test_phase4_pages.php` | Phase 4 test suite |
| `modules/pitchsnap/tests/test_phase3_pages.php` | Phase 3 test suite |
| `modules/pitchsnap/tests/test_phase2_pages.php` | Phase 2 test suite |
| `modules/pitchsnap/pitchsnap.php` | Module root: hooks, activation, DB migrations |
| `modules/pitchsnap/controllers/Pitchsnap.php` | Admin controller |
| `modules/pitchsnap/controllers/Pitchsnap_intake.php` | Public intake form |
| `modules/pitchsnap/models/Pitchsnap_model.php` | All DB operations |
| `modules/pitchsnap/helpers/pitchsnap_scraper_helper.php` | Source scraping, brand color extraction, visual character |
| `modules/pitchsnap/helpers/pitchsnap_generation_helper.php` | Prompt rendering, HTML post-processing, preview deploy, default prompts |
| `modules/pitchsnap/helpers/pitchsnap_cron_helper.php` | Cron runner, async polling, admin notification |
| `modules/pitchsnap/libraries/Pitchsnap_generator.php` | Provider facade (Anthropic + Manus) |
| `modules/pitchsnap/libraries/Pitchsnap_anthropic.php` | Anthropic API client |
| `modules/pitchsnap/libraries/Pitchsnap_manus.php` | Manus API client |
| `modules/pitchsnap/views/admin_websites.php` | Website list page |
| `modules/pitchsnap/views/admin_lead.php` | Per-lead website management page |
| `modules/pitchsnap/views/admin_detail.php` | Website detail / generation actions |
| `modules/pitchsnap/views/admin_settings.php` | Settings page |
| `modules/pitchsnap/views/lead_section.php` | Lead profile tab content |
| `modules/pitchsnap/views/intake_form.php` | Public intake form |

**DB tables:** `tblpitchsnap_redesigns`, `tblpitchsnap_logs`, `tblpitchsnap_pages`, `tblpitchsnap_site_media`, `tblpitchsnap_page_media`, `tblpitchsnap_page_generations`

**Key options:** `pitchsnap_ai_provider`, `pitchsnap_primary_provider`, `pitchsnap_fallback_provider`, `pitchsnap_anthropic_api_key`, `pitchsnap_manus_api_key`, `pitchsnap_model`, `pitchsnap_generation_prompt`, `pitchsnap_manus_prompt`, `pitchsnap_db_version`, `pitchsnap_logging_enabled`

---

## Production Status

All phases (1–5) deployed to production (2026-08-26). DB migrated to v17 (v16 tables + v17 columns confirmed). Production and Git are aligned. Branch merged to `main` at `0782213` (2026-08-28).

---

## Next

- ~~Deploy Phase 2–5 to server~~ — complete (2026-08-28). DB at v17. Production aligned.
- ~~Fix WP chrome duplication~~ — resolved in Phase 5 (body-only prompt + normalization function)
- **Implement new Website Details architecture** — generation UI (page pipeline, page edit/preview/publish) will be rebuilt as part of redesigned Website Details page. Resume generation-specific UI work then.
- **Browser UI validation (deferred)** — end-to-end test of page pipeline (Add Page → configure → Generate → Preview → Publish) pending. Site ps-17-5f59 (JackRabbit/Lenka) available when UI work resumes.
- Run end-to-end Lenka generation to verify Level B guardrail prevents navy/orange output
- Move `ps_colorprobe.php` into admin as Source Diagnostics page
- Investigate capturing body background from external CSS (fetch first same-origin `<link rel="stylesheet">`)

---

## History

- **2026-08-28** — Phases 1–5 confirmed deployed and validated on production (DB v17, all test suites passing). Diagnostic scripts removed. `claude/generation` merged to `main` at `0782213`. Workstream parked.
- **2026-08-26** — Phase 5: page publishing helper (HTML + WP flows); model publish/cleanup methods; page_publish controller + route; 6-state sidebar in admin_page_edit; test_phase5_pages.php; DA file manager down, server deployment pending
- **2026-08-26** — Phase 4 lifecycle fix: `queue_page_for_generation` WHERE IN extended to include `'generated'`; Regenerate from generated state now works; failed regen preserves prior successful generation
- **2026-08-26** — Phase 4: SVG security fix; page generation helper (readiness, queue, generate, prompt, extract); model Phase 4 methods; cron Phase 4 block; page_generate/page_preview/page_generation_set_current controller+routes; admin_page_edit updated; test_phase4_pages.php; DA file manager down, server deployment pending
- **2026-08-26** — Phase 3: pages + media admin UI; 11 controller methods; media helper; page_edit full view; test_phase3_pages.php; DA file manager down, server deployment pending
- **2026-08-26** — Phase 2 data foundation: v16 migration (4 tables), Pitchsnap_model Phase 2 methods, test_phase2_pages.php; DA file manager down, server deployment pending
- **2026-08-26** — Phase 1 deployed: v15 migration confirmed, all 42 unit tests passing, WordPress two-phase publish, canonical lock, cleanup on publish
- **2026-08-23** — Lead-profile tab hooks restored (regression from HMVC restructuring); conversation empty state fixed in admin_lead.php
- **~2026-08** — Phase B logo vision fallback added; three-level visual identity guardrail implemented
- **~2026-08** — Brand color Phase A CSS scoring system implemented; visual character detection added
- **~2026-07** — Manus async provider integrated; provider selection/fallback settings added
- **~2026-07** — DB schema migrations v5–v11: conversations, sites, agreements, logs, pricing, is_primary
- **~2026-06** — Initial Anthropic generation pipeline implemented; cron runner; intake form
