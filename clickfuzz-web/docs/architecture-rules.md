# ClickFuzz Web — Architecture Rules

Patterns the codebase must follow consistently. Apply these in every worktree and every new feature.

---

## 1. POST Guard: Check HTTP Method, Not POST Data

**Rule:** Never use `$this->input->post()` as a guard to detect POST requests.

```php
// WRONG — breaks any form that only posts the CSRF token
if (!$this->input->post()) { redirect(admin_url('pitchsnap/websites')); }

// CORRECT
if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }
```

**Why:** CodeIgniter 3 removes the CSRF token from `$_POST` after verification (`unset($_POST[$csrf_token_name])`). Any form that only posts a CSRF hidden field (no other fields) will arrive at the controller with an empty `$_POST`. `$this->input->post()` returns falsy → the guard fires → the user is silently redirected to the websites list.

`$this->input->method()` reads `$_SERVER['REQUEST_METHOD']` directly and is not affected by CSRF processing.

---

## 2. Redirect After Action: Always Include the Tab Hash

**Rule:** All redirects to `pitchsnap/detail/{id}` must include the `#tab-{name}` fragment so the user lands on the correct tab, not the Overview.

```php
// WRONG — user lands on Overview tab
redirect(admin_url('pitchsnap/detail/' . $id));

// CORRECT — user stays on the tab where the action originated
redirect(admin_url('pitchsnap/detail/' . $id) . '#tab-pages');
redirect(admin_url('pitchsnap/detail/' . $id) . '#tab-media');
redirect(admin_url('pitchsnap/detail/' . $id) . '#tab-publishing');
```

**Tab → actions that redirect there:**

| Tab hash | Actions |
|---|---|
| `#tab-pages` | approve_design, delete_versions, regenerate, delete_preview, edit_html, page_trash, page_restore |
| `#tab-media` | media_upload, media_save, media_delete |
| `#tab-publishing` | publish_site, save_publish_type, save_wp_connection, publish_site_wp, reset_wp_connector, deploy_to_wordpress, redeploy_wp_theme, reimport_wp_content, export_wordpress, update_wp_plugin, save_custom_domain, remove_custom_domain, refresh_domain_status, verify_custom_domain |

**Why:** The admin_detail.php page reads `window.location.hash` on load to activate the matching tab. Fragments survive HTTP 302 redirects in all modern browsers. Without the hash the user always lands on Overview, which breaks flow and feels like a navigation bug.

**Nested tabs (settings sub-pills):** `#tab-publishing` and `#tab-ghl` are pills inside `#tab-settings`. The tab-restore JS in admin_detail.php activates both the parent tab and the sub-pill automatically when a nested hash is set.

---

## 3. In-Page Actions: Prefer AJAX over Form POST

**Rule:** For actions triggered from within an admin detail tab (buttons that don't navigate away), use AJAX rather than HTML form POST.

```javascript
// Preferred pattern
$.ajax({
    url:      admin_url + 'pitchsnap/some_action/' + id,
    type:     'POST',
    dataType: 'json',
    data:     { [csrf_token_name]: csrf_hash },
    success:  function(r) { /* update DOM in place */ },
    error:    function()  { alert_float('danger', 'Request failed.'); }
});
```

The controller returns JSON:
```php
if ($this->input->method() !== 'post') {
    return $this->_json(['success' => false, 'message' => 'Invalid request.']);
}
// ... do work ...
return $this->_json(['success' => true, 'message' => 'Done.']);
```

**Why:** AJAX keeps the user on the same tab/scroll position. Form POSTs trigger a full page reload and require the redirect-with-hash pattern above to restore tab state. AJAX is also more responsive (no flash of white during reload). Use form POST only when a file upload is required or the action genuinely navigates to a new page.

---

## 4. Media File Paths

| What | Path |
|---|---|
| Server directory | `FCPATH . 'media/{site_id}/'` → `public_html/dashboard/media/{site_id}/` |
| Public URL | `base_url('media/{site_id}/{filename}')` via `clickfuzz_web_media_url()` |

**Wrong:** `dirname(FCPATH) . '/media/'` → resolves to `public_html/media/` which does not exist.

Always use the helper functions `clickfuzz_web_media_dir()` and `clickfuzz_web_media_url()` from `pitchsnap_media_helper.php`. Never construct media paths manually.

---

## 5. CSRF Tokens in Views

Every form must include the CI CSRF hidden field:

```php
<input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>"
       value="<?php echo $this->security->get_csrf_hash(); ?>">
```

For AJAX calls, include it in the `data` object:

```php
data: { <?php echo $this->security->get_csrf_token_name(); ?>:
        '<?php echo $this->security->get_csrf_hash(); ?>' }
```

**Stale token warning:** With `csrf_regenerate = true` (CI3 default), every POST request generates a new token. If the user performs an AJAX action and then submits a form without reloading, the form token may be stale. This is a known issue; the preferred mitigation is to use AJAX for all in-page actions (Rule 3), which always sends a fresh token from the JS layer.

---

## 6. Shared Files Across Worktrees

Shared files (`Pitchsnap.php`, `Pitchsnap_model.php`, `pitchsnap.php`, `my_routes.php`) must be reconciled against `origin/main` before deploying from a feature worktree. Never assume the worktree copy is authoritative — a parallel worktree may have added newer methods.

Always run the DA MCP pre-deployment comparison (Rule 6 in global CLAUDE.md) before deploying any existing production file.
