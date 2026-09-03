# ClickFuzz Web — Global Passdown

## What This Is

ClickFuzz Web is a custom Perfex CRM module. It handles website generation, prospect sales flow, customer onboarding, lead engagement (Lead Connect), review collection, and reporting for ClickFuzz clients.

**Server:** `clickfuzz.com/dashboard`
**DA Panel:** `homesincda.com:2222`, user `clgorman`
**DB:** `clgorman_clickfuzzdashboard` (table prefix: `tbl`)
**Stack:** Perfex CRM 3.1.4 / CodeIgniter 3.1.11 / PHP 8.3.33
**Module:** `pitchsnap` (slug), active (tblmodules id=23, active=1)
**DB schema version:** 26 (git generation branch target — v25: forms tables, v26: GHL destinations)

---

## Source-of-Truth Policy

The canonical source code lives in the git repository under:

```
clickfuzz-web/modules/pitchsnap/
clickfuzz-web/application/config/my_routes.php
```

**Only one source tree exists.** All legacy/duplicate files (`controllers/`, `models/`, `views/`, `pitchsnap.php`, `my_routes.php` at top-level, `_backup/`) were removed on 2026-08-23. The `/Users/mymac/Desktop/Projects/software/PitchSnap/` directory was also removed.

Production (`clickfuzz.com/dashboard/modules/pitchsnap/`) is deployed separately from this repo. The DA MCP is used to deploy and verify production state.

---

## Architecture

- **Framework:** Perfex CRM 3.1.4 (CodeIgniter 3 + MX Router HMVC)
- **Module root:** `modules/pitchsnap/pitchsnap.php` — hooks, activation, DB migrations, lead tab registration
- **Controllers:** `Pitchsnap` (admin), `Pitchsnap_intake` (public form), `Pitchsnap_runtime` (prospect-facing)
- **Model:** `Pitchsnap_model` — all DB operations for redesigns, sites, conversations, agreements, logs
- **Helpers:** scraper, generation, cron
- **Libraries:** generator facade, Anthropic client, Manus client, email notification
- **Views:** admin_websites, admin_lead, admin_detail, admin_settings, admin_edit_html, lead_section, intake_form, intake_result, agreement_page, subscription_success, runtime_js
- **Routes:** `modules/pitchsnap/config/routes.php` (primary) + `application/config/my_routes.php` (fallback)

### Key GOTCHA: Module model loading from global hooks

`$CI->load->model()` cannot resolve module-relative paths from global hook context (cron, lead tab hooks). Use:
```php
if (!class_exists('Pitchsnap_model')) {
    require_once FCPATH . 'modules/pitchsnap/models/Pitchsnap_model.php';
    $CI->pitchsnap_model = new Pitchsnap_model();
}
```

### Key GOTCHA: CI3 CSRF strips POST data — guard forms correctly

CI3's CSRF middleware **unsets the CSRF token from `$_POST` after validation**. If a form contains only the CSRF token field (no other fields), `$_POST` is empty by the time the controller runs. The common pattern `if (!$this->input->post()) { redirect(...); }` will then silently bail out.

**Fix:** Always include at least one non-CSRF field in any form that uses this guard. For action-confirmation forms, use `<input type="hidden" name="confirm_delete" value="1">` (or similar) and check `$this->input->post('confirm_delete') !== '1'` in the controller.

This bit the `delete_profile` action — fixed 2026-08-24 in `site-management` workstream.

### Key GOTCHA: Route value format

Module route values must NOT include the module prefix. `Modules::parse_routes()` prepends it automatically.

### Key GOTCHA: CSRF exclusions

Public endpoints must be added to `$app_csrf_exclude_uris` in `application/config/app-config.php`.

---

## Cloudflare / Hosted-Site Architecture (FINAL — 2026-08-26)

**Decision is final. Do NOT build DirectAdmin per-customer domain provisioning or per-domain Let's Encrypt.**

### Traffic flow for custom domains

```
www.customer.com
  → CNAME customers.clickfuzz.com
  → Cloudflare for SaaS / Custom Hostnames
  → fallback origin: sites.clickfuzz.com
  → dedicated CrocWeb hosted-sites account
  → ClickFuzz shared hostname-based runtime
```

### Cloudflare state (validated)

