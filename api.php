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
        deleteScreenshot($bookmark['image']);
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
    $search = trim($_GET['search'] ?? '');
    $tagFilter = $_GET['category'] ?? 'All';
    $allFolders = ($_GET['all'] ?? '') === '1';
    $folder = folderPathFromInput($_GET['folder'] ?? '');
    $sort = $_GET['sort'] ?? 'date';

    $query = "SELECT id, title, url, image, category, folder, views, added_at FROM bookmarks WHERE 1=1";
    $params = [];

    if ($search !== '') {
        // Titles, tags and folders are stored escaped, URLs are not.
        $escaped = '%' . htmlspecialchars($search) . '%';
        $query .= " AND (title LIKE ? OR url LIKE ? OR category LIKE ? OR folder LIKE ?)";
        array_push($params, $escaped, "%$search%", $escaped, $escaped);
    }
    if ($tagFilter !== 'All') {
        $query .= " AND category LIKE ?";
        $params[] = '%' . htmlspecialchars($tagFilter) . '%';
    }
    if (!$allFolders) {
        $query .= $folder === ROOT_FOLDER ? " AND (folder = ? OR folder = '' OR folder IS NULL)" : " AND folder = ?";
        $params[] = $folder;
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
    
    // All folders (full paths) with the number of bookmarks directly inside
    repairFolderAncestors($pdo);
    $folderStmt = $pdo->query("SELECT f.name, COUNT(b.id) as count FROM folders f LEFT JOIN bookmarks b ON f.name = b.folder GROUP BY f.name ORDER BY f.name ASC");
    $folders = $folderStmt->fetchAll(PDO::FETCH_ASSOC);
    $rootCount = (int)$pdo->query("SELECT COUNT(*) FROM bookmarks WHERE folder = 'Uncategorized' OR folder = '' OR folder IS NULL")->fetchColumn();

    echo json_encode(['bookmarks' => $bookmarks, 'categories' => $uniqueTags, 'folders' => $folders, 'root_count' => $rootCount]);
    exit;
}

// Folder endpoints. Paths arrive as raw text ("Music/Live", "" for the root).
if ($action === 'folder_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $parent = folderPathFromInput($_POST['parent'] ?? '');
    $name = folderPathFromInput($_POST['name'] ?? '');
    if ($name === ROOT_FOLDER) { echo json_encode(['success' => false, 'error' => 'Invalid folder name.']); exit; }
    $path = $parent === ROOT_FOLDER ? $name : $parent . '/' . $name;
    if (folderExists($pdo, $path)) { echo json_encode(['success' => false, 'error' => 'This folder already exists.']); exit; }
    ensureFolderPath($pdo, $path);
    echo json_encode(['success' => true, 'path' => $path]);
    exit;
}

if ($action === 'folder_rename' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $path = folderPathFromInput($_POST['path'] ?? '');
    $rawName = trim($_POST['name'] ?? '');
    if ($rawName === '' || str_contains($rawName, '/')) {
        echo json_encode(['success' => false, 'error' => 'Folder names must not be empty or contain "/".']); exit;
    }
    $parent = parentFolderPath($path);
    $name = htmlspecialchars($rawName);
    $new = $parent === ROOT_FOLDER ? $name : $parent . '/' . $name;
    $error = $new === ROOT_FOLDER ? 'Invalid folder name.' : moveFolder($pdo, $path, $new);
    echo json_encode($error ? ['success' => false, 'error' => $error] : ['success' => true, 'path' => $new]);
    exit;
}

if ($action === 'folder_move' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $path = folderPathFromInput($_POST['path'] ?? '');
    $target = folderPathFromInput($_POST['target'] ?? '');
    $base = folderBaseName($path);
    $new = $target === ROOT_FOLDER ? $base : "$target/$base";
    $error = moveFolder($pdo, $path, $new);
    echo json_encode($error ? ['success' => false, 'error' => $error] : ['success' => true, 'path' => $new]);
    exit;
}

if ($action === 'folder_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = deleteFolder($pdo, folderPathFromInput($_POST['path'] ?? ''));
    echo json_encode(isset($result['error']) ? ['success' => false, 'error' => $result['error']] : ['success' => true] + $result);
    exit;
}

if ($action === 'click' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $pdo->prepare("UPDATE bookmarks SET views = views + 1 WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = filter_var(trim($_POST['url'] ?? ''), FILTER_SANITIZE_URL);
    $tags = htmlspecialchars($_POST['category'] ?? '');
    $folder = folderPathFromInput($_POST['folder'] ?? '');

    if (isValidBookmarkUrl($url)) {
        $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE url = ?");
        $stmt->execute([$url]);
        if (!$stmt->fetch()) {
            ensureFolderPath($pdo, $folder);
            $imagePath = takeScreenshot($url);
            // The page title comes from a foreign page: store it escaped like all other texts.
            $title = htmlspecialchars(getWebpageTitle($url));
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
    if (!is_array($ids) || empty($ids)) { echo json_encode(['success' => false]); exit; }
    $ids = array_values(array_map('intval', $ids));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($task === 'delete') {
        $stmt = $pdo->prepare("SELECT image FROM bookmarks WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) deleteScreenshot($row['image']);
        $pdo->prepare("DELETE FROM bookmarks WHERE id IN ($placeholders)")->execute($ids);
    } elseif ($task === 'retag') {
        $newTags = htmlspecialchars($_POST['new_tags'] ?? '');
        $params = array_merge([$newTags], $ids);
        $pdo->prepare("UPDATE bookmarks SET category = ? WHERE id IN ($placeholders)")->execute($params);
    } elseif ($task === 'refolder') {
        $newFolder = folderPathFromInput($_POST['new_folder'] ?? '');
        ensureFolderPath($pdo, $newFolder);
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
        deleteScreenshot($bookmark['image']);
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
    $newUrl = filter_var(trim($_POST['url'] ?? ''), FILTER_SANITIZE_URL);
    $newTitle = htmlspecialchars($_POST['title'] ?? '');
    $newTags = htmlspecialchars($_POST['category'] ?? '');
    $newFolder = folderPathFromInput($_POST['folder'] ?? '');

    if (!isValidBookmarkUrl($newUrl)) { echo json_encode(['success' => false, 'error' => 'Invalid URL']); exit; }

    $stmt = $pdo->prepare("SELECT * FROM bookmarks WHERE id = ?");
    $stmt->execute([$id]);
    $bookmark = $stmt->fetch();

    if ($bookmark) {
        ensureFolderPath($pdo, $newFolder);
        $imagePath = $bookmark['image'];
        if ($bookmark['url'] !== $newUrl) {
            $imagePath = takeScreenshot($newUrl);
            deleteScreenshot($bookmark['image']);
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
        deleteScreenshot($bookmark['image']);
        $pdo->prepare("DELETE FROM bookmarks WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Bookmark not found']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
?>