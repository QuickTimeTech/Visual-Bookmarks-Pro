// Visual Bookmarks Pro – client logic: nested folders, folder tree, drag & drop.
//
// Text convention: the API returns titles, tags and folder paths HTML-escaped,
// exactly as stored. They are decoded once when they arrive; the client then
// works with raw text and escapes everything on output with esc().

let allBookmarks = [];
let currentPage = 0;
const limit = 25;
let hasMore = true;
let isLoading = false;
let loadSeq = 0;
let selectedIds = new Set();
let offlineIds = [];
let currentFolder = '';      // raw path, '' = top level ("Home")
let folderList = [];         // [{path, count}], count = bookmarks directly inside
let folderTree = { root: { path: '', name: 'Home', count: 0, total: 0, children: [] }, nodes: new Map() };
let rootCount = 0;
let folderPreviews = {};     // raw subfolder path => [{url, image}], up to 4 links for its card
let dragPayload = null;      // {type: 'bookmarks', ids} or {type: 'folder', path}

const addModal = new bootstrap.Modal(document.getElementById('addModal'));
const editModal = new bootstrap.Modal(document.getElementById('editModal'));
const folderModal = new bootstrap.Modal(document.getElementById('newFolderModal'));
const moveModal = new bootstrap.Modal(document.getElementById('moveFolderModal'));
const importModal = new bootstrap.Modal(document.getElementById('importModal'));
const gridContainer = document.getElementById('bookmarkGrid');
const folderContainer = document.getElementById('folderGrid');
const treeContainer = document.getElementById('folderTree');
const breadcrumbBar = document.getElementById('breadcrumbBar');
const layoutToggle = document.getElementById('layoutToggle');
const collator = new Intl.Collator(undefined, { sensitivity: 'base', numeric: true });

// --- Text and path helpers ---
const decoder = document.createElement('textarea');
function decodeHtml(s) { if (s == null) return ''; decoder.innerHTML = String(s); return decoder.value; }
function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function folderFromApi(stored) { const p = decodeHtml(stored); return p === 'Uncategorized' ? '' : p; }
function baseName(path) { return path === '' ? 'Home' : path.slice(path.lastIndexOf('/') + 1); }
function parentPath(path) { const i = path.lastIndexOf('/'); return i < 0 ? '' : path.slice(0, i); }
function isInside(path, ancestor) { return path === ancestor || path.startsWith(ancestor + '/'); }
function findBookmark(id) { return allBookmarks.find(b => String(b.id) === String(id)); }
function plural(n, word) { return `${n} ${word}${n === 1 ? '' : 's'}`; }

function normalizeBookmark(b) {
    return { ...b, title: decodeHtml(b.title), category: decodeHtml(b.category || ''), folderPath: folderFromApi(b.folder || '') };
}

async function api(action, fields) {
    const fd = new FormData();
    Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
    try {
        const res = await fetch(`api.php?action=${action}`, { method: 'POST', body: fd });
        return await res.json();
    } catch (e) {
        return { success: false, error: 'Request failed.' };
    }
}

// --- Open/closed state of the tree, kept in this browser only ---
let openFolders = new Set();
try { openFolders = new Set(JSON.parse(localStorage.getItem('treeOpen') || '[]')); } catch (e) {}
function saveOpenFolders() { try { localStorage.setItem('treeOpen', JSON.stringify([...openFolders])); } catch (e) {} }
function revealFolder(path) { for (let p = parentPath(path); p !== ''; p = parentPath(p)) openFolders.add(p); saveOpenFolders(); }
function remapOpenFolders(oldPath, newPath) {
    openFolders = new Set([...openFolders].map(p => isInside(p, oldPath) ? newPath + p.slice(oldPath.length) : p));
    saveOpenFolders();
}

// --- Folder tree model ---
function buildTree(folders) {
    const root = { path: '', name: 'Home', count: rootCount, children: [] };
    const nodes = new Map([['', root]]);
    function getNode(path) {
        if (nodes.has(path)) return nodes.get(path);
        const node = { path, name: baseName(path), count: 0, children: [] };
        nodes.set(path, node);
        getNode(parentPath(path)).children.push(node);
        return node;
    }
    folders.forEach(f => { if (f.path !== '') getNode(f.path).count = f.count; });
    (function finish(node) {
        node.children.sort((a, b) => collator.compare(a.name, b.name));
        node.total = node.count + node.children.reduce((sum, c) => sum + finish(c), 0);
        return node.total;
    })(root);
    return { root, nodes };
}

function countSubfolders(node) { return node.children.reduce((n, c) => n + 1 + countSubfolders(c), 0); }

function folderOptionsHtml(excludePath = null) {
    const out = ['<option value="/">Home (top level)</option>'];
    (function walk(node, depth) {
        node.children.forEach(c => {
            if (excludePath !== null && isInside(c.path, excludePath)) return;
            out.push(`<option value="${esc(c.path)}">${'&nbsp;&nbsp;&nbsp;'.repeat(depth)}${esc(c.name)}</option>`);
            walk(c, depth + 1);
        });
    })(folderTree.root, 1);
    return out.join('');
}

