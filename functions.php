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

// Secret part of the bookmarklet URL, so that save.php only stores links sent
// by your own bookmarklet and not by any page that opens save.php.
function bookmarkletToken(PDO $pdo) {
    $token = $pdo->query("SELECT value FROM settings WHERE name = 'bookmarklet_token'")->fetchColumn();
    if (!$token) {
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT OR REPLACE INTO settings (name, value) VALUES ('bookmarklet_token', ?)")->execute([$token]);
    }
    return $token;
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

function takeScreenshot($url) {
    $apiUrl = "https://image.thum.io/get/width/1024/crop/800/" . $url;
    $imageData = @file_get_contents($apiUrl);
    $filename = md5($url . time()) . '.jpg';
    $filepath = 'screenshots/' . $filename;

    if ($imageData) {
        file_put_contents(__DIR__ . '/' . $filepath, $imageData);
    } else {
        $fallbackUrl = "https://placehold.co/1024x800/eeeeee/999999.jpg?text=Preview+Unavailable";
        $fallbackData = @file_get_contents($fallbackUrl);
        file_put_contents(__DIR__ . '/' . $filepath, $fallbackData);
    }
    return $filepath;
}

function takeScreenshotAdmin($url) {
    $apiUrl = "https://image.thum.io/get/width/1024/crop/800/" . $url;
    $imageData = @file_get_contents($apiUrl);
    $filename = md5($url . time()) . '.jpg';
    $filepath = 'screenshots/' . $filename;
    
    if ($imageData) {
        file_put_contents(__DIR__ . '/' . $filepath, $imageData);
        return ['path' => $filepath, 'success' => true];
    } else {
        $fallbackUrl = "https://placehold.co/1024x800/eeeeee/999999.jpg?text=Preview+Unavailable";
        $fallbackData = @file_get_contents($fallbackUrl);
        file_put_contents(__DIR__ . '/' . $filepath, $fallbackData);
        return ['path' => $filepath, 'success' => false];
    }
}

function isWebsiteOnline($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ($code >= 200 && $code < 400);
    }
    return false;
}

function getWebpageTitle($url) {
    $context = stream_context_create(['http' => ['timeout' => 4, 'user_agent' => 'Mozilla/5.0']]);
    $html = @file_get_contents($url, false, $context);
    
    if ($html && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
        return trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
    }
    return parse_url($url, PHP_URL_HOST);
}
?>