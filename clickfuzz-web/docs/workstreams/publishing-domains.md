# Workstream: Publishing & Domains

**Branch:** `claude/publishing-domains`
**Worktree:** `worktrees/dashboard/publishing-domains`

---

## Goal

One ClickFuzz Hosted Site Runtime serving all published customer sites.
Each published site gets a readable `*.clickfuzz.com` subdomain backed by a persistent hostname mapping.
Custom domains come later.

---

## Confirmed Working

- Wildcard DNS `* A 104.152.168.38` live in `clickfuzz.com` zone
- `sites.clickfuzz.com` — full DA domain, PHP=ON, ssl=ON, open_basedir=OFF
- Wildcard TLS — `CN=*.clickfuzz.com` Let's Encrypt cert (issued 2026-08-04, expires 2026-11-02)
- LiteSpeed SNI — wildcard cert selected for all `*.clickfuzz.com` hostnames
- `ServerAlias *.clickfuzz.com` on `sites.clickfuzz.com` vhost (set by CrocWeb 2026-08-25)
- Diagnostic runtime at `/domains/sites.clickfuzz.com/public_html/` returns HTTP/2 200 with correct Host header for any `*.clickfuzz.com` test hostname
- `sites.clickfuzz.com` domain management via DA MCP

---

## Implemented / Phase Complete

### Phase 1 — Infrastructure (complete 2026-08-25)

- Wildcard DNS, wildcard TLS, vhost binding, Host-header preservation — all proven
- Diagnostic runtime: `index.php` + `.htaccess` at `/domains/sites.clickfuzz.com/public_html/`

### Phase 3 — Hosted-Site Runtime (complete 2026-08-25)

**Runtime** (`modules/pitchsnap/hosted-runtime/index.php`):
- Deployed to `sites.clickfuzz.com/public_html/index.php`
- Reads `HTTP_HOST`, normalizes (lowercase/strip port/trailing dot), validates RFC hostname regex
- `sites.clickfuzz.com` itself returns 404 (infrastructure host, no mapping)
- Looks up hostname in `tblpitchsnap_site_domains` (prepared statement, status=active only)
- Fetches matching `tblpitchsnap_sites.domain`, extracts storage slug via regex `|/sites/([a-z0-9-]+)$|`
- Resolves request path to `SITES_BASE_DIR/{slug}/{path}`, falls back to `index.html` for directory-style paths
- Path containment via `realpath()` (resolves symlinks, catches traversal and symlink escapes)
- Blocks: null bytes, `..` traversal (raw and percent-encoded), PHP/script file extensions (403)
- Returns generic 400/403/404 — never exposes paths, credentials, or storage slug
- MIME map covers html/css/js/json/svg/png/jpg/webp/gif/ico/txt/xml/woff/woff2/ttf/otf/mp4/webm/pdf
- Cache: `no-cache` for HTML, `public, max-age=3600` for assets

**DB credentials**: read from `app-config.php` via regex at runtime — no credential duplication

**Storage security**: `/domains/clickfuzz.com/public_html/sites/.htaccess` deployed — PHP execution blocked, directory listing disabled

**Existing `.htaccess`** at `sites.clickfuzz.com/public_html/` already routes all non-file requests to `index.php` (front-controller pattern, pre-existing)

**11 runtime tests run and passed** (2026-08-25):
- Known host → 200 + correct HTML body
- Unknown host → 404
- Malformed host (underscore) → 400
- `/assets/test.txt` → 200 + `text/plain` + correct body
- `/about/` → 200 + `about/index.html`
- Missing file → 404
- Traversal (`../`) → blocked (404 — curl normalizes before sending; realpath containment is the backstop)
- Encoded traversal (`%2e%2e`) → blocked (400)
- `sites.clickfuzz.com` own host → 404
- `evil.php` in published storage → 403 (extension block)
- Test data cleaned up (DB rows + test site directory removed)

### Phase 2 — Application/Data Layer (complete 2026-08-25)

**DB v12 migration** (`modules/pitchsnap/pitchsnap.php`):
- New table `tblpitchsnap_site_domains` — hostname → site mapping
- Gate bumped from `>= 11` to `>= 12`

**Model** (`modules/pitchsnap/models/Pitchsnap_model.php`):
- `$domain_table` property
- `get_site_domain_by_hostname($hostname)` — lookup by hostname
- `get_platform_domain_for_site($site_id)` — primary platform mapping for a site
- `hostname_available($hostname)` — uniqueness check
- `create_site_domain($data)` — insert mapping

**Helpers** (`modules/pitchsnap/helpers/pitchsnap_generation_helper.php`):
- `clickfuzz_web_slugify_business_name($name)` — business name → DNS-safe slug
- `clickfuzz_web_generate_platform_hostname($site_id, $lead_id)` — unique platform hostname (DB check, no write)
- `clickfuzz_web_publish_site` — now resolves/creates platform hostname mapping; returns `https://{hostname}/`

