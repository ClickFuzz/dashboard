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

## In Progress

Nothing — Phases 1–4 are complete.

---

## Known Issues / Risks

- `tblpitchsnap_site_domains` has no custom-domain verification or SSL provisioning fields — Phase 5
- The `domain` field on `tblpitchsnap_sites` stores `clickfuzz.com/sites/{slug}` — slug extraction is via regex; format must not change without updating the runtime
- Wildcard cert expires 2026-11-02 — auto-renewal should be confirmed before that date
- Controller (`Pitchsnap.php`) has extra WP methods on production server (752 lines) not in local git (542 lines) — do NOT overwrite server controller from local without reconciling first

---

## Next

Phase 5: Custom domain support

1. `tblpitchsnap_site_domains` schema extension — add `verified_at`, `ssl_status`, etc.
2. DNS verification flow (CNAME check)
3. SSL provisioning / status tracking
4. Custom domain UI in Publishing tab
5. Alternate hostname routing (runtime already supports any active mapping)

---

## History

- 2026-08-25: Infrastructure proven (wildcard DNS, TLS, vhost, Host-header). Worktree synced to main at `2d94ea6`.
- 2026-08-25: Phase 2 application/data layer implemented — DB v12, model methods, hostname generation, publish flow updated.
- 2026-08-25: Phase 3 hosted-site runtime deployed and tested — `sites.clickfuzz.com` now routes live hostnames to static storage. 11/11 tests pass.
- 2026-08-25: Phase 4 complete — first real site published (jackrabbit.clickfuzz.com, site_id=6), Publishing tab updated with live URL, Open Site button, and Republish state.
