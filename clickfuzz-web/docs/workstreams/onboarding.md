# Onboarding

## Scope

Post-purchase customer setup: collecting everything needed to finalize and maintain the live website. Begins after a customer completes payment and ends when the site is fully configured and live.

**Owns:**
- Business identity (name, address, service area, hours)
- Staff / team information
- Services offered
- Photos
- Testimonials
- Certifications
- Quote request configuration
- Contact configuration (phone, SMS, email)
- Review platform configuration
- Analytics IDs (GA4, GTM)
- DNS / domain information
- Legal / consent configuration
- Onboarding checklist
- Onboarding status tracking

**Does not own:**
- Payment / purchase (→ Sales Flow)
- AI website generation (→ Generation)
- Lead Connect calling/SMS system (→ Lead Connect)
- Review request sending/tracking (→ Reviews)

---

## Current State

**Phase 1H implemented and deployed.** Completion timestamp, admin visibility, and Revoke-on-completed added. Phase 1G-E deployed and manually verified prior to this session.

**Phase 1I implemented and deployed.** Automatic onboarding link creation + onboarding email on successful payment (both one-time and subscription). Idempotent via `onboarding_link_id` on `tblpitchsnap_sites` (DB v38).

**Phase 1I changes:**

- **DB v38:** `onboarding_link_id INT DEFAULT NULL` added to `tblpitchsnap_sites` (idempotency marker).
- **`Pitchsnap_runtime::_trigger_onboarding($site_id, $client_id)`**: checks `onboarding_link_id` (skip if set), reads `pitchsnap_onboarding_flow_id` setting, creates onboarding link, stores link id on site, resolves primary contact email, sends `pitchsnap-onboarding` email.
- **`subscription_complete()`**: calls `_trigger_onboarding` after marking site `subscriber`.
- **`payment_complete()`**: looks up `tblpitchsnap_sites` via `invoice_id`, calls `_trigger_onboarding` after payment is recorded.
- **`clickfuzz_web_register_email_templates()`**: registers `pitchsnap-onboarding` template with `{{contact_firstname}}` and `{{onboarding-link}}` variables.
- **Settings** (`_save_settings`, `settings()`, `admin_settings.php`): `pitchsnap_onboarding_flow_id` option added; Onboarding section on settings page with active-flow dropdown.

**Phase 1H changes:**

- **DB v37:** `completed_at DATETIME DEFAULT NULL` added to `tblpitchsnap_onboarding_links` (idempotent ALTER TABLE migration).
- **`onboarding_submit()`**: sets `completed_at` only on first successful completion (`empty($link['completed_at'])`); re-submissions leave timestamp unchanged.
- **`get_onboarding_links_for_site()`**: SELECT now includes `completed_at`.
- **Admin Onboarding tab**: Completed column added; Revoke button now available for both `active` and `completed` links; completed badge has tooltip "Submitted — still editable until revoked".

**Phase 1G-E state (deployed and verified):**
- `onboarding()` and `onboarding_embed()` now allow `active` OR `completed` links to open the wizard (only `revoked` is blocked).
- `onboarding_submit()` allows re-submission on completed links; upsert handles deduplication.
- New `onboarding_save_progress` endpoint: validates token, loads flow questions, upserts only visible submitted answers, no required-field check, does not mark completed.
- Wizard view: `_wiz_field()` now takes `$existing_val` parameter and prefills all field types (text/textarea/select/radio/checkbox/yes_no/question_builder). `question_builder` uses `data-prefill` attribute; JS `_qbInit()` restores existing rows.
- `wizGo()` now saves current-step visible answers via `wizSaveStep()` before advancing forward; navigation only completes after save succeeds.
- `_ob_normalize_value()` extracted as shared private helper used by both submit and save_progress.

---

## Confirmed Working

- Phase 1G-D: wizard submit end-to-end (token validation → visibility evaluation → required-field check → upsert site_data → mark completed → thank-you screen). Verified on production 2026-09-02.

---

## Implemented / Needs Retesting

### Phase 1A — Onboarding Flows foundation (2026-09-02)

- **DB migration v27:** `tblpitchsnap_onboarding_flows` (id, name, description, status, created_at, updated_at)
- **Model:** `get_flow`, `get_all_flows`, `create_flow`, `update_flow`, `delete_flow` on `Pitchsnap_model`
- **Controller:** `flows` (list), `flow_save` (create/edit), `flow_toggle`, `flow_duplicate`, `flow_delete` on `Pitchsnap`
- **View:** `modules/pitchsnap/views/admin_flows.php` — table + Bootstrap modal + AJAX actions
- **Menu:** "Onboarding Flows" sidebar child at `pitchsnap/flows` (position 2; Settings bumped to 3)

### Phase 1B — Onboarding Sections (2026-09-02)

