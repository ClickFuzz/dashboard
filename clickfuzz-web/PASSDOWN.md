# ClickFuzz Web — Global Passdown

## What This Is

ClickFuzz Web is a custom Perfex CRM module. It handles website generation, prospect sales flow, customer onboarding, lead engagement (Lead Connect), review collection, and reporting for ClickFuzz clients.

**Server:** `clickfuzz.com/dashboard`
**DA Panel:** `homesincda.com:2222`, user `clgorman`
**DB:** `clgorman_clickfuzzdashboard` (table prefix: `tbl`)
**Stack:** Perfex CRM 3.1.4 / CodeIgniter 3.1.11 / PHP 8.3.33
**Module:** `pitchsnap` (slug), active (tblmodules id=23, active=1)
**DB schema version:** 13 (live on production)

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

### End-to-end validation (2026-08-26)

`www.eddmautofill.com` → Cloudflare Custom Hostname → Worker → `sites.clickfuzz.com`

- Cloudflare hostname status: Active
- Cloudflare certificate: Active
- HTTPS: working
- Full chain to PHP runtime: confirmed

### Current blocker — X-ClickFuzz-Host header

CrocWeb requires the origin `Host` to be `sites.clickfuzz.com`. The Worker must proxy to `https://sites.clickfuzz.com` (preserving that Host) while passing the original customer hostname in `X-ClickFuzz-Host`.

The PHP runtime must be changed to:
- use `HTTP_HOST` for all normal routing as-is
- ONLY when `HTTP_HOST === sites.clickfuzz.com`, accept `X-ClickFuzz-Host` as the routing hostname
- apply the same normalization/validation to `X-ClickFuzz-Host` as to `HTTP_HOST`
- never trust `X-ClickFuzz-Host` from arbitrary direct clients

**This PHP change has NOT been made yet.** It is the immediate next task.

After the PHP change is committed and `index.php` is uploaded to the new server, test `https://www.eddmautofill.com/` — expected result: runtime identifies the hostname, resolves its storage slug, and serves the generated HTML.

### What still needs to happen (Phase 5C+)

1. ~~Move runtime + storage to new CrocWeb account~~ — done.
2. Implement `X-ClickFuzz-Host` header handling in `hosted-runtime/index.php`.
3. Update Cloudflare Worker to pass `X-ClickFuzz-Host` and proxy to `sites.clickfuzz.com`.
4. End-to-end test: `www.eddmautofill.com` serves correct HTML.
5. Update `clickfuzz_web_publish_site()` to write to new server (currently writes to old server filesystem).
6. Build Cloudflare Custom Hostname API automation for custom domain lifecycle.
7. Update DNS instructions in the admin UI (`CNAME www → customers.clickfuzz.com`).

### Phase 5B DNS verification — provisional status

The Phase 5B DNS helper (`pitchsnap_dns_helper.php`) and verification UI are deployed. Current DNS instructions (direct A record `104.152.168.38`) are **provisional** — they will change to `CNAME www → customers.clickfuzz.com` once the Worker+runtime path is fully wired up.

### Manual/untracked state (important)

- `runtime-config.php` on new server: secrets, never in git. Local copy at `/tmp/runtime-config.php` on dev machine (temporary).
- Cloudflare Worker code: currently manual, not in repo.
- Cloudflare for SaaS routes/custom-hostname config: manual.
- New CrocWeb server deployments: manual (DA MCP cannot reach `xqsfhrlj` account).

---

## Current Production / Main Baseline

As of 2026-08-26, local `main` is at `739f16e` and `origin/main` is in sync at `739f16e`. This merges GHL Phase 1 and Publishing Phases 1–5B. Production is verified hash-identical to main for all GHL Phase 1 files; Publishing 5B files (DNS helper, controller, model, view) are also deployed to production.

**Items pending production deployment:**
- Lead-profile tab hooks (restored 2026-08-23) — still not deployed
- Conversation empty state fix in admin_lead.php (2026-08-23) — still not deployed

**GHL Phase 1 deployed 2026-08-26 (hash-verified):**
- `modules/pitchsnap/controllers/Pitchsnap.php`
- `modules/pitchsnap/pitchsnap.php`
- `modules/pitchsnap/views/admin_detail/tab_ghl.php`
- `modules/pitchsnap/views/admin_settings.php`
- `modules/pitchsnap/libraries/Pitchsnap_ghl.php` (new)
- `modules/pitchsnap/models/Pitchsnap_ghl_model.php` (new)

---

## Workstream Status Overview

| Workstream | Status | Worktree |
|---|---|---|
| Generation | Live in production, needs retesting on a few paths | `claude/generation` |
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
| `main` | `/Users/mymac/Desktop/Projects/software/Clickfuzz/dashboard` | Canonical — `739f16e` (origin/main in sync) |
| `claude/generation` | `worktrees/dashboard/generation` | Active — at `7ade348`, feature changes present |
| `claude/ghl-integration` | `worktrees/dashboard/ghl-integration` | **Merged & deployed** — Phase 1 live at `ef02893`. Awaiting live connection test (enter Agency Private Integration Token, link a Location ID, verify Test Connection). |
| `claude/lead-capture` | `worktrees/dashboard/lead-capture` | Ready — from `0fe1d30`, no feature changes yet |
| `claude/onboarding` | `worktrees/dashboard/onboarding` | Ready — from `032b466`, no feature changes yet |
| `claude/publishing-domains` | `worktrees/dashboard/publishing-domains` | **Merged** — Phases 1–5B complete at `739f16e`. DB v13 live. **Phase 5C next** — migrate hosted-site runtime + storage to new CrocWeb account, then build Cloudflare Custom Hostname API automation. DirectAdmin per-domain provisioning rejected. DNS instructions in UI are provisional (will change to `CNAME www → customers.clickfuzz.com`). |
| `claude/recovery-audit` | `worktrees/dashboard/recovery-audit` | Complete — can be deleted after manual testing |
| `claude/sales-flow` | `worktrees/dashboard/sales-flow` | Ready — from `032b466`, no feature changes yet |
| `claude/site-management` | `worktrees/dashboard/site-management` | **Active** — 6-tab detail page + delete bug fix + tab extraction. Uncommitted changes: `admin_detail.php` (shell only), `Pitchsnap.php`, `Pitchsnap_model.php`, new `views/admin_detail/tab_*.php` (6 files). New partials not yet deployed to production. Needs deploy + verify + commit + merge to main. |
| `claude/convert-to-wp` | `worktrees/dashboard/convert-to-wp` | Fresh — from `0fe1d30`, no feature changes yet |

---

## Global Blockers

- Production deployment of 2026-08-23 recovery changes (lead tab + conversation empty state)
- End-to-end purchase path not confirmed tested
- Lead Connect not started (gating Reviews and full Reporting)
- Hosted-site runtime + generated HTML storage not yet migrated to new dedicated `sites.clickfuzz.com` CrocWeb account — custom domains non-functional until this is done

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
