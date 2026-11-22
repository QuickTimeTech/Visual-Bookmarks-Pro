<?php
// Favicon of a host for the logged-in browser. The server fetches it once from
// the host itself (see cachedFavicon), so no favicon service learns your bookmarks.
require_once 'functions.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    exit;
}
session_write_close(); // icons load in parallel; do not hold the session lock

$file = cachedFavicon($pdo, (string)($_GET['d'] ?? ''));
header('Cache-Control: private, max-age=604800');
header('X-Content-Type-Options: nosniff');
if (!$file) {
    http_response_code(404);
    exit;
}
$types = ['ico' => 'image/x-icon', 'png' => 'image/png', 'gif' => 'image/gif', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'avif' => 'image/avif'];
header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
readfile($file);
