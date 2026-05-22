<?php
/**
 * GET /port/marketing/tile.php
 *
 * Mission Control tile endpoint for the Marketing module — v1 compound shape.
 * Returns a compound tile with two children: getglyc.com + ibdmovement.com.
 *
 * Data sources:
 *   - Postiz        — posts published count per integration (LIVE, localhost:4007)
 *   - BlueSky       — follower count via public API (LIVE, per-site accounts)
 *   - GSC           — organic clicks per site (LIVE, via ADC + gsc.py)
 *   - GA4           — users per site (STUB — not wired)
 *   - Mastodon      — followers per account (LIVE, public API per instance)
 *   - X/Twitter     — follower count via API v2 (LIVE, per-site accounts)
 *
 * Cache: MK_CACHE_DIR/marketing-tile.json, 15-minute TTL.
 *
 * Authenticated (Port session cookie required). Always returns HTTP 200
 * even on data errors — Mission Control's grid loop expects 200 from
 * every module's tile endpoint.
 *
 * Issues: cobenrogers/mission-control-wiki #57, #90, #91
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

// ── Constants ─────────────────────────────────────────────────────────────────

$cacheDir  = defined('MK_CACHE_DIR') ? MK_CACHE_DIR : sys_get_temp_dir() . '/mk-cache';
$cacheFile = $cacheDir . '/marketing-tile.json';
$cacheTtl  = 900; // 15 minutes

// ── Cache check ───────────────────────────────────────────────────────────────

if (file_exists($cacheFile)) {
    $raw    = @file_get_contents($cacheFile);
    $cached = $raw ? json_decode($raw, true) : null;
    if ($cached && isset($cached['_cached_at']) && (time() - $cached['_cached_at']) <= $cacheTtl) {
        unset($cached['_cached_at']);
        echo json_encode($cached);
        exit;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Stub metric — renderer shows "—" for null values. */
function mkMetricStub(string $label): array {
    return [
        'label'            => $label,
        'value'            => null,
        'delta'            => null,
        'delta_format'     => 'raw',
        'delta_direction'  => 'neutral',
    ];
}

/** Build a live metric. */
function mkMetric(string $label, mixed $value, mixed $delta, string $deltaFormat = 'raw', string $deltaDirection = 'neutral'): array {
    return [
        'label'            => $label,
        'value'            => $value,
        'delta'            => $delta,
        'delta_format'     => $deltaFormat,
        'delta_direction'  => $deltaDirection,
    ];
}

/**
 * Fetch and decode the SOPS secrets file. Returns array or null on failure.
 * Result is cached in a static to avoid multiple shell_exec calls per request.
 */
function mkSecrets(): ?array {
    static $secrets = null;
    if ($secrets !== null) {
        return $secrets;
    }
    $raw = shell_exec('sops --decrypt /home/ben/.openclaw/secrets/secrets.enc.json 2>/dev/null');
    if (!$raw) {
        return null;
    }
    $secrets = json_decode($raw, true);
    return is_array($secrets) ? $secrets : null;
}

/**
 * Simple HTTP GET via stream context. Returns response body string or null.
 *
 * @param string   $url     Full URL to fetch
 * @param string[] $headers Additional HTTP headers (e.g. "Authorization: foo")
 * @param int      $timeout Timeout in seconds
 */
function mkHttpGet(string $url, array $headers = [], int $timeout = 10): ?string {
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => implode("\r\n", $headers),
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return ($body !== false) ? $body : null;
}

// ── Google SA token — shared JWT helper for GA4 + GSC ────────────────────────

/**
 * Exchange a service account JSON key for a short-lived OAuth2 access token.
 * Pure PHP + openssl — no google/apiclient needed (works on shared hosting).
 * Cached statically per (credPath, scope) so GA4 and GSC share the same process.
 */
