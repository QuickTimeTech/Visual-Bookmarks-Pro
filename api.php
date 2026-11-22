<?php
require_once 'functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

$action = $_GET['action'] ?? '';

// Read-only guests may load bookmarks and nothing else; not even their clicks
// are counted, so they cannot change the "Most Views" order.
if (sessionRole() !== 'admin' && $action !== 'load') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Read-only access.']);
    exit;
}

if ($action === 'admin_list') {
    $stmt = $pdo->query("SELECT id, title, url FROM bookmarks ORDER BY added_at DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'admin_process' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $stmt = $pdo->prepare("SELECT id, url, image FROM bookmarks WHERE id = ?");
    $stmt->execute([$id]);
    $bookmark = $stmt->fetch();

    if ($bookmark) {
        session_write_close(); // slow request: do not block the rest of the app meanwhile
        set_time_limit(90);
        $meta = fetchPageMeta($bookmark['url']);
        [$path, $source] = refreshPreview($pdo, $bookmark, $meta);
        $host = parse_url($bookmark['url'], PHP_URL_HOST);
        if ($host) cachedFavicon($pdo, $host);
        echo json_encode(['success' => true, 'online' => $meta['online'], 'has_image' => $path !== '', 'source' => $source]);
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
    // Mosaic of the subfolder cards, only needed when the folder view is rebuilt
    $previews = ($offset === 0 && $search === '') ? folderPreviews($pdo, $folder) : [];

    echo json_encode(['bookmarks' => $bookmarks, 'categories' => $uniqueTags, 'folders' => $folders, 'root_count' => $rootCount,
        'folder_previews' => (object)$previews]);
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
            set_time_limit(90);
            $meta = fetchPageMeta($url);
            [$imagePath] = createPreview($url, $meta);
            // The page title comes from a foreign page: store it escaped like all other texts.
            $title = htmlspecialchars($meta['title'] ?? (string)parse_url($url, PHP_URL_HOST));
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
        // Folders of the file are recreated below "Imported"; existing URLs are skipped.
        $r = importBookmarkHtml($pdo, file_get_contents($_FILES['importFile']['tmp_name']));
        if (isset($r['error'])) { echo json_encode(['success' => false, 'error' => $r['error']]); exit; }
        $message = sprintf('Imported %d bookmarks into "%s" (%d new folders). Skipped: %d already saved, %d without http(s) URL. Preview images: start the admin scanner.',
            $r['imported'], IMPORT_FOLDER, $r['folders'], $r['duplicates'], $r['invalid']);
        echo json_encode(['success' => true, 'message' => $message, 'folder' => IMPORT_FOLDER] + $r);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Failed to process file.']);
    exit;
}

if ($action === 'refresh' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $stmt = $pdo->prepare("SELECT id, url, image FROM bookmarks WHERE id = ?");
    $stmt->execute([$id]);
    $bookmark = $stmt->fetch();

    if ($bookmark) {
        session_write_close();
        set_time_limit(90);
        [$newImage, $source] = refreshPreview($pdo, $bookmark);
        echo json_encode(['success' => true, 'newImage' => $newImage, 'source' => $source]);
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
            set_time_limit(90);
            [$imagePath] = createPreview($newUrl);
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