// --- Rendering ---
function folderMenuHtml(path) {
    const p = esc(path);
    return `<div class="dropdown folder-menu-btn">
        <button class="btn btn-icon-only dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Folder actions"><i class="bi bi-three-dots-vertical"></i></button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><button class="dropdown-item" type="button" data-folder-action="new" data-path="${p}"><i class="bi bi-folder-plus me-2"></i>New subfolder</button></li>
            <li><button class="dropdown-item" type="button" data-folder-action="rename" data-path="${p}"><i class="bi bi-pencil me-2"></i>Rename</button></li>
            <li><button class="dropdown-item" type="button" data-folder-action="move" data-path="${p}"><i class="bi bi-folder-symlink me-2"></i>Move to…</button></li>
            <li><hr class="dropdown-divider"></li>
            <li><button class="dropdown-item text-danger" type="button" data-folder-action="delete" data-path="${p}"><i class="bi bi-trash3 me-2"></i>Delete</button></li>
        </ul>
    </div>`;
}

function renderTree() {
    const rowHtml = node => {
        const p = esc(node.path);
        const hasKids = node.children.length > 0;
        const open = hasKids && openFolders.has(node.path);
        return `<li class="${hasKids && !open ? 'collapsed' : ''}">
            <div class="tree-row${node.path === currentFolder ? ' active' : ''}" data-nav="${p}" data-drop-folder="${p}" data-drag-folder="${p}" draggable="true" title="${p}">
                <button type="button" class="tree-toggle${open ? ' open' : ''}${hasKids ? '' : ' no-children'}" data-tree-toggle="${p}" tabindex="-1"><i class="bi bi-chevron-right"></i></button>
                <i class="bi ${open ? 'bi-folder2-open' : 'bi-folder-fill'} text-primary"></i>
                <span class="tree-label">${esc(node.name)}</span>
                <span class="tree-count">${node.total || ''}</span>
            </div>
            ${hasKids ? `<ul class="tree">${node.children.map(rowHtml).join('')}</ul>` : ''}
        </li>`;
    };
    const root = folderTree.root;
    const home = `<li><div class="tree-row${currentFolder === '' ? ' active' : ''}" data-nav="" data-drop-folder="">
            <span class="tree-toggle no-children"></span><i class="bi bi-house-door-fill text-primary"></i>
            <span class="tree-label">Home</span><span class="tree-count">${root.total || ''}</span></div></li>`;
    treeContainer.innerHTML = `<ul class="tree">${home}${root.children.map(rowHtml).join('')}</ul>`;
}

function renderBreadcrumb() {
    const crumbs = [`<li class="breadcrumb-item"><a class="crumb fw-bold${currentFolder === '' ? ' text-body' : ''}" data-nav="" data-drop-folder=""><i class="bi bi-house-door-fill me-1"></i>Home</a></li>`];
    let path = '';
    const parts = currentFolder === '' ? [] : currentFolder.split('/');
    parts.forEach((part, i) => {
        path = path === '' ? part : `${path}/${part}`;
        const last = i === parts.length - 1;
        crumbs.push(`<li class="breadcrumb-item${last ? ' active' : ''}"><a class="crumb${last ? ' fw-bold text-body' : ''}" data-nav="${esc(path)}" data-drop-folder="${esc(path)}">${esc(part)}</a></li>`);
    });
    breadcrumbBar.innerHTML = `<div class="d-flex align-items-center gap-2 flex-wrap"><ol class="breadcrumb fs-5">${crumbs.join('')}</ol>${currentFolder === '' ? '' : folderMenuHtml(currentFolder)}</div>`;
}

// 2x2 mosaic of up to four links from the folder and its subfolders: their
// local preview images, else the site icon tile. 1 link fills the area, 2 split
// it, 3 give one large and two small cells. Empty folders keep the folder symbol.
function mosaicHtml(items) {
    if (!items.length) return '<div class="folder-mosaic folder-empty"><i class="bi bi-folder-fill"></i></div>';
    const cells = items.slice(0, 4).map(it => {
        if (isLocalImage(it.image)) return `<div class="cell"><img src="${esc(it.image)}" alt="" loading="lazy" draggable="false"></div>`;
        let domain = '';
        try { domain = new URL(it.url).hostname; } catch (e) {}
        return `<div class="cell">${tileHtml(domain, '', 'mosaic-tile')}</div>`;
    });
    return `<div class="folder-mosaic n${cells.length}">${cells.join('')}</div>`;
}

