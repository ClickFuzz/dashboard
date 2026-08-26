# GHL Integration Workstream

**Branch:** `claude/ghl-integration`
**Worktree:** `worktrees/dashboard/ghl-integration`
**DB version added:** 14 (v12 = site_domains, v13 = SSL/verification columns — both from publishing-domains Phase 5A)

---

## Purpose

GoHighLevel is the operational infrastructure layer under ClickFuzz. Each ClickFuzz client will have a GHL sub-account/location. This workstream builds the minimal API layer and admin tooling needed to link a ClickFuzz site to a GHL location and verify connectivity — without prematurely implementing any lead, SMS, or workflow functionality.

---

## Architecture

- **GHL plan:** Agency $297 (manual location provisioning + snapshot application)
- **Auth:** Agency Private Integration Token (Bearer token), stored in `tbl_options` as `pitchsnap_ghl_api_key` (option key unchanged for backwards compat)
- **API base:** `https://services.leadconnectorhq.com` (GHL v2)
- **Mapping:** One row in `tblpitchsnap_ghl_locations` per site (`site_id` unique key)
- **Library:** `modules/pitchsnap/libraries/Pitchsnap_ghl.php` — all GHL HTTP calls go here
- **Model:** `modules/pitchsnap/models/Pitchsnap_ghl_model.php` — mapping persistence
- **UI:** `modules/pitchsnap/views/admin_detail/tab_ghl.php` — replaces publishing-domains placeholder, served by `admin_detail.php`'s tab architecture (`#tab-ghl`). `admin_detail.php` itself was not modified by this workstream.

---

## Confirmed Working

*(none — not yet deployed to production)*

---

## Implemented / Needs Testing

- **DB migration (v14):** `tblpitchsnap_ghl_locations` table created via `clickfuzz_web_db_upgrade()`. Fields: `id`, `site_id`, `ghl_location_id`, `ghl_location_name`, `status`, `last_error`, `last_verified_at`, `created_at`, `updated_at`. (v12 = site_domains table; v13 = SSL/verification columns — both publishing-domains.)
- **Global config:** `pitchsnap_ghl_api_key` option seeded in v13 migration. GHL section added to admin Settings page (`pitchsnap/settings`).
- **GHL API client:** `Pitchsnap_ghl` — `get_location($location_id)` via `GET /locations/{id}`. Handles auth header, version header, timeout, HTTP status, structured errors. Does not log the API key.
- **GHL model:** `Pitchsnap_ghl_model` — `get_by_site($site_id)`, `mark_connected($site_id, $location_id, $location_name)`.
- **Link location:** `POST pitchsnap/ghl_link_location/{site_id}` — verifies location via GHL API, stores mapping on success, rejects without modifying existing record on failure.
- **Test connection:** `POST pitchsnap/ghl_test_connection/{site_id}` — re-verifies the linked location, updates `last_verified_at` on success.
- **Admin UI tab:** `tab_ghl.php` — shows agency connection status, location details, link/update input, Test Connection button. `$site` must be non-null for the linking controls to render; otherwise shows a "no site record" notice.

---

## In Progress

*(nothing currently in progress)*

---

## Known Issues / Risks

- **Token format:** Agency Private Integration Tokens are long JWT-style strings. The settings field uses `type="password"` and blank-preserve logic — verify save behavior on first entry vs. subsequent visits.
- **Location name parsing:** GHL v2 `GET /locations/{id}` response shape assumed to be `{ "location": { "name": "..." } }`. If GHL changes the response shape, `mark_connected` will fall back to storing the location ID as the name. Confirm with a real API call.
- **Sub-account creation:** Not implemented. Locations must be manually created in GHL and the ClickFuzz snapshot applied before linking. This is acceptable for MVP.
- **No OAuth flow:** Using Agency Private Integration Token (static). If GHL requires OAuth for future endpoints, auth is localized to `Pitchsnap_ghl::request()` — callers are unaffected.

---

## Explicit Non-Goals (This Phase)

Do not implement: lead/contact creation, opportunities, SMS, conversations, LC Phone, A2P registration, missed-call text-back, Lead Connect, workflows, reviews, automatic sub-account creation, snapshot provisioning.

---

## Next

1. Deploy to production and test: enter Agency Private Integration Token in Settings → GHL, create a site record, link a location ID, verify Test Connection returns success.
2. Confirm `GET /locations/{id}` response shape and adjust name extraction if needed.
3. When lead-form workstream is ready: extend `Pitchsnap_ghl` with `create_contact($location_id, $data)` for the contact/lead upsert call.

---

## History

- **2026-08-25:** Phase 1 implemented — DB migration, GHL library, model, admin partial, controller actions, settings. Not yet deployed to production.
- **2026-08-25:** Synced publishing-domains Phase 5A (v13 SSL/verification columns) into worktree. GHL migration renumbered from v13 → v14 to avoid collision. All GHL changes re-applied cleanly; no conflicts.
