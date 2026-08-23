# Reporting

## Scope

Metrics, dashboards, and analytics across all ClickFuzz Web activity.

**Owns:**
- Preview engagement (view count, device type, first/last viewed)
- Site engagement (on-site behavior, GA4/GTM data)
- Leads reporting (intake volume, source, status breakdown)
- Forms reporting (contact/quote form submissions)
- Calls reporting (call volume, connected vs. missed, call duration)
- SMS reporting (message volume, response rates)
- Sales / conversion reporting (leads → customers, revenue)
- Customer reporting (per-customer history)
- KPI dashboard (aggregate health metrics)
- Weekly reporting (automated summary)
- GA4 / GTM integration
- Attribution (source → lead → customer)

**Does not own:**
- Raw data collection (each workstream owns its own data; Reporting reads it)
- Billing management (→ Sales Flow / Perfex)

---

## Current State

**Partially implemented.** Preview engagement tracking (view count, first/last viewed, device type) is implemented in the Generation workstream's data layer. No dedicated reporting UI, KPI dashboard, or external analytics integration has been built yet.

---

## Confirmed Working

- `record_view($id, $device_type)`: increments `view_count`, sets `first_viewed_at` / `last_viewed_at` / device type on `tblpitchsnap_redesigns`
- `pitchsnap/track_view/:token` public endpoint captures each preview page load
- Admin detail page displays `view_count`, `first_viewed_at` for each redesign

---

## Implemented / Needs Retesting

- `get_websites_pending_admin_notification()` + `mark_admin_notified()`: used in cron to trigger admin alerts when sites need review. Exists but full notification flow (email delivery) needs end-to-end verification.

---

## In Progress

- Nothing. No Reporting worktree is active as of 2026-08-23.

---

## Known Issues / Risks

- No dedicated reporting or analytics UI exists. All metrics are currently visible only in individual record detail pages.
- GA4 / GTM integration is planned but not implemented. Analytics IDs will be collected during onboarding; embedding them in the live site is a Reporting + Onboarding cross-workstream task.
- `ps_colorprobe.php` is a server-root diagnostic tool — it should eventually become a proper Admin Source Diagnostics page (Generation workstream owns this, but it surfaces in reporting context).
- Call and SMS reporting depends on Lead Connect being implemented first.

---

## Architecture / Important Decisions

- Reporting reads from existing tables (`tblpitchsnap_redesigns`, `tblpitchsnap_conversations`, `tblpitchsnap_sites`, `tblpitchsnap_calls` (future), `tblpitchsnap_sms` (future)). It does not write to them.
- The ClickFuzz Web admin sidebar is the natural home for a KPI dashboard page. It should use the Pitchsnap main controller and a dedicated `admin_reporting.php` view.
- Do not create a Git worktree for Reporting until there is enough data to report on (depends on Lead Connect and Reviews being partially live).

---

## Relevant Files

| File | Purpose |
|---|---|
| `modules/pitchsnap/models/Pitchsnap_model.php` | `record_view`, `get_websites_pending_admin_notification`, `get_all`, `get_latest_per_lead` |
| `modules/pitchsnap/controllers/Pitchsnap_runtime.php` | `track_view` endpoint |
| `modules/pitchsnap/views/admin_websites.php` | Website list (currently the closest thing to a dashboard) |

**DB tables (reporting reads from):** `tblpitchsnap_redesigns`, `tblpitchsnap_conversations`, `tblpitchsnap_sites`

---

## Production Status

View tracking is live in production. No reporting dashboard exists.

---

## Next

1. Build KPI dashboard admin page (intake volume, generation status breakdown, conversion rate, preview view counts)
2. Move `ps_colorprobe.php` into admin as Source Diagnostics page (Generation workstream drives this)
3. Plan GA4/GTM embedding for live customer sites (post-Onboarding)
4. Add call + SMS reporting after Lead Connect is live

---

## History

- **2026-08-23** — Reporting workstream defined; only preview view tracking is live
- **~2026-08** — View tracking implemented (`record_view`, `track_view` endpoint, device type capture)
- **~2026-08** — Admin notification tracking (`prospect_notified_at`, `admin_notified_at`) added to redesigns schema
