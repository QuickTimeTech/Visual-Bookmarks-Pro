<?php
require_once 'functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'admin_list') {
    $stmt = $pdo->query("SELECT id, title, url FROM bookmarks ORDER BY added_at DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'admin_process' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $stmt = $pdo->prepare("SELECT url, image FROM bookmarks WHERE id = ?");
    $stmt->execute([$id]);
    $bookmark = $stmt->fetch();

    if ($bookmark) {
        $online = isWebsiteOnline($bookmark['url']);
        if (file_exists(__DIR__ . '/' . $bookmark['image'])) @unlink(__DIR__ . '/' . $bookmark['image']);
        $imgResult = takeScreenshotAdmin($bookmark['url']);
        $pdo->prepare("UPDATE bookmarks SET image = ? WHERE id = ?")->execute([$imgResult['path'], $id]);
        echo json_encode(['success' => true, 'online' => $online, 'has_image' => $imgResult['success']]);
        exit;
    }
    echo json_encode(['success' => false]);
    exit;
}

if ($action === 'load') {
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    $search = $_GET['search'] ?? '';
    $tagFilter = $_GET['category'] ?? 'All'; 
    $folderFilter = $_GET['folder'] ?? 'All';
    $sort = $_GET['sort'] ?? 'date';

    $query = "SELECT id, title, url, image, category, folder, views, added_at FROM bookmarks WHERE 1=1";
    $params = [];

    if ($search !== '') {
        $query .= " AND (title LIKE ? OR url LIKE ? OR category LIKE ? OR folder LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
    }
    if ($tagFilter !== 'All') {
        $query .= " AND category LIKE ?";
        $params[] = "%$tagFilter%";
    }
    if ($folderFilter !== 'All') {
        $query .= " AND folder = ?";
        $params[] = $folderFilter;
    }

    if ($sort === 'views') {
        $query .= " ORDER BY views DESC, added_at DESC";
    } elseif ($sort === 'category') {
        $query .= " ORDER BY category ASC, added_at DESC";
    } else {
        $query .= " ORDER BY added_at DESC";
    }
    
    $query .= " LIMIT ? OFFSET ?";
    $params[] = $limit; $params[] = $offset;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch tags
    $catStmt = $pdo->query("SELECT category FROM bookmarks WHERE category != ''");
    $allTags = [];
    foreach ($catStmt->fetchAll(PDO::FETCH_COLUMN) as $catString) {
        $tags = array_map('trim', explode(',', $catString));
        $allTags = array_merge($allTags, $tags);
    }
    $uniqueTags = array_values(array_unique(array_filter($allTags)));
    sort($uniqueTags);
    
    // Fetch folders with their file counts
    $folderStmt = $pdo->query("SELECT f.name, COUNT(b.id) as count FROM folders f LEFT JOIN bookmarks b ON f.name = b.folder GROUP BY f.name ORDER BY f.name ASC");
    $folders = $folderStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['bookmarks' => $bookmarks, 'categories' => $uniqueTags, 'folders' => $folders]);
    exit;
}

