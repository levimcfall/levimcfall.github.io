<?php
/**
 * New Church Music — Folder Browser
 *
 * Lists files in a single folder with search.
 * For "Available Music", groups files by hymn number+title.
 * For other folders, shows a flat alphabetized list.
 *
 * Scans the filesystem on first request and caches the result to JSON.
 * Cache is invalidated when the folder's mtime changes.
 */

// ---------- Configuration ----------
$ALLOWED_FOLDERS = ['Available Music', 'Offices', 'Sacraments and Rites', 'booksoftheword'];
$GROUPED_FOLDERS = ['Available Music']; // Folders where files should be grouped by hymn number.
$CACHE_FILE = __DIR__ . '/cache.json';

// ---------- Input validation ----------
$folder = $_GET['folder'] ?? '';
if (!in_array($folder, $ALLOWED_FOLDERS, true)) {
    http_response_code(404);
    echo "Folder not found.";
    exit;
}

$folderPath = __DIR__ . DIRECTORY_SEPARATOR . $folder;
if (!is_dir($folderPath)) {
    http_response_code(404);
    echo "Folder does not exist on the server.";
    exit;
}

// ---------- Helpers ----------
function safe_link(string $path): string {
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function human_filesize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return number_format($bytes / (1024 * 1024), 1) . ' MB';
    return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}

/** Pick a Font Awesome icon class for a given file extension (lowercased). */
function get_file_icon(string $ext): string {
    if ($ext === 'pdf')                                              return 'fa-file-pdf';
    if (in_array($ext, ['mid', 'midi']))                             return 'fa-music';
    if (in_array($ext, ['mus', 'musicxml', 'xml']))                  return 'fa-file-code';
    if ($ext === 'txt')                                              return 'fa-file-lines';
    if (in_array($ext, ['png', 'tif', 'tiff', 'jpg', 'jpeg', 'gif'])) return 'fa-file-image';
    if (in_array($ext, ['doc', 'docx']))                             return 'fa-file-word';
    return 'fa-file';
}

/**
 * Parse an Available Music filename into structured pieces.
 *
 * Examples:
 *   "1000 Come, Ye Thankful People, Come.pdf"
 *       -> numbers=[1000], title="Come, Ye Thankful People, Come", page=null
 *
 *   "1001.1 Come, Sing to the Lord of Harvest p.1.pdf"
 *       -> numbers=[1001], title="Come, Sing to the Lord of Harvest", page=1
 *
 *   "106, 110 Sanctus (Ancient - 7th & 8th Offices).MUS"
 *       -> numbers=[106, 110], title="Sanctus (Ancient - 7th & 8th Offices)", page=null
 *
 *   "153a Holy Supper This Is the Lord's Doing.MUS"
 *       -> numbers=["153a"], title="Holy Supper This Is the Lord's Doing", page=null
 *
 * Returns null if the filename doesn't start with a recognizable hymn number.
 */
function parse_hymn_filename(string $filename): ?array {
    // Strip extension first.
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $ext  = pathinfo($filename, PATHINFO_EXTENSION);

    // Match leading number(s). Patterns we accept:
    //   "1000 Title"           -> single number
    //   "1001.1 Title"         -> number with page suffix .1
    //   "106, 110 Title"       -> two numbers
    //   "153a Title"           -> number with letter suffix
    // Regex captures: the whole number-section, plus the rest.
    if (!preg_match('/^([\d, ]+\d[a-z]?(?:\.\d+)?)\s+(.+)$/i', $base, $m)) {
        return null;
    }

    $numberSection = $m[1];
    $rest          = $m[2];

    // Extract page number from end of title if present: "Title p.1" or "Title p.2"
    $page = null;
    if (preg_match('/^(.*?)\s+p\.(\d+)\s*$/i', $rest, $pm)) {
        $rest = $pm[1];
        $page = (int)$pm[2];
    }

    // Also detect page suffix in the number section itself: "1001.1"
    if (preg_match('/^(.+)\.(\d+)$/', $numberSection, $dm)) {
        $numberSection = $dm[1];
        if ($page === null) $page = (int)$dm[2];
    }

    // Now split number section by commas. "106, 110" -> [106, 110]
    $numbers = array_map('trim', explode(',', $numberSection));
    // Filter out anything that doesn't look like a hymn number.
    $numbers = array_filter($numbers, fn($n) => preg_match('/^\d+[a-z]?$/i', $n));
    if (empty($numbers)) return null;

    return [
        'numbers' => array_values($numbers),
        'title'   => trim($rest),
        'page'    => $page,
        'ext'     => $ext,
    ];
}

