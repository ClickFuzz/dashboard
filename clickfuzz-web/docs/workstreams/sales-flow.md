# Sales Flow

## Scope

The prospect-facing experience from first seeing the generated website through purchase completion and subscription activation.

**Owns:**
- Generated-site preview experience (runtime widget, preview page)
- Prospect interaction with the preview (chat widget, quick replies, change requests)
- Conversation history (`tblpitchsnap_conversations`)
- VSL / video sales letter modal (video demo URL setting)
- Pricing interaction and CTA
- Agreement page and acceptance
- Stripe checkout (one-time and subscription)
- Perfex invoice / subscription creation
- Customer conversion (lead → client)
- Post-purchase subscription lifecycle (status checking, subscription email tracking)
- Preview tracking (view count, first/last viewed, device type)
- Sales-related publish transitions (approved → live)
- `tblpitchsnap_sites` record (site token, client_id, status, invoice_id, subscription_id)
- `tblpitchsnap_agreements` record

**Does not own:**
- AI generation of the website (→ Generation)
- Post-purchase onboarding form (→ Onboarding)
- Lead Connect calling/SMS (→ Lead Connect)
- Review requests (→ Reviews)

---

## Current State

Core sales flow is implemented: prospect can view the preview via the runtime widget, provide quick-reply feedback, reach the agreement page, sign the agreement, and complete payment via Stripe. Both one-time and subscription payment modes are implemented. Post-purchase site record creation and subscription lifecycle checking are implemented. Several paths need retesting.

---

## Confirmed Working

- Runtime widget script (`pitchsnap/runtime.js`) served via `Pitchsnap_runtime::script()`
- Public chat endpoint (`pitchsnap/chat` POST, CORS *, CSRF-excluded)
- Prospect quick replies: `like`, `change`, `not_for_me` stored in `tblpitchsnap_conversations`
- Free-text change requests stored alongside quick reply
- Lead activity log entry on meaningful prospect responses
- Conversation display in admin lead page (`admin_lead.php`) — always-visible panel with empty state
- View tracking: `record_view()` updates `view_count`, `first_viewed_at`, `last_viewed_at`, `first_device_type`, `last_device_type`
- `pitchsnap/track_view/:token` endpoint (GET)
- Admin lead page: "Prospect Feedback" section (restored empty-state fix 2026-08-23)

---

## Implemented / Needs Retesting

- **VSL modal**: video demo URL setting exists (`pitchsnap_video_demo_url`); verify widget displays it correctly
- **Agreement page** (`pitchsnap/agreement/:token`): renders agreement text from `pitchsnap_agreement_text` setting
- **Agreement acceptance** (`pitchsnap/accept_agreement` POST): creates `tblpitchsnap_agreements` record, triggers purchase flow
- **`pitchsnap/purchase`** endpoint: Stripe checkout or subscription URL generation
- **One-time Stripe checkout** (`_create_stripe_checkout_url()`): creates Perfex invoice + Stripe Checkout session
- **Subscription Stripe flow** (`_create_stripe_subscription_url()`): creates Perfex subscription + Stripe subscription URL
- **`pitchsnap/subscription_complete/:token`** (GET): webhook-adjacent completion handler; creates/updates `tblpitchsnap_sites`, marks site active
- **Subscription lifecycle check** (cron): `get_sites_for_subscription_check()` → checks Stripe subscription status via Perfex
- **`sub_email_last_status`** field: tracks last known Stripe subscription email status
- **`publish_site`** admin action: moves site from draft → live
- **`approve_design`**: marks admin approval of a generated design

---

## In Progress

- Nothing actively in-progress as of 2026-08-23

---

## Known Issues / Risks

- **Stripe webhook not confirmed.** The `subscription_complete` endpoint handles the post-Stripe redirect, but no webhook listener for asynchronous Stripe events (failed charges, cancellations) has been confirmed. Subscription lifecycle check in cron partially compensates.
- **Idempotency of purchase flow**: `_find_existing_pitchsnap_invoice()` guards against duplicate invoices for the same client, but duplicate Stripe sessions are possible if prospect refreshes the agreement page.
- **Agreement text is placeholder**: `pitchsnap_agreement_text` is seeded with placeholder text with a legal review notice — not production-ready for real contracts without attorney review.
- **End-to-end purchase path not confirmed tested** since payment settings were implemented.

