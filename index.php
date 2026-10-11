<?php
require_once 'functions.php';

$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $MASTER_PASSWORD) {
        $_SESSION['logged_in'] = true;
        header("Location: index.php");
        exit;
    } else {
        $login_error = "Incorrect password.";
    }
}
if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit; }

$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$baseUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . (dirname($_SERVER['PHP_SELF']) === '/' ? '' : dirname($_SERVER['PHP_SELF']));
$bookmarkletCode = "javascript:(function(){window.open('{$baseUrl}/save.php?url='+encodeURIComponent(window.location.href)+'&title='+encodeURIComponent(document.title),'SaveBookmark','width=400,height=300,left='+(screen.width/2-200)+',top='+(screen.height/2-150));})();";
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visual Bookmarks Pro</title>
    <link rel="icon" type="image/png" href="bookmark.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
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
    </style>
</head>
<body>

<?php if (!$is_logged_in): ?>
<div class="container d-flex align-items-center justify-content-center vh-100">
    <div class="card shadow-lg border-0 p-5" style="max-width: 400px; width: 100%; border-radius: 20px;">
        <div class="text-center mb-4"><img src="bookmark.png" alt="Logo" style="width: 72px; height: 72px; object-fit: contain;" class="mb-3"><h3 class="fw-bold">Visual Bookmarks</h3></div>
        <?php if ($login_error): ?><div class="alert alert-danger shadow-sm py-2"><i class="bi bi-exclamation-circle me-2"></i><?= $login_error ?></div><?php endif; ?>
        <form method="POST" action="">
            <div class="mb-4"><input type="password" name="password" class="form-control form-control-lg bg-body-tertiary" placeholder="Master Password" required autofocus></div>
            <button type="submit" class="btn btn-primary btn-lg w-100 shadow-sm fw-bold"><i class="bi bi-unlock-fill me-2"></i> Log In</button>
        </form>
    </div>
</div>
<?php else: ?>

<div id="loadingOverlay"><div class="spinner-border text-primary" style="width: 3rem; height: 3rem;"></div><h5 class="mt-3 text-primary fw-bold">Processing...</h5></div>

<datalist id="dynamicFolders"></datalist>
<datalist id="dynamicCategories"></datalist>

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

<!-- Sticky Top Navbar -->
<nav class="navbar navbar-expand-lg bg-body shadow-sm sticky-top border-bottom py-2">
    <div class="container-fluid px-4">
        <a class="navbar-brand d-flex align-items-center fw-bold" href="#">
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
            <button class="btn btn-outline-secondary btn-sm fw-bold rounded-pill px-3 shadow-sm d-none d-md-block" data-bs-toggle="modal" data-bs-target="#importModal">
                <i class="bi bi-cloud-upload me-1"></i> Import
            </button>
            <div class="vr mx-1 d-none d-md-block"></div>
            <button id="layoutToggle" class="btn btn-icon-only" title="Toggle Grid/List"><i class="bi bi-list-task fs-5"></i></button>
            <button id="themeToggle" class="btn btn-icon-only" title="Toggle Theme"><i class="bi bi-moon-fill" id="themeIcon"></i></button>
            <button class="btn btn-icon-only hover-primary" data-bs-toggle="modal" data-bs-target="#adminModal" title="Admin Scanner"><i class="bi bi-shield-check fs-5"></i></button>
            <a href="?logout=1" class="btn btn-icon-only hover-danger" title="Log Out"><i class="bi bi-box-arrow-right fs-5"></i></a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4" style="max-width: 1400px;">
    
    <div id="alertBox"></div>

    <!-- Main Control Row (Add Button + Filters) -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-lg fw-bold px-4 shadow-sm rounded-pill d-flex align-items-center justify-content-center" onclick="openAddModal()">
                <i class="bi bi-plus-lg me-2"></i> Add Bookmark
            </button>
            <button class="btn btn-secondary btn-lg fw-bold px-4 shadow-sm rounded-pill d-flex align-items-center justify-content-center" data-bs-toggle="modal" data-bs-target="#newFolderModal">
                <i class="bi bi-folder-plus me-2"></i> New Folder
            </button>
        </div>
        
        <div class="d-flex gap-2 flex-wrap flex-md-nowrap flex-grow-1 justify-content-md-end" style="max-width: 500px;">
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

    <!-- Folders Grid Container -->
    <div class="row row-cols-1 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-3 mb-4" id="folderGrid"></div>

    <!-- Bookmark Grid Container -->
    <div class="row row-cols-1 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-3 pb-5" id="bookmarkGrid"></div>
