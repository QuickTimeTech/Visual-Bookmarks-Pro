<?php
// Session cookie: not readable by scripts and not sent along with cross-site
// POST requests, so foreign pages cannot drive the API with your session.
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => isHttps()]);
session_start();

function isHttps() {
    return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

// --- SECURITY CONFIGURATION ---
$MASTER_PASSWORD = 'admin'; // <-- CHANGE THIS TO YOUR SECURE PASSWORD
// ------------------------------

$screenshotDir = __DIR__ . '/screenshots/';
$dbFile = __DIR__ . '/database.sqlite';

if (!is_dir($screenshotDir)) {
    mkdir($screenshotDir, 0777, true);
}

// Favicons fetched by the server, see cachedFavicon()
$iconDir = __DIR__ . '/icons/';
if (!is_dir($iconDir)) {
    mkdir($iconDir, 0755, true);
}

// The browser may only load from this server: no CDN, no favicon or image
// services that would learn your IP address and your bookmarks.
function sendPrivacyHeaders() {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Base bookmarks table
$pdo->exec("CREATE TABLE IF NOT EXISTS bookmarks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT,
    url TEXT,
    image TEXT,
    category TEXT,
    views INTEGER DEFAULT 0,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Apply dynamic upgrades safely
try { $pdo->exec("ALTER TABLE bookmarks ADD COLUMN views INTEGER DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE bookmarks ADD COLUMN folder TEXT DEFAULT 'Uncategorized'"); } catch (Exception $e) {}

// Create dedicated Folders table for desktop-like functionality
$pdo->exec("CREATE TABLE IF NOT EXISTS folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE
)");

// Migrate any existing folders into the new table
$pdo->exec("INSERT OR IGNORE INTO folders (name) SELECT DISTINCT folder FROM bookmarks WHERE folder != '' AND folder != 'Uncategorized'");

$pdo->exec("CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT)");

// Random secret per installation, created on first use and kept in settings.
function appSecret(PDO $pdo, $key) {
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = ?");
    $stmt->execute([$key]);
    $secret = $stmt->fetchColumn();
    if (!$secret) {
        $secret = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT OR REPLACE INTO settings (name, value) VALUES (?, ?)")->execute([$key, $secret]);
    }
    return $secret;
}

// Secret part of the bookmarklet URL, so that save.php only stores links sent
// by your own bookmarklet and not by any page that opens save.php.
function bookmarkletToken(PDO $pdo) {
    return appSecret($pdo, 'bookmarklet_token');
}

// Only http and https: FILTER_VALIDATE_URL alone also accepts javascript: URLs.
function isValidBookmarkUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
}

// Deletes a stored preview image, but only files inside the screenshot folder.
function deleteScreenshot($image) {
    global $screenshotDir;
    $file = realpath(__DIR__ . '/' . $image);
    $dir = realpath($screenshotDir);
    if ($file && $dir && str_starts_with($file, $dir . DIRECTORY_SEPARATOR) && is_file($file)) {
        @unlink($file);
    }
}

// --- Nested folders ---
// A folder is addressed by its full path, e.g. "Music/Live". Each segment is
// stored HTML-escaped like every other text of the app. The root is 'Uncategorized'.
const ROOT_FOLDER = 'Uncategorized';

// Turns user input (raw text, segments separated by "/") into a stored path.
function folderPathFromInput($raw) {
    $parts = [];
    foreach (explode('/', (string)$raw) as $segment) {
        $segment = trim($segment);
        if ($segment !== '') $parts[] = htmlspecialchars($segment);
    }
    $path = implode('/', $parts);
    return ($path === '' || $path === ROOT_FOLDER) ? ROOT_FOLDER : $path;
}

function parentFolderPath($path) {
    $pos = strrpos($path, '/');
    return $pos === false ? ROOT_FOLDER : substr($path, 0, $pos);
}

function folderBaseName($path) {
    $pos = strrpos($path, '/');
    return $pos === false ? $path : substr($path, $pos + 1);
}

// Creates the folder and every folder above it.
function ensureFolderPath(PDO $pdo, $path) {
    if ($path === ROOT_FOLDER) return;
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO folders (name) VALUES (?)");
    $prefix = '';
    foreach (explode('/', $path) as $segment) {
        $prefix = $prefix === '' ? $segment : "$prefix/$segment";
        $stmt->execute([$prefix]);
    }
}

// Upper levels can be missing when bookmarks reference a path the folder table lacks.
function repairFolderAncestors(PDO $pdo) {
    $known = [];
    foreach ($pdo->query("SELECT name FROM folders")->fetchAll(PDO::FETCH_COLUMN) as $name) $known[(string)$name] = true;
    foreach (array_keys($known) as $name) {
        $parent = parentFolderPath((string)$name);
        if ($parent !== ROOT_FOLDER && !isset($known[$parent])) {
            ensureFolderPath($pdo, $parent);
            $known[$parent] = true;
        }
    }
}

// SQL condition "column is the folder or lies below it". substr instead of LIKE
// keeps % and _ in folder names literal. Bind with subtreeParams().
function subtreeSql($column) {
    return "($column = ? OR substr($column, 1, length(?) + 1) = ? || '/')";
}

function subtreeParams($path) {
    return [$path, $path, $path];
}

function folderExists(PDO $pdo, $path) {
    $stmt = $pdo->prepare("SELECT 1 FROM folders WHERE name = ?");
    $stmt->execute([$path]);
    return (bool)$stmt->fetchColumn();
}

// Renames or moves a folder together with its subfolders and bookmarks.
// Returns an error message or null on success.
function moveFolder(PDO $pdo, $old, $new) {
    if ($old === ROOT_FOLDER || $new === ROOT_FOLDER) return 'Invalid folder.';
    if (!folderExists($pdo, $old)) return 'Folder not found.';
    if ($new === $old) return null;
    if (str_starts_with($new, $old . '/')) return 'A folder cannot be moved into itself.';

    $taken = $pdo->prepare("SELECT 1 FROM folders WHERE " . subtreeSql('name') . " LIMIT 1");
    $taken->execute(subtreeParams($new));
    if ($taken->fetchColumn()) return 'A folder with this name already exists there.';

    $pdo->beginTransaction();
    try {
        ensureFolderPath($pdo, parentFolderPath($new));
        $params = array_merge([$new, $old], subtreeParams($old));
        $pdo->prepare("UPDATE folders SET name = ? || substr(name, length(?) + 1) WHERE " . subtreeSql('name'))->execute($params);
        $pdo->prepare("UPDATE bookmarks SET folder = ? || substr(folder, length(?) + 1) WHERE " . subtreeSql('folder'))->execute($params);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return 'Database error.';
    }
    return null;
}

// Deletes a folder with all subfolders and bookmarks in them.
function deleteFolder(PDO $pdo, $path) {
    if ($path === ROOT_FOLDER || !folderExists($pdo, $path)) return ['error' => 'Folder not found.'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT image FROM bookmarks WHERE " . subtreeSql('folder'));
        $stmt->execute(subtreeParams($path));
        $images = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $delBookmarks = $pdo->prepare("DELETE FROM bookmarks WHERE " . subtreeSql('folder'));
        $delBookmarks->execute(subtreeParams($path));
        $delFolders = $pdo->prepare("DELETE FROM folders WHERE " . subtreeSql('name'));
        $delFolders->execute(subtreeParams($path));
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['error' => 'Database error.'];
    }
    foreach ($images as $image) deleteScreenshot($image);
    return ['bookmarks' => $delBookmarks->rowCount(), 'folders' => $delFolders->rowCount()];
}

// --- Import of browser bookmark files (Netscape bookmark format) ---
const IMPORT_FOLDER = 'Imported';

// Value of an attribute in the raw attribute string of a tag, entities decoded.
function htmlAttribute($attrs, $attrName) {
    $re = '~(?<![\w-])' . preg_quote($attrName, '~') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~i';
    if (preg_match($re, $attrs, $m, PREG_UNMATCHED_AS_NULL)) {
        return html_entity_decode($m[1] ?? $m[2] ?? $m[3] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return null;
}

// Tag content as plain text: tags removed, entities decoded, whitespace collapsed.
function plainText($html) {
    $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
}

// Preview image straight from YouTube for video links, null for anything else.
function youtubeThumbnail($url) {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $path = (string)parse_url($url, PHP_URL_PATH);
    $id = null;
    if ($host === 'youtu.be') {
        $id = ltrim($path, '/');
    } elseif (preg_match('~(^|\.)youtube(-nocookie)?\.com$~', $host)) {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $id = $query['v'] ?? null;
        if (!$id && preg_match('~^/(shorts|embed|live)/([^/]+)~', $path, $m)) $id = $m[2];
    }
    return (is_string($id) && preg_match('~^[A-Za-z0-9_-]{11}$~', $id)) ? 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg' : null;
}

// ADD_DATE is in seconds; some exporters write milli- or microseconds.
function importTimestamp($value) {
    if ($value === null || !ctype_digit($value)) return null;
    $ts = (int)$value;
    while ($ts > 100000000000) $ts = intdiv($ts, 1000);
    return $ts > 0 ? gmdate('Y-m-d H:i:s', $ts) : null;
}

// Imports a bookmark file exported by a browser. Its folders are recreated below
// $baseFolder; the browser's own root folders (toolbar, "Other Bookmarks") add no
// level. Links whose URL already exists are skipped.
function importBookmarkHtml(PDO $pdo, $html, $baseFolder = IMPORT_FOLDER) {
    // Tokens in document order: list start, list end, folder heading, link
    $re = '~<dl\b[^>]*>|</dl\s*>|<h3\b([^>]*)>(.*?)</h3\s*>|<a\b([^>]*)>(.*?)</a\s*>~si';
    preg_match_all($re, (string)$html, $tokens, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

    $known = array_fill_keys($pdo->query("SELECT url FROM bookmarks")->fetchAll(PDO::FETCH_COLUMN), true);
    $foldersBefore = (int)$pdo->query("SELECT COUNT(*) FROM folders")->fetchColumn();
    $insert = $pdo->prepare("INSERT INTO bookmarks (title, url, image, category, folder, added_at) VALUES (?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))");
    $stats = ['imported' => 0, 'duplicates' => 0, 'invalid' => 0, 'folders' => 0];
    $stack = [];     // one entry per open list: escaped folder name, or '' for lists that add no level
    $pending = '';   // folder heading waiting for its list
    $pathOf = fn($stack) => implode('/', array_filter(array_merge([$baseFolder], $stack), 'strlen'));

    $pdo->beginTransaction();
    try {
        ensureFolderPath($pdo, $baseFolder);
        foreach ($tokens as $t) {
            if ($t[1] !== null) {
                // Folder heading
                $isBrowserRoot = preg_match('~\b(PERSONAL_TOOLBAR_FOLDER|UNFILED_BOOKMARKS_FOLDER)\s*=\s*"?true~i', $t[1]);
                $label = str_replace('/', '-', plainText($t[2]));
                $pending = $isBrowserRoot ? '' : htmlspecialchars($label === '' ? 'Unnamed' : $label);
            } elseif ($t[3] !== null) {
                // Link
                $url = trim((string)htmlAttribute($t[3], 'href'));
                if (!isValidBookmarkUrl($url)) { $stats['invalid']++; continue; }
                if (isset($known[$url])) { $stats['duplicates']++; continue; }
                $known[$url] = true;
                $title = plainText($t[4]);
                if ($title === '') $title = (string)parse_url($url, PHP_URL_HOST);
                $tags = implode(', ', array_filter(array_map('trim', explode(',', (string)htmlAttribute($t[3], 'tags'))), 'strlen'));
                $insert->execute([
                    htmlspecialchars($title), $url, '', // no network during import; the admin scanner loads previews
                    htmlspecialchars($tags), $pathOf($stack), importTimestamp(htmlAttribute($t[3], 'add_date')),
                ]);
                $stats['imported']++;
            } elseif ($t[0][1] === '/') {
                // End of a list
                array_pop($stack);
            } else {
                // Start of a list: belongs to the heading before it; empty folders are created too
                $stack[] = $pending;
                $pending = '';
                ensureFolderPath($pdo, $pathOf($stack));
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['error' => 'Import failed.'];
    }
    $stats['folders'] = (int)$pdo->query("SELECT COUNT(*) FROM folders")->fetchColumn() - $foldersBefore;
    return $stats;
}

// --- Fetching from other servers ---
// Only the server talks to other sites. It fetches titles, preview images and
// favicons once and stores them, so the browser never loads anything foreign.
const FETCH_USER_AGENT = 'Mozilla/5.0 (compatible; VisualBookmarks/1.0)';

// Addresses of a host, or [] if any of them is not a public internet address.
// Keeps requests out of the server's own networks (containers, VPN, localhost).
function publicAddressesOf($host) {
    $host = trim((string)$host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ipv6'])) $ips[] = $record['ipv6'];
        }
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) return [];
    }
    return $ips;
}

// GET request with size and time limits. Redirects are followed by hand so that
// every hop is checked and the connection is pinned to the checked address.
// Returns ['body', 'type', 'url', 'status'] or null.
function fetchRemote($url, $maxBytes = 2000000, $timeout = 8) {
    for ($hop = 0; $hop < 5; $hop++) {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = trim($parts['host'] ?? '', '[]');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') return null;
        $ips = publicAddressesOf($host);
        if (!$ips) return null;
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $ip = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => FETCH_USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept-Language: en,de;q=0.8'],
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $maxBytes) {
                $body .= $chunk;
                return strlen($body) > $maxBytes ? 0 : strlen($chunk); // 0 aborts the transfer
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        if ($ok === false) return null;
        if ($status >= 300 && $status < 400 && $location !== '') { $url = $location; continue; }
        return ['body' => $body, 'type' => $type, 'url' => $url, 'status' => $status];
    }
    return null;
}

function absoluteUrl($ref, $base) {
    $ref = trim((string)$ref);
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $ref)) return $ref;
    $b = parse_url($base);
    $scheme = $b['scheme'] ?? 'https';
    if (str_starts_with($ref, '//')) return $scheme . ':' . $ref;
    $origin = $scheme . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($ref, '/')) return $origin . $ref;
    return $origin . preg_replace('~[^/]*$~', '', $b['path'] ?? '/') . $ref;
}

// <meta> values of a page by property or name; the first occurrence wins.
function pageMetaTags($html) {
    $tags = [];
    if (preg_match_all('~<meta\b[^>]*>~i', $html, $m)) {
        foreach ($m[0] as $tag) {
            $key = strtolower(trim((string)(htmlAttribute($tag, 'property') ?? htmlAttribute($tag, 'name'))));
            $content = trim((string)htmlAttribute($tag, 'content'));
            if ($key !== '' && $content !== '' && !isset($tags[$key])) $tags[$key] = $content;
        }
    }
    return $tags;
}

// Title, preview image and icon candidates of a page. 'online' is false when the
// page could not be fetched or answered with an error status; 'status' is 0
// when there was no answer at all.
function fetchPageMeta($url) {
    $meta = ['online' => false, 'status' => 0, 'title' => null, 'image' => null, 'icons' => []];
    $r = fetchRemote($url, 2000000, 8);
    if (!$r) return $meta;
    $meta['status'] = $r['status'];
    if ($r['status'] >= 400) return $meta;
    $meta['online'] = true;
    if (stripos($r['type'], 'html') === false) return $meta;
    $html = $r['body'];
    if (preg_match('~<title[^>]*>(.*?)</title\s*>~si', $html, $m)) $meta['title'] = plainText($m[1]) ?: null;
    $tags = pageMetaTags($html);
    foreach (['og:image:secure_url', 'og:image:url', 'og:image', 'twitter:image', 'twitter:image:src'] as $key) {
        if (!empty($tags[$key])) { $meta['image'] = absoluteUrl($tags[$key], $r['url']); break; }
    }
    if (preg_match_all('~<link\b[^>]*>~i', $html, $links)) {
        foreach ($links[0] as $tag) {
            $rel = ' ' . strtolower((string)htmlAttribute($tag, 'rel')) . ' ';
            $href = htmlAttribute($tag, 'href');
            if ($href && (str_contains($rel, ' icon ') || str_contains($rel, ' apple-touch-icon '))) {
                $meta['icons'][] = absoluteUrl($href, $r['url']);
            }
        }
    }
    return $meta;
}

// File extension for raster images, null for anything else. SVG is refused on
// purpose: opened directly from this site it could run scripts.
function imageExtension($bytes) {
    $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    return [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'image/avif' => 'avif', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico',
    ][$type] ?? null;
}

// Downloads an image into the screenshot folder; returns the relative path or null.
function storeRemoteImage($url, $prefix, $timeout = 10) {
    global $screenshotDir;
    $r = fetchRemote($url, 5000000, $timeout);
    if (!$r || $r['status'] < 200 || $r['status'] >= 300 || $r['body'] === '') return null;
    $ext = imageExtension($r['body']);
    if ($ext === null) return null;
    $name = $prefix . '-' . md5($url . random_bytes(8)) . '.' . $ext;
    if (file_put_contents($screenshotDir . $name, $r['body']) === false) return null;
    return basename(rtrim($screenshotDir, '/')) . '/' . $name;
}

// Preview image of a link: the YouTube thumbnail, else the page's own preview
// (og:image, twitter:image), else a thum.io screenshot. Returns [path, source];
// path '' means none, the interface then draws a tile.
function createPreview($url, $meta = null) {
    $youtube = youtubeThumbnail($url);
    if ($youtube && ($path = storeRemoteImage($youtube, 'yt'))) return [$path, 'youtube'];
    $meta = $meta ?? fetchPageMeta($url);
    if ($meta['image'] && ($path = storeRemoteImage($meta['image'], 'og'))) return [$path, 'page'];
    // thum.io photographs error pages like any other page and answers 200, so
    // only pages that answer get a screenshot. 403 and 429 are often bot filters
    // that a real browser passes.
    if ($meta['status'] === 0 || ($meta['status'] >= 400 && !in_array($meta['status'], [403, 429], true))) return ['', 'none'];
    $shot = storeRemoteImage('https://image.thum.io/get/noanimate/width/1024/crop/800/' . $url, 'shot', 25);
    return $shot ? [$shot, 'thum.io'] : ['', 'none'];
}

// Replaces the preview of a bookmark. A failed attempt keeps a local image that
// already exists, but never a remote URL (those would be loaded by the browser).
function refreshPreview(PDO $pdo, array $bookmark, $meta = null) {
    [$path, $source] = createPreview($bookmark['url'], $meta);
    $old = (string)$bookmark['image'];
    $oldIsLocal = $old !== '' && !preg_match('~^([a-z][a-z0-9+.-]*:|//)~i', $old);
    if ($path === '' && $oldIsLocal) return [$old, 'kept'];
    if ($path !== '' || !$oldIsLocal) deleteScreenshot($old);
    $pdo->prepare("UPDATE bookmarks SET image = ? WHERE id = ?")->execute([$path, $bookmark['id']]);
    return [$path, $source];
}

function faviconBytes($url) {
    $r = fetchRemote($url, 500000, 5);
    $ok = $r && $r['status'] >= 200 && $r['status'] < 300 && $r['body'] !== '' && imageExtension($r['body']);
    return $ok ? $r['body'] : null;
}

// Favicon of a host, fetched once by the server and kept for 30 days; misses are
// remembered for 3 days. File names are keyed hashes, so nobody can probe the
// folder for the domains you saved. Returns the file path or null.
function cachedFavicon(PDO $pdo, $host) {
    global $iconDir;
    $host = strtolower(trim((string)$host));
    if ($host === '' || strlen($host) > 253 || !preg_match('~^[a-z0-9.-]+$~', $host)) return null;
    $base = $iconDir . hash_hmac('sha256', $host, appSecret($pdo, 'icon_key'));
    foreach (glob($base . '.*') ?: [] as $file) {
        $miss = str_ends_with($file, '.none');
        if (filemtime($file) > time() - ($miss ? 3 : 30) * 86400) return $miss ? null : $file;
        @unlink($file);
    }
    $bytes = faviconBytes('https://' . $host . '/favicon.ico');
    if ($bytes === null) {
        foreach (array_slice(fetchPageMeta('https://' . $host . '/')['icons'], 0, 3) as $iconUrl) {
            if (($bytes = faviconBytes($iconUrl)) !== null) break;
        }
    }
    if ($bytes === null) { touch($base . '.none'); return null; }
    $file = $base . '.' . imageExtension($bytes);
    file_put_contents($file, $bytes);
    return $file;
}

?>