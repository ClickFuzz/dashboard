# Workstream: Site Management

**Branch:** `claude/site-management`
**Worktree:** `worktrees/dashboard/site-management`
**View:** `modules/pitchsnap/views/admin_detail.php`
**Controller:** `modules/pitchsnap/controllers/Pitchsnap.php` → `detail()` action

---

## Goal

Reorganize the website details/admin page (`pitchsnap/detail/{id}`) into a compact tabbed control center for one ClickFuzz Web customer/site. No backend changes required — purely view reorganization.

---

## Confirmed Working

### Delete All ClickFuzz Web Data (fixed 2026-08-24)

The "Delete All ClickFuzz Web Data" button in the Customer tab → Danger Zone is now fully working.

**Root cause of original failure:** CI3's CSRF middleware unsets the CSRF token from `$_POST` after validation. The `ps_delete_profile_form` only contained the CSRF token field, so `$_POST` was empty by the time the controller ran. The `if (!$this->input->post())` guard fired and silently redirected without deleting anything or showing a flash message.

**Fix:** Added `<input type="hidden" name="confirm_delete" value="1">` to `ps_delete_profile_form`. Controller guard changed to `if ($this->input->post('confirm_delete') !== '1')`.

**What gets deleted:** All redesign records, conversations, agreements, site records, preview directories on disk (`/previews/{token}/`), and published site directories on disk (`/sites/{slug}/`). The Perfex lead and any Perfex customer record are preserved.

**Files changed:**
- `views/admin_detail.php` — added `confirm_delete` field to hidden form
- `controllers/Pitchsnap.php` — guard changed to check `confirm_delete`
- `models/Pitchsnap_model.php` — added published site directory cleanup to `delete_lead_profile()`

### Tabbed detail page (completed 2026-08-24)

Replaced the long single-column detail page with a compact header + 6-tab layout.

**Header:**
- Business/site name, Website ID, status badge, live domain (if set)
- Context-sensitive action buttons: View Site, Open Preview, Edit HTML

**Tab 1 — Overview (default):**
- Website summary card (version, status, provider, generated date, preview link)
- Customer summary card (name, email, lead status, agreement state)
- Publishing summary card (status, URL)
- GHL placeholder card
- Attention section: conditionally shows items needing action (not generated, failed, awaiting review, not published, agreement not accepted)

**Tab 2 — Website:**
- Current Website panel: generation status details + all action buttons inline
  - Generate / Approve & Send / Regenerate / Retry / New Version (status-conditional)
  - Edit HTML and AI Modify appear next to Regenerate whenever `generation_result` exists
  - AI Modify inline panel expands in the same card
  - Rendered prompt toggle (collapsible)
- Version History table: checkbox column (left), #, Status, Created, Provider, Actions
  - Primary versions: checkbox suppressed, last column shows `Primary` label pill
  - Non-primary: checkbox + View / Set Primary / Delete in last column
  - "Delete Selected" button at top of panel; `ps_bulk_delete()` + `ps_select_all()` JS
  - Per-row delete form preserved alongside bulk delete
- Prospect Engagement stats (views, first/last view, approved date)
- Preview card removed — Open Preview is in the page header; delete_preview available via per-row delete

**Tab 3 — Domain & Publishing:**
- Publishing panel: current status, URL, site token, Publish Site button (preserved exactly)
- Custom Domain placeholder section (Not configured for domain/DNS/SSL; "coming in a future update" note)

**Tab 4 — Customer:**
- Business section: original URL, business type (vertical), role, company size, desired improvement, created date
- Contact section: name, company, email, phone, source
- Account section: lead link, lead status, site status, agreement accepted state
- Danger Zone (red border, bottom of tab): Delete All ClickFuzz Web Data button — preserved with existing confirmation behavior

**Tab 5 — GHL:**
- Placeholder: status Not configured, Location —, Last sync —
- Info note about separate configuration

**Tab 6 — History:**
- Sales Chat: prospect conversation history (quick_reply labels + change_request text) — preserved

### What was NOT changed
- No controller logic changed
- No routes changed
- No model changes
- No JS behavior changed (ps_queue_generate, ps_modify_html, ps_delete_profile identical)
- No CSRF handling changed
- No generation behavior changed
- No publishing behavior changed (`publish_site` action untouched)
- `ps_badge()` helper unchanged

---

## Implemented / Needs Retesting

- All six tabs render correctly (verified via file upload; needs manual browser test)
- Attention section logic (basic conditions, no rules engine)

---

## In Progress

Nothing currently in progress.

---

## Intentionally Left for Other Workstreams

- **`publishing-domains`**: Custom Domain tab is a placeholder; DNS/SSL provisioning not built
- **`ghl-integration`**: GHL tab is a placeholder; no backend connectivity
- Hostname routing, DNS, SSL provisioning — out of scope for this workstream

---

## Known Issues / Risks

- The controller loads the view as `admin_detail` (no module prefix) while other views use `pitchsnap/admin_xyz`. This is existing behavior that works; do not change it.
- `ps_badge()` is defined at the end of the view file but called earlier — this works because PHP hoists top-level function declarations within an included file.

---

## Next

1. Manual browser test on production: verify all six tabs render, all actions work, no PHP errors
2. Merge `claude/site-management` into `main` after verification
3. Future: `publishing-domains` worktree fills in Domain & Publishing tab with real DNS/SSL provisioning
4. Future: `ghl-integration` worktree fills in the GHL tab

---

## History

- **2026-08-24** — Initial implementation: replaced single-column detail view with 6-tab layout. All existing functionality preserved. Deployed to production. (`2fd1b3f`)
- **2026-08-24** — Refinements: Edit HTML + AI Modify moved into Current Website card alongside Regenerate. Version History reworked with checkboxes, bulk delete, Primary pill in last column. Preview card removed. (`37176f9`)
- **2026-08-24** — Bug fix: Delete All ClickFuzz Web Data was silently failing due to CI3 CSRF middleware emptying `$_POST`. Added `confirm_delete` hidden field to form, updated controller guard, added published site directory cleanup to model. Verified working on production.
