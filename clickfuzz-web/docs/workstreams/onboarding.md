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

**Not yet implemented.** No onboarding controllers, models, views, or DB tables exist in the current codebase. The onboarding worktree (`claude/onboarding`) exists but contains no feature changes — it is at the old main baseline and needs to be recreated from the current main before onboarding development begins.

---

## Confirmed Working

- Nothing in this workstream is implemented yet.

---

## Implemented / Needs Retesting

- Nothing.

---

## In Progress

- Nothing. Onboarding worktree needs to be recreated from latest main before work begins.

---

## Known Issues / Risks

- The `claude/onboarding` branch and its worktree are at the old main baseline (`e92cf8f`). Must be deleted and recreated from the current main (`4e85bde` or later) before any development.
- No schema design exists yet for onboarding data. Will need new DB tables (likely `tblpitchsnap_onboarding` or a similar structure) and a DB migration added to `clickfuzz_web_db_upgrade()`.
- Post-purchase trigger: the handoff from Sales Flow (`subscription_complete`) to Onboarding needs to be defined. The `tblpitchsnap_sites` record (with `client_id`) is the natural anchor.

---

## Architecture / Important Decisions

- Onboarding data belongs to a `tblpitchsnap_sites` record, not the `tblpitchsnap_redesigns` record. A site may eventually switch to a newer generated design while keeping the same onboarding data.
- Onboarding will likely need both an admin-facing view (staff can see/edit) and a client-facing form (customer fills in their info). The client-facing side should use a token-protected public URL pattern consistent with the agreement page.
- Do not implement features during this organizational task. Create the worktree and design the schema before writing any code.

---

## Relevant Files

| File | Purpose |
|---|---|
| `modules/pitchsnap/pitchsnap.php` | Add new DB migration here when schema is designed |
| `modules/pitchsnap/models/Pitchsnap_model.php` | Add onboarding model methods here |
| `modules/pitchsnap/controllers/Pitchsnap_runtime.php` | Add public onboarding form endpoint here |
| `modules/pitchsnap/controllers/Pitchsnap.php` | Add admin onboarding view endpoint here |

**DB tables (planned, not yet created):** `tblpitchsnap_onboarding` (or similar)

---

## Production Status

Not deployed. Not implemented.

---

## Next

1. Recreate `claude/onboarding` worktree from current main
2. Design onboarding data schema (what fields, which tables)
3. Design client-facing onboarding form UX
4. Design admin-facing onboarding status view
5. Implement DB migration, model, controllers, views

---

## History

- **2026-08-23** — Onboarding workstream defined as a distinct area; worktree exists but is empty/outdated and must be recreated before work begins
