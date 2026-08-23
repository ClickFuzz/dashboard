# Lead Connect

## Scope

The automated lead response and communication subsystem. Lead Connect is part of the ClickFuzz Web module — it is NOT a separate Perfex module.

**Owns:**
- Twilio phone number provisioning and configuration
- Auto-callback / press-1 calling (outbound dial to prospect immediately after form submission)
- Lead Connect call connection (prospects press 1 → routed to owner)
- Round-robin staff routing
- Retry / timeout rules
- Working-hour enforcement
- Voicemail fallback
- Call escalation
- Outbound SMS to prospects
- Missed-call text-back
- Owner notifications (calls, missed calls, SMS)
- Prospect/customer chat (SMS-based or web-based)
- Contact and quote forms (inbound prospect requests)
- Lead routing logic
- Conversation handling
- Call tracking and logs

**Does not own:**
- Website generation (→ Generation)
- Stripe/payment (→ Sales Flow)
- Post-purchase onboarding (→ Onboarding)
- Review request sending (→ Reviews)
- Sales analytics (→ Reporting)

---

## Current State

**Not yet implemented.** No Lead Connect code exists in the current ClickFuzz Web module. This is a planned major subsystem.

---

## Confirmed Working

- Nothing. Lead Connect has not been built yet.

---

## Implemented / Needs Retesting

- Nothing.

---

## In Progress

- Nothing. No Lead Connect worktree is active as of 2026-08-23.

---

## Known Issues / Risks

- Lead Connect is a large, multi-component subsystem. Building it as one monolithic PR risks difficult merge conflicts and a long-lived branch with diverging state. Plan to split into task worktrees when work begins.
- Twilio provisioning requires account setup: Twilio credentials will need to be stored as settings (not hardcoded). These belong in `tbl_options` with the same pattern as other module secrets, and excluded from git via `.gitignore`.
- The `Pitchsnap_runtime` public controller is the natural home for inbound webhook receivers (Twilio call/SMS webhooks), but webhook validation (Twilio signature verification) must be added before any webhook endpoint is live.

---

## Architecture / Important Decisions

- **Lead Connect is part of the ClickFuzz Web module.** All Lead Connect code lives under `modules/pitchsnap/`. Do not create a separate Perfex module for it.
- **Multiple task worktrees are expected.** Because Lead Connect is large, development will use separate task branches per component (e.g., `claude/lead-connect-callback-sms`, `claude/lead-connect-chat-forms`, `claude/lead-connect-call-routing`). All merge back into `main`.
- **No permanent `lead-connect` worktree.** Create task worktrees only when a specific component is being actively developed.
- Twilio webhooks are unauthenticated HTTP POSTs; they must be excluded from CSRF protection and must verify the Twilio signature header before processing.

---

## Suggested Component Split (for future task worktrees)

| Worktree / Branch | Scope |
|---|---|
| `lead-connect-callback-sms` | Auto-callback press-1 calling + missed-call text-back + outbound SMS |
| `lead-connect-call-routing` | Round-robin, working hours, retries, voicemail, escalation, call tracking |
| `lead-connect-chat-forms` | Web chat, contact/quote forms, conversation storage, owner notifications |
| `lead-connect-admin` | Twilio provisioning UI, configuration, call/SMS history admin view |

---

## Relevant Files (planned, not yet created)

| File | Purpose |
|---|---|
| `modules/pitchsnap/controllers/Pitchsnap_twilio.php` | Twilio webhook receiver (inbound calls/SMS) |
| `modules/pitchsnap/helpers/pitchsnap_leadconnect_helper.php` | Call routing logic, SMS helpers |
| `modules/pitchsnap/libraries/Pitchsnap_twilio.php` | Twilio API client wrapper |

**DB tables (planned):** `tblpitchsnap_calls`, `tblpitchsnap_sms`, `tblpitchsnap_lead_connect_config`

**Settings (planned):** `pitchsnap_twilio_account_sid`, `pitchsnap_twilio_auth_token`, `pitchsnap_twilio_number`, `pitchsnap_lc_working_hours`, `pitchsnap_lc_retry_count`, `pitchsnap_lc_voicemail_url`

---

## Production Status

Not deployed. Not implemented.

---

## Next

1. Design Twilio integration architecture (provisioning, webhook validation, TwiML responses)
2. Design DB schema for call and SMS logs
3. Implement `lead-connect-callback-sms` task worktree first (highest value: auto-callback after intake form submission)
4. Then `lead-connect-call-routing` (routing logic, retries, working hours)
5. Then `lead-connect-chat-forms` and `lead-connect-admin`

---

## History

- **2026-08-23** — Lead Connect workstream defined; no code exists; component split strategy established