</div>

<!-- Create Folder Modal -->
<div class="modal fade" id="newFolderModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="folderForm">
          <div class="modal-header bg-body-tertiary border-bottom-0"><h5 class="modal-title fw-bold"><i class="bi bi-folder-plus text-primary me-2"></i>Create New Folder</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body p-4">
            <input type="text" id="newFolderName" class="form-control form-control-lg bg-body-tertiary border-0 shadow-none text-primary fw-bold" placeholder="Folder Name" required autofocus>
          </div>
          <div class="modal-footer bg-body-tertiary border-top-0"><button type="button" class="btn btn-light border shadow-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary fw-bold shadow-sm">Create Folder</button></div>
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
                    <input type="text" id="folderInput" class="form-control bg-body-tertiary border-0 shadow-none" list="dynamicFolders" placeholder="Uncategorized" required>
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
        <div class="row text-center mb-4 g-2">
            <div class="col"><div class="p-3 bg-body-tertiary rounded border"><h4 id="statTotal" class="mb-0">0</h4><small class="text-muted">Total</small></div></div>
            <div class="col"><div class="p-3 bg-body-tertiary rounded border"><h4 id="statOnline" class="text-success mb-0">0</h4><small class="text-muted">Online</small></div></div>
            <div class="col"><div class="p-3 bg-body-tertiary rounded border"><h4 id="statOffline" class="text-danger mb-0">0</h4><small class="text-muted">Offline</small></div></div>
            <div class="col"><div class="p-3 bg-body-tertiary rounded border"><h4 id="statImage" class="text-primary mb-0">0</h4><small class="text-muted">Images OK</small></div></div>
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
                <div class="col-6"><label class="form-label fw-medium text-muted small">Folder</label><input type="text" id="edit_folder" class="form-control bg-body-tertiary border-0 shadow-none" list="dynamicFolders" required></div>
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
          <div class="modal-body p-4"><p class="text-muted">Upload an HTML bookmark file exported from Chrome, Safari, Firefox, or Edge.</p><input type="file" id="importFile" class="form-control form-control-lg bg-body-tertiary border-0 shadow-none" accept=".html" required></div>
          <div class="modal-footer bg-body-tertiary border-top-0"><button type="submit" class="btn btn-primary fw-bold w-100 shadow-sm">Import Now</button></div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let allBookmarks = [];
let currentPage = 0;
const limit = 25;
let hasMore = true;
let isLoading = false;
let selectedIds = new Set();
let offlineIds = [];
let currentFolder = 'Root';

const addModal = new bootstrap.Modal(document.getElementById('addModal'));
const editModal = new bootstrap.Modal(document.getElementById('editModal'));
const folderModal = new bootstrap.Modal(document.getElementById('newFolderModal'));
const importModal = new bootstrap.Modal(document.getElementById('importModal'));
const gridContainer = document.getElementById('bookmarkGrid');
const folderContainer = document.getElementById('folderGrid');
const layoutToggle = document.getElementById('layoutToggle');

const savedLayout = localStorage.getItem('appLayout') || 'grid';
if (savedLayout === 'list') { gridContainer.classList.add('list-view-active'); layoutToggle.innerHTML = '<i class="bi bi-grid fs-5"></i>'; }