// NEW: Add Folder Endpoint
if ($action === 'add_folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim(htmlspecialchars($_POST['name'] ?? ''));
    if ($name && $name !== 'Uncategorized') {
        $pdo->prepare("INSERT OR IGNORE INTO folders (name) VALUES (?)")->execute([$name]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid folder name.']);
    }
    exit;
}

if ($action === 'click' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $pdo->prepare("UPDATE bookmarks SET views = views + 1 WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = filter_var($_POST['url'] ?? '', FILTER_SANITIZE_URL);
    $tags = htmlspecialchars($_POST['category'] ?? '');
    $folder = htmlspecialchars($_POST['folder'] ?? 'Uncategorized');

    if (filter_var($url, FILTER_VALIDATE_URL)) {
        $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE url = ?");
        $stmt->execute([$url]);
        if (!$stmt->fetch()) {
            if ($folder !== 'Uncategorized' && $folder !== '') {
                $pdo->prepare("INSERT OR IGNORE INTO folders (name) VALUES (?)")->execute([$folder]);
            }
            $imagePath = takeScreenshot($url);
            $title = getWebpageTitle($url);
            $pdo->prepare("INSERT INTO bookmarks (title, url, image, category, folder) VALUES (?, ?, ?, ?, ?)")
                ->execute([$title, $url, $imagePath, $tags, $folder]);
            echo json_encode(['success' => true]);
            exit;
        }
        echo json_encode(['success' => false, 'error' => 'URL already exists in bookmarks']);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Invalid URL']);
    exit;
}

if ($action === 'bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = json_decode($_POST['ids'] ?? '[]');
    $task = $_POST['task'] ?? '';
    if (empty($ids)) { echo json_encode(['success' => false]); exit; }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($task === 'delete') {
        $stmt = $pdo->prepare("SELECT image FROM bookmarks WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            if (file_exists(__DIR__ . '/' . $row['image'])) unlink(__DIR__ . '/' . $row['image']);
        }
        $pdo->prepare("DELETE FROM bookmarks WHERE id IN ($placeholders)")->execute($ids);
    } elseif ($task === 'retag') {
        $newTags = htmlspecialchars($_POST['new_tags'] ?? '');
        $params = array_merge([$newTags], $ids);
        $pdo->prepare("UPDATE bookmarks SET category = ? WHERE id IN ($placeholders)")->execute($params);
    } elseif ($task === 'refolder') {
        $newFolder = htmlspecialchars($_POST['new_folder'] ?? 'Uncategorized');
        if ($newFolder !== 'Uncategorized' && $newFolder !== '') {
            $pdo->prepare("INSERT OR IGNORE INTO folders (name) VALUES (?)")->execute([$newFolder]);
        }
        $params = array_merge([$newFolder], $ids);
        $pdo->prepare("UPDATE bookmarks SET folder = ? WHERE id IN ($placeholders)")->execute($params);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['importFile']) && $_FILES['importFile']['error'] === UPLOAD_ERR_OK) {
        $html = file_get_contents($_FILES['importFile']['tmp_name']);
        preg_match_all('/<A HREF="([^"]+)".*?>(.*?)<\/A>/i', $html, $matches);
        $imported = 0;
        for ($i = 0; $i < count($matches[1]); $i++) {
            $url = filter_var($matches[1][$i], FILTER_SANITIZE_URL);
            $title = htmlspecialchars(trim($matches[2][$i]));
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE url = ?");
                $stmt->execute([$url]);
                if (!$stmt->fetch()) {
                    $placeholder = "https://placehold.co/1024x800/eeeeee/999999.jpg?text=Preview+Unavailable";
                    $pdo->prepare("INSERT INTO bookmarks (title, url, image, category, folder) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$title, $url, $placeholder, '', 'Imported']);
                    $imported++;
                }
            }
        }
        echo json_encode(['success' => true, 'message' => "Imported $imported bookmarks successfully!"]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Failed to process file.']);
    exit;
}

if ($action === 'refresh' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $stmt = $pdo->prepare("SELECT url, image FROM bookmarks WHERE id = ?");
    $stmt->execute([$id]);
    $bookmark = $stmt->fetch();

    if ($bookmark) {
        if (file_exists(__DIR__ . '/' . $bookmark['image'])) unlink(__DIR__ . '/' . $bookmark['image']);
        $newImage = takeScreenshot($bookmark['url']);
        $pdo->prepare("UPDATE bookmarks SET image = ? WHERE id = ?")->execute([$newImage, $id]);
        echo json_encode(['success' => true, 'newImage' => $newImage]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Bookmark not found.']);
    exit;
}

if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $newUrl = filter_var($_POST['url'] ?? '', FILTER_SANITIZE_URL);
    $newTitle = htmlspecialchars($_POST['title'] ?? '');
    $newTags = htmlspecialchars($_POST['category'] ?? '');
    $newFolder = htmlspecialchars($_POST['folder'] ?? 'Uncategorized');

    $stmt = $pdo->prepare("SELECT * FROM bookmarks WHERE id = ?");
    $stmt->execute([$id]);
    $bookmark = $stmt->fetch();

    if ($bookmark) {
        if ($newFolder !== 'Uncategorized' && $newFolder !== '') {
            $pdo->prepare("INSERT OR IGNORE INTO folders (name) VALUES (?)")->execute([$newFolder]);
        }
        $imagePath = $bookmark['image'];
        if ($bookmark['url'] !== $newUrl && filter_var($newUrl, FILTER_VALIDATE_URL)) {
            $imagePath = takeScreenshot($newUrl);
            if (file_exists(__DIR__ . '/' . $bookmark['image'])) unlink(__DIR__ . '/' . $bookmark['image']);
        }
        $pdo->prepare("UPDATE bookmarks SET title = ?, url = ?, image = ?, category = ?, folder = ? WHERE id = ?")
            ->execute([$newTitle, $newUrl, $imagePath, $newTags, $newFolder, $id]);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Bookmark not found']);
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $stmt = $pdo->prepare("SELECT image FROM bookmarks WHERE id = ?");
    $stmt->execute([$id]);
    $bookmark = $stmt->fetch();

    if ($bookmark) {
        if (file_exists(__DIR__ . '/' . $bookmark['image'])) unlink(__DIR__ . '/' . $bookmark['image']);
        $pdo->prepare("DELETE FROM bookmarks WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Bookmark not found']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
?>