/**
 * Build the file index for a folder, optionally grouped by hymn number.
 *
 * Returns:
 *   For grouped folders: array of hymn groups, each with:
 *     - key:         "1000 Come, Ye Thankful People..."   (for search/display)
 *     - number:      "1000"
 *     - sort_number: 1000 (numeric for sorting; letter suffixes get fractional)
 *     - title:       "Come, Ye Thankful People, Come"
 *     - exts:        ["pdf", "mid", "mus", ...]   (uppercased, unique)
 *     - files:       array of file rows, each ["name" => ..., "size" => ..., "page" => ...]
 *
 *   For flat folders: array of file rows, each:
 *     - name, size, ext
 */
function build_index(string $folderPath, bool $grouped): array {
    $entries = @scandir($folderPath);
    if ($entries === false) return [];

    $files = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $folderPath . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($full)) continue;
        if (strpos($entry, '.') === 0) continue;
        $files[] = [
            'name' => $entry,
            'size' => filesize($full),
        ];
    }

    if (!$grouped) {
        usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return array_map(function($f) {
            $f['ext'] = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            return $f;
        }, $files);
    }

    // ---- Grouped: parse each filename and bucket by hymn number ----
    $groups = []; // keyed by "number|title"
    $unparsed = []; // anything that doesn't fit the hymn-number pattern

    foreach ($files as $f) {
        $parsed = parse_hymn_filename($f['name']);
        if ($parsed === null) {
            $unparsed[] = $f + ['ext' => strtolower(pathinfo($f['name'], PATHINFO_EXTENSION))];
            continue;
        }

        // A file with multiple numbers (e.g. "106, 110 Sanctus") appears under each number.
        foreach ($parsed['numbers'] as $num) {
            // Build a key that groups files of the same hymn together.
            // Lowercased title is used so case differences don't split a group.
            $key = strtolower($num) . '|' . strtolower($parsed['title']);
            if (!isset($groups[$key])) {
                // Compute a sortable number: "153a" -> 153.1, "153" -> 153.0
                $sort = (int)$num;
                if (preg_match('/[a-z]/i', $num, $sm)) {
                    $sort += (ord(strtolower($sm[0])) - ord('a') + 1) / 100;
                }
                $groups[$key] = [
                    'number'      => $num,
                    'sort_number' => $sort,
                    'title'       => $parsed['title'],
                    'exts'        => [],
                    'files'       => [],
                ];
            }
            $groups[$key]['files'][] = [
                'name' => $f['name'],
                'size' => $f['size'],
                'ext'  => strtolower($parsed['ext']),
                'page' => $parsed['page'],
            ];
            $extUpper = strtoupper($parsed['ext']);
            if (!in_array($extUpper, $groups[$key]['exts'], true)) {
                $groups[$key]['exts'][] = $extUpper;
            }
        }
    }

    // Sort groups by hymn number, then title.
    $groups = array_values($groups);
    usort($groups, function($a, $b) {
        if ($a['sort_number'] != $b['sort_number']) {
            return $a['sort_number'] <=> $b['sort_number'];
        }
        return strcasecmp($a['title'], $b['title']);
    });

    // Sort files within each group: by page number, then by extension.
    foreach ($groups as &$g) {
        // Build a display key for search/display.
        $g['key'] = $g['number'] . ' ' . $g['title'];
        usort($g['files'], function($a, $b) {
            $ap = $a['page'] ?? 0;
            $bp = $b['page'] ?? 0;
            if ($ap !== $bp) return $ap <=> $bp;
            return strcasecmp($a['ext'], $b['ext']);
        });
        // Sort extension badges in a consistent order.
        $extOrder = ['PDF', 'MID', 'MIDI', 'MUS', 'MUSICXML', 'XML', 'PNG', 'TIF', 'TIFF', 'TXT', 'BAK'];
        usort($g['exts'], function($a, $b) use ($extOrder) {
            $ai = array_search($a, $extOrder); if ($ai === false) $ai = 999;
            $bi = array_search($b, $extOrder); if ($bi === false) $bi = 999;
            return $ai <=> $bi;
        });
    }
    unset($g);

    // Append unparsed files at the end as a special "Other" pseudo-group.
    if (!empty($unparsed)) {
        usort($unparsed, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $groups[] = [
            'number'      => '',
            'sort_number' => PHP_INT_MAX,
            'title'       => 'Other Files',
            'key'         => 'Other Files',
            'exts'        => [],
            'files'       => array_map(fn($f) => $f + ['page' => null], $unparsed),
            'is_other'    => true,
        ];
    }

    return $groups;
}