layoutToggle?.addEventListener('click', () => {
    const isList = gridContainer.classList.toggle('list-view-active');
    localStorage.setItem('appLayout', isList ? 'list' : 'grid');
    layoutToggle.innerHTML = isList ? '<i class="bi bi-grid fs-5"></i>' : '<i class="bi bi-list-task fs-5"></i>';
});

// Admin Scanner Logic
async function startAdminScan() {
    const btn = document.getElementById('startAdminScanBtn'); const closeBtn = document.getElementById('adminCloseBtn'); const log = document.getElementById('adminLog'); const prog = document.getElementById('adminProgress'); const deleteBtn = document.getElementById('deleteOfflineBtn');
    btn.disabled = true; closeBtn.disabled = true; deleteBtn.classList.add('d-none'); offlineIds = []; log.innerHTML = 'Fetching bookmarks list...<br>';
    let stats = { total: 0, online: 0, offline: 0, image: 0, noimage: 0 };
    
    try {
        const res = await fetch('api.php?action=admin_list'); const list = await res.json(); stats.total = list.length;
        ['Total','Online','Offline','Image','NoImage'].forEach(id => document.getElementById('stat'+id).innerText = (id==='Total')?stats.total:'0');
        if (stats.total === 0) { log.innerHTML += 'No bookmarks found.<br>'; btn.disabled = false; closeBtn.disabled = false; return; }
        
        for (let i = 0; i < stats.total; i++) {
            let b = list[i]; log.innerHTML += `<span class="text-info">Processing (${i+1}/${stats.total}):</span> ${b.title}... `; log.scrollTop = log.scrollHeight;
            let fd = new FormData(); fd.append('id', b.id);
            try {
                let pRes = await fetch('api.php?action=admin_process', {method: 'POST', body: fd}); let pData = await pRes.json();
                if (pData.success) {
                    if (pData.online) { stats.online++; log.innerHTML += `<span class="text-success">ONLINE</span> | `; } else { stats.offline++; offlineIds.push(b.id); log.innerHTML += `<span class="text-danger">OFFLINE</span> | `; }
                    if (pData.has_image) { stats.image++; log.innerHTML += `<span class="text-primary">IMG OK</span><br>`; } else { stats.noimage++; log.innerHTML += `<span class="text-warning">NO IMG</span><br>`; }
                } else { log.innerHTML += `<span class="text-danger">ERROR</span><br>`; }
            } catch(e) { log.innerHTML += `<span class="text-danger">FAILED</span><br>`; }
            
            document.getElementById('statOnline').innerText = stats.online; document.getElementById('statOffline').innerText = stats.offline; document.getElementById('statImage').innerText = stats.image; document.getElementById('statNoImage').innerText = stats.noimage;
            let pct = Math.round(((i + 1) / stats.total) * 100); prog.style.width = pct + '%'; prog.innerText = pct + '%';
        }
        log.innerHTML += `<br><span class="text-success fw-bold">Batch complete!</span><br>`; loadBookmarks(true); 
        if (offlineIds.length > 0) { deleteBtn.classList.remove('d-none'); deleteBtn.innerHTML = `<i class="bi bi-trash-fill me-1"></i> Delete ${offlineIds.length} Offline`; }
    } catch(e) { log.innerHTML += `<span class="text-danger">Fatal error occurred.</span><br>`; }
    btn.disabled = false; closeBtn.disabled = false;
}

async function deleteOfflineBookmarks() {
    if (!confirm(`Permanently delete ${offlineIds.length} offline bookmarks?`)) return;
    const btn = document.getElementById('deleteOfflineBtn'); btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Deleting...';
    const fd = new FormData(); fd.append('task', 'delete'); fd.append('ids', JSON.stringify(offlineIds));
    await fetch('api.php?action=bulk', { method: 'POST', body: fd });
    document.getElementById('adminLog').innerHTML += `<br><span class="text-success fw-bold">Deleted ${offlineIds.length} offline links.</span><br>`; offlineIds = []; btn.classList.add('d-none'); btn.disabled = false; loadBookmarks(true);
}

