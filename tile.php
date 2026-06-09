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

// ── History store helpers ─────────────────────────────────────────────────────

/**
 * Read data/metrics_history.csv and return indexed by [date][property][metric].
 * Returns empty array if file not found or unreadable.
 */
function mkHistoryStore(): array {
    $csv = __DIR__ . '/data/metrics_history.csv';
    if (!file_exists($csv)) {
        return [];
    }
    $fh = @fopen($csv, 'r');
    if (!$fh) {
        return [];
    }
    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        return [];
    }
    $store = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) !== count($header)) {
            continue;
        }
        $r = array_combine($header, $row);
        $store[$r['date']][$r['property']][$r['metric']] = (float)$r['value'];
    }
    fclose($fh);
    return $store;
}

/** Get the most recent recorded value for (property, metric). */
function mkHistoryLatest(array $store, string $property, string $metric): ?float {
    $dates = array_keys($store);
    rsort($dates);
    foreach ($dates as $date) {
        if (isset($store[$date][$property][$metric])) {
            return $store[$date][$property][$metric];
        }
    }
    return null;
}

/**
 * Get the value closest to N days before the latest date for a given metric.
 * Returns null if no data point exists at or before the cutoff.
 */
function mkHistoryPrior(array $store, string $property, string $metric, int $daysBack = 28): ?float {
    $dates = array_keys($store);
    rsort($dates);
    if (empty($dates)) {
        return null;
    }
    $cutoff = date('Y-m-d', strtotime($dates[0] . " -{$daysBack} days"));
    foreach ($dates as $date) {
        if ($date <= $cutoff && isset($store[$date][$property][$metric])) {
            return $store[$date][$property][$metric];
        }
    }
    return null;
}

// Scoreboard targets (from marketing-analytics-scoreboard.md spec, 2026-05-27)
const MK_TARGETS = [
    'glyc' => [
        'ga4_debotted_sessions' => 1000.0,  // /28d — Mediavine engagement bar
        'indexed_pages'         => 130.0,   // ≥130 sustained 14d
    ],
    'ibd'  => [
        // Targets are "grow" / "upward slope" — no hard number yet; null = directional only
    ],
];

/**
 * Build a scoreboard entry for a single (property, metric).
 * Returns current, prior, delta, target, progress (% of target), direction.
 */
function mkScoreboardEntry(array $store, string $property, string $metric): array {
    $target  = MK_TARGETS[$property][$metric] ?? null;
    $current = mkHistoryLatest($store, $property, $metric);
    $prior   = mkHistoryPrior($store, $property, $metric, 28);
    $delta   = ($current !== null && $prior !== null) ? round($current - $prior, 2) : null;
    $progress = ($current !== null && $target !== null) ? round(($current / $target) * 100, 1) : null;

    $direction = 'neutral';
    if ($delta !== null) {
        $direction = $delta > 0 ? 'positive' : ($delta < 0 ? 'negative' : 'neutral');
    }

    return compact('metric', 'current', 'prior', 'delta', 'target', 'progress', 'direction');
}

/**
 * Build the full scoreboard section for both properties.
 * This is the history-store-backed trend + actual-vs-target view.
 */