---

## Architecture / Important Decisions

- `Pitchsnap_runtime` is the public-facing controller (no `AdminController` parent); it handles all unauthenticated prospect interactions.
- CSRF exclusions: `pitchsnap/chat`, `pitchsnap/runtime.js`, `pitchsnap/runtime`, `pitchsnap/purchase`, `pitchsnap/accept_agreement`, `pitchsnap/track_view/:any`, `pitchsnap/subscription_complete/:any` must all be in `$app_csrf_exclude_uris` in `application/config/app-config.php`.
- `file_get_contents('php://input')` is used for JSON request bodies — `App_Input` does not expose `raw_input_stream()`.
- Payment type setting (`pitchsnap_payment_type`): `onetime` or `subscription` controls which Stripe flow fires.

---

## Relevant Files

| File | Purpose |
|---|---|
| `modules/pitchsnap/controllers/Pitchsnap_runtime.php` | All public prospect-facing endpoints |
| `modules/pitchsnap/views/agreement_page.php` | Agreement display + acceptance form |
| `modules/pitchsnap/views/subscription_success.php` | Post-purchase confirmation page |
| `modules/pitchsnap/views/runtime_js.php` | Runtime widget JS |
| `modules/pitchsnap/views/admin_lead.php` | Admin view of prospect conversation |
| `modules/pitchsnap/models/Pitchsnap_model.php` | `save_conversation`, `record_view`, `get_sites_*`, `get_agreement_*`, `create_agreement` |
| `application/config/app-config.php` | CSRF exclusion list |
| `application/config/my_routes.php` | Fallback routes for public endpoints |

**DB tables:** `tblpitchsnap_conversations`, `tblpitchsnap_sites`, `tblpitchsnap_agreements`

**Key options:** `pitchsnap_payment_type`, `pitchsnap_price`, `pitchsnap_stripe_plan_id`, `pitchsnap_sub_quantity`, `pitchsnap_sub_name`, `pitchsnap_sub_description`, `pitchsnap_agreement_version`, `pitchsnap_agreement_text`, `pitchsnap_video_demo_url`

**Public URLs:**
- `clickfuzz.com/dashboard/pitchsnap/runtime.js` — widget embed script
- `clickfuzz.com/dashboard/pitchsnap/chat` — POST chat endpoint
- `clickfuzz.com/dashboard/pitchsnap/agreement/:token` — agreement page
- `clickfuzz.com/dashboard/pitchsnap/purchase` — Stripe redirect
- `clickfuzz.com/dashboard/pitchsnap/subscription_complete/:token` — post-payment handler
- `clickfuzz.com/dashboard/pitchsnap/track_view/:token` — view pixel

---

## Production Status

Runtime widget and chat endpoint are live and confirmed working. Agreement/purchase/subscription paths are deployed but full end-to-end payment flow has not been confirmed in production as of 2026-08-23.

---

## Next

- Confirm agreement → Stripe checkout → subscription_complete end-to-end with a real test purchase
- Add Stripe webhook listener for subscription status changes (cancellations, failed charges)
- Attach generated HTML preview to redesign record so preview_url is always populated
- Send prospect preview link via email when generation reaches `review_required`
- AI response to free-text change requests

---

## History

- **2026-08-23** — Conversation empty state fixed: "No prospect responses yet." shown when no feedback; panel always visible
- **~2026-08** — Subscription lifecycle cron check added; `sub_email_last_status` tracking
- **~2026-08** — Stripe subscription flow implemented alongside existing one-time checkout
- **~2026-07** — Agreement system implemented (`tblpitchsnap_agreements`, agreement page, accept endpoint)
- **~2026-07** — View tracking (`record_view`, `first_viewed_at`, `view_count`) added
- **~2026-06** — Runtime widget + prospect chat + quick replies + conversation storage implemented
