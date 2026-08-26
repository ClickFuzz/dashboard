# ClickFuzz Web — Global Passdown

## What This Is

ClickFuzz Web is a custom Perfex CRM module. It handles website generation, prospect sales flow, customer onboarding, lead engagement (Lead Connect), review collection, and reporting for ClickFuzz clients.

**Server:** `clickfuzz.com/dashboard`
**DA Panel:** `homesincda.com:2222`, user `clgorman`
**DB:** `clgorman_clickfuzzdashboard` (table prefix: `tbl`)
**Stack:** Perfex CRM 3.1.4 / CodeIgniter 3.1.11 / PHP 8.3.33
**Module:** `pitchsnap` (slug), active (tblmodules id=23, active=1)
**DB schema version:** 12 (publishing-domains branch; not yet deployed to production)

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

## Current Production / Main Baseline

As of 2026-08-26, `main` is at commit `ef02893` (GHL Phase 1). Production is verified hash-identical to main for all 6 GHL-changed files.

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
| `main` | `/Users/mymac/Desktop/Projects/software/Clickfuzz/dashboard` | Canonical — `0fe1d30` |
| `claude/generation` | `worktrees/dashboard/generation` | Active — at `7ade348`, feature changes present |
| `claude/ghl-integration` | `worktrees/dashboard/ghl-integration` | **Merged & deployed** — Phase 1 live at `ef02893`. Awaiting live connection test (enter Agency Private Integration Token, link a Location ID, verify Test Connection). |
| `claude/lead-capture` | `worktrees/dashboard/lead-capture` | Ready — from `0fe1d30`, no feature changes yet |
| `claude/onboarding` | `worktrees/dashboard/onboarding` | Ready — from `032b466`, no feature changes yet |
| `claude/publishing-domains` | `worktrees/dashboard/publishing-domains` | **Active** — Phases 1–4 complete. DB v12 + `tblpitchsnap_site_domains` live. Hosted-site runtime at `sites.clickfuzz.com`. `jackrabbit.clickfuzz.com` live. Publishing tab shows correct live URL, Open Site button, Republish state. Pending: merge to main, Phase 5 (custom domains). |
| `claude/recovery-audit` | `worktrees/dashboard/recovery-audit` | Complete — can be deleted after manual testing |
| `claude/sales-flow` | `worktrees/dashboard/sales-flow` | Ready — from `032b466`, no feature changes yet |
| `claude/site-management` | `worktrees/dashboard/site-management` | **Active** — 6-tab detail page + delete bug fix + tab extraction. Uncommitted changes: `admin_detail.php` (shell only), `Pitchsnap.php`, `Pitchsnap_model.php`, new `views/admin_detail/tab_*.php` (6 files). New partials not yet deployed to production. Needs deploy + verify + commit + merge to main. |
| `claude/convert-to-wp` | `worktrees/dashboard/convert-to-wp` | Fresh — from `0fe1d30`, no feature changes yet |

---

## Global Blockers

- Production deployment of 2026-08-23 recovery changes (lead tab + conversation empty state)
- End-to-end purchase path not confirmed tested
- Lead Connect not started (gating Reviews and full Reporting)

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

---

## References

- `docs/workstreams/generation.md` — Generation pipeline detail
- `docs/workstreams/sales-flow.md` — Sales flow / prospect experience detail
- `docs/workstreams/onboarding.md` — Onboarding (not yet built)
- `docs/workstreams/lead-connect.md` — Lead Connect architecture and component split
- `docs/workstreams/reviews.md` — Review request lifecycle
- `docs/workstreams/reporting.md` — Reporting and analytics
