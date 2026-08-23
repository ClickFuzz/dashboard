# Reviews

## Scope

Automated review request lifecycle: triggered after a customer completes their service, routes satisfied customers to public review platforms and captures private feedback from dissatisfied ones.

**Owns:**
- Completion trigger (what event kicks off a review request)
- Review request lifecycle and status
- SMS and/or email review request delivery
- Request cadence (timing, retries)
- Star selection UI (presented to customer)
- 1–4 star: private feedback capture (stays internal)
- 5-star: routing to public review platform (Google, Facebook, Yelp, etc.)
- Google review link configuration
- Additional platform links (Facebook/Yelp or other configured links)
- Request status and history (sent, viewed, responded)

**Does not own:**
- Twilio SMS infrastructure (→ Lead Connect owns that; Reviews uses it)
- Onboarding configuration (Reviews settings like Google review link belong to Onboarding's configuration data, but the review logic itself belongs here)
- Reporting on reviews (→ Reporting)

---

## Current State

**Not yet implemented.** No review request code exists in the current ClickFuzz Web module.

---

## Confirmed Working

- Nothing. Reviews has not been built yet.

---

## Implemented / Needs Retesting

- Nothing.

---

## In Progress

- Nothing. No Reviews worktree is active as of 2026-08-23.

---

## Known Issues / Risks

- Depends on Lead Connect for SMS delivery — if Twilio is not set up, review requests will require an email-only fallback or wait until Lead Connect is live.
- Completion trigger needs to be defined precisely (e.g., after a certain onboarding milestone? After a time-based trigger post-purchase? After an explicit staff action?). This decision affects schema design.
- SMS/email compliance: review request messages must comply with TCPA / CAN-SPAM. Message templates need legal review.

---

## Architecture / Important Decisions

- The completion trigger and review request state should probably live in `tblpitchsnap_sites` (per-site) or a separate `tblpitchsnap_review_requests` table.
- 5-star routing should use the platform links configured during onboarding. The Google review link in particular may differ per customer.
- Private feedback (1–4 stars) should be stored internally and surfaced in the admin — not sent anywhere external.
- Do not create a Git worktree for Reviews until active development begins.

---

## Relevant Files (planned, not yet created)

| File | Purpose |
|---|---|
| `modules/pitchsnap/helpers/pitchsnap_reviews_helper.php` | Review request logic, SMS/email dispatch |
| `modules/pitchsnap/controllers/Pitchsnap_reviews.php` | Public review response endpoint |

**DB tables (planned):** `tblpitchsnap_review_requests`

**Settings (planned):** Per-site review platform URLs (stored in onboarding data)

---

## Production Status

Not deployed. Not implemented.

---

## Next

1. Design completion trigger (when does a review request fire?)
2. Design `tblpitchsnap_review_requests` schema
3. Design customer-facing star selection UI (likely a token-protected public URL)
4. Implement after Lead Connect (SMS delivery) is live

---

## History

- **2026-08-23** — Reviews workstream defined; no code exists
