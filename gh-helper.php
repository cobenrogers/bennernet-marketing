<?php
/**
 * GitHub API helper functions for bennernet-marketing module.
 *
 * mkGhGet()        — cached GET to GitHub API, returns decoded array or null
 * mkGhRawContent() — fetch and base64-decode a file from GitHub Contents API
 */

declare(strict_types=1);

/**
 * Fetch a GitHub API URL with file-based caching.
 *
 * @param string $url Full GitHub API URL
 * @param int    $ttl Cache TTL in seconds (default 120)
 * @return array|null Decoded JSON or null on failure
 */
function mkGhGet(string $url, int $ttl = 120): ?array {
    $cacheDir = defined('MK_CACHE_DIR') ? MK_CACHE_DIR : sys_get_temp_dir() . '/mk-cache';
    $cacheKey = $cacheDir . '/gh-' . md5($url) . '.json';

    if (file_exists($cacheKey)) {
        $raw    = @file_get_contents($cacheKey);
        $cached = $raw ? json_decode($raw, true) : null;
        if ($cached && isset($cached['_cached_at']) && (time() - $cached['_cached_at']) <= $ttl) {
            return $cached['_data'];
        }
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => implode("\r\n", [
            'Authorization: Bearer ' . (defined('MK_GITHUB_TOKEN') ? MK_GITHUB_TOKEN : ''),
            'User-Agent: bennernet-marketing/1.0',
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ]),
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0750, true);
    }
    @file_put_contents($cacheKey, json_encode(['_cached_at' => time(), '_data' => $data]), LOCK_EX);

    return $data;
}

/**
 * Fetch raw (decoded) file content from GitHub Contents API.
 *
 * @param string $owner  GitHub repo owner
 * @param string $repo   GitHub repo name
 * @param string $path   Path within the repo
 * @param int    $ttl    Cache TTL in seconds (default 300)
 * @return string|null   Decoded file content or null if not found / error
 */
function mkGhRawContent(string $owner, string $repo, string $path, int $ttl = 300): ?string {
    $url  = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/contents/' . ltrim($path, '/');
    $data = mkGhGet($url, $ttl);
    if (!$data || empty($data['content'])) {
        return null;
    }
    return base64_decode(str_replace("\n", '', $data['content']));
}