function folderCardHtml(node) {
    const p = esc(node.path);
    const info = [];
    if (node.children.length) info.push(`<span class="text-nowrap">${plural(node.children.length, 'folder')}</span>`);
    if (node.total || !node.children.length) info.push(`<span class="text-nowrap">${node.total ? plural(node.total, 'link') : 'Empty'}</span>`);
    return `<div class="col">
        <div class="card bg-body border-0 shadow-sm h-100 folder-card" style="cursor: pointer;"
             data-nav="${p}" data-drop-folder="${p}" data-drag-folder="${p}" draggable="true">
            ${mosaicHtml(folderPreviews[node.path] || [])}
            <div class="d-flex align-items-center px-3 py-2">
                <i class="bi bi-folder-fill fs-5 text-primary me-2"></i>
                <div style="min-width:0;" class="me-1">
                    <h6 class="fw-bold m-0 text-truncate" title="${esc(node.name)}">${esc(node.name)}</h6>
                    <small class="text-muted">${info.join(' · ')}</small>
                </div>
                ${folderMenuHtml(node.path)}
            </div>
        </div>
    </div>`;
}

// Only images stored on this server are shown. Anything else (old remote URLs,
// no image at all) becomes a drawn tile, so the browser never contacts another site.
function isLocalImage(src) { return !!src && !/^([a-z][a-z0-9+.-]*:|\/\/)/i.test(src); }

// Favicon through this server; v=2 marks the cache with large icons, so the
// browser does not keep showing a 16 px icon it cached before.
function iconUrl(domain) { return `icon.php?d=${encodeURIComponent(domain)}&v=2`; }

// Tile without preview image: the site's icon, or the first letter if it has none.
// Icons under 48 px are doubled pixel-sharp instead of being blurred up.
function tileHtml(domain, fallbackName, cls, idAttr = '') {
    const name = (domain || fallbackName || '?').replace(/^www\./, '');
    let hue = 0;
    for (const ch of name) hue = (hue * 31 + ch.codePointAt(0)) % 360;
    const icon = domain ? `<img class="tile-icon" src="${iconUrl(domain)}" alt="" loading="lazy" draggable="false"
        onload="if (this.naturalWidth < 48) this.classList.add('small')" onerror="this.remove()">` : '';
    return `<div ${idAttr} class="${cls} preview-tile" style="--tile-hue: ${hue}">
        ${icon}<span class="tile-letter">${esc([...name][0].toUpperCase())}</span><span class="tile-domain">${esc(name)}</span></div>`;
}

function previewHtml(b, id, domain) {
    if (isLocalImage(b.image)) {
        return `<img src="${esc(b.image)}" id="img-${id}" class="card-img-top bg-body-tertiary" alt="" loading="lazy" draggable="false">`;
    }
    return tileHtml(domain, b.title, 'card-img-top', `id="img-${id}"`);
}

function bookmarkCardHtml(b, isSearch) {
    const id = esc(b.id);
    let domain = '';
    try { domain = new URL(b.url).hostname; } catch (e) {}
    const favicon = domain ? `<img src="${iconUrl(domain)}" onerror="this.style.display='none'" class="me-2 rounded" style="width: 16px; height: 16px;" alt="" loading="lazy" draggable="false">` : '';
    const tags = b.category.split(',').map(t => t.trim()).filter(Boolean)
        .map(t => `<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle badge-soft me-1 mb-1">${esc(t)}</span>`).join('');
    const folderBadge = isSearch ? `<span class="badge bg-primary-subtle text-primary border border-primary-subtle badge-soft me-1 mb-1"><i class="bi bi-folder-fill me-1"></i>${esc(b.folderPath || 'Home')}</span>` : '';
    const checked = selectedIds.has(String(b.id)) ? ' checked' : '';
    return `
    <div class="col bookmark-card" draggable="true" data-drag-bookmark="${id}">
        <div class="card h-100 shadow-sm border-0 position-relative bg-body" data-visit="${id}" title="Double-click to visit">
            <div class="bulk-checkbox-container">
                <input class="form-check-input bulk-checkbox m-0 border-secondary" type="checkbox" value="${id}"${checked} style="width: 1.1rem; height: 1.1rem; cursor: pointer;">
            </div>
            ${previewHtml(b, id, domain)}
            <div class="card-body p-3 d-flex flex-column">
                <div class="card-content">
                    <h5 class="card-title mb-1" title="${esc(b.title)}">${favicon}${esc(b.title)}</h5>
                    <div class="card-meta text-muted mb-3 d-flex flex-wrap align-items-center gap-1">${folderBadge}${tags}</div>
                </div>
                <div class="mt-auto action-buttons d-flex gap-1 align-items-center justify-content-end border-top pt-2">
                    <span class="text-muted small me-2"><i class="bi bi-eye"></i> <span id="views-${id}">${Number(b.views) || 0}</span></span>
                    <button class="btn btn-icon-only hover-primary" data-bm-action="visit" data-id="${id}" title="Visit Link"><i class="bi bi-box-arrow-up-right"></i></button>
                    <button class="btn btn-icon-only" data-bm-action="edit" data-id="${id}" title="Edit Details"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-icon-only" data-bm-action="refresh" data-id="${id}" title="Refresh Image"><i class="bi bi-arrow-clockwise"></i></button>
                    <button class="btn btn-icon-only hover-danger ms-1" data-bm-action="delete" data-id="${id}" title="Delete"><i class="bi bi-trash3"></i></button>
                </div>
            </div>
        </div>
    </div>`;
}