**Tests**: `modules/pitchsnap/tests/test_publishing_domains.php` — 28 assertions covering slug generation, normalization, suffixing, fallback, uniqueness, creation, idempotency

---

## Architecture Decisions

- Internal storage slug (`ps-{id}-{hex}`) remains in `tblpitchsnap_sites.domain` as `clickfuzz.com/sites/{slug}`
- `tblpitchsnap_sites.domain` field is preserved as-is (legacy path format, used to derive storage slug)
- Public hostname is decoupled from storage slug; lives in `tblpitchsnap_site_domains`
- `tblpitchsnap_site_domains` is the authoritative table for all hostname mappings
- Platform hostname derived from lead's company name, falling back to `site->domain` storage slug, then `site-{id}`
- Publish is idempotent: republish reuses existing hostname mapping, does not create duplicates

---

### Phase 4 — End-to-end Publish + UI (complete 2026-08-25)

**Real publish milestone:**
- site_id=6 (JackRabbit, lead_id=610) published end-to-end via `clickfuzz_web_publish_site()`
- Platform hostname `jackrabbit.clickfuzz.com` derived, registered in `tblpitchsnap_site_domains`
- `https://jackrabbit.clickfuzz.com/` returns HTTP 200 — correct HTML, noindex stripped, widget injected
- Republish is idempotent (same hostname, no duplicate mapping, site overwrites cleanly)
- DB state verified: site status=published, one active platform domain row

**Publishing tab update** (`modules/pitchsnap/views/admin_detail/tab_publishing.php`):
- Derives `$_pf_domain` via `$this->pitchsnap_model->get_platform_domain_for_site()` in view scope
- Status badge: green "Published" label vs gray draft/other label
- Live URL row shows correct `https://{hostname}/` (was showing internal storage slug — bug fixed)
- "Open Site" button shown when hostname is set
- Button label: "Republish" + refresh icon when already published; "Publish Site" + upload icon otherwise
- Confirm dialog text adapts to published vs unpublished state

---

### Phase 5A — Custom Domain Data Model + UI (complete 2026-08-25)

**DB v13 migration** (`pitchsnap.php`):
- Adds `verification_status` (pending/verified/failed), `verified_at`, `ssl_status` (pending/active/failed) to `tblpitchsnap_site_domains`
- Existing platform rows backfilled: `verification_status=verified`, `ssl_status=active` (covered by wildcard cert)
- Gate bumped from `>= 12` to `>= 13`

**Model** (`Pitchsnap_model.php`):
- `get_custom_domain_for_site($site_id)` — fetch `domain_type=custom` row for a site
- `save_custom_domain($site_id, $hostname)` — create or update custom mapping, always resets to pending
- `remove_custom_domain($site_id)` — delete custom mapping
- `hostname_available_for_site($hostname, $site_id)` — uniqueness check excluding current site own mapping

**Helper** (`pitchsnap_domain_helper.php`):
- `clickfuzz_web_normalize_hostname($input)` — strips scheme/path/port/trailing dot, lowercases, does NOT strip www
- `clickfuzz_web_validate_custom_hostname($hostname, $site_id)` — rejects: empty, no TLD, IP addresses, wildcards, `clickfuzz.com`, `*.clickfuzz.com` subdomains, malformed hostnames, hostnames already assigned to another site

**Controller** (`Pitchsnap.php`):
- `save_custom_domain($id)` — POST: normalize → validate → save → redirect with alert
- `remove_custom_domain($id)` — POST: remove custom mapping → redirect with alert

**Publishing tab** (`tab_publishing.php`):
- Custom Domain section replaces placeholder
- Shows current hostname, Pending DNS setup / Verified / Failed badge, SSL status badge
- Input field + Save/Update Domain button
- Remove Domain button (when a mapping exists)
- Note: platform URL remains active throughout — custom domain does not affect it

**Tests**: 75/75 pass — 8 normalization tests, 8 validation rejection/acceptance tests, 8 CRUD + isolation tests added to `test_publishing_domains.php`

---

### Phase 5B — DNS Instructions + Verification (complete 2026-08-26)

**DNS helper** (`pitchsnap_dns_helper.php`):
- Constants: `CFZ_DNS_SERVER_IP = 104.152.168.38`, `CFZ_DNS_RUNTIME_HOST = sites.clickfuzz.com`
- `clickfuzz_web_hostname_is_apex($hostname)` — true when exactly one dot (root domain)
- `clickfuzz_web_expected_dns_records($hostname)` — apex: `[A @ → IP, CNAME www → runtime]` (both required); subdomain: `[CNAME {label} → runtime]`
- `clickfuzz_web_verify_dns($hostname, $a_lookup, $cname_lookup, $resolver)` — injectable callables for testability
  - Apex: `_cfz_verify_dns_apex_pair()` — BOTH `A @` and `CNAME www` must be correct to reach 'verified'; wrong record on either → failed; absent → pending
  - Subdomain: `_cfz_verify_dns_single()` — single hostname check (CNAME or A)
  - Architecture: customer keeps their nameservers/email DNS; only website records change