function mkGoogleSaToken(string $credPath, string $scope): ?string {
    static $cache = [];
    $cacheKey = $credPath . ':' . $scope;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $json = @file_get_contents($credPath);
    $key  = $json ? json_decode($json, true) : null;
    if (!is_array($key) || empty($key['private_key']) || empty($key['client_email'])) {
        return $cache[$cacheKey] = null;
    }

    $b64u    = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $now     = time();
    $header  = $b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = $b64u(json_encode([
        'iss'   => $key['client_email'],
        'scope' => $scope,
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $pkey = openssl_pkey_get_private($key['private_key']);
    if (!$pkey) {
        return $cache[$cacheKey] = null;
    }
    openssl_sign("{$header}.{$payload}", $sig, $pkey, OPENSSL_ALGO_SHA256);
    $jwt = "{$header}.{$payload}." . $b64u($sig);

    $ctx  = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
    $data = $resp ? json_decode($resp, true) : null;
    return $cache[$cacheKey] = ($data['access_token'] ?? null);
}

/**
 * Fetch 7-day total users + 14-day sparkline from GA4 for one property.
 * Returns ['users' => int, 'sparkline' => int[14]] or null when credentials
 * are not configured or the API call fails.
 */
function mkGa4Users(string $propertyId): ?array {
    $credPath = defined('MK_GA4_CREDENTIALS_PATH') ? MK_GA4_CREDENTIALS_PATH : null;
    if (!$credPath || !file_exists($credPath) || $propertyId === '') {
        return null;
    }

    $token = mkGoogleSaToken($credPath, 'https://www.googleapis.com/auth/analytics.readonly');
    if (!$token) {
        return null;
    }

    $url  = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport";
    $body = json_encode([
        'dateRanges' => [['startDate' => '13daysAgo', 'endDate' => 'today']],
        'dimensions' => [['name' => 'date']],
        'metrics'    => [['name' => 'totalUsers']],
        'orderBys'   => [['dimension' => ['dimensionName' => 'date']]],
    ]);
    $ctx  = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n",
        'content'       => $body,
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $data = $resp ? json_decode($resp, true) : null;
    $rows = $data['rows'] ?? null;
    if (!is_array($rows)) {
        return null;
    }

    $dateMap = [];
    foreach ($rows as $row) {
        $date  = $row['dimensionValues'][0]['value'] ?? null; // YYYYMMDD
        $count = (int)($row['metricValues'][0]['value'] ?? 0);
        if ($date) {
            $dateMap[$date] = $count;
        }
    }

    // Build 14-element sparkline (oldest => newest) and sum last 7 days
    $sparkline  = [];
    $totalUsers = 0;
    $sevenStart = date('Ymd', strtotime('-6 days')); // inclusive: -6d .. today = 7 days
    for ($i = 13; $i >= 0; $i--) {
        $date  = date('Ymd', strtotime("-{$i} days"));
        $count = $dateMap[$date] ?? 0;
        $sparkline[] = $count;
        if ($date >= $sevenStart) {
            $totalUsers += $count;
        }
    }

    return ['users' => $totalUsers, 'sparkline' => $sparkline];
}

/**
 * Fetch UTM campaign performance from GA4 for getglyc.com.
 * Returns array of rows: [['source'=>string,'medium'=>string,'sessions'=>int,'signups'=>int], ...]
 * or null on credential/API failure.
 */
function mkGa4CampaignData(): ?array {
    $credPath   = defined('MK_GA4_CREDENTIALS_PATH') ? MK_GA4_CREDENTIALS_PATH : null;
    $propertyId = defined('MK_GA4_PROPERTY_GLYC')    ? MK_GA4_PROPERTY_GLYC    : '';
    if (!$credPath || !file_exists($credPath) || $propertyId === '') {
        return null;
    }

    $token = mkGoogleSaToken($credPath, 'https://www.googleapis.com/auth/analytics.readonly');
    if (!$token) {
        return null;
    }

    $url  = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport";
    $body = json_encode([
        'dateRanges'       => [['startDate' => '29daysAgo', 'endDate' => 'today']],
        'dimensions'       => [['name' => 'sessionSource'], ['name' => 'sessionMedium']],
        'metrics'          => [['name' => 'sessions'], ['name' => 'keyEvents']],
        'dimensionFilter'  => null,
        'metricFilter'     => null,
        'orderBys'         => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
    ]);
    $ctx  = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n",
        'content'       => $body,
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $data = $resp ? json_decode($resp, true) : null;
    $rows = $data['rows'] ?? null;
    if (!is_array($rows)) {
        return null;
    }

    $result = [];
    foreach ($rows as $row) {
        $source   = $row['dimensionValues'][0]['value'] ?? '(unknown)';
        $medium   = $row['dimensionValues'][1]['value'] ?? '(unknown)';
        $sessions = (int)($row['metricValues'][0]['value'] ?? 0);
        $signups  = (int)($row['metricValues'][1]['value'] ?? 0);
        $result[] = compact('source', 'medium', 'sessions', 'signups');
    }
    return $result;
}

// ── Postiz — posts published in the last 7 days ───────────────────────────────

/**
 * Fetch Postiz post counts for the last 7 days, grouped by integrationId.
 * Returns ['integrationId' => ['PUBLISHED' => n, 'QUEUE' => n, ...], ...]
 * or null on failure.
 *
 * Auth note: Postiz public API uses "Authorization: <key>" (no "Bearer" prefix).
 */
function mkPostizPostCounts(): ?array {
    // 7-day window: past 7 days through 7 days ahead (capture queued future posts too)
    $start = date('c', strtotime('-7 days'));
    $end   = date('c', strtotime('+7 days'));
    $qs    = 'startDate=' . urlencode($start) . '&endDate=' . urlencode($end) . '&take=200';

    $bridgeUrl   = defined('MK_BRIDGE_URL')   ? MK_BRIDGE_URL   : null;
    $bridgeToken = defined('MK_BRIDGE_TOKEN') ? MK_BRIDGE_TOKEN : null;
    if ($bridgeUrl && $bridgeToken) {
        // Bridge injects the Postiz API key internally — caller only needs bridge token
        $url     = rtrim($bridgeUrl, '/') . '/postiz/api/public/v1/posts?' . $qs;
        $headers = ["Authorization: Bearer {$bridgeToken}"];
    } else {
        // Local dev: call Postiz directly using sops-decrypted key
        $secrets = mkSecrets();
        if (!$secrets || empty($secrets['postiz_api_key'])) {
            return null;
        }
        $url     = 'http://localhost:4007/api/public/v1/posts?' . $qs;
        $headers = ["Authorization: {$secrets['postiz_api_key']}"];
    }

    $body = mkHttpGet($url, $headers, 8);
    if (!$body) {
        return null;
    }
    $data  = json_decode($body, true);
    $posts = is_array($data) ? ($data['posts'] ?? (isset($data[0]) ? $data : [])) : null;
    if (!is_array($posts)) {
        return null;
    }

    $counts = [];
    foreach ($posts as $post) {
        $iid   = $post['integrationId'] ?? ($post['integration']['id'] ?? null);
        $state = $post['state'] ?? 'UNKNOWN';
        if ($iid === null) {
            continue;
        }
        if (!isset($counts[$iid])) {
            $counts[$iid] = [];
        }
        $counts[$iid][$state] = ($counts[$iid][$state] ?? 0) + 1;
    }
    return $counts;
}

// ── BlueSky — follower count via public API ───────────────────────────────────

/**
 * Fetch BlueSky follower count for a given handle.
 * Returns ['followers' => int, 'handle' => string] or null on failure.
 *
 * @param string $handle BlueSky handle, e.g. "bennernet.bsky.social"
 */
function mkBlueskyFollowers(string $handle): ?array {
    if (empty($handle)) {
        return null;
    }

    $url  = 'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=' . urlencode($handle);
    $body = mkHttpGet($url, ['User-Agent: bennernet-marketing/1.0'], 8);
    if (!$body) {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['followersCount'])) {
        return null;
    }
    return [
        'followers' => (int)$data['followersCount'],
        'handle'    => $handle,
    ];
}

// ── Mastodon — follower count via public lookup API ──────────────────────────

/**
 * Fetch Mastodon follower count for one account via the instance's public API.
 * No auth required — /api/v1/accounts/lookup is unauthenticated and returns
 * the followers_count field on the account object.
 *
 * @param string $instance Hostname only, e.g. "mastodon.social"
 * @param string $handle   Local handle without the leading "@", e.g. "glyc"
 * @return array{'followers': int, 'handle': string, 'instance': string}|null
 */
function mkMastodonFollowers(string $instance, string $handle): ?array {
    if ($instance === '' || $handle === '') {
        return null;
    }
    $url  = 'https://' . $instance . '/api/v1/accounts/lookup?acct=' . urlencode($handle);
    $body = mkHttpGet($url, ['User-Agent: bennernet-marketing/1.0'], 8);
    if (!$body) {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['followers_count'])) {
        return null;
    }
    return [
        'followers' => (int)$data['followers_count'],
        'handle'    => $handle,
        'instance'  => $instance,
    ];
}

// ── X/Twitter — follower count via API v2 ────────────────────────────────────

/**
 * Exchange X API key + secret for an OAuth2 app-only Bearer token.
 * Uses Basic Auth: Authorization: Basic base64(key:secret).
 * Returns the access_token string or null on failure.
 */
function mkXBearerToken(): ?string {
    // Prefer config.php constants (production on Bluehost); fall back to sops (local dev).
    if (defined('MK_X_API_KEY') && MK_X_API_KEY !== '' &&
        defined('MK_X_API_SECRET') && MK_X_API_SECRET !== '') {
        $apiKey    = MK_X_API_KEY;
        $apiSecret = MK_X_API_SECRET;
    } else {
        $secrets = mkSecrets();
        if (!$secrets || empty($secrets['x_api_key']) || empty($secrets['x_api_secret'])) {
            return null;
        }
        $apiKey    = $secrets['x_api_key'];
        $apiSecret = $secrets['x_api_secret'];
    }

    $credentials = base64_encode($apiKey . ':' . $apiSecret);
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", [
            'Authorization: Basic ' . $credentials,
            'Content-Type: application/x-www-form-urlencoded',
        ]),
        'content'       => 'grant_type=client_credentials',
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents('https://api.twitter.com/oauth2/token', false, $ctx);
    if (!$resp) {
        return null;
    }
    $data = json_decode($resp, true);
    return ($data['access_token'] ?? null) ? (string)$data['access_token'] : null;
}