// Core Data Logic
async function loadBookmarks(reset = false) {
    if (isLoading || (!hasMore && !reset)) return;
    isLoading = true;
    if (reset) { 
        currentPage = 0; 
        allBookmarks = []; 
        gridContainer.innerHTML = ''; 
        folderContainer.innerHTML = ''; 
        selectedIds.clear(); 
        updateBulkAction(); 
    }

    const search = document.getElementById('searchInput').value; 
    const cat = document.getElementById('filterCategory').value; 
    const sort = document.getElementById('sortOrder').value;
    
    let queryFolder = currentFolder === 'Root' ? 'Uncategorized' : currentFolder;
    if (search !== '') queryFolder = 'All';

    const res = await fetch(`api.php?action=load&limit=${limit}&offset=${currentPage * limit}&search=${encodeURIComponent(search)}&category=${encodeURIComponent(cat)}&folder=${encodeURIComponent(queryFolder)}&sort=${sort}`);
    const data = await res.json();
    
    if (data.error) { window.location.reload(); return; }
    
    allBookmarks = [...allBookmarks, ...data.bookmarks];
    hasMore = data.bookmarks.length === limit;
    currentPage++;

    updateFilterDropdowns(data.categories, data.folders);
    renderGrid(data.bookmarks, reset, data.folders);
    isLoading = false;
}

function updateFilterDropdowns(categories, folders) {
    const filters = { cat: document.getElementById('filterCategory') };
    const cur = { cat: filters.cat.value };
    
    filters.cat.innerHTML = `<option value="All">All Tags</option>`; document.getElementById('dynamicCategories').innerHTML = '';
    document.getElementById('dynamicFolders').innerHTML = ''; document.getElementById('bulkFolder').innerHTML = `<option value="">Move Folder...</option>`;
    
    categories.forEach(c => { filters.cat.innerHTML += `<option value="${c}">${c}</option>`; document.getElementById('dynamicCategories').innerHTML += `<option value="${c}">`; });
    folders.forEach(f => { document.getElementById('dynamicFolders').innerHTML += `<option value="${f.name}">`; document.getElementById('bulkFolder').innerHTML += `<option value="${f.name}">${f.name}</option>`; });
    
    filters.cat.value = cur.cat;
}

function navToFolder(folderName) { currentFolder = folderName; document.getElementById('searchInput').value = ''; loadBookmarks(true); }

function allowDrop(ev) { ev.preventDefault(); ev.currentTarget.classList.add('drag-over'); }
function leaveDrop(ev) { ev.currentTarget.classList.remove('drag-over'); }
function dragStart(ev, id) { ev.dataTransfer.setData("bookmark_id", id); }

async function dropToFolder(ev, targetFolder) {
    ev.preventDefault(); ev.currentTarget.classList.remove('drag-over');
    const id = ev.dataTransfer.getData("bookmark_id");
    if (!id) return;
    const fd = new FormData(); fd.append('task', 'refolder'); fd.append('ids', JSON.stringify([id])); fd.append('new_folder', targetFolder);
    await fetch('api.php?action=bulk', { method: 'POST', body: fd });
    showAlert(`<i class="bi bi-check-circle-fill me-2"></i>Moved to ${targetFolder}`, 'success');
    loadBookmarks(true);
}

