# Move & Reorganize Frontend into `app/public/` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move `demo_tester.html` and all 12 files from `frontend/` into `app/public/` organized by business module (`admin/`, `menu/`, `kds/`, `pos/`, `live-ops/`, `css/`, `js/`), split `tv4_data_pipeline.html` into HTML/JS/CSS inside `live-ops/`, preserve backward-compatible redirects in `.htaccess`, and clean up `docker-compose.yml` and documentation.

**Architecture:** Apache serves `/var/www/html/public` (`app/public/`). Placing all static frontend files directly under `app/public/` eliminates the nested Docker bind mounts (`./frontend`, `./demo_tester.html`) and the 0-byte host placeholders while keeping `/frontend/menu/index.html?token=...` QR URLs and `menu.js` relative asset resolution (`new URL("../", document.currentScript.src)`) intact. Legacy URLs (`/tv4_data_pipeline.html`, `/frontend/tv4_data_pipeline.html`, `/frontend/tv1/*`, `/frontend/kds.html`, `/frontend/pos.html`) redirect with HTTP 302 in `app/public/.htaccess`.

**Tech Stack:** PHP 8.2 + Apache (`mod_rewrite`), HTML5/CSS3/Tailwind CDN, Vanilla JS, Docker Compose.

**Spec:** `docs/superpowers/specs/2026-11-16-move-frontend-into-app-design.md`

## Global Constraints

- Keep `database/init.sql`, `database/`, `tests/`, and `composer.json` untouched at repository root.
- Keep `APACHE_DOCUMENT_ROOT=/var/www/html/public`.
- Keep `/frontend/menu/index.html?token={session_token}` unchanged (`AdminCatalogController.php`, `menu.js`).
- Leave zero files in root `frontend/` and remove root `demo_tester.html` after moving.
- Keep HTTP 302 backward-compatible redirects in `app/public/.htaccess` for moved frontend URLs.

## Review Focus

- `menu/index.html` (`../css/shop.css`, `../js/menu.js`) and `menu.js` (`new URL("../", document.currentScript.src)`) must remain at the same relative depth under `app/public/frontend/`.
- `admin/table-management.html` (`../js/qrcode.js` and `getMenuPath()` replacing `/\/frontend\/.*$/`) must remain 1 level under `app/public/frontend/`.
- `kds/index.html` and `pos/index.html` must load local `kds.js` and `pos.js` after moving from `frontend/*.html` + `frontend/js/*.js`.
- `live-ops/index.html` must link `style.css` and `live-ops.js` with updated `../admin/`, `../menu/`, `../kds/`, `../pos/` cross-links while preserving all element IDs and inline `onclick`/`onchange` handlers (`toggleAutoRefresh`, `manualRefresh`, `onSourceItemChange`, `saveComboRule`, `triggerBatchETL`).
- Legacy URLs (`/tv4_data_pipeline.html`, `/frontend/tv4_data_pipeline.html`, `/frontend/tv1/*`, `/frontend/kds.html`, `/frontend/pos.html`) must match `.htaccess` `RewriteRule` directives without looping.

---

### Task 1: Create Verification Script & Move Core Frontend Modules into `app/public/`

**Files:**
- Create (scratch): verification script checking file structure, zero-byte placeholders, internal links, and `docker-compose.yml` mounts
- Move: `demo_tester.html` -> `app/public/demo_tester.html`
- Move: `frontend/css/*` -> `app/public/frontend/css/*`
- Move: `frontend/js/menu.js`, `frontend/js/qrcode.js` -> `app/public/frontend/js/`
- Move: `frontend/menu/index.html` -> `app/public/frontend/menu/index.html`
- Move: `frontend/tv1/*` -> `app/public/frontend/admin/*`
- Move & Update: `frontend/kds.html` + `frontend/js/kds.js` -> `app/public/frontend/kds/index.html` + `app/public/frontend/kds/kds.js`
- Move & Update: `frontend/pos.html` + `frontend/js/pos.js` -> `app/public/frontend/pos/index.html` + `app/public/frontend/pos/pos.js`
- Modify: `docker-compose.yml:13-14`