// ---------- Cache: load if fresh, otherwise rebuild ----------
$folderMtime = filemtime($folderPath);
$cache = null;
if (file_exists($CACHE_FILE)) {
    $cache = json_decode(file_get_contents($CACHE_FILE), true);
    if (!is_array($cache)) $cache = [];
}
if (!is_array($cache)) $cache = [];

$isGrouped = in_array($folder, $GROUPED_FOLDERS, true);
$needsRebuild = !isset($cache[$folder])
    || ($cache[$folder]['mtime'] ?? 0) < $folderMtime
    || ($cache[$folder]['grouped'] ?? null) !== $isGrouped;

if ($needsRebuild) {
    $items = build_index($folderPath, $isGrouped);
    $cache[$folder] = [
        'mtime'   => $folderMtime,
        'grouped' => $isGrouped,
        'items'   => $items,
        'built'   => time(),
    ];
    // Write atomically.
    @file_put_contents($CACHE_FILE, json_encode($cache), LOCK_EX);
}

$items = $cache[$folder]['items'];
$totalCount = $isGrouped
    ? array_sum(array_map(fn($g) => count($g['files']), $items))
    : count($items);
$groupCount = $isGrouped ? count($items) : 0;

// Manual-rebuild support: ?refresh=1
if (isset($_GET['refresh'])) {
    unset($cache[$folder]);
    @file_put_contents($CACHE_FILE, json_encode($cache), LOCK_EX);
    header("Location: browse.php?folder=" . rawurlencode($folder));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($folder) ?> — New Church Music</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 10px 20px 20px 20px;
            background-color: #f5f5f5;
            color: #222;
            line-height: 1.5;
        }

        .dashboard {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            margin-bottom: 20px;
            position: sticky;
            top: 10px;
            z-index: 1000;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            margin-bottom: 12px;
            color: #666;
        }
        .breadcrumb a {
            color: #0066cc;
            text-decoration: none;
            font-weight: 500;
        }
        .breadcrumb a:hover { text-decoration: underline; }
        .breadcrumb i { font-size: 11px; color: #aaa; }

        .header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        .header-row h1 {
            margin: 0;
            font-size: 22px;
            color: #0066cc;
        }
        .header-row .stats {
            font-size: 13px;
            color: #666;
        }

        .search-box {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .search-box input {
            flex-grow: 1;
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 16px;
            font-family: inherit;
        }
        .search-box input:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 2px rgba(0,102,204,0.15);
        }
        .clear-btn {
            padding: 10px 14px;
            background: #ddd;
            color: #333;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            transition: 0.2s;
        }
        .clear-btn:hover { background: #ccc; }
        .clear-btn:disabled { background: #f0f0f0; color: #aaa; cursor: not-allowed; }

        .results-count {
            font-size: 13px;
            color: #666;
            margin-top: 10px;
        }
        .results-count strong { color: #0066cc; }

        /* --- Hymn groups (Available Music) --- */
        .results {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .hymn-row {
            border-bottom: 1px solid #f0f0f0;
        }
        .hymn-row:last-child { border-bottom: none; }

        .hymn-header {
            display: grid;
            grid-template-columns: 70px 1fr auto auto;
            gap: 15px;
            align-items: center;
            padding: 14px 20px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .hymn-header:hover { background: #f8fafd; }
        .hymn-header.expanded { background: #f0f7ff; }

        .hymn-number {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #0066cc;
            font-size: 15px;
        }
        .hymn-title {
            font-weight: 500;
            color: #222;
        }
        .hymn-badges {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: 10px;
            font-weight: bold;
            font-family: monospace;
            border-radius: 3px;
            background: #e8eef5;
            color: #555;
            letter-spacing: 0.5px;
        }
        .badge.pdf  { background: #fde4e4; color: #b53030; }
        .badge.mid, .badge.midi  { background: #e6f3e6; color: #2d7a2d; }
        .badge.mus, .badge.musicxml, .badge.xml  { background: #e6ecf5; color: #3a5a99; }
        .badge.txt  { background: #fef4e0; color: #8a5a00; }
        .badge.png, .badge.tif, .badge.tiff  { background: #efeaf5; color: #6b4a8a; }

        .expand-icon {
            color: #999;
            font-size: 12px;
            width: 16px;
            text-align: center;
            transition: transform 0.2s;
        }
        .hymn-header.expanded .expand-icon { transform: rotate(90deg); color: #0066cc; }

        .hymn-files {
            display: none;
            padding: 0 20px 12px 105px;
            background: #fafbfc;
            border-top: 1px solid #f0f0f0;
        }
        .hymn-row.expanded .hymn-files { display: block; }
        .file-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }
        .file-link:last-child { border-bottom: none; }
        .file-link a {
            color: #0066cc;
            text-decoration: none;
            flex-grow: 1;
            word-break: break-word;
        }
        .file-link a:hover { text-decoration: underline; }
        .file-link .file-icon { color: #999; width: 16px; text-align: center; }
        .file-link .file-size { color: #888; font-size: 11px; font-family: monospace; }

        /* --- Flat file list (non-grouped folders) --- */
        .flat-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            border-bottom: 1px solid #f0f0f0;
        }
        .flat-row:last-child { border-bottom: none; }
        .flat-row:hover { background: #f8fafd; }
        .flat-row a {
            color: #0066cc;
            text-decoration: none;
            flex-grow: 1;
            font-weight: 500;
        }
        .flat-row a:hover { text-decoration: underline; }
        .flat-row .file-icon { color: #999; width: 20px; text-align: center; font-size: 16px; }
        .flat-row .file-size { color: #888; font-size: 12px; font-family: monospace; }

        .empty {
            padding: 40px;
            text-align: center;
            color: #999;
        }

        .footer-actions {
            margin-top: 15px;
            text-align: center;
            font-size: 12px;
            color: #999;
        }
        .footer-actions a {
            color: #0066cc;
            text-decoration: none;
        }
        .footer-actions a:hover { text-decoration: underline; }

        @media (max-width: 768px) {
            body { padding: 10px; }
            .dashboard { position: static; padding: 15px; }
            .header-row h1 { font-size: 18px; }
            .search-box input { font-size: 16px; /* keep ≥16px so iOS doesn't zoom */ }
            .hymn-header {
                grid-template-columns: 50px 1fr auto;
                gap: 10px;
                padding: 12px 14px;
            }
            .hymn-badges {
                grid-column: 1 / -1;
                justify-content: flex-start;
                margin-top: 6px;
            }
            .expand-icon {
                position: absolute;
                right: 14px;
                top: 14px;
            }
            .hymn-header { position: relative; padding-right: 35px; }
            .hymn-files { padding: 0 14px 12px 64px; }
            .flat-row { padding: 12px 14px; }
        }
    </style>
</head>
<body>

    <div class="dashboard">
        <div class="breadcrumb">
            <a href="index.php"><i class="fas fa-home"></i> Home</a>
            <i class="fas fa-chevron-right"></i>
            <span><?= htmlspecialchars($folder) ?></span>
        </div>

        <div class="header-row">
            <h1><?= htmlspecialchars($folder) ?></h1>
            <div class="stats">
                <?php if ($isGrouped): ?>
                    <strong><?= $groupCount ?></strong> hymns · <strong><?= $totalCount ?></strong> files
                <?php else: ?>
                    <strong><?= $totalCount ?></strong> files
                <?php endif; ?>
            </div>
        </div>

        <div class="search-box">
            <input type="text" id="searchInput"
                   placeholder="<?= $isGrouped ? 'Search by hymn number or title…' : 'Search files…' ?>"
                   autocomplete="off">
            <button class="clear-btn" id="clearBtn" disabled>
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="results-count" id="resultsCount" style="display: none;"></div>
    </div>

    <div class="results" id="results">
        <?php if (empty($items)): ?>
            <div class="empty">
                <i class="fas fa-folder-open" style="font-size: 32px; color: #ccc; margin-bottom: 10px;"></i>
                <div>This folder is empty.</div>
            </div>
        <?php elseif ($isGrouped): ?>
            <?php foreach ($items as $g):
                $isOther = !empty($g['is_other']);
                $searchKey = strtolower(($g['number'] ?? '') . ' ' . $g['title']);
            ?>
                <div class="hymn-row" data-search="<?= htmlspecialchars($searchKey) ?>">
                    <div class="hymn-header" onclick="toggleHymn(this)">
                        <span class="hymn-number"><?= htmlspecialchars($g['number']) ?></span>
                        <span class="hymn-title"><?= htmlspecialchars($g['title']) ?></span>
                        <span class="hymn-badges">
                            <?php foreach ($g['exts'] as $ext):
                                $badgeClass = strtolower($ext);
                            ?>
                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($ext) ?></span>
                            <?php endforeach; ?>
                        </span>
                        <i class="fas fa-chevron-right expand-icon"></i>
                    </div>
                    <div class="hymn-files">
                        <?php foreach ($g['files'] as $f):
                            $ext  = strtolower($f['ext']);
                            $icon = get_file_icon($ext);
                            $href = safe_link($folder . '/' . $f['name']);
                        ?>
                            <div class="file-link">
                                <i class="fas <?= $icon ?> file-icon"></i>
                                <a href="<?= $href ?>" target="_blank"><?= htmlspecialchars($f['name']) ?></a>
                                <span class="file-size"><?= human_filesize($f['size']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach ($items as $f):
                $ext  = strtolower($f['ext']);
                $icon = get_file_icon($ext);
                $href = safe_link($folder . '/' . $f['name']);
                $searchKey = strtolower($f['name']);
            ?>
                <div class="flat-row" data-search="<?= htmlspecialchars($searchKey) ?>">
                    <i class="fas <?= $icon ?> file-icon"></i>
                    <a href="<?= $href ?>" target="_blank"><?= htmlspecialchars($f['name']) ?></a>
                    <span class="file-size"><?= human_filesize($f['size']) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="footer-actions">
        <a href="browse.php?folder=<?= rawurlencode($folder) ?>&refresh=1">
            <i class="fas fa-sync"></i> Refresh file list
        </a>
    </div>

    <script>
        const searchInput = document.getElementById('searchInput');
        const clearBtn    = document.getElementById('clearBtn');
        const results     = document.getElementById('results');
        const resultsCount = document.getElementById('resultsCount');
        const rows        = results.querySelectorAll('[data-search]');
        const isGrouped   = <?= $isGrouped ? 'true' : 'false' ?>;

        function performSearch() {
            const q = searchInput.value.trim().toLowerCase();
            clearBtn.disabled = q.length === 0;

            if (q.length === 0) {
                rows.forEach(r => r.style.display = '');
                resultsCount.style.display = 'none';
                return;
            }

            // Split query into terms — every term must match (AND).
            const terms = q.split(/\s+/);
            let visible = 0;
            rows.forEach(r => {
                const haystack = r.dataset.search;
                const match = terms.every(t => haystack.includes(t));
                r.style.display = match ? '' : 'none';
                if (match) visible++;
            });

            resultsCount.style.display = 'block';
            const label = isGrouped ? 'hymn' : 'file';
            const plural = visible === 1 ? '' : 's';
            resultsCount.innerHTML = visible === 0
                ? `No ${label}s match <strong>"${escapeHtml(q)}"</strong>`
                : `Showing <strong>${visible}</strong> ${label}${plural}`;
        }

        function escapeHtml(s) {
            return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }

        function toggleHymn(headerEl) {
            const row = headerEl.parentElement;
            row.classList.toggle('expanded');
            headerEl.classList.toggle('expanded');
        }

        searchInput.addEventListener('input', performSearch);
        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            searchInput.focus();
            performSearch();
        });

        // Keyboard: Escape to clear, "/" to focus.
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && document.activeElement === searchInput) {
                searchInput.value = '';
                performSearch();
            } else if (e.key === '/' && document.activeElement !== searchInput) {
                e.preventDefault();
                searchInput.focus();
            }
        });

        // Autofocus search on desktop (skip on mobile to avoid keyboard popup).
        if (window.innerWidth > 768) searchInput.focus();
    </script>
</body>
</html>
