<?php
/**
 * Marketing module — credential diagnostics.
 *
 * Temporary script to diagnose why X / Bluesky metrics aren't showing.
 * Run: https://bennernet.com/port/marketing/mk-diag.php (must be logged in)
 *
 * DELETE after debugging is complete.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once PORT_ROOT . '/shared/shell.php';

requireModuleAccess('marketing', 'viewer');

header('Content-Type: text/plain; charset=utf-8');

function diagCheck(string $label, bool $ok, string $detail = ''): void {
    echo ($ok ? '[OK]  ' : '[FAIL]') . ' ' . $label;
    if ($detail) echo ' — ' . $detail;
    echo "\n";
}

echo "=== Bennernet Marketing Diagnostics ===\n";
echo "Time: " . date('Y-m-d H:i:s T') . "\n\n";

// ── Config constants ──────────────────────────────────────────────────────────

echo "--- Config Constants ---\n";
diagCheck('MK_X_API_KEY defined',    defined('MK_X_API_KEY')    && MK_X_API_KEY    !== '', defined('MK_X_API_KEY')    ? 'length=' . strlen(MK_X_API_KEY)    : 'not defined');
diagCheck('MK_X_API_SECRET defined', defined('MK_X_API_SECRET') && MK_X_API_SECRET !== '', defined('MK_X_API_SECRET') ? 'length=' . strlen(MK_X_API_SECRET) : 'not defined');
diagCheck('MK_X_USERNAME_GLYC',      defined('MK_X_USERNAME_GLYC') && MK_X_USERNAME_GLYC !== '', defined('MK_X_USERNAME_GLYC') ? MK_X_USERNAME_GLYC : 'not defined');
diagCheck('MK_X_USERNAME_IBD',       defined('MK_X_USERNAME_IBD')  && MK_X_USERNAME_IBD  !== '', defined('MK_X_USERNAME_IBD')  ? MK_X_USERNAME_IBD  : 'not defined');
diagCheck('MK_BLUESKY_HANDLE_GLYC',  defined('MK_BLUESKY_HANDLE_GLYC') && MK_BLUESKY_HANDLE_GLYC !== '', defined('MK_BLUESKY_HANDLE_GLYC') ? MK_BLUESKY_HANDLE_GLYC : 'not defined');
diagCheck('MK_BLUESKY_HANDLE_IBD',   defined('MK_BLUESKY_HANDLE_IBD')  && MK_BLUESKY_HANDLE_IBD  !== '', defined('MK_BLUESKY_HANDLE_IBD')  ? MK_BLUESKY_HANDLE_IBD  : 'not defined');
echo "\n";

// ── Outbound connectivity ─────────────────────────────────────────────────────

echo "--- Outbound Connectivity ---\n";
function diagFetch(string $url, array $opts = []): array {
    $ctx = stream_context_create(['http' => array_merge([
        'method'        => 'GET',
        'timeout'       => 8,
        'ignore_errors' => true,
    ], $opts)]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header)) {
        preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $m);
        $code = (int)($m[1] ?? 0);
    }
    return ['body' => $body, 'code' => $code, 'ok' => $body !== false];
}

$bskyTest = diagFetch('https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=bennernet.bsky.social');
diagCheck('Reach bsky.app',        $bskyTest['ok'],   'HTTP ' . $bskyTest['code']);

$twitterTest = diagFetch('https://api.twitter.com/2/openapi.json');
diagCheck('Reach api.twitter.com', $twitterTest['ok'], 'HTTP ' . $twitterTest['code']);
echo "\n";

// ── X OAuth2 bearer token ─────────────────────────────────────────────────────

echo "--- X/Twitter OAuth2 ---\n";
if (defined('MK_X_API_KEY') && MK_X_API_KEY !== '' && defined('MK_X_API_SECRET') && MK_X_API_SECRET !== '') {
    $credentials = base64_encode(MK_X_API_KEY . ':' . MK_X_API_SECRET);
    $tokenResp = diagFetch('https://api.twitter.com/oauth2/token', [
        'method'  => 'POST',
        'header'  => "Authorization: Basic {$credentials}\r\nContent-Type: application/x-www-form-urlencoded",
        'content' => 'grant_type=client_credentials',
    ]);
    if (!$tokenResp['ok']) {
        diagCheck('X token fetch', false, 'HTTP ' . $tokenResp['code'] . ' — cannot reach Twitter API');
    } else {
        $tokenData = json_decode($tokenResp['body'], true);
        $bearerToken = $tokenData['access_token'] ?? null;
        diagCheck('X bearer token', $bearerToken !== null,
            $bearerToken ? 'token=' . substr($bearerToken, 0, 20) . '...'
                         : 'error=' . ($tokenData['errors'][0]['message'] ?? $tokenData['error'] ?? 'unknown'));

        if ($bearerToken) {
            $glycResp = diagFetch(
                'https://api.twitter.com/2/users/by/username/' . rawurlencode(defined('MK_X_USERNAME_GLYC') ? MK_X_USERNAME_GLYC : 'getglyc') . '?user.fields=public_metrics',
                ['header' => "Authorization: Bearer {$bearerToken}\r\nUser-Agent: bennernet-marketing/1.0"]
            );
            $glycData = json_decode($glycResp['body'] ?? '', true);
            $followers = $glycData['data']['public_metrics']['followers_count'] ?? null;
            diagCheck('X getglyc followers', $followers !== null, $followers !== null ? $followers . ' followers' : 'error=' . ($glycResp['body'] ?? 'no response'));
        }
    }
} else {
    echo "[SKIP] X credentials not in config.php\n";
}
echo "\n";

// ── Bluesky followers ─────────────────────────────────────────────────────────

echo "--- Bluesky ---\n";
if ($bskyTest['ok']) {
    $bskyData = json_decode($bskyTest['body'], true);
    $followers = $bskyData['followersCount'] ?? null;
    diagCheck('bennernet.bsky.social followers', $followers !== null, $followers !== null ? $followers . ' followers' : 'unexpected response');

    if (defined('MK_BLUESKY_HANDLE_IBD') && MK_BLUESKY_HANDLE_IBD !== '') {
        $ibdResp = diagFetch('https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=' . urlencode(MK_BLUESKY_HANDLE_IBD));
        $ibdData = json_decode($ibdResp['body'] ?? '', true);
        $ibdFollowers = $ibdData['followersCount'] ?? null;
        diagCheck(MK_BLUESKY_HANDLE_IBD . ' followers', $ibdFollowers !== null, $ibdFollowers !== null ? $ibdFollowers . ' followers' : 'unexpected response');
    }
} else {
    diagCheck('bsky.app reachable', false, 'blocked or timeout');
}
echo "\n";

// ── Tile cache ────────────────────────────────────────────────────────────────

echo "--- Tile Cache ---\n";
$cacheFile = (defined('MK_CACHE_DIR') ? MK_CACHE_DIR : sys_get_temp_dir() . '/mk-cache') . '/marketing-tile.json';
if (file_exists($cacheFile)) {
    $raw = @file_get_contents($cacheFile);
    $cache = $raw ? json_decode($raw, true) : null;
    $age   = $cache ? (time() - ($cache['_cached_at'] ?? 0)) : -1;
    diagCheck('Cache file exists', true, 'age=' . $age . 's');
    if ($cache) {
        // Show X and Bluesky values from cache
        foreach ($cache['children'] ?? [] as $child) {
            $childName = $child['name'] ?? '?';
            foreach ($child['metrics'] ?? [] as $m) {
                $lbl = $m['label'] ?? '';
                if (stripos($lbl, 'x followers') !== false || stripos($lbl, 'bluesky followers') !== false) {
                    echo "  cache[{$childName}][{$lbl}] = " . json_encode($m['value']) . "\n";
                }
            }
        }
    }
} else {
    diagCheck('Cache file exists', false, $cacheFile);
}
echo "\n";

echo "=== End Diagnostics ===\n";