function renderView(bookmarks, reset) {
    const isSearch = document.getElementById('searchInput').value.trim() !== '';
    if (reset) {
        renderTree();
        gridContainer.innerHTML = '';
        let subfolders = [];
        if (isSearch) {
            breadcrumbBar.innerHTML = '<h5 class="fw-bold text-muted m-0"><i class="bi bi-search me-2"></i>Search Results</h5>';
        } else {
            renderBreadcrumb();
            subfolders = (folderTree.nodes.get(currentFolder) || folderTree.root).children;
        }
        folderContainer.innerHTML = subfolders.map(folderCardHtml).join('');
        if (subfolders.length && bookmarks.length) {
            gridContainer.innerHTML = '<div class="col-12 w-100 mt-2 mb-1"><h6 class="fw-bold text-muted text-uppercase small m-0">Bookmarks</h6></div>';
        }
        if (!bookmarks.length && !subfolders.length) {
            gridContainer.innerHTML = `<div class="col-12 text-center text-muted py-5 mt-5 w-100"><i class="bi bi-journal-x display-1 opacity-25 mb-3 d-block"></i><h4>${isSearch ? 'No bookmarks found' : 'This folder is empty'}</h4></div>`;
        }
    }
    gridContainer.insertAdjacentHTML('beforeend', bookmarks.map(b => bookmarkCardHtml(b, isSearch)).join(''));
}

function updateFilterDropdowns(categories) {
    const sel = document.getElementById('filterCategory');
    const cur = sel.value;
    sel.innerHTML = '<option value="All">All Tags</option>' + categories.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
    sel.value = categories.includes(cur) ? cur : 'All';
    document.getElementById('dynamicCategories').innerHTML = categories.map(c => `<option value="${esc(c)}">`).join('');
    const paths = folderList.map(f => f.path).sort(collator.compare);
    document.getElementById('dynamicFolders').innerHTML = paths.map(p => `<option value="${esc(p)}">`).join('');
    document.getElementById('bulkFolder').innerHTML = '<option value="">Move to…</option>' + folderOptionsHtml();
}

// --- Loading ---
async function loadBookmarks(reset = false) {
    if (!reset && (isLoading || !hasMore)) return;
    const seq = ++loadSeq;
    isLoading = true;
    if (reset) { currentPage = 0; hasMore = true; selectedIds.clear(); updateBulkAction(); }

    const search = document.getElementById('searchInput').value.trim();
    const params = new URLSearchParams({
        action: 'load', limit, offset: currentPage * limit, search,
        category: document.getElementById('filterCategory').value,
        sort: document.getElementById('sortOrder').value,
        folder: currentFolder,
    });
    if (search !== '') params.set('all', '1');

    let data;
    try {
        const res = await fetch('api.php?' + params);
        data = await res.json();
    } catch (e) {
        if (seq === loadSeq) { isLoading = false; showAlert('Could not load bookmarks.', 'danger'); }
        return;
    }
    if (seq !== loadSeq) return; // a newer request took over
    if (data.error) { window.location.reload(); return; }

    const bookmarks = data.bookmarks.map(normalizeBookmark);
    if (reset) {
        allBookmarks = [];
        rootCount = Number(data.root_count) || 0;
        folderList = data.folders.map(f => ({ path: folderFromApi(f.name), count: Number(f.count) || 0 }));
        folderTree = buildTree(folderList);
        folderPreviews = {};
        Object.entries(data.folder_previews || {}).forEach(([path, items]) => { folderPreviews[folderFromApi(path)] = items; });
        if (currentFolder !== '' && !folderTree.nodes.has(currentFolder)) {
            isLoading = false;
            setCurrentFolder('', true);
            return loadBookmarks(true);
        }
        updateFilterDropdowns(data.categories.map(decodeHtml));
    }
    allBookmarks = [...allBookmarks, ...bookmarks];
    hasMore = data.bookmarks.length === limit;
    currentPage++;
    renderView(bookmarks, reset);
    isLoading = false;
}

// --- Navigation; the current folder lives in the URL fragment ---
function folderFromHash() { try { return decodeURIComponent(location.hash.slice(1)); } catch (e) { return ''; } }
function setCurrentFolder(path, replace = false) {
    currentFolder = path;
    if (folderFromHash() === path) return;
    const url = path ? '#' + encodeURIComponent(path) : location.pathname + location.search;
    history[replace ? 'replaceState' : 'pushState'](null, '', url);
}
function navToFolder(path) {
    setCurrentFolder(path);
    revealFolder(path);
    if (path !== '') { openFolders.add(path); saveOpenFolders(); } // show its subfolders in the tree
    document.getElementById('searchInput').value = '';
    bootstrap.Offcanvas.getInstance(document.getElementById('folderSidebar'))?.hide();
    loadBookmarks(true);
}
window.addEventListener('popstate', () => { currentFolder = folderFromHash(); revealFolder(currentFolder); loadBookmarks(true); });

