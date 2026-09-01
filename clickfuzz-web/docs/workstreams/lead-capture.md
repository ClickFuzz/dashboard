# Lead Capture Workstream

**Branch:** `claude/lead-capture`
**Worktree:** `worktrees/dashboard/lead-capture`

---

## Purpose

Website lead forms, quote/contact submissions, ClickFuzz-owned frontend lead capture UX, and normalized lead routing through the CRM integration layer (GHL).

---

## Architecture

### GHL Destination Registry

All GHL field mappings go through the `tblpitchsnap_ghl_destinations` table (added in DB migration v26):

| Column | Notes |
|---|---|
| `id` | PK |
| `label` | Display name shown in admin and form builder dropdown |
| `ghl_key` | GHL contact field key or custom field UUID |
| `mode` | `single` (one field per form) or `multiple` (aggregated) |
| `site_id` | NULL = global; site ID = per-site custom destination |
| `active` | Soft disable without deleting |
| `sort_order` | Display ordering |

**Global seeds (v26):** First Name, Last Name, Email, Phone, Quote Content.

**Quote Content** is the special aggregation destination: all fields mapped to it are concatenated as `Label: Value` lines and submitted to GHL as a custom field. The GHL UUID for Quote Content must be pre-configured per location in `tbloptions` as `pitchsnap_qrc_ghl_{md5(location_id)}`.

### Form Fields

`tblpitchsnap_forms.fields` is a JSON array. Each field object:

```json
{
  "label": "Building Size",
  "type": "select",
  "ghl_dest_id": 6,
  "ghl_field": "custom.abc123_uuid",
  "ghl_dest_mode": "single",
  "required": true,
  "options": ["Small", "Medium", "Large"]
}
```

`ghl_dest_id` is the canonical reference. `ghl_field` and `ghl_dest_mode` are denormalized at save time for runtime use.

### Runtime Submission

- `form_render/{form_id}` — renders HTML form; multi-select uses `data-cf-idx` attributes for index-based name binding (`cf_field[0]`, `cf_field[1]`, etc.)
- `form_submit` — routes via `_build_ghl_payload()`:
  - Single-mode standard destinations → standard GHL contact fields
  - Single-mode custom destinations → `customField[{ id: uuid, value: val }]`
  - Multiple-mode / Quote Content → aggregated into QRC custom field
- Legacy forms (no `ghl_dest_mode`) fall through to old QRC path

### Admin Endpoints

- `GET  pitchsnap/ghl_destinations_json/{site_id}` — returns global + site-specific destinations; `global: true` if `site_id IS NULL`
- `POST pitchsnap/ghl_dest_save` — create/update a destination; pass `site_id` to scope to a site
- `POST pitchsnap/ghl_dest_delete/{id}` — hard delete
- `GET  pitchsnap/form_placements_json/{form_id}` — placements for a form
- `POST pitchsnap/form_save/{site_id}` — create/update form with server-side single-destination duplicate check
- `POST pitchsnap/form_delete/{form_id}` — delete custom form
- `POST pitchsnap/form_placement_add/{form_id}` — add page placement
- `POST pitchsnap/form_placement_remove/{placement_id}` — remove placement

---

## Confirmed Working

*(none — not yet deployed to production)*

---

## Implemented / Needs Testing

### GHL Destination Registry

- DB migration v26 creates `tblpitchsnap_ghl_destinations` and seeds 5 global rows.
- Model CRUD: `get_ghl_destinations`, `get_ghl_destination`, `create_ghl_destination`, `update_ghl_destination`, `delete_ghl_destination`.
- Admin Settings → GHL Destinations tab: full table + add/edit modal for global destinations.

### Per-Site Custom GHL Fields

- Admin Settings → Site Detail → Forms tab → **Custom GHL Fields** button.
- Opens a panel listing all site-specific destinations for the current site (rows where `site_id = <current_site_id>`).
- Table columns: Display Name, GHL Custom Field Key, Input Mode, Edit/Delete actions.
- Footer row: empty inputs to add a new destination inline.
- AJAX via existing `ghl_dest_save` / `ghl_dest_delete` endpoints.
- After add/edit/delete, `cachedGhlDests` is invalidated so the form builder dropdown refreshes automatically on next use.
- Site-specific destinations appear in the form builder destination dropdown alongside global ones, marked with `✦`.

### Form Builder (destination-aware)

- GHL destination dropdown loaded once per page via `ghl_destinations_json/{site_id}` (cached as `cachedGhlDests`).
- Options grouped into "Single Input" / "Multiple Inputs" optgroups.
- Single Input destinations disabled in other rows when already selected (deduplication).
- Server-side duplicate check on `form_save` for Single Input destinations.

### Runtime Submission

- Index-based field naming (`cf_field[0]`, `cf_field[1]`) prevents name collisions.
- `_build_ghl_payload()` routes each field to the correct GHL target by destination mode.
- Legacy forms (no dest metadata) fall through to QRC aggregation path.

### Field Types

All types supported: text, email, phone, textarea, number, select, multi_select, date, checkbox.
Select/multi_select have an options input row in the form builder.

---

## In Progress

*(nothing — all implemented, awaiting deploy + test)*

---

## Known Issues / Risks

- **Quote Content GHL UUID:** Must be pre-configured manually per location in `tbloptions` as `pitchsnap_qrc_ghl_{md5(location_id)}`. No auto-create; submission will fail gracefully if missing.
- **GHL custom field key format:** GHL uses either a plain UUID or a `custom.{key}` dot-notation key. Confirm which format a given GHL sub-account uses before registering.
- **Delete of used destinations:** Deleting a destination that form fields are mapped to leaves those fields with a dangling `ghl_dest_id`. Forms will silently skip the field at submission. No cascade warning currently shown.

---

## Explicit Non-Goals

- Do NOT build GHL integration logic directly — route through the GHL adapter.
- Do NOT build publishing or domain routing.
- Do NOT merge into `main` without explicit instruction.
- Do NOT deploy to production without explicit instruction.
- Do NOT query GHL for custom fields or create them — registration here is always manual.

---

## Next

1. Deploy to production and test:
   - Site detail → Forms tab → **Custom GHL Fields** → add a site-specific destination (use a real GHL custom field key from the sub-account).
   - Return to Forms → New Form → verify the site-specific destination appears in the dropdown under "Single Input" (marked ✦).
   - Save a form with the custom destination mapped → submit from the published page → verify value arrives in GHL on the correct custom field.
   - Admin Settings → GHL Destinations → add/edit/delete a global destination — verify it appears/disappears in the form builder.
   - Test Single Input deduplication: select same destination in two rows → verify second row shows "(in use)".
   - Submit form → verify Quote Content custom field receives aggregated values.
2. Confirm the GHL custom field key format required by this client's sub-account (`custom.{key}` vs plain UUID).
3. Verify QRC submission reaches GHL correctly (requires `pitchsnap_qrc_ghl_{md5(location_id)}` pre-configured).

---

## History

- **2026-09-01:** Full GHL Destination Registry — DB schema (v26), model CRUD, admin UI (settings tab + per-site custom fields panel in Forms tab), form builder rewrite (destination-aware with deduplication), runtime index-based submission routing. All on `claude/lead-capture`, not yet deployed.
