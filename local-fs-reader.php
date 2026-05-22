<?php
/**
 * Local filesystem reader helpers for the marketing module.
 *
 * mkLocalDraftCount()     — count .md drafts in workspace/queue/
 * mkLocalRecentPublished() — last 5 .md files from workspace/published/
 *
 * Both functions fall back to the GitHub API (via mkGhGet) when the local
 * workspace path is not configured or does not exist.
 */

declare(strict_types=1);

/**
 * Count draft .md files in the local workspace queue/ directory.
 *
 * Scans one level of platform subdirectories inside queue/.
 * Falls back to the GitHub API when the local path is unavailable.
 *
 * @param string|null $workspacePath  Override for the workspace root (used in tests).
 * @return array{count:int, error:bool, missing:bool}
 */
function mkLocalDraftCount(?string $workspacePath = null): array {
    $root = $workspacePath ?? (defined('MK_WORKSPACE_PATH') ? MK_WORKSPACE_PATH : null);

    if ($root !== null && is_dir($root . '/queue')) {
        $queueDir = $root . '/queue';
        $count    = 0;

        // Count .md files directly inside queue/
        $direct = glob($queueDir . '/*.md');
        if ($direct !== false) {
            $count += count($direct);
        }

        // Count .md files one level of platform subdirs deep
        $subdirs = glob($queueDir . '/*', GLOB_ONLYDIR);
        if ($subdirs !== false) {
            foreach ($subdirs as $subdir) {
                $files = glob($subdir . '/*.md');
                if ($files !== false) {
                    $count += count($files);
                }
            }
        }

        return ['count' => $count, 'error' => false, 'missing' => false];
    }

    // ── GitHub API fallback ───────────────────────────────────────────────────
    $queueUrl  = 'https://api.github.com/repos/cobenrogers/glyc/contents/docs/marketing/workspace/queue';
    $queueData = mkGhGet($queueUrl, 120);

    if ($queueData === null) {
        return ['count' => 0, 'error' => true, 'missing' => false];
    }
    if (isset($queueData['message'])) {
        return ['count' => 0, 'error' => false, 'missing' => true];
    }

    $files = array_filter($queueData, fn($i) => isset($i['type']) && $i['type'] === 'file');
    $dirs  = array_filter($queueData, fn($i) => isset($i['type']) && $i['type'] === 'dir');
    $count = 0;
    if (!empty($dirs) && empty($files)) {
        foreach ($dirs as $dir) {
            $subData = mkGhGet($dir['url'], 120);
            if (is_array($subData)) {
                $count += count(array_filter($subData, fn($i) => isset($i['type']) && $i['type'] === 'file'));
            }
        }
    } else {
        $count = count($files);
    }

    return ['count' => $count, 'error' => false, 'missing' => false];
}

/**
 * Return the 5 most-recently published .md files from the local workspace.
 *
 * Scans one level of platform subdirectories inside published/.  Files are
 * sorted by filename descending (YYYY-MM-DD prefix keeps them chronological).
 * Falls back to the GitHub API when the local path is unavailable.
 *
 * Each entry has the same shape as a GitHub Contents API item:
 *   ['name' => 'filename.md', 'path' => 'docs/marketing/workspace/published/...', 'type' => 'file']
 *
 * @param string|null $workspacePath  Override for the workspace root (used in tests).
 * @return array{files:list<array{name:string,path:string,type:string}>, error:bool, missing:bool}
 */
function mkLocalRecentPublished(?string $workspacePath = null): array {
    $root = $workspacePath ?? (defined('MK_WORKSPACE_PATH') ? MK_WORKSPACE_PATH : null);

    if ($root !== null && is_dir($root . '/published')) {
        $publishedDir = $root . '/published';
        $allFiles     = [];

        // Files directly inside published/
        $direct = glob($publishedDir . '/*.md');
        if ($direct !== false) {
            foreach ($direct as $filePath) {
                $name       = basename($filePath);
                $allFiles[] = [
                    'name' => $name,
                    'path' => 'docs/marketing/workspace/published/' . $name,
                    'type' => 'file',
                ];
            }
        }

        // Files one level of platform subdirs deep
        $subdirs = glob($publishedDir . '/*', GLOB_ONLYDIR);
        if ($subdirs !== false) {
            foreach ($subdirs as $subdir) {
                $platform = basename($subdir);
                $files    = glob($subdir . '/*.md');
                if ($files !== false) {
                    foreach ($files as $filePath) {
                        $name       = basename($filePath);
                        $allFiles[] = [
                            'name' => $name,
                            'path' => 'docs/marketing/workspace/published/' . $platform . '/' . $name,
                            'type' => 'file',
                        ];
                    }
                }
            }
        }

        usort($allFiles, fn($a, $b) => strcmp($b['name'], $a['name']));

        return ['files' => array_slice($allFiles, 0, 5), 'error' => false, 'missing' => false];
    }

    // ── GitHub API fallback ───────────────────────────────────────────────────
    $publishedUrl  = 'https://api.github.com/repos/cobenrogers/glyc/contents/docs/marketing/workspace/published';
    $publishedData = mkGhGet($publishedUrl, 300);

    if ($publishedData === null) {
        return ['files' => [], 'error' => true, 'missing' => false];
    }
    if (isset($publishedData['message'])) {
        return ['files' => [], 'error' => false, 'missing' => true];
    }

    $allFiles = [];
    foreach ($publishedData as $item) {
        if (!isset($item['type'])) {
            continue;
        }
        if ($item['type'] === 'file') {
            $allFiles[] = $item;
        } elseif ($item['type'] === 'dir') {
            $subData = mkGhGet($item['url'], 300);
            if (is_array($subData)) {
                foreach ($subData as $sub) {
                    if (isset($sub['type']) && $sub['type'] === 'file') {
                        $allFiles[] = $sub;
                    }
                }
            }
        }
    }
    usort($allFiles, fn($a, $b) => strcmp($b['name'], $a['name']));

    return ['files' => array_slice($allFiles, 0, 5), 'error' => false, 'missing' => false];
}