function toggleTreeNode(path) {
    if (openFolders.has(path)) openFolders.delete(path); else openFolders.add(path);
    saveOpenFolders();
    renderTree();
}

// --- Clicks (delegated, since tree and grids are re-rendered) ---
document.addEventListener('click', e => {
    const t = e.target;
    const folderAction = t.closest('[data-folder-action]');
    if (folderAction) { e.preventDefault(); handleFolderAction(folderAction.dataset.folderAction, folderAction.dataset.path); return; }
    if (t.closest('[data-bs-toggle="dropdown"], .dropdown-menu')) return;
    const toggle = t.closest('[data-tree-toggle]');
    if (toggle) { toggleTreeNode(toggle.dataset.treeToggle); return; }
    const bmAction = t.closest('[data-bm-action]');
    if (bmAction) { handleBookmarkAction(bmAction.dataset.bmAction, bmAction.dataset.id); return; }
    const nav = t.closest('[data-nav]');
    if (nav) navToFolder(nav.dataset.nav);
});

document.addEventListener('dblclick', e => {
    if (e.target.closest('.bulk-checkbox-container, .action-buttons')) return;
    const card = e.target.closest('[data-visit]');
    if (card) trackAndVisit(card.dataset.visit);
});

document.addEventListener('change', e => {
    if (!e.target.classList.contains('bulk-checkbox')) return;
    if (e.target.checked) selectedIds.add(e.target.value); else selectedIds.delete(e.target.value);
    updateBulkAction();
});

// --- Drag & drop: bookmarks and folders onto tree rows, folder cards and the path bar ---
let dragOverEl = null;
let expandTimer = null;

function dropTargetFor(e) {
    const el = e.target.closest ? e.target.closest('[data-drop-folder]') : null;
    if (!el || !dragPayload) return null;
    const target = el.dataset.dropFolder;
    if (dragPayload.type === 'folder') {
        const p = dragPayload.path;
        if (isInside(target, p) || parentPath(p) === target) return null; // into itself or where it already is
    }
    return el;
}

function clearDragState() {
    dragOverEl?.classList.remove('drag-over');
    dragOverEl = null;
    clearTimeout(expandTimer);
    document.querySelectorAll('.is-dragging').forEach(x => x.classList.remove('is-dragging'));
}

document.addEventListener('dragstart', e => {
    const el = e.target.closest ? e.target.closest('[data-drag-bookmark], [data-drag-folder]') : null;
    if (!el) return;
    if (el.dataset.dragBookmark !== undefined) {
        const id = el.dataset.dragBookmark;
        const ids = selectedIds.has(id) ? [...selectedIds] : [id];
        dragPayload = { type: 'bookmarks', ids };
        e.dataTransfer.setData('text/plain', ids.length > 1 ? plural(ids.length, 'bookmark') : (findBookmark(id)?.title || ''));
    } else {
        dragPayload = { type: 'folder', path: el.dataset.dragFolder };
        e.dataTransfer.setData('text/plain', dragPayload.path);
    }
    e.dataTransfer.effectAllowed = 'move';
    el.classList.add('is-dragging');
});

document.addEventListener('dragover', e => {
    const el = dropTargetFor(e);
    if (el !== dragOverEl) {
        dragOverEl?.classList.remove('drag-over');
        dragOverEl = el;
        clearTimeout(expandTimer);
        if (el) {
            el.classList.add('drag-over');
            // Hovering over a closed tree node opens it after a moment
            const li = el.classList.contains('tree-row') ? el.parentElement : null;
            if (li && li.classList.contains('collapsed')) {
                expandTimer = setTimeout(() => {
                    openFolders.add(el.dataset.dropFolder); saveOpenFolders();
                    li.classList.remove('collapsed');
                    el.querySelector('.tree-toggle')?.classList.add('open');
                }, 700);
            }
        }
    }
    if (el) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }
});

document.addEventListener('drop', async e => {
    const payload = dragPayload;
    const el = dropTargetFor(e);
    clearDragState();
    dragPayload = null;
    if (!el || !payload) return;
    e.preventDefault();
    const target = el.dataset.dropFolder;
    if (payload.type === 'folder') await moveFolderTo(payload.path, target);
    else await moveBookmarks(payload.ids, target);
});

document.addEventListener('dragend', () => { clearDragState(); dragPayload = null; });

async function moveBookmarks(ids, target) {
    const moving = ids.filter(id => findBookmark(id)?.folderPath !== target);
    if (!moving.length) return;
    const data = await api('bulk', { task: 'refolder', ids: JSON.stringify(moving), new_folder: target || '/' });
    if (!data.success) { showAlert('Move failed.', 'danger'); return; }
    showAlert(`Moved ${plural(moving.length, 'bookmark')} to ${target || 'Home'}`, 'success');
    loadBookmarks(true);
}

