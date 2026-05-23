<?php
/**
 * New Church Music — Landing Page
 *
 * Replaces the Apache directory listing at newchurchmusic.org/
 * Shows folder buttons + root-level PDFs.
 */

// ---------- Configuration ----------
// Folders shown as primary navigation buttons.
// Each button gets its own accent color (fill + hover) to differentiate sections.
$FOLDERS = [
    'Available Music' => [
        'icon'       => 'fa-music',
        'desc'       => 'Hymns, anthems, and service music',
        'color'      => '#0066cc',  // blue — matches the player
        'color_dark' => '#0052a3',
    ],
    'Offices' => [
        'icon'       => 'fa-book-open',
        'desc'       => 'Liturgical offices and services',
        'color'      => '#0e7c66',  // muted teal
        'color_dark' => '#0a5e4d',
    ],
    'Sacraments and Rites' => [
        'icon'       => 'fa-hands-praying',
        'desc'       => 'Sacraments, baptisms, marriages, and more',
        'color'      => '#8a3a3a',  // muted burgundy
        'color_dark' => '#6b2c2c',
    ],
];

// Folders shown as links in the Documents section (alongside root-level PDFs).
$DOCUMENT_FOLDERS = [
    'booksoftheword' => 'Books of the Word',
];

// ---------- Helpers ----------
function safe_link(string $path): string {
    // Build a URL-safe href that preserves spaces/punctuation correctly.
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function human_filesize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return number_format($bytes / (1024 * 1024), 1) . ' MB';
    return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}