- **DB migration v28:** `tblpitchsnap_onboarding_sections` (id, flow_id, name, description, sort_order, created_at, updated_at)
- **Model:** `get_section`, `get_sections_for_flow`, `flow_has_sections`, `create_section`, `update_section`, `delete_section`, `move_section` on `Pitchsnap_model`
- **Controller:** `flow_sections` (page), `section_save` (create/edit AJAX), `section_delete` (AJAX), `section_move` (up/down AJAX) on `Pitchsnap`; `flow_delete` updated to block if sections exist
- **Views:** `admin_flow_sections.php` (new) — table + modal + ▲/▼ reorder; `admin_flows.php` updated with Sections button per row and error-message display on failed delete

---

## In Progress

- Phase 1I deployed; pending production test (configure a flow in Settings → Onboarding, run a test purchase, verify link created + email sent + idempotency on re-fire).

---

## Known Issues / Risks

- Phase 1A–1C not yet deployed or production-tested.
- Post-purchase trigger: the handoff from Sales Flow (`subscription_complete`) to a specific flow is not yet defined. The `tblpitchsnap_sites` record (with `client_id`) is the natural anchor — a `flow_id` column on `tblpitchsnap_sites` is likely needed in Phase 1B or later.
- CSRF: AJAX actions in the view pass the token via `$CI->security` in the view. If Perfex overrides CSRF handling differently, validate on first production load.

---

## Architecture / Important Decisions

- Onboarding data belongs to a `tblpitchsnap_sites` record, not the `tblpitchsnap_redesigns` record. A site may eventually switch to a newer generated design while keeping the same onboarding data.
- Onboarding will need both an admin-facing view (staff can see/edit) and a client-facing form (customer fills in their info). The client-facing side should use a token-protected public URL pattern consistent with the agreement page.
- **Flow design:** A flow is a named, reusable template (active/inactive). Sections and questions will be nested under flows in Phase 1B+. Flows are not yet linked to a specific site record — that linkage (`sites.flow_id`) comes when customer-facing onboarding is introduced.
- **Duplicate creates inactive copy** — duplicates default to inactive so staff explicitly activates a new flow before it can be assigned to customers.
- **Hard delete** chosen for flows because no foreign key references exist yet (no sections/questions). When Phase 1B adds child records, delete logic will need to cascade or soft-delete.

---

## Relevant Files

| File | Purpose |
|---|---|
| `modules/pitchsnap/pitchsnap.php` | DB migrations v27–v35 + menu registration |
| `modules/pitchsnap/models/Pitchsnap_model.php` | All CRUD: flows, sections, questions, usage_tags, question_tags, site_data, onboarding_links |
| `modules/pitchsnap/controllers/Pitchsnap.php` | Admin endpoints: all onboarding management |
| `modules/pitchsnap/controllers/Pitchsnap_runtime.php` | Public `onboarding_embed($token)` endpoint + `onboarding_loader_js()` loader script |
| `modules/pitchsnap/views/onboarding_loader_js.php` | JS loader served at `pitchsnap/onboarding_loader.js` — mounts iframe into `#clickfuzz-onboarding` |
| `modules/pitchsnap/views/admin_flows.php` | Flow list + create/edit modal |
| `modules/pitchsnap/views/admin_flow_sections.php` | Section list + create/edit modal + ▲/▼ reorder |
| `modules/pitchsnap/views/admin_section_questions.php` | Question list + create/edit modal + reorder + tag badges |
| `modules/pitchsnap/views/admin_usage_tags.php` | Usage tag library CRUD |
| `modules/pitchsnap/views/admin_detail.php` | Website detail page — Data + Onboarding tabs added |
| `modules/pitchsnap/views/admin_detail/tab_data.php` | Site key/value data table + add/edit/delete modal |
| `modules/pitchsnap/views/admin_detail/tab_onboarding.php` | Onboarding links list + create link modal + revoke |
| `modules/pitchsnap/views/onboarding_wizard.php` | Self-contained public wizard — all field types, multi-step, conditionals |

**DB tables:** flows, sections, questions, usage_tags, question_tags (join), site_data, onboarding_links

---

## Production Status

Phases 1G-D, 1G-E, 1H, and 1I all deployed. Migration v38 runs automatically on next admin page load (`onboarding_link_id` on `tblpitchsnap_sites`). `pitchsnap-onboarding` email template seeded on next page load. Pending production test of 1I (set flow in settings, trigger a payment, verify link + email + idempotency).

---

## Next

1. **Production test Phase 1I:** Set an active flow in Settings → Onboarding. Run a test purchase (or trigger `subscription_complete` / `payment_complete` directly). Verify: onboarding link created, `Onboarding Email` received, `{{onboarding-link}}` resolves, link appears in site's Onboarding admin tab. Re-fire the payment handler → verify no duplicate link or email.
2. Commit Phase 1I work on `claude/onboarding`, merge to `main`.
3. **Phase 1J (future):** Trigger GHL contact creation / A2P registration after onboarding completion (out of scope for this phase).

---

## History