function renderGrid(bookmarks, reset, foldersList = []) {
    const isSearch = document.getElementById('searchInput').value.trim() !== '';

    if (reset) {
        if (isSearch) {
            folderContainer.innerHTML = `<div class="col-12 w-100 mb-2"><h5 class="fw-bold text-muted m-0"><i class="bi bi-search me-2"></i>Search Results</h5></div>`;
        } else if (currentFolder === 'Root') {
            const activeFolders = foldersList.filter(f => f.name !== 'Uncategorized');
            if (activeFolders.length > 0) {
                folderContainer.innerHTML = `<div class="col-12 w-100 mb-1"><h5 class="fw-bold text-muted m-0"><i class="bi bi-folder-fill me-2"></i>Folders</h5></div>`;
                activeFolders.forEach(f => {
                    folderContainer.innerHTML += `
                    <div class="col">
                        <div class="card bg-body border-0 shadow-sm h-100 p-3 d-flex flex-row align-items-center folder-card" 
                             style="cursor: pointer;" onclick="navToFolder('${f.name}')"
                             ondragover="allowDrop(event)" ondragleave="leaveDrop(event)" ondrop="dropToFolder(event, '${f.name}')">
                            <i class="bi bi-folder-fill fs-2 text-primary me-3"></i>
                            <div style="min-width:0;">
                                <h6 class="fw-bold m-0 text-truncate" title="${f.name}">${f.name}</h6>
                                <small class="text-muted">${f.count} items</small>
                            </div>
                        </div>
                    </div>`;
                });
                gridContainer.innerHTML = `<div class="col-12 w-100 mt-2 mb-1"><h5 class="fw-bold text-muted m-0"><i class="bi bi-file-earmark-text-fill me-2"></i>Bookmarks</h5></div>`;
            } else {
                folderContainer.innerHTML = '';
            }
        } else {
            folderContainer.innerHTML = `
            <div class="col-12 w-100 mb-3 d-flex align-items-center">
                <button class="btn btn-light border shadow-sm me-3 fw-bold px-3 rounded-pill" onclick="navToFolder('Root')"><i class="bi bi-arrow-left"></i> Back</button>
                <h4 class="fw-bold m-0"><i class="bi bi-folder2-open text-primary me-2"></i>${currentFolder}</h4>
            </div>`;
        }
    }

    if (bookmarks.length === 0 && (!foldersList || foldersList.length === 0 || currentFolder !== 'Root')) {
        gridContainer.innerHTML += `<div class="col-12 text-center text-muted py-5 mt-5 w-100"><i class="bi bi-journal-x display-1 opacity-25 mb-3 d-block"></i><h4>No bookmarks found</h4></div>`;
    }

    bookmarks.forEach(b => {
        let domain = 'example.com'; try { domain = new URL(b.url).hostname; } catch(e) {}
        const favicon = `https://www.google.com/s2/favicons?domain=${domain}&sz=32`;
        const tagsHtml = b.category ? b.category.split(',').map(t => t.trim()).filter(t=>t).map(t => `<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle badge-soft me-1 mb-1">${t}</span>`).join('') : '';
        const viewsStr = b.views > 0 ? `<span class="text-muted small me-2"><i class="bi bi-eye"></i> <span id="views-${b.id}">${b.views}</span></span>` : `<span class="text-muted small me-2"><i class="bi bi-eye"></i> <span id="views-${b.id}">0</span></span>`;

        gridContainer.innerHTML += `
            <div class="col bookmark-card" draggable="true" ondragstart="dragStart(event, ${b.id})">
                <div class="card h-100 shadow-sm border-0 position-relative bg-body" ondblclick="trackAndVisit(${b.id}, '${b.url}')" title="Double-click to visit">
                    
                    <div class="bulk-checkbox-container">
                        <input class="form-check-input bulk-checkbox m-0 border-secondary" type="checkbox" value="${b.id}" style="width: 1.1rem; height: 1.1rem; cursor: pointer;">
                    </div>

                    <img src="${b.image}" id="img-${b.id}" class="card-img-top bg-body-tertiary" alt="Screenshot" loading="lazy">
                    
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="card-content">
                            <h5 class="card-title mb-1" title="${b.title}">
                                <img src="${favicon}" onerror="this.style.display='none'" class="me-2 rounded bg-light" style="width: 16px; height: 16px;">${b.title}
                            </h5>
                            <div class="card-meta text-muted mb-3 d-flex flex-wrap align-items-center gap-1">
                                ${ isSearch ? `<span class="badge bg-primary-subtle text-primary border border-primary-subtle badge-soft me-1 mb-1"><i class="bi bi-folder-fill me-1"></i>${b.folder}</span>` : '' }
                                ${tagsHtml}
                            </div>
                        </div>
                        
                        <div class="mt-auto action-buttons d-flex gap-1 align-items-center justify-content-end border-top pt-2" onclick="event.stopPropagation();">
                            ${viewsStr}
                            <button class="btn btn-icon-only hover-primary" onclick="trackAndVisit(${b.id}, '${b.url}')" title="Visit Link"><i class="bi bi-box-arrow-up-right"></i></button>
                            <button class="btn btn-icon-only" onclick="openEdit(${b.id})" title="Edit Details"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-icon-only" onclick="refreshImage(${b.id})" title="Refresh Image"><i class="bi bi-arrow-clockwise"></i></button>
                            <button class="btn btn-icon-only hover-danger ms-1" onclick="deleteBookmark(${b.id})" title="Delete"><i class="bi bi-trash3"></i></button>
                        </div>
                    </div>
                </div>
            </div>`;
    });

    document.querySelectorAll('.bulk-checkbox').forEach(cb => {
        cb.addEventListener('change', (e) => { 
            e.stopPropagation();
            if (e.target.checked) selectedIds.add(e.target.value); else selectedIds.delete(e.target.value); updateBulkAction(); 
        });
        cb.addEventListener('dblclick', (e) => e.stopPropagation());
    });

    const existingSentinel = document.getElementById('sentinel'); if (existingSentinel) existingSentinel.remove();
    if (hasMore) {
        gridContainer.insertAdjacentHTML('afterend', '<div id="sentinel" class="w-100" style="height: 20px;"></div>');
        new IntersectionObserver(e => { if (e[0].isIntersecting) loadBookmarks(); }).observe(document.getElementById('sentinel'));
    }
}

