# Barebones Theme Manager – Production Guide

## 1. Overview
This document explains everything you need to run the **Barebones Theme Manager** in production:
* WordPress theme setup
* Admin-panel interaction
* Continuous delivery / update workflow via GitHub
* Daily site-health reporting

The guide is intentionally self-contained – copy it into any internal wiki.

---

## 2. Files & Directories
```
├── barebones
│   ├── functions.php               # Existing theme bootstrap
│   ├── includes/
│   │   ├── custom-functions.php    # Your own helper snippets
│   │   └── theme-manager.php       # <- our new integration logic
│   └── PRODUCTION_GUIDE.md         # <- this document
```

---

## 3. Configuration constants
The integration relies on **three constants**. Define them _once_ – either in `wp-config.php` (preferred) or leave them in `theme-manager.php` after editing.

| Constant | Required | Description |
| -------- | -------- | ----------- |
| `YC_ADMIN_API_URL` | **Yes** | Base-URL of the remote admin panel (no trailing slash). Example: `https://yc.com/api` |
| `BAREBONES_GITHUB_REPO` | **Yes** | GitHub slug `vendor/repo` that hosts the theme releases. Example: `coresol/barebones-theme` |
| `BAREBONES_GITHUB_TOKEN` | No | Personal Access Token for private repos or high-volume polling. Leave empty if public. |

> **Tip** – In `wp-config.php` you can hide the token from version-control:
> ```php
> define( 'BAREBONES_GITHUB_TOKEN', getenv( 'GITHUB_TOKEN' ) );
> ```

---

## 4. Workflow diagram
```
┌──────────┐   activate theme    ┌────────────────┐   approve ▶ send key   ┌──────────┐
│ WordPress│ ────────────────▶ │  Admin Panel   │ ───────────────────────▶│WordPress │
│   site   │  POST /register    │(Django/Node)   │                        │   site   │
└──────────┘                     └────────────────┘                        └──────────┘
       │                               ▲                                        │
       │ daily cron /update            │ dashboard /installations               │
       ▼                               │                                        ▼
┌──────────┐                     ┌────────────────┐                        ┌──────────┐
│ GitHub   │ <────── theme check │ Cron job + WP  │ <──── visualize data ─ │  Admin   │
└──────────┘                     └────────────────┘                        └──────────┘
```

---

## 5. Theme installation & first run
1. **Upload & activate** the Barebones theme like any regular theme.  
2. On activation the site immediately `POST`s to `${YC_ADMIN_API_URL}/register` with its meta-payload.  
3. In the admin panel, mark the request as _approved_.  
4. The panel returns `{status:"activated", activation_key:"…"}` – stored in `wp_options`.  
5. Theme features that you gate behind the activation key (`get_option( 'barebones_activation_key' )`) are now unlocked.

---

## 6. Update mechanism
1. Release a new version on GitHub (use the **release tag** `v2.0.0` style).  
2. WordPress checks `/repos/:slug/releases/latest` (cached 6 h).  
3. If the version is newer than `style.css` header, WP’s native **Update Themes** UI shows it.  
4. The zipball URL from GitHub is used as the update package.

> **Rollback?** GitHub keeps older zips, so you can downgrade by changing the tag-name in `wp_options → theme_…_version` manually or releasing a hot-fix tag.

---

## 7. Daily site-health reporting
* A cron event `barebones_daily_health_event` is scheduled automatically (first run ≈30s after activation).  
* It collects a minimal JSON with WP version, theme version, active plugins and PHP version.  
* POSTs to `${YC_ADMIN_API_URL}/update`.

If your production site uses real **server-side cron** instead of WP-Cron (recommended), hook:
```bash
*/5 * * * * wp cron event run --due-now > /dev/null 2>&1
```

---

## 8. Admin Panel API spec (v1)
### 8.1  `POST /register`
```json
{
  "site_url": "https://example.com",
  "theme_version": "1.3.0",
  "wp_version": "6.5.3",
  "plugins": "[\"akismet/akismet.php\"]",
  "php_version": "8.2.7"
}
```
Response
```json
{ "status": "pending", "request_id": "uuid" }
```

### 8.2  `POST /activate`
```json
{ "request_id": "uuid", "activation_key": "abc123" }
```
Response → `{ "status": "activated" }`

### 8.3  `POST /update`
```json
{
  "site_url": "https://example.com",
  "health_metrics": "{ … }"
}
```
---

## 9. Interface guidelines
### WordPress side (client)
* Show a dismissible admin-notice when registration is **pending** >24 h.
* Use `get_option( Barebones_Theme_Manager::OPTION_REGISTRATION_STATUS )` to reflect state.
* Wrap premium features:
  ```php
  if ( 'activated' === get_option( Barebones_Theme_Manager::OPTION_REGISTRATION_STATUS ) ) {
      // premium code
  }
  ```

### Admin Panel
* **Installations list** – table columns: site_url, WP version, Theme version, Status, Last Ping.
* Detail view: surface full health JSON & plugin list.
* Approve / reject buttons.  Reject returns `{status:"rejected"}`.
* Web-socket or polling every 10 s for real-time dashboard updates.

---

## 10. Security checklist
* All endpoints **HTTPS only**.
* Add `Authorization: Bearer <token>` header from WordPress (future enhancement).
* Sanitize every inbound string on the admin-panel backend.
* Rate-limit `/register` with e.g. **express-rate-limit** or Django throttling.

---

## 11. Troubleshooting
| Symptom | Likely cause | Fix |
| ------- | ------------ | ---- |
| "No update available" but you just released | Release tag not in semver `vX.Y.Z` format or caching | Clear transients: `wp transient delete --all` |
| Cron events not running | Low traffic triggers WP-Cron seldom | Add real cron + `wp cron event run` |
| `is_wp_error()` on register | Firewall blocking outgoing POST | Allowlist admin panel domain / port |

---

© Coresol – Last updated: {{DATE}} 