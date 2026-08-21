# PitchSnap — Session Passdown

## What This Is

PitchSnap is a Perfex CRM module for ClickFuzz that handles website redesign intake and AI-powered proposal generation, with a prospect-facing chat runtime widget.

**Server:** `clickfuzz.com/dashboard`  
**DA Panel:** `homesincda.com:2222`, user `clgorman`  
**DB:** `clgorman_clickfuzzdashboard` (table prefix: `tbl`)  
**Stack:** Perfex CRM 3.1.4 / CodeIgniter 3.1.11 / PHP 8.3.33  
**Module status:** Activated (tblmodules id=23, active=1)

---

## Current State Summary

| Feature | Status |
|---|---|
| Intake form — creates lead + redesign record | ✓ Working |
| Settings page (Provider, API key, Model, Prompt) | ✓ Built |
| Generate / Retry / Regenerate buttons | ✓ Built |
| Status lifecycle: new → pending_generation → generating → review_required / failed | ✓ Working |
| Cron AI generation pipeline (Anthropic) | ✓ Working |
| Embeddable runtime widget — `pitchsnap/runtime.js` | ✓ Live |
| Prospect chat widget (floating, auto-opens 20s, 3 quick replies) | ✓ Working |
| Public chat endpoint — `pitchsnap/chat` (POST, CORS, CSRF-excluded) | ✓ Working |
| `tblpitchsnap_conversations` — stores prospect replies | ✓ Working |
| Prospect Conversation section on redesign detail page | ✓ Working |
| Lead activity log entry on meaningful responses | ✓ Working |
| Brand color extraction — CSS Phase A | ✓ Live, regression-tested |
| Brand color extraction — Logo vision Phase B (Haiku) | ✓ Live |
| Three-level visual identity guardrail (HIGH/MEDIUM/LOW) | ✓ Live, regression-tested |
| Visual character detection (tone/saturation/temperature) | ✓ Live, regression-tested |
| Brand color diagnostic probe (`/ps_colorprobe.php`) | ✓ Live, token-protected |

---

## All Module Files (local → server, all in sync)

Local root: `/Users/mymac/Desktop/Projects/software/PitchSnap/`  
Server root: `/domains/clickfuzz.com/public_html/dashboard/`

| Local file | Server path |
|---|---|
| `modules/pitchsnap/pitchsnap.php` | same |
| `modules/pitchsnap/models/Pitchsnap_model.php` | same |
| `modules/pitchsnap/controllers/Pitchsnap.php` | same |
| `modules/pitchsnap/controllers/Pitchsnap_intake.php` | same |
| `modules/pitchsnap/controllers/Pitchsnap_runtime.php` | same |
| `modules/pitchsnap/views/admin_redesigns.php` | same |
| `modules/pitchsnap/views/admin_detail.php` | same |
| `modules/pitchsnap/views/admin_settings.php` | same |
| `modules/pitchsnap/views/lead_section.php` | same |
| `modules/pitchsnap/views/intake_form.php` | same |
| `modules/pitchsnap/views/intake_result.php` | same |
| `modules/pitchsnap/views/runtime_js.php` | same |
| `modules/pitchsnap/libraries/Pitchsnap_generator.php` | same |
| `modules/pitchsnap/libraries/Pitchsnap_anthropic.php` | same |
| `modules/pitchsnap/helpers/pitchsnap_scraper_helper.php` | same — **heavily updated** |
| `modules/pitchsnap/helpers/pitchsnap_generation_helper.php` | same — **updated** |
| `modules/pitchsnap/config/routes.php` | same |
| `application/config/my_routes.php` | same |
| `application/config/app-config.php` | same |

---

## Public URLs

| URL | Purpose |
|---|---|
| `clickfuzz.com/dashboard/pitchsnap/intake` | Public intake form |
| `clickfuzz.com/dashboard/pitchsnap/runtime.js` | Embeddable widget script |
| `clickfuzz.com/dashboard/pitchsnap/chat` | POST endpoint (CORS *, CSRF excluded) |

### Embed Snippet (from any redesign detail page in Perfex)
```html
<script
  src="https://clickfuzz.com/dashboard/pitchsnap/runtime.js"
  data-redesign-token="PREVIEW_TOKEN_HERE">
</script>
```

---