/**
 * Fetch X/Twitter follower count for a given username via API v2.
 * Returns ['followers' => int, 'username' => string] or null on failure.
 *
 * @param string $username     X handle without the leading "@", e.g. "getglyc"
 * @param string $bearerToken  OAuth2 app-only Bearer token from mkXBearerToken()
 */
function mkXFollowers(string $username, string $bearerToken): ?array {
    if ($username === '' || $bearerToken === '') {
        return null;
    }

    $url  = 'https://api.twitter.com/2/users/by/username/' . rawurlencode($username)
          . '?user.fields=public_metrics';
    $body = mkHttpGet($url, [
        'Authorization: Bearer ' . $bearerToken,
        'User-Agent: bennernet-marketing/1.0',
    ], 10);
    if (!$body) {
        return null;
    }
    $data      = json_decode($body, true);
    $followers = $data['data']['public_metrics']['followers_count'] ?? null;
    if ($followers === null) {
        return null;
    }
    return [
        'followers' => (int)$followers,
        'username'  => $username,
    ];
}

// ── GSC — organic clicks per site via Search Console REST API ─────────────────

/**
 * Pure parsing helper for a GSC searchAnalytics/query response array.
 * Separated from mkGscTotals() so it can be unit-tested without HTTP.
 *
 * @return array{'clicks': int, 'impressions': int, 'ctr': float|null, 'position': float|null}|null
 */
