<?php
session_start();

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
        curl_close($ch);
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