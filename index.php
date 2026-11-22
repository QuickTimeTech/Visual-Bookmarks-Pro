<?php
require_once 'functions.php';

$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $role = loginRole((string)$_POST['password']);
    if ($role) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = $role;
        header("Location: index.php");
        exit;
    } else {
        $login_error = "Incorrect password.";
    }
}
if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit; }

$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$is_admin = sessionRole() === 'admin'; // read-only guests get no editing controls and no bookmarklet token
sendPrivacyHeaders();

$protocol = isHttps() ? "https" : "http";
$bookmarkletToken = $is_admin ? bookmarkletToken($pdo) : '';
$baseUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . (dirname($_SERVER['PHP_SELF']) === '/' ? '' : dirname($_SERVER['PHP_SELF']));
$bookmarkletCode = "javascript:(function(){window.open('{$baseUrl}/save.php?token={$bookmarkletToken}&url='+encodeURIComponent(window.location.href)+'&title='+encodeURIComponent(document.title),'SaveBookmark','width=400,height=300,left='+(screen.width/2-200)+',top='+(screen.height/2-150));})();";
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <!-- No Referer to any site: visited pages must not learn where you came from -->
    <meta name="referrer" content="no-referrer">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visual Bookmarks Pro</title>
    <link rel="icon" type="image/png" href="bookmark.png">
    <!-- Served locally (Bootstrap 5.3.0, Bootstrap Icons 1.11.1, MIT) instead of a CDN -->
    <link href="vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: var(--bs-body-bg); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .card { transition: transform 0.1s ease, box-shadow 0.2s ease; border-radius: 12px; border: 1px solid var(--bs-border-color-translucent); }
        
        /* Desktop Folder Styling */
        .folder-card { user-select: none; }
        .folder-card:hover { background-color: var(--bs-secondary-bg) !important; transform: translateY(-2px); box-shadow: 0 6px 15px rgba(0,0,0,0.08) !important; border-color: var(--bs-primary); }
        .folder-card:active { transform: translateY(0); }
        .folder-card.drag-over { border: 2px dashed var(--bs-primary) !important; background-color: var(--bs-primary-bg-subtle) !important; }

        /* Grid View Default */
        .bookmark-card { cursor: grab; }
        .bookmark-card:active { cursor: grabbing; }
        .bookmark-card .card { cursor: pointer; user-select: none; }
        .bookmark-card .card:hover { border-color: var(--bs-primary-border-subtle); box-shadow: 0 4px 12px rgba(0,0,0,0.05) !important; }
        
        .card-img-top { height: 180px; object-fit: cover; object-position: top; border-top-left-radius: 11px; border-top-right-radius: 11px; border-bottom: 1px solid var(--bs-border-color-translucent); }
        /* Drawn tile for links without a local preview image */
        .preview-tile { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; overflow: hidden;
            background: linear-gradient(135deg, hsl(var(--tile-hue) 55% 90%), hsl(calc(var(--tile-hue) + 40) 55% 82%)); color: hsl(var(--tile-hue) 45% 28%); }
        [data-bs-theme="dark"] .preview-tile { background: linear-gradient(135deg, hsl(var(--tile-hue) 35% 24%), hsl(calc(var(--tile-hue) + 40) 35% 17%)); color: hsl(var(--tile-hue) 60% 82%); }
        .preview-tile .tile-letter { font-size: 3.5rem; font-weight: 700; line-height: 1; }
        .preview-tile .tile-domain { font-size: 0.85rem; opacity: 0.8; max-width: 90%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .preview-tile .tile-icon { width: 72px; height: 72px; padding: 10px; object-fit: contain; background: #fff; border-radius: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.12); }
        .preview-tile .tile-icon.small { padding: 20px; image-rendering: pixelated; }
        .preview-tile:has(.tile-icon) .tile-letter { display: none; }
        .list-view-active .preview-tile .tile-letter { font-size: 1.75rem; }
        .list-view-active .preview-tile .tile-domain { display: none; }
        .list-view-active .preview-tile .tile-icon { width: 44px; height: 44px; padding: 6px; border-radius: 10px; }
        .list-view-active .preview-tile .tile-icon.small { padding: 6px; }
        /* Folder cards: 2x2 mosaic of links inside (own corners, so the menu is not clipped) */
        .folder-mosaic { display: grid; gap: 2px; height: 180px; border-top-left-radius: 11px; border-top-right-radius: 11px; overflow: hidden; background: var(--bs-body-bg); border-bottom: 1px solid var(--bs-border-color-translucent); }
        .folder-mosaic.n1 { grid-template: 1fr / 1fr; }
        .folder-mosaic.n2 { grid-template: 1fr / 1fr 1fr; }
        .folder-mosaic.n3, .folder-mosaic.n4 { grid-template: 1fr 1fr / 1fr 1fr; }
        .folder-mosaic.n3 > .cell:first-child { grid-row: span 2; }
        .folder-mosaic .cell { min-width: 0; min-height: 0; overflow: hidden; }
        .folder-mosaic .cell > img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .folder-mosaic .preview-tile { height: 100%; gap: 0; }
        .folder-mosaic .tile-domain { display: none; }
        .folder-mosaic .tile-letter { font-size: 2rem; }
        .folder-mosaic .tile-icon { width: 48px; height: 48px; padding: 7px; border-radius: 12px; }
        .folder-mosaic .tile-icon.small { padding: 12px; }
        .folder-mosaic.n1 .tile-icon, .folder-mosaic.n2 .tile-icon, .folder-mosaic.n3 > .cell:first-child .tile-icon { width: 64px; height: 64px; padding: 9px; border-radius: 16px; }
        .folder-empty { display: grid; place-items: center; background: var(--bs-tertiary-bg); }
        .folder-empty i { font-size: 3.5rem; color: var(--bs-primary); opacity: 0.85; }
        @media (max-width: 575.98px) { .folder-mosaic { height: 150px; } }
        /* Folder mosaics stay muted until the pointer is on the card (devices with a mouse only) */
        @media (hover: hover) {
            .folder-mosaic { filter: saturate(0.4); opacity: 0.75; transition: filter 0.2s ease, opacity 0.2s ease; }
            .folder-card:hover .folder-mosaic, .folder-card.drag-over .folder-mosaic, .folder-card:focus-within .folder-mosaic { filter: none; opacity: 1; }
        }
        /* Caption strip of link and folder cards in the tint of the selected tree folder */
        .card-caption { background: var(--bs-primary-bg-subtle); border-bottom-left-radius: inherit; border-bottom-right-radius: inherit; }
        .card-caption .border-top { border-top-color: var(--bs-primary-border-subtle) !important; }
        .list-view-active .card-caption { background: transparent; }
        .list-view-active .card-caption .border-top { border-top-color: var(--bs-border-color) !important; }
        .bulk-checkbox-container { position: absolute; top: 12px; left: 12px; z-index: 10; background: var(--bs-body-bg); border-radius: 6px; padding: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: flex; align-items: center; justify-content: center; }
        
        /* Enhanced List View */
        .list-view-active .bookmark-card { width: 100% !important; margin-bottom: 0.5rem; }
        .list-view-active .card { flex-direction: row; align-items: center; padding: 12px 16px; gap: 16px; height: auto !important; }
        .list-view-active .card:hover { background-color: var(--bs-tertiary-bg); }
        .list-view-active .bulk-checkbox-container { position: static; background: transparent; padding: 0; box-shadow: none; margin-right: 4px; }
        .list-view-active .card-img-top { width: 64px; height: 64px; border-radius: 8px; border: 1px solid var(--bs-border-color-translucent); flex-shrink: 0; }
        .list-view-active .card-body { padding: 0; display: flex; flex-direction: row !important; align-items: center; width: 100%; min-width: 0; }
        .list-view-active .card-content { flex-grow: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
        .list-view-active .card-title { font-size: 1.05rem; font-weight: 600; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding-right: 20px; }
        .list-view-active .card-meta { display: flex; align-items: center; gap: 8px; margin-bottom: 0 !important; }
        .list-view-active .action-buttons { flex-shrink: 0; margin-top: 0 !important; }

        /* General UI Tweaks */
        .badge-soft { font-weight: 500; font-size: 0.75rem; padding: 0.35em 0.65em; }
        .btn-icon-only { width: 32px; height: 32px; padding: 0; display: flex; align-items: center; justify-content: center; border-radius: 8px; color: var(--bs-secondary-color); border: 1px solid transparent; background: transparent; transition: all 0.2s; position: relative; z-index: 2; }
        .btn-icon-only:hover { background: var(--bs-secondary-bg); color: var(--bs-body-color); border-color: var(--bs-border-color); }
        .btn-icon-only.hover-danger:hover { background: var(--bs-danger-bg-subtle); color: var(--bs-danger); border-color: var(--bs-danger-border-subtle); }
        .btn-icon-only.hover-primary:hover { background: var(--bs-primary-bg-subtle); color: var(--bs-primary); border-color: var(--bs-primary-border-subtle); }
        
        #loadingOverlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(var(--bs-body-bg-rgb), 0.85); z-index: 1050; justify-content: center; align-items: center; flex-direction: column; backdrop-filter: blur(2px); }
        #bulkActionBar { position: fixed; bottom: -100px; left: 50%; transform: translateX(-50%); z-index: 1040; transition: bottom 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        #bulkActionBar.active { bottom: 40px; }

        /* Layout with folder tree */
        .app-layout { display: flex; gap: 1.5rem; }
        .app-main { flex: 1 1 auto; min-width: 0; }
        .sidebar-resizer { display: none; } /* shown on large screens only */
        @media (min-width: 992px) {
            /* Width set by dragging the handle (app.js), 270px by default */
            #folderSidebar { position: relative; flex: 0 0 var(--sidebar-width, 270px); max-width: min(var(--sidebar-width, 270px), 50vw); }
            /* Sticky only moves inside its parent: the body spans the full sidebar height, so the tree stays in view */
            #folderSidebar .offcanvas-body { height: 100%; }
            #folderSidebar .tree-sticky { position: sticky; top: 76px; }
            #folderSidebar .tree-card { max-height: calc(100vh - 92px); overflow-y: auto; }
            .sidebar-resizer { display: block; position: absolute; top: 0; bottom: 0; right: -12px; width: 10px; cursor: col-resize; touch-action: none; z-index: 5; }
            .sidebar-resizer::after { content: ""; position: absolute; top: 10px; bottom: 10px; left: 1px; width: 3px; border-radius: 2px; background: transparent; transition: background-color 0.15s ease; }
            .sidebar-resizer:hover::after, .sidebar-resizer:focus-visible::after, .resizing-sidebar .sidebar-resizer::after { background: var(--bs-primary); }
            .sidebar-resizer:focus-visible { outline: none; }
            .resizing-sidebar, .resizing-sidebar * { cursor: col-resize !important; user-select: none !important; }
        }
        .tree { list-style: none; margin: 0; padding: 0; }
        .tree .tree { padding-left: 0.9rem; margin-left: 0.55rem; border-left: 1px solid var(--bs-border-color-translucent); }
        .tree li.collapsed > .tree { display: none; }
        .tree-row { display: flex; align-items: center; gap: 2px; border-radius: 8px; padding: 1px 4px; cursor: pointer; user-select: none; border: 2px dashed transparent; }
        .tree-row:hover { background: var(--bs-secondary-bg); }
        .tree-row.active { background: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); font-weight: 600; }
        .tree-toggle { width: 22px; height: 22px; border: 0; background: transparent; color: var(--bs-secondary-color); padding: 0; flex-shrink: 0; transition: transform .15s ease; }
        .tree-toggle.open { transform: rotate(90deg); }
        .tree-toggle.no-children { visibility: hidden; }
        .tree-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding: 4px 2px; }
        .tree-count { font-size: .72rem; color: var(--bs-secondary-color); padding-right: 4px; }

        /* Drag & drop */
        .drag-over { border: 2px dashed var(--bs-primary) !important; background-color: var(--bs-primary-bg-subtle) !important; }
        .is-dragging { opacity: .4; }
        .crumb { border: 2px dashed transparent; border-radius: 8px; padding: 2px 8px; text-decoration: none; cursor: pointer; }
        .crumb:hover { background: var(--bs-secondary-bg); }
        .breadcrumb { align-items: center; margin: 0; }
        .folder-card .folder-menu-btn { margin-left: auto; }
        /* The hover transform gives each card its own stacking layer; without this
           an open menu would sit behind the cards that follow it. */
        .folder-card:has(.dropdown-menu.show) { z-index: 1000; }
        #breadcrumbBar { position: relative; z-index: 1001; }
        .folder-card .dropdown-toggle::after, #breadcrumbBar .dropdown-toggle::after { display: none; }
    </style>
</head>
<body<?= $is_logged_in && !$is_admin ? ' class="read-only"' : '' ?>>

<?php if (!$is_logged_in): ?>
<div class="container d-flex align-items-center justify-content-center vh-100">
    <div class="card shadow-lg border-0 p-5" style="max-width: 400px; width: 100%; border-radius: 20px;">
        <div class="text-center mb-4"><img src="bookmark.png" alt="Logo" style="width: 72px; height: 72px; object-fit: contain;" class="mb-3"><h3 class="fw-bold">Visual Bookmarks</h3></div>
        <?php if ($login_error): ?><div class="alert alert-danger shadow-sm py-2"><i class="bi bi-exclamation-circle me-2"></i><?= $login_error ?></div><?php endif; ?>
        <form method="POST" action="">
            <div class="mb-4"><input type="password" name="password" class="form-control form-control-lg bg-body-tertiary" placeholder="Password" required autofocus></div>
            <button type="submit" class="btn btn-primary btn-lg w-100 shadow-sm fw-bold"><i class="bi bi-unlock-fill me-2"></i> Log In</button>
        </form>
    </div>
</div>
<?php else: ?>

<div id="loadingOverlay"><div class="spinner-border text-primary" style="width: 3rem; height: 3rem;"></div><h5 class="mt-3 text-primary fw-bold">Processing...</h5></div>

<datalist id="dynamicFolders"></datalist>
<datalist id="dynamicCategories"></datalist>

<?php if ($is_admin): ?>
<!-- Floating Bulk Action Bar -->
<div id="bulkActionBar" class="card border-0 bg-dark text-white px-4 py-3 rounded-pill d-flex flex-row align-items-center gap-3">
    <span class="fw-bold text-nowrap" id="bulkCount">0 selected</span>
    <div class="vr bg-secondary mx-1"></div>
    <select id="bulkFolder" class="form-select form-select-sm bg-dark text-white border-secondary shadow-none" style="width: 140px;">
        <option value="">Move Folder...</option>
    </select>
    <button class="btn btn-secondary btn-sm fw-bold text-nowrap" onclick="executeBulk('refolder')">Move</button>
    <div class="vr bg-secondary mx-1"></div>
    <input type="text" id="bulkTags" class="form-control form-control-sm bg-dark text-white border-secondary shadow-none" placeholder="Add Tags..." style="width: 120px;">
    <button class="btn btn-secondary btn-sm fw-bold text-nowrap" onclick="executeBulk('retag')">Tag</button>
    <button class="btn btn-danger btn-sm fw-bold ms-2 text-nowrap" onclick="executeBulk('delete')"><i class="bi bi-trash3-fill"></i></button>
</div>
<?php endif; ?>

<!-- Sticky Top Navbar -->
<nav class="navbar navbar-expand-lg bg-body shadow-sm sticky-top border-bottom py-2">
    <div class="container-fluid px-4">
        <button class="btn btn-icon-only d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#folderSidebar" title="Folders"><i class="bi bi-layout-sidebar fs-5"></i></button>
        <a class="navbar-brand d-flex align-items-center fw-bold me-auto me-lg-0" href="#">
            <img src="bookmark.png" alt="Logo" width="32" height="32" class="d-inline-block align-text-top me-2 logo-img">
            Visual Bookmarks
        </a>
        
        <!-- Search Bar in Navbar -->
        <div class="mx-auto d-none d-lg-flex" style="max-width: 500px; width: 100%;">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-body-tertiary border-end-0 text-muted"><i class="bi bi-search"></i></span>
                <input type="text" id="searchInput" class="form-control bg-body-tertiary border-start-0 shadow-none" placeholder="Search titles, URLs, tags...">
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <?php if ($is_admin): ?>
            <button class="btn btn-outline-secondary btn-sm fw-bold rounded-pill px-3 shadow-sm d-none d-md-block" data-bs-toggle="modal" data-bs-target="#importModal">
                <i class="bi bi-cloud-upload me-1"></i> Import
            </button>
            <?php else: ?>
            <span class="badge rounded-pill bg-secondary-subtle text-secondary border border-secondary-subtle d-none d-md-inline-block" title="You can browse and open links but not change anything"><i class="bi bi-eye me-1"></i>Read only</span>
            <?php endif; ?>
            <div class="vr mx-1 d-none d-md-block"></div>
            <button id="layoutToggle" class="btn btn-icon-only" title="Toggle Grid/List"><i class="bi bi-list-task fs-5"></i></button>
            <button id="themeToggle" class="btn btn-icon-only" title="Toggle Theme"><i class="bi bi-moon-fill" id="themeIcon"></i></button>
            <?php if ($is_admin): ?>
            <button class="btn btn-icon-only hover-primary" data-bs-toggle="modal" data-bs-target="#adminModal" title="Admin Scanner"><i class="bi bi-shield-check fs-5"></i></button>
            <?php endif; ?>
            <a href="?logout=1" class="btn btn-icon-only hover-danger" title="Log Out"><i class="bi bi-box-arrow-right fs-5"></i></a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4" style="max-width: 1680px;">
  <div class="app-layout">

    <!-- Folder tree: sidebar on large screens, slide-in panel on small ones -->
    <aside class="offcanvas-lg offcanvas-start" tabindex="-1" id="folderSidebar">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title fw-bold"><i class="bi bi-folder2-open me-2"></i>Folders</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#folderSidebar"></button>
        </div>
        <div class="offcanvas-body d-block p-3 p-lg-0">
          <div class="tree-sticky">
            <div class="card border-0 shadow-sm p-2 tree-card">
                <div class="d-flex align-items-center justify-content-between px-2 pt-1 pb-2">
                    <span class="fw-bold text-muted small text-uppercase">Folders</span>
                    <?php if ($is_admin): ?><button class="btn btn-icon-only" data-folder-action="new" data-path="" title="New top-level folder"><i class="bi bi-folder-plus"></i></button><?php endif; ?>
                </div>
                <div id="folderTree"></div>
            </div>
            <div class="sidebar-resizer" id="sidebarResizer" role="separator" aria-orientation="vertical" aria-label="Resize folder panel"
                 aria-valuemin="200" aria-valuemax="560" aria-valuenow="270" tabindex="0" title="Drag to resize, double-click to reset"></div>
          </div>
        </div>
    </aside>

    <main class="app-main">
    
    <div id="alertBox"></div>

    <!-- Main Control Row (Add Button + Filters) -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        
        <?php if ($is_admin): ?>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-lg fw-bold px-4 shadow-sm rounded-pill d-flex align-items-center justify-content-center" onclick="openAddModal()">
                <i class="bi bi-plus-lg me-2"></i> Add Bookmark
            </button>
            <button class="btn btn-secondary btn-lg fw-bold px-4 shadow-sm rounded-pill d-flex align-items-center justify-content-center" onclick="openFolderModal('create', currentFolder)">
                <i class="bi bi-folder-plus me-2"></i> New Folder
            </button>
        </div>
        <?php endif; ?>

        <div class="d-flex gap-2 flex-wrap flex-md-nowrap flex-grow-1 justify-content-md-end ms-md-auto" style="max-width: 500px;">
            <div class="input-group input-group-sm shadow-sm flex-grow-1">
                <span class="input-group-text bg-body border-end-0 text-muted"><i class="bi bi-tag"></i></span>
                <select id="filterCategory" class="form-select bg-body border-start-0 fw-medium shadow-none"><option value="All">All Tags</option></select>
            </div>
            <div class="input-group input-group-sm shadow-sm" style="max-width: 150px;">
                <span class="input-group-text bg-body border-end-0 text-muted"><i class="bi bi-sort-down"></i></span>
                <select id="sortOrder" class="form-select bg-body border-start-0 shadow-none">
                    <option value="date">Newest</option>
                    <option value="views">Most Views</option>
                    <option value="category">Category</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Path of the current folder; every part is a drop target -->
    <nav id="breadcrumbBar" class="mb-3" aria-label="Folder path"></nav>

    <!-- Folders Grid Container -->
    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 row-cols-xxl-4 g-3 mb-4" id="folderGrid"></div>

    <!-- Bookmark Grid Container -->
    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 row-cols-xxl-4 g-3 pb-5" id="bookmarkGrid"></div>
    <div id="sentinel" style="height: 20px;"></div>
    </main>
  </div>
</div>

<?php if ($is_admin): ?>
<!-- Create Folder Modal -->
<div class="modal fade" id="newFolderModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="folderForm">
          <div class="modal-header bg-body-tertiary border-bottom-0"><h5 class="modal-title fw-bold"><i class="bi bi-folder-plus text-primary me-2"></i><span id="folderNameTitle">Create New Folder</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body p-4">
            <div class="small text-muted mb-2" id="folderNameHint"></div>
            <input type="text" id="newFolderName" class="form-control form-control-lg bg-body-tertiary border-0 shadow-none text-primary fw-bold" placeholder="Folder Name" required autofocus>
          </div>
          <div class="modal-footer bg-body-tertiary border-top-0"><button type="button" class="btn btn-light border shadow-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary fw-bold shadow-sm" id="folderNameSubmit">Create Folder</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Move Folder Modal -->
<div class="modal fade" id="moveFolderModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="moveFolderForm">
          <div class="modal-header bg-body-tertiary border-bottom-0"><h5 class="modal-title fw-bold text-truncate"><i class="bi bi-folder-symlink text-primary me-2"></i>Move <span id="moveFolderName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body p-4">
            <label class="form-label fw-medium text-muted small" for="moveFolderTarget">Move into</label>
            <select id="moveFolderTarget" class="form-select bg-body-tertiary border-0 shadow-none" size="10" required></select>
          </div>
          <div class="modal-footer bg-body-tertiary border-top-0"><button type="button" class="btn btn-light border shadow-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary fw-bold shadow-sm">Move Folder</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Add Bookmark Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="addForm">
          <div class="modal-header bg-body-tertiary border-bottom-0">
            <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle text-primary me-2"></i>Add New Link</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label fw-medium text-muted small">URL</label>
                <input type="url" id="urlInput" class="form-control form-control-lg bg-body-tertiary border-0 shadow-none text-primary" placeholder="https://example.com" required autofocus>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label fw-medium text-muted small">Folder</label>
                    <input type="text" id="folderInput" class="form-control bg-body-tertiary border-0 shadow-none" list="dynamicFolders" placeholder="Top level, or Music/Live">
                </div>
                <div class="col-6">
                    <label class="form-label fw-medium text-muted small">Tags (comma separated)</label>
                    <input type="text" id="catInput" class="form-control bg-body-tertiary border-0 shadow-none" list="dynamicCategories" placeholder="News, Tech...">
                </div>
            </div>
          </div>
          <div class="modal-footer bg-body-tertiary border-top-0">
            <button type="button" class="btn btn-light border shadow-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary fw-bold shadow-sm">Save Bookmark</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Admin Batch Scanner Modal -->
<div class="modal fade" id="adminModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-body-tertiary border-bottom-0"><h5 class="modal-title fw-bold"><i class="bi bi-shield-check text-primary me-2"></i>Admin Dashboard</h5><button type="button" class="btn-close" data-bs-dismiss="modal" id="adminCloseBtn"></button></div>
      <div class="modal-body p-4">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-4 p-3 bg-body-tertiary rounded border">
            <span class="small text-muted flex-grow-1">Bookmarklet: drag this button to your bookmarks toolbar to save the current page with one click.</span>
            <a class="btn btn-sm btn-outline-secondary fw-bold" href="<?= htmlspecialchars($bookmarkletCode) ?>" onclick="return false;"><i class="bi bi-bookmark-plus me-1"></i>Save to Bookmarks</a>
        </div>
        <div class="row text-center mb-4 g-2">
            <div class="col"><div class="p-3 bg-body-tertiary rounded border"><h4 id="statTotal" class="mb-0">0</h4><small class="text-muted">Total</small></div></div>
            <div class="col"><div class="p-3 bg-body-tertiary rounded border"><h4 id="statOnline" class="text-success mb-0">0</h4><small class="text-muted">Online</small></div></div>
            <div class="col"><div class="p-3 bg-body-tertiary rounded border"><h4 id="statOffline" class="text-danger mb-0">0</h4><small class="text-muted">Offline</small></div></div>
            <div class="col"><div class="p-3 bg-body-tertiary rounded border"><h4 id="statImage" class="text-primary mb-0">0</h4><small class="text-muted">Images OK</small></div></div>
            <div class="col"><div class="p-3 bg-body-tertiary rounded border"><h4 id="statNoImage" class="text-warning mb-0">0</h4><small class="text-muted">No Image</small></div></div>
        </div>
        <div class="progress mb-3 shadow-sm" style="height: 20px; border-radius: 10px;"><div id="adminProgress" class="progress-bar progress-bar-striped progress-bar-animated bg-primary fw-bold" style="width: 0%;">0%</div></div>
        <div id="adminLog" class="bg-dark text-light p-3 rounded" style="height: 200px; overflow-y: auto; font-family: monospace; font-size: 0.85rem; border: 1px solid #333;">Ready to scan...</div>
      </div>
      <div class="modal-footer bg-body-tertiary border-top-0 d-flex gap-2">
        <button type="button" class="btn btn-primary fw-bold flex-grow-1 shadow-sm" id="startAdminScanBtn" onclick="startAdminScan()"><i class="bi bi-play-fill me-1"></i> Start Batch Scan</button>
        <button type="button" class="btn btn-danger fw-bold d-none flex-grow-1 shadow-sm" id="deleteOfflineBtn" onclick="deleteOfflineBookmarks()"><i class="bi bi-trash-fill me-1"></i> Delete Offline Links</button>
      </div>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="editForm">
          <div class="modal-header bg-body-tertiary border-bottom-0"><h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Bookmark</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body p-4">
            <input type="hidden" id="edit_id">
            <div class="mb-3"><label class="form-label fw-medium text-muted small">Title</label><input type="text" id="edit_title" class="form-control bg-body-tertiary border-0 shadow-none" required></div>
            <div class="mb-3"><label class="form-label fw-medium text-muted small">URL</label><input type="url" id="edit_url" class="form-control bg-body-tertiary border-0 shadow-none text-primary" required></div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label fw-medium text-muted small">Folder</label><input type="text" id="edit_folder" class="form-control bg-body-tertiary border-0 shadow-none" list="dynamicFolders" placeholder="Top level"></div>
                <div class="col-6"><label class="form-label fw-medium text-muted small">Tags</label><input type="text" id="edit_cat" class="form-control bg-body-tertiary border-0 shadow-none" list="dynamicCategories"></div>
            </div>
          </div>
          <div class="modal-footer bg-body-tertiary border-top-0"><button type="button" class="btn btn-light border shadow-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary fw-bold shadow-sm">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="importForm">
          <div class="modal-header bg-body-tertiary border-bottom-0"><h5 class="modal-title fw-bold"><i class="bi bi-cloud-upload text-primary me-2"></i>Import Bookmarks</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body p-4"><p class="text-muted">Upload an HTML bookmark file exported from Chrome, Safari, Firefox, or Edge.</p><p class="text-muted small">Its folders are recreated inside the folder “Imported”. Links that are already saved are skipped.</p><input type="file" id="importFile" class="form-control form-control-lg bg-body-tertiary border-0 shadow-none" accept=".html" required></div>
          <div class="modal-footer bg-body-tertiary border-top-0"><button type="submit" class="btn btn-primary fw-bold w-100 shadow-sm">Import Now</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>"></script>
<?php endif; ?>

<script>
const themeToggleBtn = document.getElementById('themeToggle');
const themeIcon = document.getElementById('themeIcon');
const htmlElement = document.documentElement;
const initTheme = localStorage.getItem('appTheme') || 'light';
setTheme(initTheme);

themeToggleBtn?.addEventListener('click', () => {
    setTheme(htmlElement.getAttribute('data-bs-theme') === 'light' ? 'dark' : 'light');
});

function setTheme(theme) {
    htmlElement.setAttribute('data-bs-theme', theme);
    localStorage.setItem('appTheme', theme);
    if (themeIcon && themeToggleBtn) {
        if (theme === 'dark') { themeIcon.classList.replace('bi-moon-fill', 'bi-sun-fill'); } 
        else { themeIcon.classList.replace('bi-sun-fill', 'bi-moon-fill'); }
    }
}
</script>
</body>
</html>