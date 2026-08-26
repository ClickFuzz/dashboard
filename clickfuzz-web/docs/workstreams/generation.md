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

## In Progress

- Nothing in progress. Phase 2 code complete (2026-08-26); server deployment pending DA recovery.

---

## Known Issues / Risks

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

Phase 1 deployed to production (2026-08-26) — DB at v15. Phase 2 code committed and pushed (2026-08-26); server at v15 pending deployment to v16. DA file manager was unavailable at time of Phase 2 commit — deploy when DA recovers or via SSH.

---

## Next

- **Deploy Phase 2 to server** — when DA file manager recovers, upload `pitchsnap.php`, `Pitchsnap_model.php`, `test_phase2_pages.php`; run v16 migration probe; verify syntax + test output
- Begin Phase 3: admin UI for pages and media library (tab_pages.php in admin_detail)
- Run end-to-end Lenka generation to verify Level B guardrail prevents navy/orange output
- Move `ps_colorprobe.php` into admin as Source Diagnostics page
- Investigate capturing body background from external CSS (fetch first same-origin `<link rel="stylesheet">`)

---

## History

- **2026-08-26** — Phase 2 data foundation: v16 migration (4 tables), Pitchsnap_model Phase 2 methods, test_phase2_pages.php; DA file manager down, server deployment pending
- **2026-08-26** — Phase 1 deployed: v15 migration confirmed, all 42 unit tests passing, WordPress two-phase publish, canonical lock, cleanup on publish
- **2026-08-23** — Lead-profile tab hooks restored (regression from HMVC restructuring); conversation empty state fixed in admin_lead.php
- **~2026-08** — Phase B logo vision fallback added; three-level visual identity guardrail implemented
- **~2026-08** — Brand color Phase A CSS scoring system implemented; visual character detection added
- **~2026-07** — Manus async provider integrated; provider selection/fallback settings added
- **~2026-07** — DB schema migrations v5–v11: conversations, sites, agreements, logs, pricing, is_primary
- **~2026-06** — Initial Anthropic generation pipeline implemented; cron runner; intake form
