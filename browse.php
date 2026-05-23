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
// Subfolders to also scan and merge into the parent folder's listing.
// Files from these subfolders get parsed for hymn-number prefix and appended to the
// matching hymn group; files with no recognizable hymn number go in "Other Audio".
$SUBFOLDERS = [
    'Available Music' => ['Audio Files'],
];
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
    if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'])) return 'fa-volume-high';
    if (in_array($ext, ['mus', 'musicxml', 'xml']))                  return 'fa-file-code';
    if ($ext === 'txt')                                              return 'fa-file-lines';
    if (in_array($ext, ['png', 'tif', 'tiff', 'jpg', 'jpeg', 'gif'])) return 'fa-file-image';
    if (in_array($ext, ['doc', 'docx']))                             return 'fa-file-word';
    if (in_array($ext, ['zip', 'tar', 'gz']))                        return 'fa-file-zipper';
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
 *   "821 Come, Thou Almighty King - New Church Liturgy Selections_ Twenty Hymns.mp3"
 *       -> numbers=[821], title="Come, Thou Almighty King"   (album suffix stripped)
 *
 *   "16 - Lori John Odhner - We Will Serve the Lord.mp3"
 *       -> null   (looks like an album track number, not a hymn)
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

    // Reject album-track-number patterns: "16 - Artist - Song" with a leading
    // dash immediately after the number. Real hymn filenames go straight into
    // the title text ("16 Title"), never "16 - Title".
    if (preg_match('/^-\s/', $rest)) {
        return null;
    }

    // Strip album/collection suffix from audio recordings. The pattern is
    // distinctive: " - <Album Name>_ <Subtitle>" where the underscore stands in
    // for a colon that can't appear in filenames. Examples from Audio Files/:
    //   "821 Come, Thou Almighty King - New Church Liturgy Selections_ Twenty Hymns"
    //   "999 The God of Harvest Praise - New Church Liturgy Selections_ Seventeen Hymns"
    // We require the trailing "_ " (underscore + space) so we don't mangle real
    // hymn titles that contain " - " inside parentheses, e.g.
    //   "Sanctus (Ancient - 7th & 8th Offices)"
    //   "Guide Me, O Thou Great Jehovah (Beethoven - Variant)"
    if (preg_match('/^(.*?)\s+-\s+[^()]+_\s.+$/', $rest, $am)) {
        $rest = $am[1];
    }

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
/**
 * List regular files in a directory (no recursion, no dotfiles, no directories).
 * Returns array of ['name' => string, 'size' => int].
 */
function scan_files(string $dir): array {
    $entries = @scandir($dir);
    if ($entries === false) return [];
    $files = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dir . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($full)) continue;
        if (strpos($entry, '.') === 0) continue;
        $files[] = ['name' => $entry, 'size' => filesize($full)];
    }
    return $files;
}

/**
 * Build the file index for a folder, optionally grouped by hymn number,
 * optionally merging files from subfolders into matching hymn groups.
 *
 * Returns:
 *   For grouped folders: array of hymn groups, each with:
 *     - key:         "1000 Come, Ye Thankful People..."   (for search/display)
 *     - number:      "1000"
 *     - sort_number: 1000 (numeric for sorting; letter suffixes get fractional)
 *     - title:       "Come, Ye Thankful People, Come"
 *     - exts:        ["pdf", "mid", "mus", ...]   (uppercased, unique)
 *     - files:       array of file rows. Each file has:
 *           - name:    the bare filename
 *           - size:    bytes
 *           - ext:     lowercased extension
 *           - page:    page number if "p.1" suffix found, else null
 *           - subdir:  if from a subfolder, the subfolder name; else null
 *
 *   For flat folders: array of file rows, each:
 *     - name, size, ext
 *
 * Files from subfolders that don't match any hymn number are collected under
 * a special "Other Audio" group at the end (distinct from "Other Files",
 * which holds unparseable files from the main folder).
 *
 * @param array<string> $subfolders Names of subfolders to also scan and merge in.
 */
