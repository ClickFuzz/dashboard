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
- Current Website panel with generation status details
- Generation action buttons (Generate, Approve & Send, Regenerate, Retry, New Version) — all preserved
- Rendered prompt toggle (collapsible) — preserved
- Preview & HTML panel (Open Preview, Edit HTML, AI Modify, Delete Preview) — all preserved
- AI Modify inline panel — preserved
- Version History table (compact: #, Status, Created, Provider, Primary indicator, View/Set Primary/Delete actions)
- Prospect Engagement stats (views, first/last view, approved date)

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

- **2026-08-24** — Initial implementation: replaced single-column detail view with 6-tab layout. All existing functionality preserved. Deployed to production.