function mkGscParseRow(?array $data): ?array {
    if (!is_array($data) || isset($data['error'])) {
        return null;
    }
    $row = $data['rows'][0] ?? null;
    return [
        'clicks'      => (int)($row['clicks']      ?? 0),
        'impressions' => (int)($row['impressions']  ?? 0),
        'ctr'         => isset($row['ctr'])      ? (float)$row['ctr']      : null,
        'position'    => isset($row['position']) ? (float)$row['position'] : null,
    ];
}

/**
 * Fetch total clicks, impressions, CTR, and avg position from the Search
 * Console Data API v3.  Uses the same SA key as GA4 (MK_GA4_CREDENTIALS_PATH);
 * site URL must be in sc-domain: or https:// format matching the verified property.
 *
 * @return array{'clicks': int, 'impressions': int, 'ctr': float|null, 'position': float|null}|null
 */
function mkGscTotals(string $siteUrl, int $days = 7): ?array {
    $credPath = defined('MK_GA4_CREDENTIALS_PATH') ? MK_GA4_CREDENTIALS_PATH : null;
    if (!$credPath || !file_exists($credPath)) {
        return null;
    }

    $token = mkGoogleSaToken($credPath, 'https://www.googleapis.com/auth/webmasters.readonly');
    if (!$token) {
        return null;
    }

    $endDate   = date('Y-m-d', strtotime('-1 day'));
    $startDate = date('Y-m-d', strtotime("-{$days} days"));
    $url       = 'https://searchconsole.googleapis.com/webmasters/v3/sites/'
               . urlencode($siteUrl) . '/searchAnalytics/query';
    $body      = json_encode([
        'startDate' => $startDate,
        'endDate'   => $endDate,
        'rowLimit'  => 1,
        'metrics'   => ['clicks', 'impressions', 'ctr', 'position'],
    ]);

    $ctx  = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n",
        'content'       => $body,
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $data = $resp ? json_decode($resp, true) : null;
    return mkGscParseRow($data);
}