// ---------- Scan root-level files (PDFs etc., excluding directories) ----------
$rootDir = __DIR__;
$rootFiles = [];
if (is_dir($rootDir)) {
    foreach (scandir($rootDir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $rootDir . DIRECTORY_SEPARATOR . $entry;
        if (is_file($full) && strpos($entry, '.') !== 0 && !in_array(strtolower($entry), ['index.php', 'browse.php', 'cache.json'])) {
            // Only include user-facing files (PDFs, etc.) — skip the PHP itself.
            $rootFiles[] = [
                'name' => $entry,
                'size' => filesize($full),
            ];
        }
    }
    // Sort case-insensitively.
    usort($rootFiles, fn($a, $b) => strcasecmp($a['name'], $b['name']));
}

// Filter to only folders that actually exist on disk.
$availableFolders = [];
foreach ($FOLDERS as $name => $meta) {
    if (is_dir($rootDir . DIRECTORY_SEPARATOR . $name)) {
        $availableFolders[$name] = $meta;
    }
}

// Build list of folder entries to show in the Documents section.
// These appear alongside the root PDFs as clickable list items.
$documentFolders = [];
foreach ($DOCUMENT_FOLDERS as $folderName => $displayName) {
    if (is_dir($rootDir . DIRECTORY_SEPARATOR . $folderName)) {
        $documentFolders[] = [
            'name'    => $displayName,
            'path'    => $folderName,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Church Music</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
            color: #222;
            line-height: 1.5;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            margin-bottom: 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0 0 8px 0;
            font-size: 28px;
            color: #0066cc;
            font-weight: bold;
        }
        .header p {
            margin: 0;
            color: #666;
            font-size: 15px;
        }

        .section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .section h2 {
            margin: 0 0 16px 0;
            font-size: 18px;
            color: #333;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }

        /* --- Folder buttons (per-button color set inline) --- */
        .folder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 15px;
        }
        .folder-btn {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            padding: 20px;
            color: white;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            font-family: inherit;
            transition: 0.2s;
            text-align: left;
            /* Each button defines: --btn-bg, --btn-bg-hover, --btn-shadow */
            background: var(--btn-bg);
        }
        .folder-btn:hover {
            background: var(--btn-bg-hover);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px var(--btn-shadow);
        }
        .folder-btn i {
            font-size: 28px;
            margin-bottom: 10px;
            opacity: 0.9;
        }
        .folder-btn .folder-name {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        /* --- Root file list --- */
        .file-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .file-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 8px;
            border-bottom: 1px solid #f0f0f0;
        }
        .file-list li:last-child { border-bottom: none; }
        .file-list a {
            color: #0066cc;
            text-decoration: none;
            flex-grow: 1;
            font-weight: 500;
        }
        .file-list a:hover { text-decoration: underline; }
        .file-list .file-icon {
            color: #999;
            width: 18px;
            text-align: center;
        }
        .file-list .file-size {
            color: #888;
            font-size: 12px;
            font-family: monospace;
        }
        /* Folder entries shown alongside PDFs in Documents section */
        .file-list li.folder-link a {
            font-weight: 600;
        }
        .file-list li.folder-link .file-icon {
            color: #0066cc;
        }
        .file-list li.folder-link .folder-arrow {
            color: #999;
            font-size: 11px;
        }

        /* --- Player link (outlined / stroke style) --- */
        .player-banner {
            background: white;
            color: #0066cc;
            padding: 18px 24px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            border: 2px solid #0066cc;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            transition: 0.2s;
        }
        .player-banner:hover {
            box-shadow: 0 4px 12px rgba(0,102,204,0.15);
        }
        .player-banner .pb-text {
            flex-grow: 1;
        }
        .player-banner h3 {
            margin: 0 0 4px 0;
            font-size: 16px;
            color: #0066cc;
        }
        .player-banner p {
            margin: 0;
            font-size: 13px;
            color: #555;
        }
        .player-banner a {
            background: #0066cc;
            color: white;
            padding: 10px 18px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            white-space: nowrap;
            transition: 0.2s;
        }
        .player-banner a:hover { background: #0052a3; }

        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { padding: 20px; }
            .header h1 { font-size: 22px; }
            .folder-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .folder-btn { padding: 15px; }
            .folder-btn i { font-size: 22px; }
            .folder-btn .folder-name { font-size: 14px; }
            .player-banner { flex-direction: column; align-items: stretch; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="container">

        <div class="header">
            <h1>New Church Music</h1>
            <p>Online resources for the New Church Liturgy</p>
        </div>

        <div class="player-banner">
            <div class="pb-text">
                <h3><i class="fas fa-play-circle"></i> Interactive Music Player</h3>
                <p>Learn selected hymns with synchronized sheet music, adjustable tempo, and voice mixer.</p>
            </div>
            <a href="https://learn.newchurchmusic.org/" target="_blank">
                Open Player <i class="fas fa-external-link-alt" style="font-size: 11px;"></i>
            </a>
        </div>

        <?php if (!empty($availableFolders)): ?>
        <div class="section">
            <h2>Browse Collections</h2>
            <div class="folder-grid">
                <?php foreach ($availableFolders as $name => $meta):
                    // Convert the hex accent into an rgba shadow so each button glows in its own color on hover.
                    $hex = ltrim($meta['color'], '#');
                    $r = hexdec(substr($hex, 0, 2));
                    $g = hexdec(substr($hex, 2, 2));
                    $b = hexdec(substr($hex, 4, 2));
                    $shadow = "rgba($r,$g,$b,0.3)";
                    $style = "--btn-bg: {$meta['color']}; --btn-bg-hover: {$meta['color_dark']}; --btn-shadow: {$shadow};";
                ?>
                    <a class="folder-btn" href="browse.php?folder=<?= rawurlencode($name) ?>" style="<?= htmlspecialchars($style) ?>">
                        <i class="fas <?= htmlspecialchars($meta['icon']) ?>"></i>
                        <div class="folder-name"><?= htmlspecialchars($name) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($rootFiles) || !empty($documentFolders)): ?>
        <div class="section">
            <h2>Documents</h2>
            <ul class="file-list">
                <?php foreach ($documentFolders as $df): ?>
                    <li class="folder-link">
                        <i class="fas fa-folder file-icon"></i>
                        <a href="browse.php?folder=<?= rawurlencode($df['path']) ?>"><?= htmlspecialchars($df['name']) ?></a>
                        <span class="folder-arrow"><i class="fas fa-chevron-right"></i></span>
                    </li>
                <?php endforeach; ?>
                <?php foreach ($rootFiles as $f):
                    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                    if ($ext === 'pdf')                  { $icon = 'fa-file-pdf'; }
                    elseif ($ext === 'doc' || $ext === 'docx') { $icon = 'fa-file-word'; }
                    elseif ($ext === 'txt')              { $icon = 'fa-file-lines'; }
                    else                                 { $icon = 'fa-file'; }
                ?>
                    <li>
                        <i class="fas <?= $icon ?> file-icon"></i>
                        <a href="<?= safe_link($f['name']) ?>"><?= htmlspecialchars($f['name']) ?></a>
                        <span class="file-size"><?= human_filesize($f['size']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

    </div>
</body>
</html>