function updateBulkAction() {
    const bar = document.getElementById('bulkActionBar'); document.getElementById('bulkCount').innerText = `${selectedIds.size} selected`;
    if (selectedIds.size > 0) bar.classList.add('active'); else bar.classList.remove('active');
}

async function executeBulk(task) {
    if (task === 'delete' && !confirm(`Delete ${selectedIds.size} bookmarks?`)) return;
    const fd = new FormData(); fd.append('task', task); fd.append('ids', JSON.stringify([...selectedIds]));
    if (task === 'retag') fd.append('new_tags', document.getElementById('bulkTags').value);
    if (task === 'refolder') { const f = document.getElementById('bulkFolder').value; if (!f) return; fd.append('new_folder', f); }
    await fetch('api.php?action=bulk', { method: 'POST', body: fd });
    document.getElementById('bulkTags').value = ''; document.getElementById('bulkFolder').value = ''; loadBookmarks(true);
}

// FIXED: trackAndVisit now properly appends the ID to FormData
async function trackAndVisit(id, url) { 
    const fd = new FormData(); 
    fd.append('id', id);
    
    fetch('api.php?action=click', { method: 'POST', body: fd }); 
    
    const viewSpan = document.getElementById(`views-${id}`);
    if (viewSpan) viewSpan.innerText = parseInt(viewSpan.innerText) + 1;

    window.open(url, '_blank'); 
}

async function refreshImage(id) {
    const imgEl = document.getElementById(`img-${id}`); imgEl.style.opacity = '0.3'; 
    const fd = new FormData(); fd.append('id', id);
    const res = await fetch('api.php?action=refresh', { method: 'POST', body: fd }); const data = await res.json();
    if (data.success) { imgEl.src = data.newImage + '?t=' + new Date().getTime(); }
    imgEl.style.opacity = '1';
}

