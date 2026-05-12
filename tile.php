<?php
/**
 * GET /port/marketing/tile.php
 *
 * Mission Control tile endpoint for the Marketing module.
 * Returns a fixed JSON shape Mission Control's dashboard tile-grid uses
 * to render the Marketing tile.
 *
 * Authenticated (Port session cookie required). Always returns HTTP 200
 * even on data errors — Mission Control's grid loop expects 200 from
 * every module's tile endpoint.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once PORT_ROOT . '/shared/shell.php';
require_once __DIR__ . '/gh-helper.php';

// CLI bypass: Port's dashboard may invoke this via shell_exec; no session
// exists in that path so requireModuleAccess() would redirect (breaking the
// JSON contract). Skip the gate for locally-trusted CLI subprocess.
if (php_sapi_name() !== 'cli') {
    requireModuleAccess('marketing', 'viewer');
}

header('Content-Type: application/json');

$tile = [
    'slug'           => 'marketing',
    'name'           => 'Marketing',
    'icon'           => 'megaphone',
    'status'         => 'online',
    'primary_metric' => 'loading…',
    'detail'         => 'glyc · ibd movement',
    'last_updated'   => date('c'),
    'link'           => '/port/marketing/',
];

$cacheDir = defined('MK_CACHE_DIR') ? MK_CACHE_DIR : sys_get_temp_dir() . '/mk-cache';
$cacheFile = $cacheDir . '/tile-draft-count.json';
$cacheTtl  = 120;

// ── Try cached result first ──────────────────────────────────────────────────
$fromCache = false;
if (file_exists($cacheFile)) {
    $raw    = @file_get_contents($cacheFile);
    $cached = $raw ? json_decode($raw, true) : null;
    if ($cached && isset($cached['_cached_at']) && (time() - $cached['_cached_at']) <= $cacheTtl) {
        $tile['primary_metric'] = $cached['primary_metric'];
        $tile['status']         = 'online';
        echo json_encode($tile);
        exit;
    }
    // Stale cache exists — use as fallback if live fetch fails
    if ($cached) {
        $fromCache = true;
    }
}

// ── Fetch live draft count from GitHub ──────────────────────────────────────
$queueUrl = 'https://api.github.com/repos/cobenrogers/glyc/contents/docs/marketing/workspace/queue';
$data     = mkGhGet($queueUrl, $cacheTtl);

if ($data === null) {
    // Network failure — try stale cache as degraded fallback
    if ($fromCache && $cached) {
        $tile['status']         = 'degraded';
        $tile['primary_metric'] = $cached['primary_metric'];
    } else {
        $tile['status']         = 'offline';
        $tile['primary_metric'] = 'data unavailable';
    }
    echo json_encode($tile);
    exit;
}

// GitHub returns a 404 message as an object (not array) when path missing
if (isset($data['message'])) {
    $primaryMetric = 'no drafts queued';
} else {
    // Filter to files only (not sub-directories) unless it's all sub-dirs
    $files = array_filter($data, fn($item) => isset($item['type']) && $item['type'] === 'file');
    $dirs  = array_filter($data, fn($item) => isset($item['type']) && $item['type'] === 'dir');

    if (!empty($dirs) && empty($files)) {
        // Sub-directory layout — sum files across sub-dirs
        $totalFiles = 0;
        foreach ($dirs as $dir) {
            $subData = mkGhGet($dir['url'], $cacheTtl);
            if (is_array($subData)) {
                $totalFiles += count(array_filter($subData, fn($i) => isset($i['type']) && $i['type'] === 'file'));
            }
        }
        $count         = $totalFiles;
    } else {
        $count = count($files);
    }

    $primaryMetric = $count === 0 ? 'no drafts queued' : $count . ' draft' . ($count === 1 ? '' : 's') . ' queued';
}

// ── Persist to cache ─────────────────────────────────────────────────────────
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0750, true);
}
@file_put_contents(
    $cacheFile,
    json_encode(['_cached_at' => time(), 'primary_metric' => $primaryMetric]),
    LOCK_EX
);

$tile['primary_metric'] = $primaryMetric;
$tile['status']         = 'online';

echo json_encode($tile);