// ── Fetch all data ────────────────────────────────────────────────────────────

$fetchStart = time();

// Postiz integration IDs (from running Postiz instance)
// - cmouj99190001pi8h1f0upfga  = Glyc (Bluesky / bennernet.bsky.social)
// - cmpbj9osm0008poec8q68tlgo  = IBD Movement (Bluesky / ibdmovement.bsky.social)
// - cmouqqkw70001o08gts5rpnyb  = Ben Rogers (Mastodon / glyc profile)
// - cmouqudgd0003o08gq5w1q3jj  = The IBD Movement (Mastodon / ibdmovement profile)
// - cmpbr9le70003mo8mzzg84o2d  = Glyc (X / getglyc)
// - cmpbr6c0n0001mo8mj5m2d3hx  = IBD Movement (X / IBDMovement)
const POSTIZ_ID_GLYC_MASTODON = 'cmouqqkw70001o08gts5rpnyb';
const POSTIZ_ID_IBD_MASTODON  = 'cmouqudgd0003o08gq5w1q3jj';
const POSTIZ_ID_GLYC_BLUESKY  = 'cmouj99190001pi8h1f0upfga';
const POSTIZ_ID_IBD_BLUESKY   = 'cmpbj9osm0008poec8q68tlgo';
const POSTIZ_ID_GLYC_X        = 'cmpbr9le70003mo8mzzg84o2d';
const POSTIZ_ID_IBD_X         = 'cmpbr6c0n0001mo8mj5m2d3hx';

$postizCounts = mkPostizPostCounts();

// Per-site Bluesky follower counts (separate accounts per site)
$secrets          = mkSecrets();
$glycBskyHandle   = $secrets['bluesky_handle']               ?? '';
$ibdBskyHandle    = $secrets['bluesky_ibdmovement_handle']   ?? '';
$bskyGlyc         = mkBlueskyFollowers($glycBskyHandle);
$bskyIbd          = mkBlueskyFollowers($ibdBskyHandle);
$gscGlyc      = mkGscTotals('sc-domain:getglyc.com',    7);
$gscIbd       = mkGscTotals('sc-domain:ibdmovement.com', 7);
$ga4Glyc      = mkGa4Users(defined('MK_GA4_PROPERTY_GLYC') ? MK_GA4_PROPERTY_GLYC : '');
$ga4Ibd       = mkGa4Users(defined('MK_GA4_PROPERTY_IBD')  ? MK_GA4_PROPERTY_IBD  : '');
$campaignData = mkGa4CampaignData();
$mastoGlyc    = mkMastodonFollowers(
    defined('MK_MASTODON_INSTANCE_GLYC') ? MK_MASTODON_INSTANCE_GLYC : '',
    defined('MK_MASTODON_HANDLE_GLYC')   ? MK_MASTODON_HANDLE_GLYC   : ''
);
$mastoIbd     = mkMastodonFollowers(
    defined('MK_MASTODON_INSTANCE_IBD') ? MK_MASTODON_INSTANCE_IBD : '',
    defined('MK_MASTODON_HANDLE_IBD')   ? MK_MASTODON_HANDLE_IBD   : ''
);