- **2026-08-23** — Onboarding workstream defined as a distinct area; worktree created
- **2026-09-02** — Phase 1A implemented: `tblpitchsnap_onboarding_flows` (DB v27), model CRUD, admin controller, admin list+modal view, sidebar menu item
- **2026-09-02** — Phase 1B implemented: `tblpitchsnap_onboarding_sections` (DB v28), section CRUD + move_section + flow_has_sections model methods, 4 controller endpoints, admin_flow_sections.php view, Sections button in flows table, flow_delete guard
- **2026-09-02** — Phase 1D implemented: DB v30 ALTER TABLE adds `data_key` VARCHAR(100) + `purpose` VARCHAR(30) to questions; server validation (required, regex, uniqueness); `question_data_key_exists` model method; modal updated with Data Key + Purpose fields; auto-suggest from label; data_key shown in-line under label in table; purpose badge shown when non-default
- **2026-09-02** — Phase 1C implemented: `tblpitchsnap_onboarding_questions` (DB v29), question CRUD + reorder_questions + section_has_questions model methods, 4 controller endpoints, admin_section_questions.php view with drag-and-drop reorder, Questions button in sections table, section_delete guard
- **2026-09-02** — Phase 1F implemented: `tblpitchsnap_onboarding_usage_tags` + `tblpitchsnap_onboarding_question_tags` (DB v33), 8 default tags seeded, Usage Tags admin page (list/create/edit/delete-guard), question modal updated with multi-select tag checkboxes, tags shown as badges in question list, `sync_question_tags` model method, tag assignment stored in join table
- **2026-09-02** — Phase 1G-A/B implemented: `tblpitchsnap_site_data` (DB v34, site_id+data_key unique), model: `get_site_data`, `get_site_data_value`, `upsert_site_data`, `delete_site_data_by_id`; Data tab on website detail page with table (key/value/updated), JSON pretty-print display, admin add/edit/delete via modal
- **2026-09-02** — Phase 1G-C.1 implemented: `question_builder` field type added — controller validation, admin dropdown + label map, public wizard repeatable question editor (label + input type + options editor for select/radio/checkbox, drag reorder, serializes to JSON array stored in hidden input)
- **2026-09-02** — Phase 1G-C implemented: `tblpitchsnap_onboarding_links` (DB v35, UNIQUE token), model: `create_onboarding_link`, `get_onboarding_links_for_site`, `get_onboarding_link_by_token`, `revoke_onboarding_link`; admin Onboarding tab on website detail page (link list + create modal + revoke); public `Pitchsnap_runtime::onboarding($token)` renders self-contained wizard with all 10 field types, multi-step navigation, client-side conditional show/hide; Submit button disabled (no answer saving yet)
- **2026-09-02** — WordPress embed delivery: new `onboarding_embed` endpoint (GET `?token=`) renders wizard HTML page; new `onboarding_loader_js` endpoint serves dedicated `onboarding_loader_js.php` JS that mounts an iframe into `#clickfuzz-onboarding`; WP page publish pipeline replaces `<!-- CLICKFUZZ_ONBOARDING -->` marker with embed div + script tag; admin link URLs now driven by `pitchsnap_onboarding_page_url` setting (fallback: direct embed URL); old `/onboarding/{token}` path-based route left as-is (returns 404 since no WP page matches that path)
- **2026-09-02** — Phase 1H implemented and deployed: DB v37 adds `completed_at` to `tblpitchsnap_onboarding_links`; `onboarding_submit()` sets `completed_at` on first completion only; `get_onboarding_links_for_site()` includes `completed_at` in SELECT; admin Onboarding tab gains Completed column, Revoke button for completed links, and badge tooltip clarifying link remains editable until revoked
- **2026-09-02** — Phase 1I implemented and deployed: `onboarding_link_id` on `tblpitchsnap_sites` (DB v38); `_trigger_onboarding()` private method in `Pitchsnap_runtime`; called from `subscription_complete` (subscription payments) and `payment_complete` (one-time payments); `pitchsnap-onboarding` email template registered with `{{contact_firstname}}` and `{{onboarding-link}}`; `pitchsnap_onboarding_flow_id` setting with flow dropdown on admin settings page
- **2026-09-02** — Phase 1G-E implemented: completed links reopen (only revoked blocked); `onboarding_save_progress` endpoint (validates token, loads flow questions, upserts visible submitted answers, no required-field check, no status change); wizard prefills all field types from `tblpitchsnap_site_data` (`_wiz_field` takes `$existing_val`; `question_builder` uses `data-prefill` attr + JS `_qbInit` restores rows); `wizGo()` saves current step via `wizSaveStep()` before advancing; `_ob_normalize_value()` extracted as shared helper; route added for save_progress
- **2026-09-02** — Phase 1G-D implemented: `Pitchsnap_runtime::onboarding_submit()` (JSON body POST, CSRF-free); token validation → link lookup → server-side question load → visibility evaluation (equals/not_equals/contains) → required-field check → normalize (checkbox→JSON array, question_builder→normalized JSON) → `upsert_site_data()` per visible question with data_key → mark link `completed`; wizard view wired up: Submit button enabled with `wizSubmit()`, JS collects all q_ answers as JSON (checkbox as array), POSTs to `/pitchsnap/onboarding_submit`, shows thank-you on success, inline error on failure; `_wizReportHeight()` called after both outcomes