**Interfaces:**
- Produces: `app/public/demo_tester.html`, `app/public/frontend/{admin,menu,kds,pos,css,js}/` ready for cross-linking in Tasks 2 & 3.

- [ ] **Step 1: Write verification test script in scratch (RED)**

Write a PowerShell assertion script that asserts:
- Target files exist and are non-empty under `app/public/demo_tester.html` and `app/public/frontend/{admin,menu,kds,pos,live-ops,css,js}/`.
- Root `frontend/` and root `demo_tester.html` do not exist.
- `kds/index.html` references `kds.js` (not `js/kds.js`) and `pos/index.html` references `pos.js` (not `js/pos.js`).
- `admin/table-management.html` references `../js/qrcode.js` and `menu-management.html`.
- `menu/index.html` references `../css/shop.css` and `../js/menu.js`.
- `docker-compose.yml` does not contain `./frontend:` or `./demo_tester.html:`.

- [ ] **Step 2: Run verification script to verify it fails (RED)**

Run: PowerShell verification script.
Expected: FAIL (root `frontend/` and `demo_tester.html` still at root, target files missing / 0 bytes).

- [ ] **Step 3: Move `demo_tester.html`, `admin/` (from `tv1/`), `menu/`, `css/`, `js/` (`menu.js`, `qrcode.js`), `kds/`, and `pos/` into `app/public/`**

- Replace 0-byte `app/public/demo_tester.html` with root `demo_tester.html`.
- Move `frontend/css/*` to `app/public/frontend/css/`.
- Move `frontend/js/menu.js` and `frontend/js/qrcode.js` to `app/public/frontend/js/`.
- Move `frontend/menu/index.html` to `app/public/frontend/menu/index.html`.
- Move `frontend/tv1/table-management.html` and `frontend/tv1/menu-management.html` to `app/public/frontend/admin/`.
- Move `frontend/kds.html` -> `app/public/frontend/kds/index.html` (change `<script src="js/kds.js">` to `<script src="kds.js">`) and `frontend/js/kds.js` -> `app/public/frontend/kds/kds.js`.
- Move `frontend/pos.html` -> `app/public/frontend/pos/index.html` (change `<script src="js/pos.js">` to `<script src="pos.js">`) and `frontend/js/pos.js` -> `app/public/frontend/pos/pos.js`.
- Update `docker-compose.yml` to remove lines 13-14 (`./frontend:/var/www/html/public/frontend` and `./demo_tester.html:/var/www/html/public/demo_tester.html`).

- [ ] **Step 4: Re-run verification script on Task 1 assertions**

Expected: All Task 1 assertions PASS (only `live-ops/` and `.htaccess`/PHP/doc assertions from Tasks 2-3 remain failing).

---

### Task 2: Split `tv4_data_pipeline.html` into `app/public/frontend/live-ops/{index.html,live-ops.js,style.css}`

**Files:**
- Create: `app/public/frontend/live-ops/index.html`
- Create: `app/public/frontend/live-ops/live-ops.js`
- Create: `app/public/frontend/live-ops/style.css`
- Delete: `frontend/tv4_data_pipeline.html` (and empty `frontend/` root directory)

**Interfaces:**
- Consumes: `app/public/frontend/{admin,menu,kds,pos}/` paths from Task 1.
- Produces: `/frontend/live-ops/` entrypoint (`index.html`, `live-ops.js`, `style.css`) preserving all DOM IDs and global handlers (`toggleAutoRefresh`, `manualRefresh`, `onSourceItemChange`, `saveComboRule`, `triggerBatchETL`).

- [ ] **Step 1: Add `live-ops/` assertions to verification script and verify RED**

Assert:
- `app/public/frontend/live-ops/index.html`, `live-ops.js`, `style.css` exist and are non-empty.
- `live-ops/index.html` links `<link rel="stylesheet" href="style.css">` and `<script src="live-ops.js"></script>` and contains zero inline `<script>` logic blocks (other than Tailwind config) and zero `<style>` tags.
- `live-ops/index.html` links to `../admin/table-management.html`, `../admin/menu-management.html`, `../menu/index.html?token=tbl-01-sess`, `../kds/index.html`, `../pos/index.html` (no broken `tv1/`, `kds.html`, `pos.html` relative links).
- `live-ops/live-ops.js` defines `toggleAutoRefresh`, `manualRefresh`, `onSourceItemChange`, `saveComboRule`, `triggerBatchETL` and has balanced braces/parentheses.
- Root `frontend/` directory is completely removed.

