# Visual Bookmarks Pro for agents
A self-hosted bookmark manager in plain PHP and SQLite with a single-page client in vanilla JavaScript and Bootstrap 5. There is no build step, no framework and no package manager: the files in the repository are what the web server runs. If a file `AGENTS.local.md` exists next to this one, read it as well; it describes the local test environment and the deployment of this checkout and is not part of the repository.


---

# Layout
- [index.php](index.php): login page, the markup of the whole app, all CSS in one `<style>` block, and the bookmarklet code
- [app.js](app.js): all client logic, divided by `// --- … ---` comments into helpers, tree model, rendering, loading, navigation, delegated clicks, drag & drop, folder, bookmark and bulk actions, admin scanner and misc UI
- [api.php](api.php): JSON API, one `action` parameter per request: `load`, `add`, `edit`, `delete`, `click`, `refresh`, `bulk`, `import`, `folder_create`, `folder_rename`, `folder_move`, `folder_delete`, `admin_list`, `admin_process`
- [functions.php](functions.php): configuration (`$MASTER_PASSWORD`, `$VIEW_PASSWORD`), database setup and upgrades, folder helpers, the bookmark-file import parser, server-side fetching, previews and the favicon cache
- [save.php](save.php): endpoint of the bookmarklet popup, protected by a per-installation token
- [icon.php](icon.php): serves site icons from the server-side cache
- [vendor/](vendor/): Bootstrap 5.3.0 and Bootstrap Icons 1.11.1 (MIT), served locally
- Runtime data, created on first use and ignored by git (see [.gitignore](.gitignore)): `database.sqlite`, `screenshots/` for preview images, `icons/` for the favicon cache


---

# Data model
- `bookmarks`: `id`, `title`, `url`, `image`, `category` (the comma-separated tags), `views`, `added_at`, `folder`
- `folders`: `id`, `name` (unique)
- `settings`: `name`, `value`; holds random per-installation secrets created by `appSecret()`, currently `bookmarklet_token` and `icon_key`
- A folder is its full path with `/` as separator ("Music/Live/2024"), in `folders.name` and in `bookmarks.folder` alike, so a flat folder from an old database is a path with one segment. `/` is therefore not allowed inside a folder name. The root is stored as `Uncategorized` and shown as Home.
- `ensureFolderPath()` creates missing ancestors, `repairFolderAncestors()` fills gaps when loading, `subtreeSql()` and `subtreeParams()` select a folder with everything below it, `moveFolder()` and `deleteFolder()` work on whole subtrees.
- There are no migration files: schema changes are idempotent statements (`CREATE TABLE IF NOT EXISTS`, `ALTER TABLE` inside `try`) at the top of [functions.php](functions.php). Keep it that way so existing databases upgrade themselves.


---

# Text and escaping
- The server stores titles, tags and every folder segment HTML-escaped (`htmlspecialchars`), exactly once.
- The client decodes API text once with `decodeHtml()` (a `textarea` element) and paths with `folderFromApi()`, which also maps `Uncategorized` to the empty root path. Every value that goes into HTML passes through `esc()`.
- Clicks are delegated from containers; do not put data into inline event handlers.
- Only http and https URLs are accepted (`isValidBookmarkUrl()`).


---

# Access
- Two passwords in [functions.php](functions.php): `$MASTER_PASSWORD` for the admin and the optional `$VIEW_PASSWORD` for read-only guests, empty meaning no guest access. `loginRole()` maps a password to `admin` or `viewer`, the login stores it in `$_SESSION['role']`, and `sessionRole()` reads it. A session without a role counts as admin, because before the view password only the master password could open one.
- The server enforces read-only access, the client only follows it: [api.php](api.php) refuses every action except `load` for guests, [save.php](save.php) refuses them entirely, [index.php](index.php) leaves out the editing markup and the bookmarklet token, and [icon.php](icon.php) serves guests only icons of hosts that appear among the bookmarks (`isBookmarkedHost()`), so they cannot make the server contact arbitrary sites.
- In [app.js](app.js) `canEdit` is false when the body has the class `read-only`. It drops folder menus, checkboxes, edit buttons and drag & drop, and visits of guests are not counted. A new editing feature needs both: a check on the server and `canEdit` in the client.


---