// X/Twitter follower counts (one Bearer token shared across both accounts)
// Username resolution: config.php constants > sops > hardcoded fallbacks.
$xUsername_glyc = defined('MK_X_USERNAME_GLYC') && MK_X_USERNAME_GLYC !== ''
    ? MK_X_USERNAME_GLYC
    : ($secrets['x_username_glyc'] ?? 'getglyc');
$xUsername_ibd  = defined('MK_X_USERNAME_IBD') && MK_X_USERNAME_IBD !== ''
    ? MK_X_USERNAME_IBD
    : ($secrets['x_username_ibd'] ?? 'IBDMovement');
$xBearerToken   = mkXBearerToken();
$xGlyc          = $xBearerToken !== null ? mkXFollowers($xUsername_glyc, $xBearerToken) : null;
$xIbd           = $xBearerToken !== null ? mkXFollowers($xUsername_ibd,  $xBearerToken) : null;

// ── Build per-child metrics ───────────────────────────────────────────────────

/**
 * Count published posts for a given Postiz integration in the fetched window.
 * Returns int (may be 0) or null if Postiz fetch failed entirely.
 */
function mkPostizPublished(?array $counts, string $integrationId): ?int {
    if ($counts === null) {
        return null;
    }
    $stateMap = $counts[$integrationId] ?? [];
    // Count PUBLISHED + QUEUE (queued = scheduled, will publish)
    return (int)($stateMap['PUBLISHED'] ?? 0);
}

function mkPostizQueued(?array $counts, string $integrationId): int {
    if ($counts === null) {
        return 0;
    }
    $stateMap = $counts[$integrationId] ?? [];
    return (int)($stateMap['QUEUE'] ?? 0) + (int)($stateMap['DRAFT'] ?? 0);
}

// ── Glyc child ────────────────────────────────────────────────────────────────

$glycPublished  = mkPostizPublished($postizCounts, POSTIZ_ID_GLYC_MASTODON);
$glycQueued     = mkPostizQueued($postizCounts, POSTIZ_ID_GLYC_MASTODON);
$glycGscClicks       = $gscGlyc !== null ? $gscGlyc['clicks']      : null;
$glycGscImpressions  = $gscGlyc !== null ? $gscGlyc['impressions'] : null;
$glycGscCtr          = $gscGlyc !== null ? $gscGlyc['ctr']         : null;
$glycGscPosition     = $gscGlyc !== null ? $gscGlyc['position']    : null;
$glycXPublished = mkPostizPublished($postizCounts, POSTIZ_ID_GLYC_X);
$glycXQueued    = mkPostizQueued($postizCounts, POSTIZ_ID_GLYC_X);

$glycPostsDelta = null;
if ($glycQueued > 0) {
    $glycPostsDelta = '+' . $glycQueued . ' scheduled';
}

$glycXPostsDelta = null;
if ($glycXQueued > 0) {
    $glycXPostsDelta = '+' . $glycXQueued . ' scheduled';
}

