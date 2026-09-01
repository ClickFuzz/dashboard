# Lead Capture Workstream

**Branch:** `claude/lead-capture`
**Worktree:** `worktrees/dashboard/lead-capture`

---

## Purpose

Website lead forms, quote/contact submissions, ClickFuzz-owned frontend lead capture UX, and normalized lead routing through the CRM integration layer (GHL).

---

## Architecture

- **Forms storage:** `tblpitchsnap_forms` (DB v25) — `fields` column is JSON array of `{label, type, ghl_field, required, options}`. No migration needed for GHL mapping features; all mapping data lives in the JSON.
- **Form submissions:** `tblpitchsnap_form_submissions` — audit log of submissions (form_id, site_id, ghl_contact_id, submitted_at).
- **Runtime endpoints:** `Pitchsnap_runtime` — `form_render/{form_id}` (GET), `form_submit` (POST JSON).
- **Admin endpoints:** `Pitchsnap` controller — form CRUD + new GHL field discovery + custom-field creation.
- **GHL library:** `Pitchsnap_ghl` — all GHL API calls. Extended with `get_custom_fields` and `create_custom_field`.
- **QRC field cache:** Per-location GHL "Quote Request Content" field ID cached in `tbloptions` as `pitchsnap_qrc_ghl_{md5(location_id)}`.

---

## Confirmed Working

*(none — not yet deployed to production)*

---

## Implemented / Needs Testing

### GHL Field Discovery (dynamic dropdown)

- **`GET pitchsnap/ghl_fields_json/{site_id}`** — returns standard GHL contact fields + custom fields fetched from the site's linked GHL location. Returns standard-only with a warning if location not connected.
- Admin form editor now loads GHL field options dynamically when the editor opens (cached per page load). Falls back gracefully if GHL is not connected.
- Standard fields included: `firstName`, `lastName`, `email`, `phone`, `name`, `address1`, `city`, `state`, `postalCode`, `website`, `companyName`.
- Custom fields: fetched via `GET /locations/{id}/customFields`; stored by GHL `id` (UUID) as `ghl_field` value.
- Field options grouped into "Standard GHL Fields" / "Custom GHL Fields" optgroups.

### Duplicate Mapping Protection

- **UI:** When any GHL field is selected in a form row, all other rows' dropdowns disable that option and mark it "(in use)". Runs on change and on editor open.
- **Server-side:** `form_save` validates no two fields share the same non-empty `ghl_field` value before saving. Returns a 200 JSON error if duplicate detected.

### Create GHL Custom Field

- **`POST pitchsnap/ghl_create_custom_field/{site_id}`** — creates a GHL custom field for the site's location.
- ClickFuzz type → GHL dataType: `text/email/phone/textarea → TEXT`, `number → NUMERICAL`, `select → DROPDOWN`, `multi_select → CHECKBOX`, `date → DATE`, `checkbox → CHECKBOX`.
- Duplicate prevention: checks existing custom fields by name (case-insensitive) before creating; returns existing field ID if found.
- After successful creation, admin UI invalidates the GHL field cache, reloads all options, and auto-selects the new field in the originating row.
- **"Create in GHL" button** added to each field row in the form editor (only meaningful for fields that will become quote-specific custom fields).

### Quote Request Content

- On `form_submit`, builds a formatted string of `{Label}: {Value}` lines for all non-empty quote-specific fields (fields whose `ghl_field` is NOT a standard GHL contact key).
- Multi-select values joined with `, `.
- Empty values and standard contact fields excluded from QRC.
- QRC GHL custom field ("Quote Request Content") found or created automatically per location on first submission, then ID cached in `tbloptions`.
- QRC submitted as `customField[{ id: qrc_field_id, value: qrc_text }]` alongside normal payload.

### Submission Fault Tolerance

- Attempt 1: full payload (standard contact fields + custom mapped fields + QRC).
- Attempt 2 (if attempt 1 fails): standard contact fields + QRC only (custom field mappings stripped). Logs a `log_activity` warning.
- Logs GHL failure details without exposing sensitive data.
- Submission audit record always written regardless of GHL outcome.

### Field Types

- `select` (Single Select) and `multi_select` (Multi Select) already implemented (commit `78b28b4`).
- Runtime `form_render` renders select as `<select>`, multi_select as checkboxes.
- Runtime `form_submit` handles multi-select arrays: joined with `, ` for GHL standard fields, passed as array then joined for QRC formatting.
- `_map_to_ghl_contact` updated with legacy snake_case aliases (backward compat for forms saved before GHL field discovery feature) + native camelCase GHL keys.

---

## In Progress

*(nothing currently in progress — awaiting deploy + test)*

---

## Known Issues / Risks

- **GHL dataType mapping for `multi_select`:** Mapped to GHL `CHECKBOX` type. Verify GHL accepts this for contact custom fields — may need to be `MULTIPLE_OPTIONS` depending on GHL version.
- **QRC field dataType:** Created as `TEXT`. If GHL requires `LARGE_TEXT` for multi-line content, the field may truncate. Monitor in production.
- **Unmapped fields:** Form fields with no `ghl_field` set are excluded from QRC (since there's no reliable key to look up their submitted value). Admin should always set GHL mappings for quote question fields.
- **Legacy form fields:** Existing forms that use the old snake_case GHL keys (`first_name`, `last_name`, etc.) continue to work via backward-compat aliases in `_map_to_ghl_contact`.
- **GHL custom field `id` response shape:** Assumes `data.customField.id`. If GHL returns a different shape, field ID extraction may fail. Verify with a real API call.

---

## Explicit Non-Goals

- Do NOT build GHL integration logic directly — route through `Pitchsnap_ghl`.
- Do NOT build publishing or domain routing.
- Do NOT merge into `main` without explicit instruction.
- Do NOT deploy to production without explicit instruction.

---

## Next

1. Deploy to production and test:
   - Open a site detail page → Forms tab → New Form → Add fields → observe GHL field dropdown populates from GHL location.
   - Map fields → verify duplicate protection prevents selecting same GHL field twice.
   - Use "Create in GHL" button for a quote question → verify GHL custom field created, auto-selected.
   - Submit the form from a published page → verify GHL contact created with standard fields, custom mapped fields, and "Quote Request Content" custom field.
   - Submit with an invalid custom field ID → verify fallback attempt delivers standard fields + QRC.
2. Verify GHL `CHECKBOX` dataType is accepted for multi_select fields; adjust to `MULTIPLE_OPTIONS` if needed.
3. Verify QRC `TEXT` dataType handles multi-line content in GHL; upgrade to `LARGE_TEXT` if truncation observed.
4. Verify `data.customField.id` response shape from GHL `create_custom_field` call.

---

## History

- **2026-09-01:** GHL field discovery, duplicate mapping protection, "Create in GHL" button, Quote Request Content, submission fault tolerance — all implemented on `claude/lead-capture`. Not yet deployed.