// --- Folder actions ---
function handleFolderAction(action, path) {
    if (action === 'new') openFolderModal('create', path);
    else if (action === 'rename') openFolderModal('rename', path);
    else if (action === 'move') openMoveModal(path);
    else if (action === 'delete') deleteFolderConfirm(path);
}

let folderModalState = { mode: 'create', path: '' };
function openFolderModal(mode, path) {
    folderModalState = { mode, path };
    const rename = mode === 'rename';
    document.getElementById('folderNameTitle').textContent = rename ? 'Rename Folder' : 'Create New Folder';
    document.getElementById('folderNameSubmit').textContent = rename ? 'Rename' : 'Create Folder';
    document.getElementById('folderNameHint').textContent = rename ? `Folder: ${path}` : `Inside: ${path || 'Home (top level)'}`;
    document.getElementById('newFolderName').value = rename ? baseName(path) : '';
    folderModal.show();
}
document.getElementById('newFolderModal').addEventListener('shown.bs.modal', () => {
    const input = document.getElementById('newFolderName'); input.focus(); input.select();
});

document.getElementById('folderForm').addEventListener('submit', async e => {
    e.preventDefault();
    const name = document.getElementById('newFolderName').value.trim();
    if (!name) return;
    const { mode, path } = folderModalState;
    const data = mode === 'rename' ? await api('folder_rename', { path, name }) : await api('folder_create', { parent: path, name });
    if (!data.success) { showAlert(data.error || 'Failed.', 'danger'); return; }
    folderModal.hide();
    const newPath = folderFromApi(data.path);
    if (mode === 'rename') {
        remapOpenFolders(path, newPath);
        if (isInside(currentFolder, path)) setCurrentFolder(newPath + currentFolder.slice(path.length), true);
        showAlert('Folder renamed', 'success');
    } else {
        revealFolder(newPath);
        showAlert('Folder created', 'success');
    }
    loadBookmarks(true);
});

let movePath = '';
function openMoveModal(path) {
    movePath = path;
    document.getElementById('moveFolderName').textContent = baseName(path);
    const sel = document.getElementById('moveFolderTarget');
    sel.innerHTML = folderOptionsHtml(path);
    sel.value = parentPath(path) || '/';
    moveModal.show();
}

document.getElementById('moveFolderForm').addEventListener('submit', async e => {
    e.preventDefault();
    const target = document.getElementById('moveFolderTarget').value;
    moveModal.hide();
    await moveFolderTo(movePath, target === '/' ? '' : target);
});

async function moveFolderTo(path, target) {
    if (parentPath(path) === target) return;
    if (isInside(target, path)) { showAlert('A folder cannot be moved into itself.', 'warning'); return; }
    const data = await api('folder_move', { path, target });
    if (!data.success) { showAlert(data.error || 'Move failed.', 'danger'); return; }
    const newPath = folderFromApi(data.path);
    remapOpenFolders(path, newPath);
    revealFolder(newPath);
    if (isInside(currentFolder, path)) setCurrentFolder(newPath + currentFolder.slice(path.length), true);
    showAlert(`Moved “${baseName(path)}” to ${target || 'Home'}`, 'success');
    loadBookmarks(true);
}

async function deleteFolderConfirm(path) {
    const node = folderTree.nodes.get(path);
    if (!node) return;
    const subs = countSubfolders(node);
    const parts = [];
    if (node.total) parts.push(plural(node.total, 'bookmark'));
    if (subs) parts.push(plural(subs, 'subfolder'));
    const what = parts.length ? ` together with ${parts.join(' and ')}` : '';
    if (!confirm(`Delete folder “${path}”${what}?\n\nThis cannot be undone.`)) return;
    const data = await api('folder_delete', { path });
    if (!data.success) { showAlert(data.error || 'Delete failed.', 'danger'); return; }
    if (isInside(currentFolder, path)) setCurrentFolder(parentPath(path), true);
    openFolders.forEach(p => { if (isInside(p, path)) openFolders.delete(p); });
    saveOpenFolders();
    showAlert(`Deleted “${baseName(path)}”: ${plural(data.folders, 'folder')}, ${plural(data.bookmarks, 'bookmark')}`, 'success');
    loadBookmarks(true);
}

// --- Bookmark actions ---
function handleBookmarkAction(action, id) {
    if (action === 'visit') trackAndVisit(id);
    else if (action === 'edit') openEdit(id);
    else if (action === 'refresh') refreshImage(id);
    else if (action === 'delete') deleteBookmark(id);
}