**Model** (`Pitchsnap_model`):
- `update_domain_verification($domain_id, $status, $verified_at)` — narrow update, never touches `ssl_status`

**Controller** (`Pitchsnap`):
- `verify_custom_domain($id)` — POST admin action; loads custom domain, calls verify_dns, writes result; preserves 'verified' status if subsequent check is merely inconclusive (pending)

**Publishing tab**:
- DNS record instructions table shown when custom domain is pending/failed (hidden when verified)
- www CNAME shown as "required" (not "recommended")
- Nameserver safety note: "Keep your existing nameservers and email/DNS records. Only add or update the records shown below."
- Verify DNS button (POST form) alongside Remove Domain
- Verified-at timestamp shown when verified
- SSL label reads "Pending (after verification)" when ssl_status=pending

**Tests**: 103/103 pass — apex-pair tests in sections 23–29 (both correct → verified; A ok + www missing → pending; wrong www CNAME → failed; wrong A → failed; subdomain CNAME tests unchanged)

---

## Architecture Decisions (DNS)

- Customer **keeps existing nameservers** — do NOT ask them to change nameservers or transfer DNS
- Only website-specific DNS records are modified
- Apex domain is canonical; `www` should ultimately redirect to apex (redirect not yet implemented)
- Apex requires BOTH `A @ → 104.152.168.38` AND `CNAME www → sites.clickfuzz.com` — both are required, not optional
- Existing MX/TXT/email records must remain untouched

## In Progress

Nothing — reconciliation with v17 main complete. Awaiting review before merge to main.

---

## Known Issues / Risks

- `tblpitchsnap_site_domains` has no custom-domain verification or SSL provisioning fields — Phase 5C
- The `domain` field on `tblpitchsnap_sites` stores `clickfuzz.com/sites/{slug}` — slug extraction is via regex; format must not change without updating the runtime
- Wildcard cert expires 2026-11-02 — auto-renewal should be confirmed before that date
- Phase 5C (Cloudflare Custom Hostname API automation, apex redirect, DNS UI update, FTP config UI) still paused

---

## Next

1. Review this reconciled branch (`65aaa9d`) before merging to main.
2. After merge: Phase 5C — Cloudflare Custom Hostname API automation, apex redirect, DNS UI update, FTP credentials admin UI.
3. Phase 5C DNS test sequence (when resumed):
   - Save spare Namecheap domain as custom domain on site_id=6 via admin UI
   - Add Namecheap DNS records manually (A @ → 104.152.168.38 for apex, or CNAME www → sites.clickfuzz.com for www)
   - Hit Verify DNS in Publishing tab — confirm verification_status updates to 'verified'
   - SSL provisioning: DirectAdmin Let's Encrypt for the custom hostname

---

## History

- 2026-08-25: Infrastructure proven (wildcard DNS, TLS, vhost, Host-header). Worktree synced to main at `2d94ea6`.
- 2026-08-25: Phase 2 application/data layer implemented — DB v12, model methods, hostname generation, publish flow updated.
- 2026-08-25: Phase 3 hosted-site runtime deployed and tested — `sites.clickfuzz.com` now routes live hostnames to static storage. 11/11 tests pass.
- 2026-08-25: Phase 4 complete — first real site published (jackrabbit.clickfuzz.com, site_id=6), Publishing tab updated with live URL, Open Site button, and Republish state.
- 2026-08-25: Phase 5A complete — custom domain data model, normalization/validation helper, save/remove controller actions, Custom Domain UI in Publishing tab. 75/75 tests pass. DB at v13.
- 2026-08-26: Phase 5B complete — DNS helper with injectable lookups, verify_custom_domain controller action, DNS record instructions table in Publishing tab. 102/102 tests pass.
- 2026-08-26: Phase 5B adjustment — apex-pair DNS verification (both A @ and CNAME www required; 'verified' only when both pass). Nameserver safety note added to UI. 103/103 tests pass.
- 2026-08-28: Phase 6 — Publishing-state foundation committed (37ad59e): is_site_published(), helper-level publish_type lock (HTML↔WP mutual exclusion before I/O), atomic publish_type+status on successful publish, test sections 32–38. Branch was blocked on claude/generation merging to main first.
- 2026-08-28: Reconciliation with v17 main (65aaa9d): temporary duplicate v15 migration removed, duplicate controller methods dropped, canonical main implementations kept. All publishing-domains behavioral contributions preserved. Awaiting review before merge to main.