function mkScoreboard(array $store): array {
    $tracked = [
        'glyc' => [
            'ga4_debotted_sessions', 'ga4_engaged_sessions', 'ga4_sign_ups',
            'ga4_returning_users', 'utm_social_sessions',
            'gsc_clicks', 'gsc_impressions', 'gsc_avg_position', 'indexed_pages',
            'social_bsky_followers', 'social_bsky_likes_last10', 'social_bsky_reposts_last10',
            'social_masto_followers', 'social_masto_favs_last10', 'social_masto_boosts_last10',
        ],
        'ibd'  => [
            'ga4_debotted_sessions', 'ga4_engaged_sessions', 'ga4_sign_ups',
            'ga4_returning_users', 'utm_social_sessions',
            'gsc_clicks', 'gsc_impressions', 'gsc_avg_position', 'indexed_pages',
            'social_bsky_followers', 'social_bsky_likes_last10', 'social_bsky_reposts_last10',
            'social_masto_followers', 'social_masto_favs_last10', 'social_masto_boosts_last10',
        ],
    ];

    $board = [];
    foreach ($tracked as $prop => $metrics) {
        $board[$prop] = [];
        foreach ($metrics as $metric) {
            $board[$prop][$metric] = mkScoreboardEntry($store, $prop, $metric);
        }
    }
    return $board;
}

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
function mkGscTotals(string $siteUrl, int $days = 7, int $endOffset = 1): ?array {
    $credPath = defined('MK_GA4_CREDENTIALS_PATH') ? MK_GA4_CREDENTIALS_PATH : null;
    if (!$credPath || !file_exists($credPath)) {
        return null;
    }

    $token = mkGoogleSaToken($credPath, 'https://www.googleapis.com/auth/webmasters.readonly');
    if (!$token) {
        return null;
    }

    $endDate   = date('Y-m-d', strtotime("-{$endOffset} days"));
    $startDate = date('Y-m-d', strtotime('-' . ($endOffset + $days - 1) . ' days'));
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

// ── History store (read once; used for scoreboard + deltas) ──────────────────
$history    = mkHistoryStore();
$scoreboard = mkScoreboard($history);

// Bluesky follower deltas from history (7-day lookback)
$glycBskyPrior7d = mkHistoryPrior($history, 'glyc', 'social_bsky_followers', 7);
$ibdBskyPrior7d  = mkHistoryPrior($history, 'ibd',  'social_bsky_followers', 7);
$glycMastoPrior7d = mkHistoryPrior($history, 'glyc', 'social_masto_followers', 7);
$ibdMastoPrior7d  = mkHistoryPrior($history, 'ibd',  'social_masto_followers', 7);

// Instagram follower counts from history store (collected daily by
// collect_social_metrics.py via the Postiz DB Graph API token — no live
// Graph call needed here). 7-day prior for delta.
$glycInstaFollowers = mkHistoryLatest($history, 'glyc', 'social_ig_followers');
$ibdInstaFollowers  = mkHistoryLatest($history, 'ibd',  'social_ig_followers');
$glycInstaPrior7d   = mkHistoryPrior($history, 'glyc', 'social_ig_followers', 7);
$ibdInstaPrior7d    = mkHistoryPrior($history, 'ibd',  'social_ig_followers', 7);

// Device split — mobile share of ENGAGED sessions (the real-user signal; raw
// device users are bot-inflated, esp. Glyc mobile). Collected daily by
// collect_daily_metrics.py as ga4_device_<dev>_engaged_sessions.
$mkMobileShare = function (array $h, string $p): ?array {
    $m = mkHistoryLatest($h, $p, 'ga4_device_mobile_engaged_sessions');
    $d = mkHistoryLatest($h, $p, 'ga4_device_desktop_engaged_sessions');
    $t = mkHistoryLatest($h, $p, 'ga4_device_tablet_engaged_sessions');
    if ($m === null && $d === null && $t === null) {
        return null;
    }
    $total = (int)$m + (int)$d + (int)$t;
    if ($total <= 0) {
        return null;
    }
    return ['pct' => (int)round(100 * (int)$m / $total), 'mobile' => (int)$m, 'total' => $total];
};
$glycMobileShare = $mkMobileShare($history, 'glyc');
$ibdMobileShare  = $mkMobileShare($history, 'ibd');

// Postiz integration IDs (from running Postiz instance)
// - cmouj99190001pi8h1f0upfga  = Glyc (Bluesky / bennernet.bsky.social)
// - cmpbj9osm0008poec8q68tlgo  = IBD Movement (Bluesky / ibdmovement.bsky.social)
// - cmouqqkw70001o08gts5rpnyb  = Ben Rogers (Mastodon / glyc profile)
// - cmouqudgd0003o08gq5w1q3jj  = The IBD Movement (Mastodon / ibdmovement profile)
// - cmpbr9le70003mo8mzzg84o2d  = Glyc (X / getglyc)
// - cmpbr6c0n0001mo8mj5m2d3hx  = IBD Movement (X / IBDMovement)
// - cmq2rp6l1001ol98ugo3dz6oh  = Glyc (Instagram / getglyc)
// - cmq142urk0017l98u8phwixop  = IBD Movement (Instagram / theibdmovement)
const POSTIZ_ID_GLYC_MASTODON   = 'cmouqqkw70001o08gts5rpnyb';
const POSTIZ_ID_IBD_MASTODON    = 'cmouqudgd0003o08gq5w1q3jj';
const POSTIZ_ID_GLYC_BLUESKY    = 'cmouj99190001pi8h1f0upfga';
const POSTIZ_ID_IBD_BLUESKY     = 'cmpbj9osm0008poec8q68tlgo';
const POSTIZ_ID_GLYC_X          = 'cmpbr9le70003mo8mzzg84o2d';
const POSTIZ_ID_IBD_X           = 'cmpbr6c0n0001mo8mj5m2d3hx';
const POSTIZ_ID_GLYC_INSTAGRAM  = 'cmq2rp6l1001ol98ugo3dz6oh';
const POSTIZ_ID_IBD_INSTAGRAM   = 'cmq142urk0017l98u8phwixop';

$postizCounts = mkPostizPostCounts();

// Per-site Bluesky follower counts (separate accounts per site)
// Config.php constants take priority over sops so production (no sops) works.
$secrets          = mkSecrets();
$glycBskyHandle   = defined('MK_BLUESKY_HANDLE_GLYC') && MK_BLUESKY_HANDLE_GLYC !== ''
    ? MK_BLUESKY_HANDLE_GLYC
    : ($secrets['bluesky_handle'] ?? 'getglyc.com');
$ibdBskyHandle    = defined('MK_BLUESKY_HANDLE_IBD') && MK_BLUESKY_HANDLE_IBD !== ''
    ? MK_BLUESKY_HANDLE_IBD
    : ($secrets['bluesky_ibdmovement_handle'] ?? 'ibdmovement.bsky.social');
$bskyGlyc         = mkBlueskyFollowers($glycBskyHandle);
$bskyIbd          = mkBlueskyFollowers($ibdBskyHandle);
$gscGlyc      = mkGscTotals('sc-domain:getglyc.com',    7);
$gscIbd       = mkGscTotals('sc-domain:ibdmovement.com', 7);
$gscGlycPrior = mkGscTotals('sc-domain:getglyc.com',    7, 8);
$gscIbdPrior  = mkGscTotals('sc-domain:ibdmovement.com', 7, 8);
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
$glycGscPriorClicks  = $gscGlycPrior !== null ? $gscGlycPrior['clicks'] : null;
$glycXPublished         = mkPostizPublished($postizCounts, POSTIZ_ID_GLYC_X);
$glycXQueued            = mkPostizQueued($postizCounts, POSTIZ_ID_GLYC_X);
$glycInstaPublished     = mkPostizPublished($postizCounts, POSTIZ_ID_GLYC_INSTAGRAM);
$glycInstaQueued        = mkPostizQueued($postizCounts, POSTIZ_ID_GLYC_INSTAGRAM);

$glycPostsDelta = null;
if ($glycQueued > 0) {
    $glycPostsDelta = '+' . $glycQueued . ' scheduled';
}

$glycXPostsDelta = null;
if ($glycXQueued > 0) {
    $glycXPostsDelta = '+' . $glycXQueued . ' scheduled';
}

$glycInstaPostsDelta = null;
if ($glycInstaQueued > 0) {
    $glycInstaPostsDelta = '+' . $glycInstaQueued . ' scheduled';
}

// Engaged sessions from history store (28d, de-botted) with target progress
$glycEngaged28d = mkHistoryLatest($history, 'glyc', 'ga4_debotted_sessions');
$glycEngagedTarget = MK_TARGETS['glyc']['ga4_debotted_sessions'];
$glycEngagedDelta  = $scoreboard['glyc']['ga4_debotted_sessions']['delta'] ?? null;
$glycEngagedProgress = $glycEngaged28d !== null
    ? sprintf('%.0f / %.0f target (%.1f%%)', $glycEngaged28d, $glycEngagedTarget,
        ($glycEngaged28d / $glycEngagedTarget) * 100)
    : null;

$ibdEngaged28d = mkHistoryLatest($history, 'ibd', 'ga4_debotted_sessions');
$ibdEngagedDelta = $scoreboard['ibd']['ga4_debotted_sessions']['delta'] ?? null;

$glycMetrics = [
    $ga4Glyc !== null
        ? mkMetric('Users', $ga4Glyc['users'], null, 'raw', 'neutral')
        : mkMetricStub('Users'),
    $glycEngaged28d !== null
        ? mkMetric('Engaged sessions (28d)', (int)$glycEngaged28d, $glycEngagedProgress, 'raw',
            $glycEngagedDelta > 0 ? 'positive' : ($glycEngagedDelta < 0 ? 'negative' : 'neutral'))
        : mkMetricStub('Engaged sessions (28d)'),
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
    $glycGscPriorClicks !== null
        ? mkMetric('GSC clicks (prior 7d)', $glycGscPriorClicks, null, 'raw', 'neutral')
        : mkMetricStub('GSC clicks (prior 7d)'),
    $mastoGlyc !== null
        ? mkMetric('Mast. followers', $mastoGlyc['followers'],
            ($glycMastoPrior7d !== null ? sprintf('%+d vs 7d ago', $mastoGlyc['followers'] - (int)$glycMastoPrior7d) : null),
            'raw', $mastoGlyc['followers'] >= ($glycMastoPrior7d ?? $mastoGlyc['followers']) ? 'positive' : 'negative')
        : mkMetricStub('Mast. followers'),
    $bskyGlyc !== null
        ? mkMetric('Bluesky followers', $bskyGlyc['followers'],
            ($glycBskyPrior7d !== null ? sprintf('%+d vs 7d ago', $bskyGlyc['followers'] - (int)$glycBskyPrior7d) : null),
            'raw', $bskyGlyc['followers'] >= ($glycBskyPrior7d ?? $bskyGlyc['followers']) ? 'positive' : 'negative')
        : mkMetricStub('Bluesky followers'),
    $xGlyc !== null
        ? mkMetric('X followers', $xGlyc['followers'], null, 'raw', 'neutral')
        : mkMetricStub('X followers'),
    $glycInstaFollowers !== null
        ? mkMetric('Instagram followers', (int)$glycInstaFollowers,
            ($glycInstaPrior7d !== null ? sprintf('%+d vs 7d ago', (int)$glycInstaFollowers - (int)$glycInstaPrior7d) : null),
            'raw', $glycInstaFollowers >= ($glycInstaPrior7d ?? $glycInstaFollowers) ? 'positive' : 'negative')
        : mkMetricStub('Instagram followers'),
    $glycPublished !== null
        ? mkMetric('Posts published', $glycPublished, $glycPostsDelta, 'raw', 'neutral')
        : mkMetricStub('Posts published'),
    $glycXPublished !== null
        ? mkMetric('X posts published', $glycXPublished, $glycXPostsDelta, 'raw', 'neutral')
        : mkMetricStub('X posts published'),
    $glycInstaPublished !== null
        ? mkMetric('Instagram posts published', $glycInstaPublished, $glycInstaPostsDelta, 'raw', 'neutral')
        : mkMetricStub('Instagram posts published'),
    $glycMobileShare !== null
        ? mkMetric('Mobile share (engaged)', $glycMobileShare['pct'] . '%',
            sprintf('%d of %d engaged sessions', $glycMobileShare['mobile'], $glycMobileShare['total']),
            'raw', 'neutral')
        : mkMetricStub('Mobile share (engaged)'),
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
$ibdGscPriorClicks    = $gscIbdPrior !== null ? $gscIbdPrior['clicks'] : null;
$ibdXPublished      = mkPostizPublished($postizCounts, POSTIZ_ID_IBD_X);
$ibdXQueued         = mkPostizQueued($postizCounts, POSTIZ_ID_IBD_X);
$ibdInstaPublished  = mkPostizPublished($postizCounts, POSTIZ_ID_IBD_INSTAGRAM);
$ibdInstaQueued     = mkPostizQueued($postizCounts, POSTIZ_ID_IBD_INSTAGRAM);

$ibdPostsDelta = null;
if ($ibdQueued > 0) {
    $ibdPostsDelta = '+' . $ibdQueued . ' scheduled';
}

$ibdXPostsDelta = null;
if ($ibdXQueued > 0) {
    $ibdXPostsDelta = '+' . $ibdXQueued . ' scheduled';
}

$ibdInstaPostsDelta = null;
if ($ibdInstaQueued > 0) {
    $ibdInstaPostsDelta = '+' . $ibdInstaQueued . ' scheduled';
}

$ibdMetrics = [
    $ga4Ibd !== null
        ? mkMetric('Users', $ga4Ibd['users'], null, 'raw', 'neutral')
        : mkMetricStub('Users'),
    $ibdEngaged28d !== null
        ? mkMetric('Engaged sessions (28d)', (int)$ibdEngaged28d,
            ($ibdEngagedDelta !== null ? sprintf('%+.0f vs prior 28d', $ibdEngagedDelta) : null),
            'raw', $ibdEngagedDelta > 0 ? 'positive' : ($ibdEngagedDelta < 0 ? 'negative' : 'neutral'))
        : mkMetricStub('Engaged sessions (28d)'),
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
    $ibdGscPriorClicks !== null
        ? mkMetric('GSC clicks (prior 7d)', $ibdGscPriorClicks, null, 'raw', 'neutral')
        : mkMetricStub('GSC clicks (prior 7d)'),
    $mastoIbd !== null
        ? mkMetric('Mast. followers', $mastoIbd['followers'],
            ($ibdMastoPrior7d !== null ? sprintf('%+d vs 7d ago', $mastoIbd['followers'] - (int)$ibdMastoPrior7d) : null),
            'raw', $mastoIbd['followers'] >= ($ibdMastoPrior7d ?? $mastoIbd['followers']) ? 'positive' : 'negative')
        : mkMetricStub('Mast. followers'),
    $bskyIbd !== null
        ? mkMetric('Bluesky followers', $bskyIbd['followers'],
            ($ibdBskyPrior7d !== null ? sprintf('%+d vs 7d ago', $bskyIbd['followers'] - (int)$ibdBskyPrior7d) : null),
            'raw', $bskyIbd['followers'] >= ($ibdBskyPrior7d ?? $bskyIbd['followers']) ? 'positive' : 'negative')
        : mkMetricStub('Bluesky followers'),
    $xIbd !== null
        ? mkMetric('X followers', $xIbd['followers'], null, 'raw', 'neutral')
        : mkMetricStub('X followers'),
    $ibdInstaFollowers !== null
        ? mkMetric('Instagram followers', (int)$ibdInstaFollowers,
            ($ibdInstaPrior7d !== null ? sprintf('%+d vs 7d ago', (int)$ibdInstaFollowers - (int)$ibdInstaPrior7d) : null),
            'raw', $ibdInstaFollowers >= ($ibdInstaPrior7d ?? $ibdInstaFollowers) ? 'positive' : 'negative')
        : mkMetricStub('Instagram followers'),
    $ibdPublished !== null
        ? mkMetric('Posts published', $ibdPublished, $ibdPostsDelta, 'raw', 'neutral')
        : mkMetricStub('Posts published'),
    $ibdXPublished !== null
        ? mkMetric('X posts published', $ibdXPublished, $ibdXPostsDelta, 'raw', 'neutral')
        : mkMetricStub('X posts published'),
    $ibdInstaPublished !== null
        ? mkMetric('Instagram posts published', $ibdInstaPublished, $ibdInstaPostsDelta, 'raw', 'neutral')
        : mkMetricStub('Instagram posts published'),
    $ibdMobileShare !== null
        ? mkMetric('Mobile share (engaged)', $ibdMobileShare['pct'] . '%',
            sprintf('%d of %d engaged sessions', $ibdMobileShare['mobile'], $ibdMobileShare['total']),
            'raw', 'neutral')
        : mkMetricStub('Mobile share (engaged)'),
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
    'scoreboard'     => $scoreboard,
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