# Privacy and server-side fetching
The browser must not contact any third party. There is no CDN and no favicon service, no remote preview URL ends up in the page, `<meta name="referrer" content="no-referrer">` is set, and links open with `noopener,noreferrer`. `sendPrivacyHeaders()` sends the Content-Security-Policy (`default-src 'self'`, `img-src 'self' data:`, inline scripts and styles allowed). New features have to keep this: remote images are stored locally (`storeRemoteImage()`), remote data is fetched by the server.

## Fetching
Every server-side request goes through `fetchRemote()`:
- Only public addresses are allowed: `publicAddressesOf()` resolves the host and `isPublicIp()` checks every address. `FILTER_FLAG_GLOBAL_RANGE` alone lets IPv6 forms of internal addresses through [1](https://github.com/php/php-src/pull/25426), so `isPublicIp()` also refuses IPv6 addresses with an embedded IPv4 address, site-local and multicast.
- Redirects are followed by hand, at most five, and every hop is checked again.
- The connection is pinned to the checked address with `CURLOPT_RESOLVE`.
- Size limits: page 2 MB, image 5 MB, favicon 500 KB; timeouts apply.

`FILTER_FLAG_GLOBAL_RANGE` is the reason for the minimum of PHP 8.2 [2](https://github.com/php/doc-en/blob/master/reference/filter/constants.validation.xml).

## Previews and icons
- `createPreview()` tries, in this order: the YouTube thumbnail (`maxresdefault`, then `mqdefault`, then `hqdefault` with its black bars), the page's og:image or twitter:image, and a thum.io screenshot. thum.io also photographs error pages, so it is only used when the page answers with a status below 400, or with 403 or 429; the URL uses `noanimate/`. Without a preview the client draws a tile with the site icon, or the first letter if there is no icon.
- `icon.php?d=<domain>&v=2` serves icons through `cachedFavicon()`. Cache file names are HMAC-SHA256 of the domain with the prefix `v2:` and the key `icon_key`; hits are kept 30 days, misses 3 days. Icons of at least 48 px declared by the home page come first, ranked by `iconRank()` (the smallest of at least 96 px, else the largest), then `/favicon.ico`, then the smaller declared icons; SVG is skipped, and at most five URLs are tried.
- `folderPreviews()` picks up to four links for the mosaic of each folder card, in turn from each branch below the folder. `load` returns them as `folder_previews` for the first page of a folder without a search.


---

# Client state
- The current folder lives in the URL fragment, so reload and the back button work.
- Kept in `localStorage`: `treeOpen` (open tree folders), `appLayout` (grid or list), `sidebarWidth` (200 to 560 px), `sortOrder` (restored only if it matches an option). Wrap new accesses in `try`/`catch`, storage can be unavailable.
- Breakpoint 992 px (Bootstrap `lg`): above it the tree is a sticky, resizable sidebar; below it an offcanvas panel.
- Folder mosaics are muted only inside `@media (hover: hover)`, so touch devices show them in full colour.


---

# Testing
- Run it with PHP's built-in server from the repository root, for example `php -S 127.0.0.1:8080`. PHP 8.2 or newer with the SQLite (PDO) and cURL extensions is required. The default password in [functions.php](functions.php) is `admin`; for guest access set `$VIEW_PASSWORD` in the test copy.
- There is no automated test suite in the repository. At least run `php -l` on every changed PHP file and `node --check app.js`.
- Headless Chromium reports `hover: none`, so the hover rules above do not apply there; inject the CSS when a test needs them.
- Bootstrap's reboot sets `scroll-behavior: smooth`, so `window.scrollTo()` is animated and `scrollY` reads 0 right after it; pass `{behavior: 'instant'}` before measuring.
- Previews, icons and the address checks need outgoing internet access from the PHP process.


---

# Commits
Write commit messages in English with an imperative subject line and a body that explains why and lists the visible changes. Keep one feature or fix per commit, and keep [README.md](README.md) in step with prerequisites and features.


---

# Sources
1. [php-src PR #25426: ext/filter: Fix FILTER_VALIDATE_IP range flag bypasses through IPv6 notations](https://github.com/php/php-src/pull/25426)
2. [PHP manual source, filter validation constants: FILTER_FLAG_GLOBAL_RANGE, "Available as of PHP 8.2.0"](https://github.com/php/doc-en/blob/master/reference/filter/constants.validation.xml)