$glycMetrics = [
    $ga4Glyc !== null
        ? mkMetric('Users', $ga4Glyc['users'], null, 'raw', 'neutral')
        : mkMetricStub('Users'),
    $glycGscClicks !== null
        ? mkMetric('GSC clicks', $glycGscClicks, null, 'raw', 'positive')
        : mkMetricStub('GSC clicks'),
    $glycGscImpressions !== null
        ? mkMetric('GSC impressions', $glycGscImpressions, null, 'raw', 'positive')
        : mkMetricStub('GSC impressions'),
    $glycGscCtr !== null
        ? mkMetric('GSC CTR', $glycGscCtr, null, 'raw', 'positive')
        : mkMetricStub('GSC CTR'),
    $glycGscPosition !== null
        ? mkMetric('GSC avg position', $glycGscPosition, null, 'raw', 'negative')
        : mkMetricStub('GSC avg position'),
    $mastoGlyc !== null
        ? mkMetric('Mast. followers', $mastoGlyc['followers'], null, 'raw', 'neutral')
        : mkMetricStub('Mast. followers'),
    $bskyGlyc !== null
        ? mkMetric('Bluesky followers', $bskyGlyc['followers'], null, 'raw', 'neutral')
        : mkMetricStub('Bluesky followers'),
    $xGlyc !== null
        ? mkMetric('X followers', $xGlyc['followers'], null, 'raw', 'neutral')
        : mkMetricStub('X followers'),
    $glycPublished !== null
        ? mkMetric('Posts published', $glycPublished, $glycPostsDelta, 'raw', 'neutral')
        : mkMetricStub('Posts published'),
    $glycXPublished !== null
        ? mkMetric('X posts published', $glycXPublished, $glycXPostsDelta, 'raw', 'neutral')
        : mkMetricStub('X posts published'),
];

// Glyc status: online if we have at least one live source
$glycSourcesOk = ($glycGscClicks !== null) || ($glycPublished !== null) || ($mastoGlyc !== null) || ($bskyGlyc !== null) || ($xGlyc !== null);
$glycStatus    = $glycSourcesOk ? 'online' : 'idle';

// ── IBD child ─────────────────────────────────────────────────────────────────

$ibdPublished  = mkPostizPublished($postizCounts, POSTIZ_ID_IBD_MASTODON);
$ibdQueued     = mkPostizQueued($postizCounts, POSTIZ_ID_IBD_MASTODON);
$ibdGscClicks         = $gscIbd !== null ? $gscIbd['clicks']      : null;
$ibdGscImpressions    = $gscIbd !== null ? $gscIbd['impressions'] : null;
$ibdGscCtr            = $gscIbd !== null ? $gscIbd['ctr']         : null;
$ibdGscPosition       = $gscIbd !== null ? $gscIbd['position']    : null;
$ibdXPublished = mkPostizPublished($postizCounts, POSTIZ_ID_IBD_X);
$ibdXQueued    = mkPostizQueued($postizCounts, POSTIZ_ID_IBD_X);

$ibdPostsDelta = null;
if ($ibdQueued > 0) {
    $ibdPostsDelta = '+' . $ibdQueued . ' scheduled';
}

$ibdXPostsDelta = null;
if ($ibdXQueued > 0) {
    $ibdXPostsDelta = '+' . $ibdXQueued . ' scheduled';
}

$ibdMetrics = [
    $ga4Ibd !== null
        ? mkMetric('Users', $ga4Ibd['users'], null, 'raw', 'neutral')
        : mkMetricStub('Users'),
    $ibdGscClicks !== null
        ? mkMetric('GSC clicks', $ibdGscClicks, null, 'raw', 'positive')
        : mkMetricStub('GSC clicks'),
    $ibdGscImpressions !== null
        ? mkMetric('GSC impressions', $ibdGscImpressions, null, 'raw', 'positive')
        : mkMetricStub('GSC impressions'),
    $ibdGscCtr !== null
        ? mkMetric('GSC CTR', $ibdGscCtr, null, 'raw', 'positive')
        : mkMetricStub('GSC CTR'),
    $ibdGscPosition !== null
        ? mkMetric('GSC avg position', $ibdGscPosition, null, 'raw', 'negative')
        : mkMetricStub('GSC avg position'),
    $mastoIbd !== null
        ? mkMetric('Mast. followers', $mastoIbd['followers'], null, 'raw', 'neutral')
        : mkMetricStub('Mast. followers'),
    $bskyIbd !== null
        ? mkMetric('Bluesky followers', $bskyIbd['followers'], null, 'raw', 'neutral')
        : mkMetricStub('Bluesky followers'),
    $xIbd !== null
        ? mkMetric('X followers', $xIbd['followers'], null, 'raw', 'neutral')
        : mkMetricStub('X followers'),
    $ibdPublished !== null
        ? mkMetric('Posts published', $ibdPublished, $ibdPostsDelta, 'raw', 'neutral')
        : mkMetricStub('Posts published'),
    $ibdXPublished !== null
        ? mkMetric('X posts published', $ibdXPublished, $ibdXPostsDelta, 'raw', 'neutral')
        : mkMetricStub('X posts published'),
];