function trackAndVisit(id) {
    const b = findBookmark(id);
    if (!b || !/^https?:\/\//i.test(b.url)) return;
    api('click', { id });
    const viewSpan = document.getElementById(`views-${id}`);
    if (viewSpan) viewSpan.innerText = (parseInt(viewSpan.innerText, 10) || 0) + 1;
    window.open(b.url, '_blank', 'noopener,noreferrer'); // no Referer: the site must not learn where you came from
}

async function refreshImage(id) {
    const preview = document.getElementById(`img-${id}`);
    if (preview) preview.style.opacity = '0.3';
    const data = await api('refresh', { id });
    const b = findBookmark(id);
    if (data.success && b) {
        b.image = data.newImage;
        const card = document.querySelector(`.bookmark-card[data-drag-bookmark="${CSS.escape(String(id))}"]`);
        if (card) { card.outerHTML = bookmarkCardHtml(b, document.getElementById('searchInput').value.trim() !== ''); return; }
    }
    if (preview) preview.style.opacity = '1';
    if (!data.success) showAlert(data.error || 'Could not refresh the image.', 'danger');
}

async function deleteBookmark(id) {
    if (!confirm('Delete this bookmark?')) return;
    await api('delete', { id });
    loadBookmarks(true);
}

function openAddModal() {
    document.getElementById('urlInput').value = '';
    document.getElementById('catInput').value = '';
    document.getElementById('folderInput').value = currentFolder;
    addModal.show();
}

document.getElementById('addForm').addEventListener('submit', async e => {
    e.preventDefault(); addModal.hide(); toggleLoading(true);
    const data = await api('add', {
        url: document.getElementById('urlInput').value,
        folder: document.getElementById('folderInput').value,
        category: document.getElementById('catInput').value,
    });
    toggleLoading(false);
    if (data.success) { showAlert('Bookmark saved!', 'success'); loadBookmarks(true); } else { showAlert(data.error || 'Failed.', 'danger'); }
});

function openEdit(id) {
    const b = findBookmark(id);
    if (!b) return;
    document.getElementById('edit_id').value = b.id;
    document.getElementById('edit_title').value = b.title;
    document.getElementById('edit_url').value = b.url;
    document.getElementById('edit_folder').value = b.folderPath;
    document.getElementById('edit_cat').value = b.category;
    editModal.show();
}

document.getElementById('editForm').addEventListener('submit', async e => {
    e.preventDefault(); editModal.hide(); toggleLoading(true);
    const data = await api('edit', {
        id: document.getElementById('edit_id').value,
        title: document.getElementById('edit_title').value,
        url: document.getElementById('edit_url').value,
        folder: document.getElementById('edit_folder').value,
        category: document.getElementById('edit_cat').value,
    });
    toggleLoading(false);
    if (!data.success) showAlert(data.error || 'Failed.', 'danger');
    loadBookmarks(true);
});

document.getElementById('importForm').addEventListener('submit', async e => {
    e.preventDefault(); toggleLoading(true);
    const fd = new FormData(); fd.append('importFile', document.getElementById('importFile').files[0]);
    let data;
    try { const res = await fetch('api.php?action=import', { method: 'POST', body: fd }); data = await res.json(); } catch (err) { data = { success: false, error: 'Import failed.' }; }
    toggleLoading(false);
    if (data.success) {
        importModal.hide();
        document.getElementById('importFile').value = '';
        if (data.imported > 0 && data.folder) navToFolder(folderFromApi(data.folder)); else loadBookmarks(true);
        showAlert(data.message || 'Imported.', 'success', 9000);
    } else {
        showAlert(data.error || 'Import failed.', 'danger');
    }
});

// --- Bulk actions ---
function updateBulkAction() {
    const bar = document.getElementById('bulkActionBar');
    document.getElementById('bulkCount').innerText = `${selectedIds.size} selected`;
    bar.classList.toggle('active', selectedIds.size > 0);
}

async function executeBulk(task) {
    if (!selectedIds.size) return;
    if (task === 'delete' && !confirm(`Delete ${plural(selectedIds.size, 'bookmark')}?`)) return;
    const fields = { task, ids: JSON.stringify([...selectedIds]) };
    if (task === 'retag') fields.new_tags = document.getElementById('bulkTags').value;
    if (task === 'refolder') { const f = document.getElementById('bulkFolder').value; if (!f) return; fields.new_folder = f; }
    await api('bulk', fields);
    document.getElementById('bulkTags').value = ''; document.getElementById('bulkFolder').value = '';
    loadBookmarks(true);
}

// --- Admin scanner ---
async function startAdminScan() {
    const btn = document.getElementById('startAdminScanBtn'), closeBtn = document.getElementById('adminCloseBtn');
    const log = document.getElementById('adminLog'), prog = document.getElementById('adminProgress'), deleteBtn = document.getElementById('deleteOfflineBtn');
    const setStat = (id, v) => { document.getElementById('stat' + id).innerText = v; };
    btn.disabled = true; closeBtn.disabled = true; deleteBtn.classList.add('d-none'); offlineIds = [];
    log.innerHTML = 'Fetching bookmarks list...<br>'; prog.style.width = '0%'; prog.innerText = '0%';
    const stats = { total: 0, online: 0, offline: 0, image: 0, noimage: 0 };
    try {
        const res = await fetch('api.php?action=admin_list'); const list = await res.json();
        stats.total = list.length;
        setStat('Total', stats.total); ['Online', 'Offline', 'Image', 'NoImage'].forEach(id => setStat(id, 0));
        if (stats.total === 0) { log.innerHTML += 'No bookmarks found.<br>'; btn.disabled = false; closeBtn.disabled = false; return; }
        for (let i = 0; i < stats.total; i++) {
            const b = list[i];
            log.innerHTML += `<span class="text-info">Processing (${i + 1}/${stats.total}):</span> ${esc(decodeHtml(b.title))}... `; log.scrollTop = log.scrollHeight;
            const pData = await api('admin_process', { id: b.id });
            if (pData.success) {
                if (pData.online) { stats.online++; log.innerHTML += '<span class="text-success">ONLINE</span> | '; } else { stats.offline++; offlineIds.push(b.id); log.innerHTML += '<span class="text-danger">OFFLINE</span> | '; }
                if (pData.has_image) { stats.image++; log.innerHTML += `<span class="text-primary">IMG ${esc(pData.source || 'OK')}</span><br>`; } else { stats.noimage++; log.innerHTML += '<span class="text-warning">NO IMG (tile)</span><br>'; }
            } else { log.innerHTML += '<span class="text-danger">ERROR</span><br>'; }
            setStat('Online', stats.online); setStat('Offline', stats.offline); setStat('Image', stats.image); setStat('NoImage', stats.noimage);
            const pct = Math.round(((i + 1) / stats.total) * 100); prog.style.width = pct + '%'; prog.innerText = pct + '%';
        }
        log.innerHTML += '<br><span class="text-success fw-bold">Batch complete!</span><br>'; loadBookmarks(true);
        if (offlineIds.length > 0) { deleteBtn.classList.remove('d-none'); deleteBtn.innerHTML = `<i class="bi bi-trash-fill me-1"></i> Delete ${offlineIds.length} Offline`; }
    } catch (e) { log.innerHTML += '<span class="text-danger">Fatal error occurred.</span><br>'; }
    btn.disabled = false; closeBtn.disabled = false;
}

async function deleteOfflineBookmarks() {
    if (!confirm(`Permanently delete ${offlineIds.length} offline bookmarks?`)) return;
    const btn = document.getElementById('deleteOfflineBtn'); btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Deleting...';
    await api('bulk', { task: 'delete', ids: JSON.stringify(offlineIds) });
    document.getElementById('adminLog').innerHTML += `<br><span class="text-success fw-bold">Deleted ${offlineIds.length} offline links.</span><br>`;
    offlineIds = []; btn.classList.add('d-none'); btn.disabled = false; loadBookmarks(true);
}

// --- Misc UI ---
const savedLayout = localStorage.getItem('appLayout') || 'grid';
if (savedLayout === 'list') { gridContainer.classList.add('list-view-active'); layoutToggle.innerHTML = '<i class="bi bi-grid fs-5"></i>'; }
layoutToggle?.addEventListener('click', () => {
    const isList = gridContainer.classList.toggle('list-view-active');
    localStorage.setItem('appLayout', isList ? 'list' : 'grid');
    layoutToggle.innerHTML = isList ? '<i class="bi bi-grid fs-5"></i>' : '<i class="bi bi-list-task fs-5"></i>';
});

let searchTimeout;
document.getElementById('searchInput').addEventListener('input', () => { clearTimeout(searchTimeout); searchTimeout = setTimeout(() => loadBookmarks(true), 300); });
document.getElementById('filterCategory').addEventListener('change', () => loadBookmarks(true));
document.getElementById('sortOrder').addEventListener('change', () => loadBookmarks(true));

function toggleLoading(show) { document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none'; }

// Messages are plain text; showAlert escapes them.
function showAlert(text, type, duration = 3500) {
    const icon = { success: 'bi-check-circle-fill', danger: 'bi-exclamation-triangle-fill', warning: 'bi-exclamation-circle-fill' }[type] || 'bi-info-circle-fill';
    const box = document.getElementById('alertBox');
    box.innerHTML = `<div class="alert alert-${type} shadow-sm border-0"><i class="bi ${icon} me-2"></i>${esc(text)}</div>`;
    clearTimeout(showAlert.timer);
    showAlert.timer = setTimeout(() => { box.innerHTML = ''; }, duration);
}

// Infinite scroll
new IntersectionObserver(entries => { if (entries[0].isIntersecting && allBookmarks.length) loadBookmarks(); })
    .observe(document.getElementById('sentinel'));

window.addEventListener('message', event => { if (event.data === 'bookmark_saved') loadBookmarks(true); });

currentFolder = folderFromHash();
revealFolder(currentFolder);
loadBookmarks(true);
