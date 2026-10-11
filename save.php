<?php
require_once 'functions.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    die("
    <div style='font-family: sans-serif; text-align: center; padding: 20px;'>
        <h3 style='color: #dc3545;'>❌ Unauthorized</h3>
        <p>Please log in to your main Visual Bookmarks app first.</p>
        <script>setTimeout(() => window.close(), 3000);</script>
    </div>
    ");
}

$url = filter_var($_GET['url'] ?? '', FILTER_SANITIZE_URL);
$category = ''; 
$folder = 'Uncategorized';
$status = 'saving';
$message = '';

if (filter_var($url, FILTER_VALIDATE_URL)) {
    $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE url = ?");
    $stmt->execute([$url]);
    
    if ($stmt->fetch()) {
        $status = 'warning';
        $message = 'Already in your bookmarks!';
    } else {
        $imagePath = takeScreenshot($url);
        $title = getWebpageTitle($url);
        
        $stmt = $pdo->prepare("INSERT INTO bookmarks (title, url, image, category, folder) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, $url, $imagePath, $category, $folder]);
        
        $status = 'success';
        $message = 'Saved successfully!';
    }
} else {
    $status = 'error';
    $message = 'Invalid URL.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Saving Bookmark...</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center vh-100 bg-light text-center">
    <div>
        <?php if ($status === 'success'): ?>
            <h3 class="text-success mb-3">✅ <?php echo $message; ?></h3>
            <p class="text-muted small">Closing window...</p>
            <script>setTimeout(() => window.close(), 1500);</script>
        <?php elseif ($status === 'warning'): ?>
            <h3 class="text-warning mb-3">⚠️ <?php echo $message; ?></h3>
            <p class="text-muted small">Closing window...</p>
            <script>setTimeout(() => window.close(), 1500);</script>
        <?php else: ?>
            <h3 class="text-danger mb-3">❌ <?php echo $message; ?></h3>
            <p class="text-muted small">You can close this window.</p>
        <?php endif; ?>
    </div>
</body>
</html>