$ibdSourcesOk = ($ibdGscClicks !== null) || ($ibdPublished !== null) || ($mastoIbd !== null) || ($bskyIbd !== null) || ($xIbd !== null);
$ibdStatus    = $ibdSourcesOk ? 'online' : 'idle';

// ── Top-level status = worst-of-children ─────────────────────────────────────

$statusRank = ['online' => 0, 'idle' => 1, 'degraded' => 2, 'offline' => 3];
$topStatus  = $statusRank[$glycStatus] >= $statusRank[$ibdStatus] ? $glycStatus : $ibdStatus;

// Degrade to "degraded" if major sources (Postiz + GSC) are both down
$majorSourcesDown = ($postizCounts === null) && ($gscGlyc === null) && ($gscIbd === null);
if ($majorSourcesDown) {
    $topStatus = 'degraded';
}

// ── Top-level Bluesky posts published metric (both Glyc + IBD, last 7d) ──────

$bskyGlycPublished = mkPostizPublished($postizCounts, POSTIZ_ID_GLYC_BLUESKY);
$bskyIbdPublished  = mkPostizPublished($postizCounts, POSTIZ_ID_IBD_BLUESKY);
$bskyTotalPublished = ($bskyGlycPublished !== null || $bskyIbdPublished !== null)
    ? (int)($bskyGlycPublished ?? 0) + (int)($bskyIbdPublished ?? 0)
    : null;

$topLevelMetrics  = [
    $bskyTotalPublished !== null
        ? mkMetric('Bluesky posts (7d)', $bskyTotalPublished, null, 'raw', 'neutral')
        : mkMetricStub('Bluesky posts (7d)'),
];

// ── Build sparklines ─────────────────────────────────────────────────────────

$glycSparkline14 = $ga4Glyc !== null ? $ga4Glyc['sparkline'] : array_fill(0, 14, 0);
$ibdSparkline14  = $ga4Ibd  !== null ? $ga4Ibd['sparkline']  : array_fill(0, 14, 0);

// ── Assemble tile ─────────────────────────────────────────────────────────────

$now  = date('c');
$tile = [
    'slug'                   => 'marketing',
    'name'                   => 'Marketing & Analytics',
    'icon'                   => 'megaphone',
    'link'                   => '/port/marketing/',
    'status'                 => $topStatus,
    'period'                 => 'Last 7 days',
    'last_updated'           => $now,
    'data_freshness'         => $now,
    'stale_threshold_minutes' => 2160,
    'metrics'                => $topLevelMetrics,
    'sparkline'              => null,
    'alerts'                 => [
        'count'        => 0,
        'link'         => '/port/marketing/',
        'severity_max' => 'info',
    ],
    'children' => [
        [
            'name'      => 'getglyc.com',
            'status'    => $glycStatus,
            'link'      => '/port/marketing/?site=glyc',
            'metrics'   => $glycMetrics,
            'sparkline' => [
                'label' => '14-day users',
                'data'  => $glycSparkline14,
            ],
        ],
        [
            'name'      => 'ibdmovement.com',
            'status'    => $ibdStatus,
            'link'      => '/port/marketing/?site=ibd',
            'metrics'   => $ibdMetrics,
            'sparkline' => [
                'label' => '14-day users',
                'data'  => $ibdSparkline14,
            ],
        ],
    ],
    'campaign_data'  => $campaignData,
    // v0 back-compat fields (renderer may still read these during migration)
    'primary_metric' => $bskyTotalPublished !== null
        ? $bskyTotalPublished . ' Bluesky posts (7d)'
        : (($glycPublished !== null || $ibdPublished !== null)
            ? (($glycPublished ?? 0) + ($ibdPublished ?? 0)) . ' posts published (7d)'
            : 'data loading'),
    'detail' => 'glyc · ibd movement',
];

// ── Persist to cache ─────────────────────────────────────────────────────────

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0750, true);
}
$cacheData               = $tile;
$cacheData['_cached_at'] = time();
@file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);

echo json_encode($tile);