- `clickfuzz.com` DNS is now authoritative on Cloudflare. All existing records migrated successfully.
- Existing services (clickfuzz.com WordPress, `/dashboard/`, `jackrabbit.clickfuzz.com`, mail) confirmed working.
- General records are DNS-only (grey-cloud) unless intentionally proxied.
- Cloudflare for SaaS is enabled, billing configured.
- SSL mode: Full. Universal SSL: active.
- `customers.clickfuzz.com` — proxied CNAME → `sites.clickfuzz.com`. This is the permanent hostname customers will CNAME their `www` to.
- `sites.clickfuzz.com` — fallback origin, status: Active in Cloudflare. Points to the **new dedicated CrocWeb hosted-sites account**.
- Custom Hostname TLS is handled by Cloudflare (not DirectAdmin, not Let's Encrypt).
- ClickFuzz will eventually create/manage Custom Hostnames via Cloudflare API.

### MVP canonical hostname strategy

- `www.customer.com` is the canonical custom hostname for MVP.
- Apex/root domain handling (redirect `customer.com` → `www.customer.com`) is deferred.

### New CrocWeb hosted-sites account (active 2026-08-26)

- **Server IP:** `104.152.168.40`
- **DA user:** `xqsfhrlj`
- **Runtime root:** `/home/xqsfhrlj/domains/sites.clickfuzz.com/public_html/`
- **Site storage:** `/home/xqsfhrlj/domains/sites.clickfuzz.com/public_html/sites/`
- `index.php` (commit `1457b10`), front-controller `.htaccess`, and storage `.htaccess` manually deployed.
- Generated HTML site directories manually copied to `sites/`.
- `runtime-config.php` exists on the server with DB credentials. **Never commit to git.**
- DB is on old server `104.152.168.38`. Remote MySQL access for `104.152.168.40` manually enabled in DA.
- Existing DA MCP (`clgorman` / `homesincda.com:2222`) cannot access the new account — deployment is manual via the new account's DA panel or SFTP.

### Cloudflare Worker (active 2026-08-26)

- Worker name: `clickfuzz-site-router`
- Catch-all route: `*/*` → `clickfuzz-site-router`
- Explicit Worker=None exclusions: `sites.clickfuzz.com/*`, `www.clickfuzz.com/*`, `clickfuzz.com/*` — **do not remove these**.
- Worker interception proven: request to `www.eddmautofill.com` returned `worker-hit: www.eddmautofill.com`.
- Worker fetch to `https://sites.clickfuzz.com/` returned the PHP runtime's `404 Not Found` — proving the full chain works:
  `custom hostname → Cloudflare for SaaS → Worker → sites.clickfuzz.com → CrocWeb → PHP runtime`

### End-to-end validation (2026-08-26) — COMPLETE

Full publish-and-serve path verified working:

`Publish button → Explicit FTPS (port 21, AUTH TLS) → sites.clickfuzz.com/sites/{slug}/index.html`
`www.eddmautofill.com → Cloudflare for SaaS → Worker (X-ClickFuzz-Host) → sites.clickfuzz.com → PHP runtime → DB slug lookup → /sites/{slug}/index.html`

- Cloudflare Custom Hostname status: Active
- Cloudflare certificate: Active
- HTTPS: working end-to-end
- Generated HTML served correctly via `www.eddmautofill.com`

### Publishing infrastructure (live as of 2026-08-26)

- FTPS publishing: Explicit FTPS on port 21 (ftp:// + `CURLUSESSL_ALL` + `CURLFTPAUTH_TLS`). Credentials stored in `tbloptions` (`pitchsnap_publish_ftp_*`). FTP account `publish@sites.clickfuzz.com` is chrooted to `/home/xqsfhrlj/domains/sites.clickfuzz.com/public_html/sites/`. Base `'/'` produces absolute-path URL within the chroot.
- X-ClickFuzz-Host header handling: implemented in `hosted-runtime/index.php`. Trusted only when `HTTP_HOST === sites.clickfuzz.com`. Never trusted from arbitrary direct clients.
- Legacy slug self-healing: `clickfuzz_web_publish_site()` auto-generates and persists slug for sites created before the slug system (domain=null records).
- Publish form: `confirm_publish` hidden field added to defeat CI3 CSRF silent-redirect bug.

### Remaining future work (publishing-domains)

1. Automate Cloudflare Custom Hostname lifecycle via API (create/remove when custom domain is added/removed in admin).
2. Handle apex → www redirect for custom domains.
3. Replace provisional DNS verification UI/logic with CNAME-based flow (`CNAME www → customers.clickfuzz.com`).
4. Wire FTPS credentials/settings into a proper admin config UI (currently set manually in tbloptions).
5. Consider tightening FTPS `SSL_VERIFYPEER` once CrocWeb certificate situation is confirmed.

### Phase 5B/5C DNS verification — provisional status

The Phase 5B DNS helper (`pitchsnap_dns_helper.php`) and verification UI are deployed. Current DNS instructions shown in the UI (direct A record `104.152.168.38`) are **provisional** — they will change to `CNAME www → customers.clickfuzz.com` once Custom Hostname API automation is built.

### Manual/untracked state (important)

- `runtime-config.php` on new server: secrets, never in git. Local copy at `/tmp/runtime-config.php` on dev machine (temporary).
- Cloudflare Worker code: currently manual, not in repo.
- Cloudflare for SaaS routes/custom-hostname config: manual.
- New CrocWeb server deployments: manual (DA MCP cannot reach `xqsfhrlj` account).

---

## Current Production / Main Baseline

As of 2026-09-03, `main` is at `5bd774f` (pages-publish + WP connector v2.2.0 merged). `origin/main` is in sync. DB canonical version: v24 in main.

Production is running pages-publish + sales-flow output. FTPS publishing and hosted-site serving (`www.eddmautofill.com`) remain live.

**`claude/generation` re-synced to main (2026-09-03):** fast-forward merged `origin/main` (11 commits: pages-publish, WP connector, color palette, page editor) into `claude/generation`. Branch is now at `5bd774f` + GHL Destinations additions (uncommitted).

**GHL Destinations feature added to generation branch (2026-09-03):** Production had this feature (controller endpoints, model methods, view tab, migrations v25/v26) but it was not in git. Reconciled into this branch:
- `Pitchsnap.php`: `settings()` now passes `$data['ghl_destinations']` and `$data['active_flows']` ([] stub until onboarding merged); added `ghl_destinations_json()`, `ghl_dest_save()`, `ghl_dest_delete()`
- `Pitchsnap_model.php`: added `destinations_table` property + 6 GHL destination CRUD methods
- `pitchsnap.php`: added v25 (forms tables) and v26 (GHL destinations table with 5 seed rows); early-return check bumped to `>= 26`; version stored as `'26'`
- `admin_settings.php`: added GHL Destinations tab nav, tab pane, modal, JS; added Onboarding section with `$active_flows` loop; tab active logic updated to 3-way

**Production settings page crash is fixed** (via server-side patch applied last session). The git branch now also has the same fix so the next deployment will include it in-source.

**Items pending production deployment (from main):**
- Lead-profile tab hooks (restored 2026-08-23) — still not deployed to `clickfuzz.com/dashboard`
- Conversation empty state fix in `admin_lead.php` (2026-08-23) — still not deployed

---

## Workstream Status Overview

| Workstream | Status | Worktree |
|---|---|---|
| Generation | **Active** — synced to main `5bd774f` (2026-09-03). GHL Destinations feature reconciled from production into branch. Uncommitted — needs commit + production test. | `claude/generation` |
| Sales Flow | Implemented, purchase path needs end-to-end test | `claude/sales-flow` |
| Onboarding | Not started | `claude/onboarding` |
| Lead Connect | Not started | task worktrees created as needed |
| Reviews | Not started | no worktree yet |
| Reporting | View tracking only, no dashboard | no worktree yet |

See `docs/workstreams/` for detailed subsystem history and status.

---

## Active Branches / Worktrees

| Branch | Worktree | Status |
|---|---|---|
| `main` | `/Users/mymac/Desktop/Projects/software/Clickfuzz/dashboard` | Canonical — `5bd774f` (origin/main in sync) |
| `claude/generation` | `worktrees/dashboard/generation` | **Active** — synced to `5bd774f` + GHL Destinations (2026-09-03). Uncommitted changes: controller, model, pitchsnap.php, view, PASSDOWN.md. |
| `claude/ghl-integration` | `worktrees/dashboard/ghl-integration` | **Merged & deployed** — Phase 1 live at `ef02893`. Awaiting live connection test. |
| `claude/lead-capture` | `worktrees/dashboard/lead-capture` | Ready — from `0fe1d30`, no feature changes yet |
| `claude/onboarding` | `worktrees/dashboard/onboarding` | Ready — from `032b466`, no feature changes yet |
| `claude/publishing-domains` | `worktrees/dashboard/publishing-domains` | **Merged to main (2026-08-28)** — publishing-state foundation + Website Details Phase 1 (tab_pages/tab_media, canonical $is_published) + Phase 2 (Settings tab: Publishing/Integrations/Activity). Phase 5C (Cloudflare Custom Hostname automation) still paused. |
| `claude/recovery-audit` | `worktrees/dashboard/recovery-audit` | Complete — can be deleted after manual testing |
| `claude/settings` | `worktrees/dashboard/settings` | **Fresh** — from `7e0871e` (main post-publishing-domains merge). Global Settings page redesign. No feature changes yet. |
| `claude/sales-flow` | `worktrees/dashboard/sales-flow` | Ready — from `032b466`, no feature changes yet |
| `claude/site-management` | `worktrees/dashboard/site-management` | **Active** — 6-tab detail page + delete bug fix + tab extraction. Uncommitted changes: `admin_detail.php` (shell only), `Pitchsnap.php`, `Pitchsnap_model.php`, new `views/admin_detail/tab_*.php` (6 files). New partials not yet deployed to production. Needs deploy + verify + commit + merge to main. |
| `claude/convert-to-wp` | `worktrees/dashboard/convert-to-wp` | **Active** — Phases 1-4+C complete (nav, footer, custom logo, WP connector v1.3.0). Committed. `admin_detail.php` has uncommitted UI changes. |
| `claude/wp-connector` | `worktrees/dashboard/wp-connector` | **Active** — WP connector v2 (ClickFuzz-generated key pairing). Significant uncommitted changes (routes, controller, model, helpers, plugin files, assets copy). Needs commit + merge to main. |

---

## WordPress Connector — Confirmed Working (2026-08-30)

Full "Deploy to WordPress" flow confirmed end-to-end on `https://homesincda.com`:

- Theme build + deploy + activate ✓
- WXR content import (with logo) ✓

**Plugin source:** `wp-plugin/clickfuzz-connector/` (wp-connector worktree) + copy in `modules/pitchsnap/assets/wp-plugin/` (bundled with Perfex module for ZIP download/self-update).

**Key fixes landed (uncommitted in wp-connector worktree):**
- `wp_tempnam` guard — `deploy_theme()` + `update_plugin()` now `require_once` `wp-admin/includes/file.php` before calling `wp_tempnam()` (fatal in REST API context otherwise)
- `import_content()` — was passing `get_file_params()` array directly as `$xml_content`; now reads `$_FILES['xml']['tmp_name']` via `file_get_contents()` and extracts body params correctly

**Deploy method:** User downloads plugin ZIP from ClickFuzz dashboard and installs via WP Admin → Upload Plugin. Do NOT try to locate the installed plugin via DA MCP — it's not findable that way.

**Uncommitted changes in `claude/wp-connector`:** routes, Pitchsnap controller, model, wordpress helper, pitchsnap.php, plugin source files, assets copy. Must commit + merge to main.

---

## Global Blockers

- Production deployment of 2026-08-23 recovery changes (lead tab + conversation empty state)
- End-to-end purchase path not confirmed tested
- Lead Connect not started (gating Reviews and full Reporting)
- `claude/wp-connector` has significant uncommitted changes — needs commit + merge to main

---

## Generation Workstream — Session State (2026-08-26)

### What we built (Phases 1–5, branch `claude/generation`)

The complete internal-page pipeline is implemented and deployed. Commits on branch:
- `74b6306` — Phase 4 internal page AI generation (Anthropic, cron-based)
- `1a14711` — allow regeneration from generated state
- `e4dbc28` — Phase 5 hardening (body-only, canonical chrome, WP menus)
- `4dc54f9` — rewrite page_preview to use canonical site chrome
- `14f2436` — resolve 8 test failures across Phase 3/4/5 test suites

### What was deployed to production

All 30 changed files uploaded via DA MCP. DB migrated from v15 → v17:
- v16: created `tblpitchsnap_pages`, `tblpitchsnap_site_media`, `tblpitchsnap_page_media`, `tblpitchsnap_page_generations`
- v17: added `wp_primary_menu_item_id` + `wp_footer_menu_item_id` columns on `tblpitchsnap_pages`

**GOTCHA — migration anomaly (2026-08-26):** The `pitchsnap_db_version` is stored in `tbloptions` (via Perfex `add_option`/`update_option`), NOT in `tblsettings`. The v16 tables were created but `pitchsnap_db_version` was already set to `17` in a prior migration run (before the v17 ALTER TABLE code was deployed). The v17 gate fired early and skipped the column adds. Fixed by running `ps_migrate_v17.php` directly to add the two columns. Future migrations: verify tbloptions not tblsettings; be careful if deploying new migration code to a server that already ran part of the migration.

**GOTCHA — test runner and exit():** Phase 1/2/3 test files call `exit()` at the end. Using ob_start()/ob_get_clean() in a combined runner does NOT capture the output — exit() flushes all buffers and kills the process. Run each test file via a separate HTTP request (WebFetch per file, not via the runner).

### Validation status

| Item | Status |
|---|---|
| DB migration v16/v17 | ✓ Complete |
| PHP syntax (24 files) | ✓ All pass |
| Unit tests — Phase 1 Publishing (42) | ✓ Pass |
| Unit tests — Phase 2 Pages (51) | ✓ Pass |
| Unit tests — Phase 3 Pages (76) | ✓ Pass |
| Unit tests — Phase 4 Pages (97) | ✓ Pass (re-verified 2026-08-28) |
| Unit tests — Phase 5 Pages (168) | ✓ Pass (re-verified 2026-08-28) |
| Unit tests — Publishing Domains (103) | ✓ Pass |
| Diagnostic scripts removed from production | ✓ Complete (2026-08-28) — ps_dbcheck*.php, ps_migrate_v17.php, ps_ping.php, ps_run_all_tests.php, ps_summary.php, ps_syntax.php |
| WP chrome duplication — docs updated | ✓ Complete (2026-08-28) — fix confirmed present in code; stale known-issue entry retired |
| UI — Pages tab visible | ⚠ Pending manual browser test — published site ps-17-5f59 (JackRabbit/Lenka) exists and homepage is live |
| UI — Create page, configure, generate | Pending manual browser test |
| UI — Preview (canonical chrome) | Pending manual browser test |
| UI — Regenerate / version history | Pending manual browser test |
| UI — HTML publish | Pending manual browser test |
| UI — WordPress publish | Pending manual browser test |

### What to do next (in order)

**Merge complete (2026-08-28)** — `claude/generation` fast-forward merged to `main` at `0782213`. Workstream parked.

1. **Implement new Website Details architecture** — generation UI (page pipeline, page edit/preview/publish) will be rebuilt as part of redesigned Website Details page.

2. **Browser UI validation (deferred)** — end-to-end test of page pipeline (Add Page → configure → Generate → Preview → Publish) still pending. Site ps-17-5f59 (JackRabbit/Lenka) available when UI work resumes.

---

## Deployment Policy

1. Develop in a task worktree (branch off main)
2. Test in worktree
3. Commit
4. Merge into main
5. Run combined smoke tests on main
6. Deploy production via DA MCP file uploads
7. Verify production via DA MCP + production URLs

**Never deploy directly from a feature/recovery worktree.**

---

## Cross-Workstream Decisions

- **Lead Connect is part of the ClickFuzz Web module** — not a separate Perfex module.
- **All DB migrations live in `clickfuzz_web_db_upgrade()`** in `pitchsnap.php`. Increment the version gate (`pitchsnap_db_version`) after each schema change.
- **Secrets (API keys, Twilio credentials) go in `tbl_options`**, not in config files or git.
- **Probe/diagnostic scripts** are gitignored and self-deleting where possible. The permanent `ps_colorprobe.php` is token-protected and lives in the server root — it should eventually move to the admin UI.
- **Custom domain TLS is handled by Cloudflare for SaaS** — do not build per-domain DirectAdmin provisioning or Let's Encrypt automation.
- **Hosted-site infrastructure lives on a dedicated CrocWeb account** (`sites.clickfuzz.com`) separate from the main `clickfuzz.com` account.

---

## References

- `docs/workstreams/generation.md` — Generation pipeline detail
- `docs/workstreams/sales-flow.md` — Sales flow / prospect experience detail
- `docs/workstreams/onboarding.md` — Onboarding (not yet built)
- `docs/workstreams/lead-connect.md` — Lead Connect architecture and component split
- `docs/workstreams/reviews.md` — Review request lifecycle
- `docs/workstreams/reporting.md` — Reporting and analytics