Run verification script. Expected: FAIL on `live-ops/` assertions.

- [ ] **Step 2: Extract `style.css`, `live-ops.js`, and write `index.html` in `app/public/frontend/live-ops/`, then remove `frontend/`**

- `style.css`: lines 24-29 of `frontend/tv4_data_pipeline.html` (`body` font-family, `@keyframes pulse-dot`, `.pulse-live`).
- `live-ops.js`: lines 404-882 of `frontend/tv4_data_pipeline.html` (identical logic and UTF-8 strings, `menuUrl` remains `/frontend/menu/index.html?token=...`).
- `index.html`: HTML from `frontend/tv4_data_pipeline.html` linking `style.css` and `live-ops.js`, updating header/section links (`../admin/table-management.html`, `../admin/menu-management.html`, `../menu/index.html?token=tbl-01-sess`, `../kds/index.html`, `../pos/index.html`).
- Delete `frontend/tv4_data_pipeline.html` and the now-empty root `frontend/` directory.

- [ ] **Step 3: Run verification script (GREEN for Task 2)**

Expected: All Task 1 & Task 2 assertions PASS.

---

### Task 3: Update `.htaccess` Redirects, PHP Navigation Links, and Documentation

**Files:**
- Modify: `app/public/.htaccess:1-11`
- Modify: `app/public/dashboard.php:106,218,242,258,275`
- Modify: `app/public/products.php:115`
- Modify: `app/public/documents.php:113`
- Modify: `README.md`, `DINEFLOW_WEB_TEAM_SPEC_V1.md`, `TASK_CHECKLIST_DINEFLOW_PHP.md`, `tv4.md`

**Interfaces:**
- Consumes: Final `/frontend/{admin,menu,kds,pos,live-ops}/` paths from Tasks 1 & 2.

- [ ] **Step 1: Add `.htaccess`, PHP link, and doc assertions to verification script and verify RED**

Assert:
- `app/public/.htaccess` redirects `^tv4_data_pipeline\.html$`, `^frontend/tv4_data_pipeline\.html$`, `^frontend/tv1/(.*)$`, `^frontend/kds\.html$`, and `^frontend/pos\.html$` with `[R=302,L]` to `/frontend/live-ops/`, `/frontend/admin/$1`, `/frontend/kds/`, and `/frontend/pos/` before API rules.
- `app/public/dashboard.php`, `products.php`, `documents.php` contain zero links to `/frontend/tv4_data_pipeline.html`, `/frontend/tv1/`, or `/frontend/kds.html`, and point to `/frontend/admin/table-management.html`, `/frontend/kds/`, `/frontend/live-ops/`.
- Active docs (`README.md`, `DINEFLOW_WEB_TEAM_SPEC_V1.md`, `TASK_CHECKLIST_DINEFLOW_PHP.md`, `tv4.md`) contain zero stale `frontend/tv1/`, `frontend/tv4_data_pipeline.html`, or root `demo_tester.html` path claims.

Run verification script. Expected: FAIL on Task 3 assertions.

- [ ] **Step 2: Update `app/public/.htaccess`, `dashboard.php`, `products.php`, `documents.php`, and docs**

- Add 302 redirects in `app/public/.htaccess` for old URLs (`tv4_data_pipeline.html`, `frontend/tv4_data_pipeline.html`, `frontend/tv1/(.*)`, `frontend/kds.html`, `frontend/pos.html`).
- Update navigation/card `href` attributes in `app/public/dashboard.php`, `app/public/products.php`, `app/public/documents.php`.
- Update file path references and URL tables in `README.md`, `DINEFLOW_WEB_TEAM_SPEC_V1.md`, `TASK_CHECKLIST_DINEFLOW_PHP.md`, `tv4.md`.

- [ ] **Step 3: Run full verification script & git status check (GREEN)**

Run verification script and `git status -s`.
Expected: 100% PASS, zero stale references, clean `app/public/` layout.
Also test Docker Compose config / PHP test suite if Docker access is approved.