function openAddModal() {
    document.getElementById('urlInput').value = '';
    document.getElementById('catInput').value = '';
    document.getElementById('folderInput').value = currentFolder === 'Root' ? 'Uncategorized' : currentFolder;
    addModal.show();
}

document.getElementById('addForm').addEventListener('submit', async (e) => {
    e.preventDefault(); addModal.hide(); toggleLoading(true);
    const fd = new FormData(); fd.append('url', document.getElementById('urlInput').value); fd.append('folder', document.getElementById('folderInput').value); fd.append('category', document.getElementById('catInput').value);
    const res = await fetch('api.php?action=add', { method: 'POST', body: fd }); const data = await res.json();
    toggleLoading(false);
    if (data.success) { showAlert('<i class="bi bi-check-circle-fill me-2"></i>Bookmark saved!', 'success'); loadBookmarks(true); } else { showAlert(data.error, 'danger'); }
});

document.getElementById('folderForm').addEventListener('submit', async (e) => {
    e.preventDefault(); 
    const name = document.getElementById('newFolderName').value.trim();
    if (!name) return;
    const fd = new FormData(); fd.append('name', name);
    const res = await fetch('api.php?action=add_folder', { method: 'POST', body: fd }); const data = await res.json();
    if (data.success) { folderModal.hide(); document.getElementById('newFolderName').value = ''; showAlert('<i class="bi bi-folder-check me-2"></i>Folder Created!', 'success'); loadBookmarks(true); } 
    else { showAlert(data.error, 'danger'); }
});

document.getElementById('importForm').addEventListener('submit', async (e) => {
    e.preventDefault(); toggleLoading(true);
    const fd = new FormData(); fd.append('importFile', document.getElementById('importFile').files[0]);
    const res = await fetch('api.php?action=import', { method: 'POST', body: fd }); const data = await res.json();
    toggleLoading(false); if (data.success) { importModal.hide(); loadBookmarks(true); } else { showAlert(data.error, 'danger'); }
});

async function deleteBookmark(id) { if (!confirm('Delete this bookmark?')) return; const fd = new FormData(); fd.append('id', id); await fetch('api.php?action=delete', { method: 'POST', body: fd }); loadBookmarks(true); }

function openEdit(id) {
    const b = allBookmarks.find(x => x.id == id);
    document.getElementById('edit_id').value = b.id; document.getElementById('edit_title').value = b.title; document.getElementById('edit_url').value = b.url; document.getElementById('edit_folder').value = b.folder; document.getElementById('edit_cat').value = b.category;
    editModal.show();
}

document.getElementById('editForm').addEventListener('submit', async (e) => {
    e.preventDefault(); editModal.hide(); toggleLoading(true);
    const fd = new FormData(); fd.append('id', document.getElementById('edit_id').value); fd.append('title', document.getElementById('edit_title').value); fd.append('url', document.getElementById('edit_url').value); fd.append('folder', document.getElementById('edit_folder').value); fd.append('category', document.getElementById('edit_cat').value);
    await fetch('api.php?action=edit', { method: 'POST', body: fd });
    toggleLoading(false); loadBookmarks(true);
});

let searchTimeout;
document.getElementById('searchInput').addEventListener('input', () => { clearTimeout(searchTimeout); searchTimeout = setTimeout(() => loadBookmarks(true), 300); });
document.getElementById('filterCategory').addEventListener('change', () => loadBookmarks(true));
document.getElementById('sortOrder').addEventListener('change', () => loadBookmarks(true));

function toggleLoading(show) { document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none'; }
function showAlert(msg, type) { const box = document.getElementById('alertBox'); box.innerHTML = `<div class="alert alert-${type} shadow-sm border-0"><i class="bi bi-info-circle-fill me-2"></i>${msg}</div>`; setTimeout(() => box.innerHTML = '', 3500); }

loadBookmarks(true);
window.addEventListener('message', (event) => { if (event.data === 'bookmark_saved') loadBookmarks(true); });
</script>
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