function build_index(string $folderPath, bool $grouped, array $subfolders = []): array {
    // Always scan the main folder.
    $files = scan_files($folderPath);

    if (!$grouped) {
        // Flat folders: just sort and return. Don't bother with subfolders for these.
        usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return array_map(function($f) {
            $f['ext'] = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            return $f;
        }, $files);
    }

    // ---- Grouped: parse each filename and bucket by hymn number ----
    $groups = []; // keyed by "number"
    $unparsed = [];        // unparseable files from the main folder
    $otherAudio = [];      // unparseable files from subfolders (audio orphans)

    // Track basenames already seen in the main folder so we can dedupe
    // subfolder files that are exact duplicates (same filename, same hymn).
    // Key: hymn number; Value: set of lowercased basenames already in that hymn.
    $seenInGroup = [];

    /**
     * Add a parsed file into the right hymn group(s).
     * Closure captures $groups, $seenInGroup by reference.
     */
    $addToGroup = function(array $file, array $parsed, ?string $subdir) use (&$groups, &$seenInGroup) {
        foreach ($parsed['numbers'] as $num) {
            $groupKey = strtolower($num);
            if (!isset($groups[$groupKey])) {
                // Compute a sortable number: "153a" -> 153.01, "153" -> 153.0
                $sort = (int)$num;
                if (preg_match('/[a-z]/i', $num, $sm)) {
                    $sort += (ord(strtolower($sm[0])) - ord('a') + 1) / 100;
                }
                $groups[$groupKey] = [
                    'number'      => $num,
                    'sort_number' => $sort,
                    'title'       => $parsed['title'],   // use first-seen title
                    'titles_seen' => [],                  // track alternate titles
                    'exts'        => [],
                    'files'       => [],
                ];
                $seenInGroup[$groupKey] = [];
            }

            // Track this title as an alternate if it differs from the canonical one.
            $titleLower = strtolower($parsed['title']);
            if (!in_array($titleLower, $groups[$groupKey]['titles_seen'], true)) {
                $groups[$groupKey]['titles_seen'][] = $titleLower;
            }

            // Dedupe by exact filename (case-insensitive) within the hymn.
            // This handles the case where the same .mid lives in both the main folder
            // and the Audio Files subfolder — only show one copy (the main folder wins
            // because it's scanned first).
            $basenameKey = strtolower($file['name']);
            if (in_array($basenameKey, $seenInGroup[$groupKey], true)) {
                return; // Skip duplicate.
            }
            $seenInGroup[$groupKey][] = $basenameKey;

            $groups[$groupKey]['files'][] = [
                'name'   => $file['name'],
                'size'   => $file['size'],
                'ext'    => strtolower($parsed['ext']),
                'page'   => $parsed['page'],
                'subdir' => $subdir, // null for main folder, e.g. "Audio Files" for subfolder
            ];
            $extUpper = strtoupper($parsed['ext']);
            if (!in_array($extUpper, $groups[$groupKey]['exts'], true)) {
                $groups[$groupKey]['exts'][] = $extUpper;
            }
        }
    };

    // Process main folder first (so its files take precedence in dedup).
    foreach ($files as $f) {
        $parsed = parse_hymn_filename($f['name']);
        if ($parsed === null) {
            $unparsed[] = $f + ['ext' => strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), 'subdir' => null];
            continue;
        }
        $addToGroup($f, $parsed, null);
    }

    // Process each subfolder, merging into existing groups or collecting orphans.
    foreach ($subfolders as $sub) {
        $subPath = $folderPath . DIRECTORY_SEPARATOR . $sub;
        if (!is_dir($subPath)) continue;
        $subFiles = scan_files($subPath);
        foreach ($subFiles as $f) {
            $parsed = parse_hymn_filename($f['name']);
            if ($parsed === null) {
                // Subfolder orphans go in "Other Audio", not "Other Files".
                $otherAudio[] = $f + [
                    'ext'    => strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)),
                    'subdir' => $sub,
                ];
                continue;
            }
            $addToGroup($f, $parsed, $sub);
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

    // Sort files within each group: main folder first, then audio subfolder;
    // within each, by page number then by extension.
    foreach ($groups as &$g) {
        $g['key'] = $g['number'] . ' ' . $g['title'];
        unset($g['titles_seen']); // No longer needed after grouping.
        usort($g['files'], function($a, $b) {
            // Main folder files first (subdir === null), subfolder files after.
            $aMain = $a['subdir'] === null ? 0 : 1;
            $bMain = $b['subdir'] === null ? 0 : 1;
            if ($aMain !== $bMain) return $aMain <=> $bMain;
            $ap = $a['page'] ?? 0;
            $bp = $b['page'] ?? 0;
            if ($ap !== $bp) return $ap <=> $bp;
            return strcasecmp($a['name'], $b['name']);
        });
        // Sort extension badges in a consistent order.
        $extOrder = ['PDF', 'MID', 'MIDI', 'MP3', 'WAV', 'OGG', 'M4A', 'MUS', 'MUSICXML', 'XML', 'PNG', 'TIF', 'TIFF', 'TXT', 'BAK'];
        usort($g['exts'], function($a, $b) use ($extOrder) {
            $ai = array_search($a, $extOrder); if ($ai === false) $ai = 999;
            $bi = array_search($b, $extOrder); if ($bi === false) $bi = 999;
            return $ai <=> $bi;
        });
    }
    unset($g);

    // Append "Other Files" pseudo-group for unparseable main-folder files.
    if (!empty($unparsed)) {
        usort($unparsed, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $groups[] = [
            'number'      => '',
            'sort_number' => PHP_INT_MAX - 1,
            'title'       => 'Other Files',
            'key'         => 'Other Files',
            'exts'        => [],
            'files'       => array_map(fn($f) => $f + ['page' => null], $unparsed),
            'is_other'    => true,
        ];
    }

    // Append "Other Audio" pseudo-group for subfolder files we couldn't match
    // to any hymn (e.g., "audio.zip", "A Dwelling Place for You.mp3").
    if (!empty($otherAudio)) {
        usort($otherAudio, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $groups[] = [
            'number'      => '',
            'sort_number' => PHP_INT_MAX,
            'title'       => 'Other Audio',
            'key'         => 'Other Audio',
            'exts'        => [],
            'files'       => array_map(fn($f) => $f + ['page' => null], $otherAudio),
            'is_other'    => true,
        ];
    }

    return $groups;
}

// ---------- Cache: load if fresh, otherwise rebuild ----------
// Effective mtime = max of main folder and any configured subfolders.
// This way adding a file to Audio Files/ also invalidates the cache.
$subfolders = $SUBFOLDERS[$folder] ?? [];
$folderMtime = filemtime($folderPath);
foreach ($subfolders as $sub) {
    $subPath = $folderPath . DIRECTORY_SEPARATOR . $sub;
    if (is_dir($subPath)) {
        $folderMtime = max($folderMtime, filemtime($subPath));
    }
}

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
    $items = build_index($folderPath, $isGrouped, $subfolders);
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

// Compute the set of available file types in this folder, with the count of
// hymn groups that contain at least one file of that type. Used to render
// filter chips above the results. Only enabled for grouped folders, since
// the filter operates on hymn-level "has-type" membership.
$typeCounts = [];   // ext (uppercase) => number of hymns containing >=1 file of that type
$typeOrder  = ['PDF', 'MID', 'MIDI', 'MP3', 'WAV', 'OGG', 'M4A', 'MUS', 'MUSICXML', 'XML', 'PNG', 'TIF', 'TIFF', 'TXT', 'BAK', 'ZIP'];
if ($isGrouped) {
    foreach ($items as $g) {
        if (!empty($g['is_other'])) continue; // Don't count "Other Files" / "Other Audio" buckets.
        foreach ($g['exts'] as $ext) {
            $typeCounts[$ext] = ($typeCounts[$ext] ?? 0) + 1;
        }
    }
    // Sort by the canonical order, with anything unknown at the end.
    uksort($typeCounts, function($a, $b) use ($typeOrder) {
        $ai = array_search($a, $typeOrder); if ($ai === false) $ai = 999;
        $bi = array_search($b, $typeOrder); if ($bi === false) $bi = 999;
        return $ai <=> $bi;
    });
}

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
            white-space: nowrap;
        }
        .badge.pdf  { background: #fde4e4; color: #b53030; }
        .badge.mid, .badge.midi  { background: #e6f3e6; color: #2d7a2d; }
        .badge.mp3, .badge.wav, .badge.ogg, .badge.m4a, .badge.aac, .badge.flac { background: #e0f0f7; color: #0e7c8a; }
        .badge.mus, .badge.musicxml, .badge.xml  { background: #e6ecf5; color: #3a5a99; }
        .badge.txt  { background: #fef4e0; color: #8a5a00; }
        .badge.png, .badge.tif, .badge.tiff  { background: #efeaf5; color: #6b4a8a; }
        .badge.zip, .badge.tar, .badge.gz { background: #ece8e0; color: #6b5a30; }

        /* --- Filter chip bar --- */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        .filter-label {
            font-size: 12px;
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex-shrink: 0;
        }
        .filter-chips {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            flex-grow: 1;
        }
        /* Filter chips reuse .badge color classes but are larger and clickable */
        .filter-chip {
            font-size: 12px;
            padding: 5px 10px;
            border: 2px solid transparent;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            opacity: 0.7;
            transition: opacity 0.15s, border-color 0.15s, transform 0.05s;
            line-height: 1;
        }
        .filter-chip:hover { opacity: 1; }
        .filter-chip:active { transform: scale(0.96); }
        .filter-chip[aria-pressed="true"] {
            opacity: 1;
            border-color: currentColor;
        }
        .filter-count {
            font-size: 10px;
            font-weight: normal;
            opacity: 0.7;
            font-family: monospace;
        }
        .filter-clear {
            font-size: 12px;
            padding: 5px 10px;
            background: transparent;
            color: #666;
            border: 1px solid #ccc;
            border-radius: 3px;
            cursor: pointer;
            font-family: inherit;
            transition: 0.15s;
            flex-shrink: 0;
        }
        .filter-clear:hover:not(:disabled) {
            background: #f0f0f0;
            color: #333;
        }
        .filter-clear:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

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
            /* On mobile, badges sit just under the title — full row, tight gap,
               smaller chips so 4 fit on a phone width without wrapping awkwardly. */
            .hymn-badges {
                grid-column: 1 / -1;
                justify-content: flex-start;
                margin-top: 6px;
                gap: 4px;
            }
            .badge {
                font-size: 9px;
                padding: 2px 6px;
                letter-spacing: 0.3px;
            }
            .expand-icon {
                position: absolute;
                right: 14px;
                top: 14px;
            }
            .hymn-header { position: relative; padding-right: 35px; }
            .hymn-files { padding: 0 14px 12px 64px; }
            .flat-row { padding: 12px 14px; }
            /* Filter bar: stack chips below the label, allow horizontal scroll if many */
            .filter-bar {
                gap: 8px;
                margin-top: 14px;
                padding-top: 12px;
                border-top: 1px solid #f0f0f0;
            }
            .filter-label {
                width: 100%;
                font-size: 11px;
            }
            .filter-chips { gap: 5px; }
            .filter-chip {
                font-size: 11px;
                padding: 6px 9px;
            }
            .filter-clear {
                font-size: 11px;
                padding: 6px 10px;
                margin-left: auto;
            }
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

        <?php if ($isGrouped && !empty($typeCounts)): ?>
        <div class="filter-bar" id="filterBar">
            <span class="filter-label">File types:</span>
            <div class="filter-chips">
                <?php foreach ($typeCounts as $ext => $count):
                    $extLower = strtolower($ext);
                ?>
                    <button type="button"
                            class="filter-chip badge <?= $extLower ?>"
                            data-type="<?= htmlspecialchars($extLower) ?>"
                            aria-pressed="false">
                        <?= htmlspecialchars($ext) ?>
                        <span class="filter-count"><?= $count ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <button type="button" class="filter-clear" id="filterClear" disabled>Clear</button>
        </div>
        <?php endif; ?>

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
                // Lowercased, space-separated list of extensions so the JS filter
                // can do an O(1) "contains" check per row. Empty for "Other" buckets.
                $typeKey = strtolower(implode(' ', $g['exts']));
            ?>
                <div class="hymn-row" data-search="<?= htmlspecialchars($searchKey) ?>" data-types="<?= htmlspecialchars($typeKey) ?>"<?= $isOther ? ' data-other="1"' : '' ?>>
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
                            // If the file lives in a subfolder, include that in the href.
                            $relPath = isset($f['subdir']) && $f['subdir']
                                ? $folder . '/' . $f['subdir'] . '/' . $f['name']
                                : $folder . '/' . $f['name'];
                            $href = safe_link($relPath);
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

    <script>
        const searchInput  = document.getElementById('searchInput');
        const clearBtn     = document.getElementById('clearBtn');
        const results      = document.getElementById('results');
        const resultsCount = document.getElementById('resultsCount');
        const rows         = results.querySelectorAll('[data-search]');
        const isGrouped    = <?= $isGrouped ? 'true' : 'false' ?>;

        // Filter chip state — set of lowercased file-type strings currently active.
        // Empty set = no type filter (all types pass).
        const activeTypes = new Set();
        const filterChips = document.querySelectorAll('.filter-chip');
        const filterClear = document.getElementById('filterClear');

        /**
         * Apply both filters (text search + type chips) and update visibility + count.
         * A row is visible iff: (1) every search term appears in its data-search, AND
         * (2) at least one active type appears in its data-types.
         * "Other Files" / "Other Audio" rows are hidden whenever a type filter is on,
         * since they don't belong to any specific type.
         */
        function applyFilters() {
            const q = searchInput.value.trim().toLowerCase();
            const terms = q.length ? q.split(/\s+/) : [];
            const hasTypeFilter = activeTypes.size > 0;

            clearBtn.disabled = terms.length === 0;
            if (filterClear) filterClear.disabled = !hasTypeFilter;

            let visible = 0;
            rows.forEach(r => {
                let match = true;
                if (terms.length) {
                    const haystack = r.dataset.search || '';
                    match = terms.every(t => haystack.includes(t));
                }
                if (match && hasTypeFilter) {
                    // "Other" buckets never match a type filter.
                    if (r.dataset.other === '1') {
                        match = false;
                    } else {
                        const types = (r.dataset.types || '').split(' ').filter(Boolean);
                        match = types.some(t => activeTypes.has(t));
                    }
                }
                r.style.display = match ? '' : 'none';
                if (match) visible++;
            });

            // Show the results-count line whenever either filter is active.
            if (terms.length === 0 && !hasTypeFilter) {
                resultsCount.style.display = 'none';
                return;
            }
            resultsCount.style.display = 'block';
            const label = isGrouped ? 'hymn' : 'file';
            const plural = visible === 1 ? '' : 's';
            const filterDesc = [];
            if (terms.length) filterDesc.push(`matching <strong>"${escapeHtml(q)}"</strong>`);
            if (hasTypeFilter) filterDesc.push(`with <strong>${[...activeTypes].map(t => t.toUpperCase()).join(', ')}</strong>`);
            const filterText = filterDesc.join(' ');
            resultsCount.innerHTML = visible === 0
                ? `No ${label}s ${filterText}`
                : `Showing <strong>${visible}</strong> ${label}${plural} ${filterText}`;
        }

        function escapeHtml(s) {
            return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }

        function toggleHymn(headerEl) {
            const row = headerEl.parentElement;
            row.classList.toggle('expanded');
            headerEl.classList.toggle('expanded');
        }

        searchInput.addEventListener('input', applyFilters);
        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            searchInput.focus();
            applyFilters();
        });

        // Wire up filter chips: clicking toggles membership in activeTypes.
        filterChips.forEach(chip => {
            chip.addEventListener('click', () => {
                const t = chip.dataset.type;
                if (activeTypes.has(t)) {
                    activeTypes.delete(t);
                    chip.setAttribute('aria-pressed', 'false');
                } else {
                    activeTypes.add(t);
                    chip.setAttribute('aria-pressed', 'true');
                }
                applyFilters();
            });
        });

        // "Clear" button resets all active type chips.
        if (filterClear) {
            filterClear.addEventListener('click', () => {
                activeTypes.clear();
                filterChips.forEach(chip => chip.setAttribute('aria-pressed', 'false'));
                applyFilters();
            });
        }

        // Keyboard: Escape clears both filters (when search has focus); "/" focuses search.
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && document.activeElement === searchInput) {
                searchInput.value = '';
                applyFilters();
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