## Key Architecture / Gotchas

### MX Router route value format
Routes in `modules/pitchsnap/config/routes.php` must NOT include the module prefix in values — `Modules::parse_routes()` prepends `$module/` automatically.
- Module routes file: `$route['pitchsnap/chat'] = 'pitchsnap_runtime/chat';`
- `application/config/my_routes.php` (fallback): `$route['pitchsnap/chat'] = 'pitchsnap/pitchsnap_runtime/chat';`
- Both files define all pitchsnap public routes. Module routes take precedence; my_routes.php is the guaranteed fallback.

### Raw POST body in public controllers
`App_Input` (Perfex's CI_Input extension) does not expose `raw_input_stream()`. Use `file_get_contents('php://input')` to read JSON request bodies.

### CSRF exclusions
`APP_CSRF_PROTECTION = true` globally. Public API endpoints are excluded via `$app_csrf_exclude_uris` in `application/config/app-config.php`. Currently excluded: `pitchsnap/chat`, `pitchsnap/runtime.js`, `pitchsnap/runtime`.

### Module context in global hooks (cron)
`$CI->load->model()` and `$CI->load->library()` can't resolve module paths from global hook functions. Use `require_once FCPATH . 'modules/pitchsnap/...'` then manually instantiate. Leads_model is fine via `$CI->load->model()` since it lives in `application/models/`.

### DB probe access
`mcp__directadmin__db_query` only reaches the WordPress DB. Perfex DB queries go through PDO probe files in the dashboard root. Credentials are in `application/config/app-config.php` as `define()` constants: `APP_DB_HOSTNAME`, `APP_DB_USERNAME`, `APP_DB_PASSWORD`, `APP_DB_NAME`. The prefix is `tbl` (auto-discovered via `SHOW TABLES LIKE '%options'`).

### Probe script patterns
- Define `BASEPATH`, `FCPATH`, `APPPATH` constants at top
- Stub `get_option()` and `log_activity()` if not loading full CI
- Write output to a `.txt` log file and `@unlink(__FILE__)` at end — scripts self-delete
- OPcache: reusing the same probe filename can serve stale cached PHP — always use a fresh filename
- WAF (ModSecurity) was a problem in earlier sessions for probe scripts that include the scraper helper from the public root. Current probes work because they define the right constants first.

### Status lifecycle
```
new → (Generate clicked) → pending_generation → (cron claims) → generating → review_required
                                                                            → failed
```
Regeneration creates a NEW record with `parent_redesign_id` set.

### Settings in tbl_options
- `pitchsnap_ai_provider`, `pitchsnap_anthropic_api_key`, `pitchsnap_model`, `pitchsnap_generation_prompt`

---

## Brand Color System — Complete and Live

### Background / Problem Solved

Lenka Craniosacral (`kranio.lenkagorman.com`) — a cream/beige/warm/muted site — was receiving navy + orange redesigns from Anthropic. Root cause chain:

1. Lenka's cream background is in an external stylesheet → `$bg_colors` empty
2. No semantic CSS variables, no strong brand signals → confidence = LOW
3. WordPress default Gutenberg palette (vivid-red, vivid-orange, etc.) was contaminating `visual_character` → returned "Balanced, Vivid" (completely wrong)
4. At LOW confidence, the old generation prompt said "for plumbing/HVAC use deep navy (#1B2A4A)" → Anthropic chose navy/orange
5. After prompt fix, Anthropic still chose navy/orange because no color information constrained it

All four issues now fixed.

---

### Brand Color Extraction (`pitchsnap_scraper_helper.php`)

**Phase A — Deterministic CSS scoring** (`clickfuzz_web_extract_brand_colors()`):

Evidence sources and point values:
| Source | Points |
|---|---|
| theme-color meta tag | 5 |
| Squarespace palette JSON | 5 |
| Semantic CSS variable (`--primary`, `--brand`, `--accent`, etc.) | 5 |
| Elementor global color variable | 5 |
| Divi accent/primary color variable | 5 |
| Header/nav inline background style | 4 |
| CSS header/nav selector background | 4 |
| CSS button/CTA selector background | 4 |
| WordPress primary/secondary preset | 2 |
| WordPress generic color preset | 1 |

Confidence thresholds (top filtered score): ≥ 6 → HIGH, ≥ 3 → MEDIUM, else LOW.

Neutral filter: colors with `L > 0.93 || L < 0.07 || HSL_S < 0.08` excluded from brand palette unless score ≥ 8.

Per-color evidence gate: a color whose ONLY evidence is `"WordPress color preset (generic)"` is stripped from the palette — it cannot independently earn palette inclusion.

**Phase B — Logo vision fallback** (only when Phase A = LOW AND logo URL AND API key):
- Calls `clickfuzz_web_logo_color_vision()` → Haiku-4-5, returns 1–3 dominant hex colors
- ALL logo colors (including near-neutrals) returned for visual character input
- Only non-neutral logo colors boost brand confidence (if top filtered score ≥ 4 → MEDIUM)

**Body/html background extraction** (`$bg_colors`): separate from brand scoring, scans inline `<style>` blocks for `body`/`html` background rules. Weight 3 in visual character. Does NOT include external CSS (limitation — see Known Gaps).

**`$all_scored` snapshot**: taken before neutral filtering. Used (as `$char_scored`) for visual character.

**`$char_scored` filter**: `$all_scored` minus any color whose ONLY evidence source is `"WordPress color preset (generic)"`. These are default Gutenberg palette entries that never reflect actual site appearance. Colors with real evidence that also happen to be WP generic presets are kept.

---

### Visual Character (`clickfuzz_web_visual_character()`)

Takes `($bg_colors, $char_scored, $logo_raw)`. Returns `['tone'=>..., 'saturation'=>..., 'temperature'=>...]` or `null`.

**Weights**: bg_colors = 3, logo_raw = 2, char_scored = min(score, 3).

**Tone**: avg_L > 0.65 → light, < 0.35 → dark, else balanced.

**Saturation**: uses chroma C = max−min (reliable near white/black, unlike HSL S). avg_C < 0.10 → muted, > 0.35 → vivid, else moderate.

**Temperature**: `if ($chroma > 0.08)` guard — achromatic colors (white, black, gray) do NOT vote. Hue 20–70° and 330–360° → warm; 180–300° → cool. Only reported when one direction dominates by ≥ 1.5× weight.

---

### Three-Level Guardrail in Source Package

**Level A — HIGH or MEDIUM confidence:**
```
DESIGN REQUIREMENT — BRAND COLOR PRESERVATION:
This company has an established color identity. Keep it recognizable in the redesign.

Established palette:
  Primary: #XXXXXX
  Accent:  #XXXXXX

YOU MAY: [complementary colors, shades, layout changes]
YOU MUST NOT: [replace palette with unrelated colors]

BRAND COLOR PALETTE
Confidence: HIGH
Primary: #XXXXXX
Evidence: [source string]
```

**Level B — LOW + visual_character detected:**
```
DESIGN REQUIREMENT — VISUAL IDENTITY PRESERVATION:
Exact brand hex values could not be reliably determined, but the source site's
existing visual character has been detected from its CSS and design elements.

Detected character: Light, Warm, Muted

[Dominance note + YOU MAY / YOU MUST NOT with dimension-specific constraints]
— Transform a predominantly light identity into a predominantly dark identity  ← only if tone=light detected
— Transform a predominantly muted identity into a vivid identity               ← only if saturation=muted detected
— Transform a predominantly warm identity into a predominantly cool identity    ← only if temperature=warm detected

BRAND COLOR PALETTE
Confidence: LOW
Visual character: Light, Warm, Muted
Exact palette undetermined — preserve the detected visual character above.
```

**Level C — LOW + visual_character = null:**
```
BRAND COLOR PALETTE
Confidence: LOW
No visual color evidence detected — choose an appropriate palette for this business type.
```
No guardrail block emitted.

**Provider behavior**: `brand_color_preservation` guardrail is ON for Anthropic, OFF for Manus (unchanged).

---

### Generation Prompt (`pitchsnap_generation_helper.php` + `tbl_options`)

Old HVAC fallback removed. Colors section now reads:

> If exact brand colors are not determinable, preserve the overall visual color character of the source website when possible. Use the existing site's imagery, logo, backgrounds, and visible styling as guidance. You may modernize or expand the palette with complementary colors, but do not unnecessarily replace the business's recognizable visual identity. If there is truly no reliable visual color direction, choose an appropriate professional palette for the business type ({{vertical}}). Do not default to navy/orange or generic tech colors unless the business identity clearly supports them.

Both the file and `tbl_options.pitchsnap_generation_prompt` are updated and in sync.

---

### Regression Test Results (all passing)

| Test | confidence | primary | visual_character |
|---|---|---|---|
| Lenka live (`kranio.lenkagorman.com`) | low | [] | Light, Muted |
| ClickFuzz synthetic (red semantic var + nav) | high | [#d40200] | Light, Vivid |
| Dark/cool synthetic (nav + btn) | medium | [#1a2d44] | Dark, Vivid, Cool |
| WP generic presets only + white body | low | [] | Light, Muted |
| WP generic + real nav bg (#2c3e50) | medium | [#2c3e50] | Balanced, Muted, Cool |
| Truly unknown (no inline CSS) | low | [] | null |
| HVAC wording check | — | — | old wording absent |
| Logo neutral isolation | — | — | cream=neutral ✓, navy=not-neutral ✓ |

---

## Diagnostic Probe

Permanent protected endpoint in public root:

```
https://clickfuzz.com/ps_colorprobe.php?token=b3a7f2d9c14e48018f6a52e9073dc4b1&url=https://example.com
```

- Token-protected: no token → HTTP 403
- Calls production extraction functions only — no logic duplicated inside the probe
- Logo vision is suppressed (no Anthropic call) — reports eligibility only
- Self-persisting (not a one-time script)

**Lenka output:**
```
confidence:       low
primary:          []
accent:           []
evidence:         insufficient evidence
visual_character: Light, Muted
  tone:           Light
  saturation:     Muted
  temperature:    (not detected)
logo_url:         https://kranio.lenkagorman.com/wp-content/uploads/2025/04/kranialsacralni-terapie-liberec.png
logo_source:      header-homepage-link
vision_eligible:  yes
vision_attempted: no (suppressed in diagnostic)
```

**Planned next step**: move this into the ClickFuzz Web admin as a proper Source/Brand Diagnostics page rather than a public root PHP file.

---

## Known Gaps / Pending

1. **Body background in external CSS not captured.** Lenka's cream body background is in an external stylesheet — `$bg_colors` is empty for her site. Visual character is derived from the white nav background only (after WP preset filtering), giving Light/Muted without temperature. Acceptable for now — the critical wrong signals are gone. If Lenka's logo has warm colors, logo vision will add temperature at generation time.

2. **End-to-end Lenka generation not yet run.** All CSS-level and prompt fixes are confirmed. A fresh paid generation should be triggered against Lenka to verify the full pipeline: Level B guardrail → Anthropic output. Expect Light/Muted constraints to prevent navy/orange.

3. **Probe should move to admin UI.** See above.

4. **Test 5 anomaly**: `--wp--preset--color--primary` also matches the semantic CSS variable regex (`--primary-color`), giving 5pts instead of 2pts for that preset. Pre-existing behavior, not introduced by our changes.

5. **Phase 2 close-out tests** (from earlier sessions, may be complete — verify):
   - Test D: Enter real API key, run cron, confirm `review_required` + generation output
   - Test A: Verify Settings UI renders correctly, API key not in HTML source
   - Test F: Click Regenerate, confirm new record with `parent_redesign_id`
   - Delete legacy probe files from server root (`_probe3.php`, `_cron_reset.php`, `_cron_test.php`, `_probe_modules.php`, `_ps_test.php`, `_ps_runtime_test.html`) if still present

---

## Open Items / What's Next

- [ ] Run a paid Lenka generation and verify output is no longer navy/orange
- [ ] Move `/ps_colorprobe.php` into ClickFuzz Web admin as Source Diagnostics page
- [ ] Investigate capturing body background from external CSS (possible: fetch first `<link rel="stylesheet">` that's same-origin — one extra HTTP request)
- [ ] Attach generated HTML preview to the redesign record + host it via PitchSnap
- [ ] Send prospect the preview link (email trigger on `review_required`)
- [ ] "Viewed" / "Sent" tracking via `first_viewed_at`, `last_viewed_at`, `view_count` columns (already in schema)
- [ ] AI response to free-text change requests
- [ ] Approve / decline flow in Perfex admin
