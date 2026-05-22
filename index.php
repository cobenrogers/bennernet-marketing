<?php
/**
 * Marketing module — landing page / dashboard.
 *
 * §7 Landing page UX (issue #65):
 *   - Today's digest: per-site snapshots (getglyc.com + ibdmovement.com)
 *   - Postiz queue status
 *   - Anomaly flag block (stubbed)
 *   - Quick links nav bar
 *   - Recent activity feed (last 5 published posts)
 *   - Drafts queue card
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once PORT_ROOT . '/shared/shell.php';
require_once __DIR__ . '/gh-helper.php';
require_once __DIR__ . '/local-fs-reader.php';

$user = requireModuleAccess('marketing', 'viewer');

// ── Data: draft queue count ───────────────────────────────────────────────────
$draftResult  = mkLocalDraftCount();
$draftCount   = $draftResult['count'];
$queueError   = $draftResult['error'];
$queueMissing = $draftResult['missing'];

// ── Data: recent published posts ──────────────────────────────────────────────
$publishedResult  = mkLocalRecentPublished();
$recentPublished  = $publishedResult['files'];
$publishedError   = $publishedResult['error'];
$publishedMissing = $publishedResult['missing'];

// ── Data: tile cache (per-site metrics) ───────────────────────────────────────
$tileCacheFile = (defined('MK_CACHE_DIR') ? MK_CACHE_DIR : sys_get_temp_dir() . '/mk-cache')
               . '/marketing-tile.json';
$tileCacheTtl  = 900; // 15 minutes — must match tile.php
$tileCache     = null;
if (file_exists($tileCacheFile)) {
    $raw = @file_get_contents($tileCacheFile);
    $decoded = $raw ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $tileCache = $decoded;
    }
}

// If cache is stale or missing, fire a background refresh via loopback so the
// next page load (or this one if tile.php is fast) gets fresh X/GA4/GSC data.
$tileCacheAge = $tileCache ? (time() - ($tileCache['_cached_at'] ?? 0)) : PHP_INT_MAX;
if ($tileCacheAge > $tileCacheTtl && php_sapi_name() !== 'cli') {
    $mkTileScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'ssl://' : '';
    $mkTileHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $mkTilePort   = empty($mkTileScheme) ? 80 : 443;
    $mkTileSock   = @fsockopen($mkTileScheme . $mkTileHost, $mkTilePort, $errno, $errstr, 2);
    if ($mkTileSock) {
        stream_set_blocking($mkTileSock, false);
        fwrite($mkTileSock,
            "GET /port/marketing/tile.php HTTP/1.0\r\n"
            . "Host: {$mkTileHost}\r\n"
            . "Cookie: " . ($_SERVER['HTTP_COOKIE'] ?? '') . "\r\n"
            . "Connection: close\r\n\r\n"
        );
        fclose($mkTileSock);
    }
}


// ── Data: per-site metrics extracted from tile cache children ────────────────
$glycPostsPublished = null;
$ibdPostsPublished  = null;
$glycMastoFollowers = null;
$ibdMastoFollowers  = null;
$glycGscClicks       = null;
$ibdGscClicks        = null;
$glycGscImpressions  = null;
$ibdGscImpressions   = null;
$glycGscCtr          = null;
$ibdGscCtr           = null;
$glycGscPosition     = null;
$ibdGscPosition      = null;
$glycBskyFollowers  = null;
$ibdBskyFollowers   = null;
$glycXFollowers     = null;
$ibdXFollowers      = null;
$glycGa4Users       = null;
$ibdGa4Users        = null;
$glycSparkline      = null;
$ibdSparkline       = null;
if ($tileCache && isset($tileCache['children']) && is_array($tileCache['children'])) {
    foreach ($tileCache['children'] as $child) {
        $name    = $child['name'] ?? '';
        $isGlyc  = stripos($name, 'glyc') !== false;
        $isIbd   = stripos($name, 'ibd')  !== false;
        if (!$isGlyc && !$isIbd) {
            continue;
        }
        foreach (($child['metrics'] ?? []) as $metric) {
            $label = $metric['label'] ?? '';
            $value = $metric['value'] ?? null;
            if (stripos($label, 'posts published') !== false) {
                if ($isGlyc) $glycPostsPublished = $value;
                if ($isIbd)  $ibdPostsPublished  = $value;
            } elseif (stripos($label, 'mast') !== false) {
                if ($isGlyc) $glycMastoFollowers = $value;
                if ($isIbd)  $ibdMastoFollowers  = $value;
            } elseif (stripos($label, 'GSC clicks') !== false) {
                if ($isGlyc) $glycGscClicks = $value;
                if ($isIbd)  $ibdGscClicks  = $value;
            } elseif (stripos($label, 'GSC impressions') !== false) {
                if ($isGlyc) $glycGscImpressions = $value;
                if ($isIbd)  $ibdGscImpressions  = $value;
            } elseif (stripos($label, 'GSC CTR') !== false) {
                if ($isGlyc) $glycGscCtr = $value;
                if ($isIbd)  $ibdGscCtr  = $value;
            } elseif (stripos($label, 'GSC avg position') !== false) {
                if ($isGlyc) $glycGscPosition = $value;
                if ($isIbd)  $ibdGscPosition  = $value;
            } elseif (stripos($label, 'bluesky followers') !== false) {
                if ($isGlyc) $glycBskyFollowers = $value;
                if ($isIbd)  $ibdBskyFollowers  = $value;
            } elseif ($label === 'X followers') {
                if ($isGlyc) $glycXFollowers = $value;
                if ($isIbd)  $ibdXFollowers  = $value;
            } elseif (stripos($label, 'users') !== false) {
                if ($isGlyc) $glycGa4Users = $value;
                if ($isIbd)  $ibdGa4Users  = $value;
            }
        }
        // Extract sparkline (14-day users)
        $sparklineData = isset($child['sparkline']['data']) && is_array($child['sparkline']['data'])
            ? $child['sparkline']['data']
            : null;
        if ($sparklineData !== null) {
            if ($isGlyc) $glycSparkline = $sparklineData;
            if ($isIbd)  $ibdSparkline  = $sparklineData;
        }
    }
}

$campaignData = $tileCache['campaign_data'] ?? null;

// ── Postiz integration IDs (mirrors tile.php constants) ──────────────────────
if (!defined('POSTIZ_ID_GLYC_MASTODON')) define('POSTIZ_ID_GLYC_MASTODON', 'cmouqqkw70001o08gts5rpnyb');
if (!defined('POSTIZ_ID_IBD_MASTODON'))  define('POSTIZ_ID_IBD_MASTODON',  'cmouqudgd0003o08gq5w1q3jj');
if (!defined('POSTIZ_ID_GLYC_BLUESKY'))  define('POSTIZ_ID_GLYC_BLUESKY',  'cmouj99190001pi8h1f0upfga');
if (!defined('POSTIZ_ID_IBD_BLUESKY'))   define('POSTIZ_ID_IBD_BLUESKY',   'cmpbj9osm0008poec8q68tlgo');
if (!defined('POSTIZ_ID_GLYC_X'))        define('POSTIZ_ID_GLYC_X',        'cmpbr9le70003mo8mzzg84o2d');
if (!defined('POSTIZ_ID_IBD_X'))         define('POSTIZ_ID_IBD_X',         'cmpbr6c0n0001mo8mj5m2d3hx');

// ── Data: Postiz queue status ─────────────────────────────────────────────────
$postizQueueCount       = null;
$postizError            = false;
$postizConfigured       = false;
$postizByPlatform       = [];
$recentPostizActivity   = [];

// Support both MK_POSTIZ_URL/MK_POSTIZ_TOKEN (spec) and MK_BRIDGE_URL/MK_BRIDGE_TOKEN (local)
$postizBaseUrl   = null;
$postizAuthHeader = null;

if (defined('MK_POSTIZ_URL') && MK_POSTIZ_URL !== '' &&
    defined('MK_POSTIZ_TOKEN') && MK_POSTIZ_TOKEN !== '') {
    $postizBaseUrl    = rtrim(MK_POSTIZ_URL, '/');
    $postizAuthHeader = 'Authorization: Bearer ' . MK_POSTIZ_TOKEN;
    $postizConfigured = true;
} elseif (defined('MK_BRIDGE_URL') && MK_BRIDGE_URL !== '' &&
          defined('MK_BRIDGE_TOKEN') && MK_BRIDGE_TOKEN !== '') {
    $postizBaseUrl    = rtrim(MK_BRIDGE_URL, '/') . '/postiz';
    $postizAuthHeader = 'Authorization: Bearer ' . MK_BRIDGE_TOKEN;
    $postizConfigured = true;
}

if ($postizConfigured && $postizBaseUrl !== null && $postizAuthHeader !== null) {
    // Date window: 7 days back through 7 days forward to capture PUBLISHED, ERROR, and QUEUE posts.
    $qs = 'startDate=' . urlencode(date('c', strtotime('-7 days')))
        . '&endDate='  . urlencode(date('c', strtotime('+7 days')))
        . '&take=200';
    $postizUrl = $postizBaseUrl . '/api/public/v1/posts?' . $qs;
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => $postizAuthHeader . "\r\nUser-Agent: bennernet-marketing/1.0",
        'timeout'       => 6,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($postizUrl, false, $ctx);
    if ($body === false) {
        $postizError = true;
    } else {
        $data  = json_decode($body, true);
        $posts = is_array($data) ? ($data['posts'] ?? (isset($data[0]) ? $data : null)) : null;
        if (is_array($posts)) {
            // Count QUEUE posts for the summary badge
            $queued = array_filter($posts, fn($p) => ($p['state'] ?? '') === 'QUEUE');
            $postizQueueCount = count($queued);
            // Build per-platform breakdown
            $postizByPlatform = mkPostizByPlatform($posts);
            // Extract last 5 published posts for Recent Activity
            $mkPub = array_filter($posts, fn($p) => ($p['state'] ?? '') === 'PUBLISHED');
            usort($mkPub, fn($a, $b) => strcmp(
                $b['publishDate'] ?? $b['publishedAt'] ?? $b['createdAt'] ?? '',
                $a['publishDate'] ?? $a['publishedAt'] ?? $a['createdAt'] ?? ''
            ));
            $recentPostizActivity = array_slice(array_values($mkPub), 0, 5);
        } else {
            $postizError = true;
        }
    }
}

// ── Data: upcoming calendar (next 14 days, QUEUE posts) ──────────────────
$calendarPosts = [];
$calendarError = false;
if ($postizConfigured && $postizBaseUrl !== null && $postizAuthHeader !== null) {
    $calStart = date('Y-m-d\TH:i:s\Z');
    $calEnd   = date('Y-m-d\TH:i:s\Z', strtotime('+14 days'));
    $calUrl   = $postizBaseUrl . '/api/public/v1/posts?startDate=' . urlencode($calStart) . '&endDate=' . urlencode($calEnd);
    $calCtx   = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => $postizAuthHeader . "\r\n",
        'timeout'       => 8,
        'ignore_errors' => true,
    ]]);
    $calRaw  = @file_get_contents($calUrl, false, $calCtx);
    $calData = $calRaw ? json_decode($calRaw, true) : null;
    if (is_array($calData) && isset($calData['posts'])) {
        $allCalPosts = $calData['posts'];
        // Filter to QUEUE only, sort ascending by publishDate
        $calendarPosts = array_filter($allCalPosts, fn($p) => ($p['state'] ?? '') === 'QUEUE');
        usort($calendarPosts, fn($a, $b) => strcmp($a['publishDate'] ?? '', $b['publishDate'] ?? ''));
        $calendarPosts = array_values($calendarPosts);
    } elseif ($postizConfigured) {
        $calendarError = true;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Calculate conversion rate as a formatted percentage string.
 * Returns null when sessions = 0 to signal a "—" display.
 */
function mkConvRate(int $sessions, int $signups): ?string {
    if ($sessions === 0) return null;
    return number_format($signups / $sessions * 100, 1) . '%';
}

/**
 * Derive a human-readable platform name from a Postiz post object.
 * Uses integration type/name/provider fields; falls back to 'Social'.
 */
function mkPostizPostPlatform(array $post): string {
    $intg = $post['integration'] ?? [];
    $type = strtolower($intg['type'] ?? $intg['provider'] ?? $intg['providerIdentifier'] ?? '');
    if (str_contains($type, 'bluesky') || str_contains($type, 'bsky')) return 'Bluesky';
    if (str_contains($type, 'mastodon'))                                return 'Mastodon';
    if (str_contains($type, 'twitter') || $type === 'x' || str_contains($type, '_x_')) return 'X/Twitter';
    $name = strtolower($intg['name'] ?? '');
    if (str_contains($name, 'bluesky') || str_contains($name, 'bsky')) return 'Bluesky';
    if (str_contains($name, 'mastodon') || str_contains($name, 'masto')) return 'Mastodon';
    if (str_contains($name, 'twitter') || str_contains($name, ' x') || str_contains($name, 'x ')) return 'X/Twitter';
    return 'Social';
}

/**
 * Group a Postiz posts array by known integration platform keys.
 *
 * Returns counts per platform: queued, published_7d, errors_7d, last_published.
 * Uses the POSTIZ_ID_* constants defined above (mirrored from tile.php).
 *
 * Issue: cobenrogers/bennernet-marketing#94
 */
function mkPostizByPlatform(array $posts): array
{
    $idMap = [];
    if (defined('POSTIZ_ID_GLYC_MASTODON')) $idMap[POSTIZ_ID_GLYC_MASTODON] = 'glyc_mastodon';
    if (defined('POSTIZ_ID_IBD_MASTODON'))  $idMap[POSTIZ_ID_IBD_MASTODON]  = 'ibd_mastodon';
    if (defined('POSTIZ_ID_GLYC_BLUESKY'))  $idMap[POSTIZ_ID_GLYC_BLUESKY]  = 'glyc_bluesky';
    if (defined('POSTIZ_ID_IBD_BLUESKY'))   $idMap[POSTIZ_ID_IBD_BLUESKY]   = 'ibd_bluesky';
    if (defined('POSTIZ_ID_GLYC_X'))        $idMap[POSTIZ_ID_GLYC_X]        = 'glyc_x';
    if (defined('POSTIZ_ID_IBD_X'))         $idMap[POSTIZ_ID_IBD_X]         = 'ibd_x';

    $result = [];
    foreach (array_unique(array_values($idMap)) as $key) {
        $result[$key] = ['queued' => 0, 'published_7d' => 0, 'errors_7d' => 0, 'last_published' => null];
    }
    foreach (['glyc_mastodon', 'ibd_mastodon', 'glyc_bluesky', 'ibd_bluesky'] as $key) {
        if (!isset($result[$key])) {
            $result[$key] = ['queued' => 0, 'published_7d' => 0, 'errors_7d' => 0, 'last_published' => null];
        }
    }

    $cutoff7d = time() - 7 * 86400;

    foreach ($posts as $post) {
        $integrationId = $post['integration']['id'] ?? ($post['integrationId'] ?? '');
        $platformKey   = $idMap[$integrationId] ?? null;
        if ($platformKey === null) {
            continue;
        }
        $state       = $post['state'] ?? '';
        $publishedAt = $post['publishDate'] ?? ($post['publishedAt'] ?? ($post['createdAt'] ?? null));
        $postTs      = $publishedAt !== null ? strtotime($publishedAt) : false;

        if ($state === 'QUEUE') {
            $result[$platformKey]['queued']++;
        } elseif ($state === 'PUBLISHED') {
            if ($postTs !== false && $postTs >= $cutoff7d) {
                $result[$platformKey]['published_7d']++;
            }
            $current = $result[$platformKey]['last_published'];
            if ($publishedAt !== null && ($current === null || $publishedAt > $current)) {
                $result[$platformKey]['last_published'] = $publishedAt;
            }
        } elseif ($state === 'ERROR') {
            if ($postTs !== false && $postTs >= $cutoff7d) {
                $result[$platformKey]['errors_7d']++;
            }
        }
    }

    return $result;
}

/**
 * Infer platform from a filename or path string.
 */
function mkInferPlatform(string $nameOrPath): string {
    $s = strtolower($nameOrPath);
    if (str_contains($s, 'bsky') || str_contains($s, 'bluesky')) {
        return 'Bluesky';
    }
    if (str_contains($s, 'mastodon') || str_contains($s, 'masto')) {
        return 'Mastodon';
    }
    if (str_contains($s, 'linkedin') || str_contains($s, 'li-') || str_contains($s, '-li')) {
        return 'LinkedIn';
    }
    if (str_contains($s, 'reddit')) {
        return 'Reddit';
    }
    if (str_contains($s, 'twitter') || str_contains($s, 'twit') || str_contains($s, '-x-')) {
        return 'X/Twitter';
    }
    if (str_contains($s, 'instagram') || str_contains($s, 'insta')) {
        return 'Instagram';
    }
    return 'Unknown';
}

/**
 * Return a CSS class suffix for a platform badge.
 */
function mkPlatformBadgeClass(string $platform): string {
    return match ($platform) {
        'Bluesky'   => 'bluesky',
        'Mastodon'  => 'mastodon',
        'LinkedIn'  => 'linkedin',
        'Reddit'    => 'reddit',
        'X/Twitter' => 'twitter',
        'Instagram' => 'instagram',
        default     => 'neutral',
    };
}

/**
 * Compute a channel health status string from Postiz error count and last published timestamp.
 *
 * Returns 'healthy', 'stale', or 'error'.
 * Issue: cobenrogers/bennernet-marketing#95
 */
function mkChannelStatus(?int $errors7d, ?string $lastPublished, bool $postizAvailable = true): string {
    if (!$postizAvailable) return 'unknown';
    if ($errors7d !== null && $errors7d > 0) return 'error';
    if ($lastPublished === null) return 'stale';
    $age = (time() - strtotime($lastPublished)) / 86400;
    return $age <= 14 ? 'healthy' : 'stale';
}

/**
 * Check for Postiz ERROR posts in the last 7 days.
 *
 * Issue: cobenrogers/bennernet-marketing#98
 */
function mkCheckPostizErrors(array $postizByPlatform): array {
    $errorPlatforms = [];
    foreach ($postizByPlatform as $key => $data) {
        if (($data['errors_7d'] ?? 0) > 0) {
            $errorPlatforms[] = $key . ' (' . $data['errors_7d'] . ' error' . ($data['errors_7d'] > 1 ? 's' : '') . ')';
        }
    }
    if (empty($errorPlatforms)) {
        return ['ok' => true, 'message' => 'No Postiz errors in the last 7 days.', 'severity' => 'info'];
    }
    $n = array_sum(array_column($postizByPlatform, 'errors_7d'));
    return [
        'ok'       => false,
        'message'  => "⚠️ {$n} Postiz ERROR post(s) on " . implode(', ', $errorPlatforms) . " — manual re-post needed",
        'severity' => 'error',
    ];
}

/**
 * Check for a GSC clicks week-over-week drop of more than 25%.
 *
 * Issue: cobenrogers/bennernet-marketing#98
 */
function mkCheckGscDrop(?int $currentClicks, ?int $priorClicks, string $site): array {
    if ($currentClicks === null || $priorClicks === null || $priorClicks === 0) {
        return ['ok' => true, 'message' => "GSC clicks data unavailable for {$site}.", 'severity' => 'info'];
    }
    $drop = ($priorClicks - $currentClicks) / $priorClicks;
    if ($drop > 0.25) {
        $pct = round($drop * 100);
        return [
            'ok'       => false,
            'message'  => "📉 GSC clicks down {$pct}% week-over-week for {$site}",
            'severity' => 'warn',
        ];
    }
    return ['ok' => true, 'message' => "GSC clicks stable for {$site}.", 'severity' => 'info'];
}

/**
 * Check for overdue engagement check-ins in the engagement log.
 *
 * Issue: cobenrogers/bennernet-marketing#98
 */
function mkCheckEngagementOverdue(): array {
    $wsPath = defined('MK_WORKSPACE_PATH') ? MK_WORKSPACE_PATH : null;
    if (!$wsPath) {
        return ['ok' => true, 'message' => 'Workspace path not configured.', 'severity' => 'info'];
    }
    $logPath = rtrim($wsPath, '/') . '/tracking/engagement-log.md';
    if (!file_exists($logPath)) {
        return ['ok' => true, 'message' => 'Engagement log not found.', 'severity' => 'info'];
    }
    $content = file_get_contents($logPath);
    if ($content === false) {
        return ['ok' => true, 'message' => 'Could not read engagement log.', 'severity' => 'info'];
    }
    // Parse check-in dates from table rows — format: | ... | YYYY-MM-DD | ... |
    preg_match_all('/\|\s*(\d{4}-\d{2}-\d{2})\s*\|/', $content, $matches);
    $today   = date('Y-m-d');
    $overdue = 0;
    foreach ($matches[1] as $dateStr) {
        if ($dateStr < $today) {
            $overdue++;
        }
    }
    if ($overdue > 0) {
        return [
            'ok'       => false,
            'message'  => "📋 {$overdue} overdue engagement check-in(s) — run engagement-check.py --all --update-log",
            'severity' => 'warn',
        ];
    }
    return ['ok' => true, 'message' => 'All engagement check-ins up to date.', 'severity' => 'info'];
}

/**
 * Strip HTML tags and collapse whitespace for calendar post preview.
 */
function mkCalendarStripHtml(string $html): string {
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
}

/**
 * Return a plain-text preview of post content, truncated to $maxLen chars.
 */
function mkCalendarPreview(string $content, int $maxLen = 100): string {
    $plain = mkCalendarStripHtml($content);
    return mb_strlen($plain) > $maxLen ? mb_substr($plain, 0, $maxLen) . "…" : $plain;
}

/**
 * Format an ISO 8601 datetime string for display in the calendar.
 */
function mkCalendarFormatDate(string $iso): string {
    $ts = strtotime($iso);
    return $ts !== false ? date('D M j · g:i A', $ts) : $iso;
}

/**
 * Extract a relative time label from a filename with a YYYY-MM-DD prefix.
 */
function mkRelativeTime(string $filename): string
{
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $filename, $m)) {
        $ts = strtotime($m[1]);
        if ($ts !== false) {
            $diff = time() - $ts;
            if ($diff < 86400)     return 'today';
            if ($diff < 86400 * 2) return 'yesterday';
            if ($diff < 86400 * 7) return (int)floor($diff / 86400) . 'd ago';
            return (int)floor($diff / (86400 * 7)) . 'wk ago';
        }
    }
    return '';
}

// Relative time for a Unix timestamp — sub-day granularity for Postiz activity.
function mkRelativeTimeTs(int $ts): string
{
    $diff = time() - $ts;
    if ($diff < 3600)      return (int)floor($diff / 60) . 'm ago';
    if ($diff < 86400)     return (int)floor($diff / 3600) . 'h ago';
    if ($diff < 86400 * 2) return 'yesterday';
    if ($diff < 86400 * 7) return (int)floor($diff / 86400) . 'd ago';
    return (int)floor($diff / (86400 * 7)) . 'wk ago';
}

// ── Anomaly checks ────────────────────────────────────────────────────────────
$anomalyChecks = [
    mkCheckPostizErrors($postizByPlatform),
    mkCheckGscDrop($glycGscClicks, null, 'getglyc.com'),  // prior week data not cached yet
    mkCheckGscDrop($ibdGscClicks,  null, 'ibdmovement.com'),
    mkCheckEngagementOverdue(),
];
$activeFlags = array_filter($anomalyChecks, fn($c) => !$c['ok']);

renderHeader('Marketing', [
    'user'        => $user,
    'module_slug' => 'marketing',
    'breadcrumb'  => [
        ['label' => 'Port',      'url' => '/port/'],
        ['label' => 'Marketing', 'url' => null],
    ],
]);
?>

<style>
/* ── Marketing module — scoped styles ────────────────────────────────────────
   All colors use Port CSS token variables. No hardcoded hex values.        */

.mk-dashboard {
  padding: var(--space-6);
  max-width: 1100px;
  font-family: var(--font-sans);
}

/* Page header */
.mk-page-header {
  margin-bottom: var(--space-6);
}
.mk-page-header__title {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-family: var(--font-heading);
  font-size: var(--text-2xl);
  color: var(--color-text-primary);
  margin-bottom: var(--space-1);
}
.mk-page-header__subtitle {
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
}

/* Section headings */
.mk-section-heading {
  font-family: var(--font-heading);
  font-size: var(--text-lg);
  color: var(--color-text-primary);
  margin-bottom: var(--space-4);
  padding-bottom: var(--space-2);
  border-bottom: 1px solid var(--color-border);
}

/* Cards */
.mk-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow: hidden;
}
.mk-card--stub {
  opacity: 0.72;
}
.mk-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-raised);
}
.mk-card__title {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-family: var(--font-sans);
  font-size: var(--text-base);
  font-weight: 600;
  color: var(--color-text-primary);
}
.mk-card__link {
  font-size: var(--text-sm);
  color: var(--color-accent);
  text-decoration: none;
}
.mk-card__link:hover {
  color: var(--color-accent-hover);
  text-decoration: underline;
}
.mk-card__body {
  padding: var(--space-4);
}

/* Grid layouts */
.mk-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: var(--space-4);
  margin-bottom: var(--space-8);
}
.mk-grid--halves {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-4);
  margin-bottom: var(--space-6);
}
@media (max-width: 640px) {
  .mk-grid--halves {
    grid-template-columns: 1fr;
  }
}

/* Stat block */
.mk-stat {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}
.mk-stat__value {
  font-family: var(--font-mono);
  font-size: var(--text-2xl);
  font-weight: 700;
  color: var(--color-text-primary);
}
.mk-stat__label {
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
}

/* Metric row inside a site snapshot card */
.mk-metric-list {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
.mk-metric-list__item {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  font-size: var(--text-sm);
}
.mk-metric-list__label {
  color: var(--color-text-secondary);
}
.mk-metric-list__value {
  font-family: var(--font-mono);
  font-size: var(--text-sm);
  font-weight: 600;
  color: var(--color-text-primary);
}
.mk-metric-list__value--stub {
  color: var(--color-text-secondary);
}

/* Badges */
.mk-badge {
  display: inline-block;
  padding: 2px var(--space-2);
  border-radius: var(--radius-sm);
  font-size: var(--text-xs);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--color-surface);
  background: var(--color-text-secondary);
}
.mk-badge--bluesky   { background: #0085ff; color: #fff; }
.mk-badge--mastodon  { background: #6364ff; color: #fff; }
.mk-badge--linkedin  { background: #0a66c2; color: #fff; }
.mk-badge--reddit    { background: #ff4500; color: #fff; }
.mk-badge--twitter   { background: #000;    color: #fff; }
.mk-badge--instagram { background: #e1306c; color: #fff; }
.mk-badge--neutral   { background: var(--color-text-secondary); color: var(--color-surface); }
.mk-badge--stub      { background: var(--color-border); color: var(--color-text-secondary); }

/* Notice / empty states */
.mk-notice {
  font-size: var(--text-sm);
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-sm);
  color: var(--color-text-primary);
}
.mk-notice--warn {
  background: var(--color-warning-bg);
  border: 1px solid var(--color-warning);
  color: var(--color-warning);
}
.mk-notice--success {
  background: var(--color-success-bg);
  border: 1px solid var(--color-success);
  color: var(--color-success);
}
.mk-notice--info {
  background: var(--color-accent-soft);
  border: 1px solid var(--color-accent);
  color: var(--color-accent);
}
.mk-empty {
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
}
.mk-stub-notice {
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
}

/* Quick links nav bar */
.mk-quicklinks {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-bottom: var(--space-8);
}
.mk-quicklinks__link {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-accent);
  font-size: var(--text-sm);
  font-weight: 600;
  text-decoration: none;
  transition: background var(--transition-fast), border-color var(--transition-fast);
}
.mk-quicklinks__link:hover {
  background: var(--color-accent-soft);
  border-color: var(--color-accent);
  color: var(--color-accent-hover);
}
.mk-quicklinks__link--active {
  background: var(--color-accent-soft);
  border-color: var(--color-accent);
  color: var(--color-accent);
}
.mk-quicklinks__link--stub {
  color: var(--color-text-secondary);
  border-color: var(--color-border);
  cursor: default;
  pointer-events: none;
  opacity: 0.6;
}

/* Activity feed */
.mk-file-list {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
.mk-file-list__item {
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
  font-size: var(--text-sm);
  flex-wrap: wrap;
}
.mk-file-list__name {
  color: var(--color-accent);
  text-decoration: none;
  word-break: break-all;
}
.mk-file-list__name:hover {
  color: var(--color-accent-hover);
  text-decoration: underline;
}
.mk-file-list__time {
  font-size: var(--text-xs);
  color: var(--color-text-secondary);
  margin-left: auto;
  white-space: nowrap;
}

/* Legacy nav (kept for compatibility; hidden on landing since quicklinks replace it) */
.mk-nav {
  display: none;
}

/* Channel health table */
.mk-channel-health-table {
  width: 100%;
  border-collapse: collapse;
}
.mk-channel-health-table th,
.mk-channel-health-table td {
  padding: var(--space-2) var(--space-3);
  text-align: left;
  font-size: var(--text-sm);
  border-bottom: 1px solid var(--color-border);
  white-space: nowrap;
}
.mk-channel-health-table th {
  font-weight: 600;
  color: var(--color-text-secondary);
  background: var(--color-surface-raised);
}
.mk-channel-health-table tr:last-child td {
  border-bottom: none;
}
.mk-status--healthy {
  color: var(--color-success);
  font-weight: 600;
}
.mk-status--stale {
  color: var(--color-warning);
  font-weight: 600;
}
.mk-status--unknown {
  color: var(--muted, #6b7280);
}
.mk-status--error {
  color: var(--color-danger, #dc2626);
  font-weight: 600;
}
.mk-channel-health-scroll {
  overflow-x: auto;
}

/* Icon sizing */
.icon {
  width: 16px;
  height: 16px;
  flex-shrink: 0;
  vertical-align: middle;
}

/* Flags list */
.mk-flag-list { list-style: none; padding: 0; margin: 0; }
.mk-flag-list__item { padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border); }
.mk-flag-list__item:last-child { border-bottom: none; }
.mk-flag-list__item--warn { color: var(--color-warn, #b45309); }
.mk-flag-list__item--error { color: var(--color-error, #dc2626); }

/* Recommendations list */
.mk-recommendation-list { list-style: disc; padding-left: var(--space-6); margin: 0; }
.mk-recommendation-list__item { padding: var(--space-1) 0; }
.mk-recommendation-list__item--static { color: var(--muted, #6b7280); }

/* Upcoming Calendar */
.mk-calendar-date-divider {
  font-size: var(--text-xs);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-secondary);
  padding: var(--space-2) 0 var(--space-1);
  border-top: 1px solid var(--color-border);
  margin-top: var(--space-2);
}
.mk-calendar-date-divider:first-child {
  border-top: none;
  margin-top: 0;
}
.mk-calendar-item {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-2) 0;
  font-size: var(--text-sm);
}
.mk-calendar-item__time {
  color: var(--color-text-secondary);
  white-space: nowrap;
  flex-shrink: 0;
  min-width: 9rem;
}
.mk-calendar-item__preview {
  color: var(--color-text-primary);
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>

<div class="mk-dashboard">

  <!-- ── Page header ──────────────────────────────────────────────────────── -->
  <div class="mk-page-header">
    <div class="mk-page-header__text">
      <h1 class="mk-page-header__title">
        <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#megaphone"></use></svg>
        Marketing
      </h1>
      <p class="mk-page-header__subtitle">Glyc &amp; IBD Movement — content pipeline</p>
    </div>
  </div>

  <!-- ── Today's Digest ───────────────────────────────────────────────────── -->
  <section aria-labelledby="digest-heading">
    <h2 class="mk-section-heading" id="digest-heading">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#sun"></use></svg>
      Today's Digest
    </h2>

    <!-- Per-site snapshot cards -->
    <div class="mk-grid--halves">

      <!-- getglyc.com -->
      <div class="mk-card" aria-labelledby="card-glyc-title">
        <div class="mk-card__header">
          <h3 class="mk-card__title" id="card-glyc-title">
            <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#globe"></use></svg>
            getglyc.com
          </h3>
        </div>
        <div class="mk-card__body">
          <ul class="mk-metric-list">
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">Posts published</span>
              <?php if ($glycPostsPublished !== null): ?>
                <span class="mk-metric-list__value"><?= h((string)$glycPostsPublished) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">GSC clicks (7d)</span>
              <?php if ($glycGscClicks !== null): ?>
                <span class="mk-metric-list__value"><?= h((string)$glycGscClicks) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">GSC impressions (7d)</span>
              <?php if ($glycGscImpressions !== null): ?>
                <span class="mk-metric-list__value"><?= h((string)$glycGscImpressions) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">GSC CTR (7d)</span>
              <?php if ($glycGscCtr !== null): ?>
                <span class="mk-metric-list__value"><?= h(number_format((float)$glycGscCtr * 100, 1) . '%') ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">GSC avg position (7d)</span>
              <?php if ($glycGscPosition !== null): ?>
                <span class="mk-metric-list__value"><?= h(number_format((float)$glycGscPosition, 1)) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">GA4 users (7d)</span>
              <?php if ($glycGa4Users !== null): ?>
                <span class="mk-metric-list__value"
                  <?php if ($glycSparkline !== null): ?>data-sparkline="<?= h(implode(',', $glycSparkline)) ?>"<?php endif; ?>
                ><?= h((string)$glycGa4Users) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">Mastodon followers</span>
              <?php if ($glycMastoFollowers !== null): ?>
                <span class="mk-metric-list__value"><?= h((string)$glycMastoFollowers) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">Bluesky followers</span>
              <?php if ($glycBskyFollowers !== null): ?>
                <span class="mk-metric-list__value"><?= h((string)$glycBskyFollowers) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">X followers</span>
              <?php if ($glycXFollowers !== null): ?>
                <span class="mk-metric-list__value"><?= h((string)$glycXFollowers) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
          </ul>
        </div>
      </div>

      <!-- ibdmovement.com -->
      <div class="mk-card" aria-labelledby="card-ibd-title">
        <div class="mk-card__header">
          <h3 class="mk-card__title" id="card-ibd-title">
            <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#globe"></use></svg>
            ibdmovement.com
          </h3>
        </div>
        <div class="mk-card__body">
          <ul class="mk-metric-list">
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">Posts published</span>
              <?php if ($ibdPostsPublished !== null): ?>
                <span class="mk-metric-list__value"><?= h((string)$ibdPostsPublished) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">GSC clicks (7d)</span>
              <?php if ($ibdGscClicks !== null): ?>
                <span class="mk-metric-list__value"><?= h((string)$ibdGscClicks) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">GSC impressions (7d)</span>
              <?php if ($ibdGscImpressions !== null): ?>
                <span class="mk-metric-list__value"><?= h((string)$ibdGscImpressions) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">GSC CTR (7d)</span>
              <?php if ($ibdGscCtr !== null): ?>
                <span class="mk-metric-list__value"><?= h(number_format((float)$ibdGscCtr * 100, 1) . '%') ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">GSC avg position (7d)</span>
              <?php if ($ibdGscPosition !== null): ?>
                <span class="mk-metric-list__value"><?= h(number_format((float)$ibdGscPosition, 1)) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">GA4 users (7d)</span>
              <?php if ($ibdGa4Users !== null): ?>
                <span class="mk-metric-list__value"
                  <?php if ($ibdSparkline !== null): ?>data-sparkline="<?= h(implode(',', $ibdSparkline)) ?>"<?php endif; ?>
                ><?= h((string)$ibdGa4Users) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">Mastodon followers</span>
              <?php if ($ibdMastoFollowers !== null): ?>
                <span class="mk-metric-list__value"><?= h((string)$ibdMastoFollowers) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">Bluesky followers</span>
              <?php if ($ibdBskyFollowers !== null): ?>
                <span class="mk-metric-list__value"><?= h((string)$ibdBskyFollowers) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
            <li class="mk-metric-list__item">
              <span class="mk-metric-list__label">X followers</span>
              <?php if ($ibdXFollowers !== null): ?>
                <span class="mk-metric-list__value"><?= h((string)$ibdXFollowers) ?></span>
              <?php else: ?>
                <span class="mk-metric-list__value mk-metric-list__value--stub">&mdash;</span>
              <?php endif; ?>
            </li>
          </ul>
        </div>
      </div>

    </div><!-- /.mk-grid--halves -->

    <!-- Postiz status + Anomaly block side by side -->
    <div class="mk-grid--halves" style="margin-top: 0;">

      <!-- Postiz queue status -->
      <div class="mk-card" aria-labelledby="card-postiz-title">
        <div class="mk-card__header">
          <h3 class="mk-card__title" id="card-postiz-title">
            <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#calendar-clock"></use></svg>
            Postiz Queue
          </h3>
        </div>
        <div class="mk-card__body">
          <?php if (!$postizConfigured): ?>
            <p class="mk-empty">Postiz not configured.</p>
          <?php elseif ($postizError): ?>
            <p class="mk-notice mk-notice--warn">Could not reach Postiz — check bridge connection.</p>
          <?php elseif ($postizQueueCount !== null): ?>
            <div class="mk-stat">
              <span class="mk-stat__value"><?= $postizQueueCount ?></span>
              <span class="mk-stat__label">post<?= $postizQueueCount !== 1 ? 's' : '' ?> queued</span>
            </div>
          <?php else: ?>
            <p class="mk-empty">No queued posts.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Flags + Recommendations stacked in the right column -->
      <div style="display: flex; flex-direction: column; gap: var(--space-4);">

        <!-- Anomaly / Flags card -->
        <div class="mk-card" aria-labelledby="card-anomaly-title">
          <div class="mk-card__header">
            <h3 class="mk-card__title" id="card-anomaly-title">
              <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#alert-triangle"></use></svg>
              Flags
            </h3>
          </div>
          <div class="mk-card__body">
            <?php if (empty($activeFlags)): ?>
              <p class="mk-notice mk-notice--success">All clear — no anomalies detected.</p>
            <?php else: ?>
              <ul class="mk-flag-list">
                <?php foreach ($activeFlags as $flag): ?>
                  <li class="mk-flag-list__item mk-flag-list__item--<?= h($flag['severity']) ?>">
                    <?= h($flag['message']) ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>

        <!-- Recommendations card -->
        <div class="mk-card" aria-labelledby="card-recommendations-title">
          <div class="mk-card__header">
            <h3 class="mk-card__title" id="card-recommendations-title">
              <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#lightbulb"></use></svg>
              Recommendations
            </h3>
          </div>
          <div class="mk-card__body">
            <ul class="mk-recommendation-list">
              <?php foreach ($activeFlags as $flag): ?>
                <?php
                  // Derive action from flag message
                  $action = str_contains($flag['message'], 'Postiz ERROR')
                      ? 'Re-post failed content in Postiz'
                      : (str_contains($flag['message'], 'GSC clicks down')
                          ? 'Review GSC drop — check Search Console for indexing issues'
                          : (str_contains($flag['message'], 'engagement check-in')
                              ? 'Run engagement-check.py --all --update-log'
                              : $flag['message']));
                ?>
                <li class="mk-recommendation-list__item"><?= h($action) ?></li>
              <?php endforeach; ?>
              <li class="mk-recommendation-list__item mk-recommendation-list__item--static">Review Drafts Queue for ready-to-publish content</li>
              <li class="mk-recommendation-list__item mk-recommendation-list__item--static">Check upcoming calendar for scheduling gaps</li>
              <li class="mk-recommendation-list__item mk-recommendation-list__item--static">Update engagement log after check-ins</li>
            </ul>
          </div>
        </div>

      </div><!-- /stacked right column -->

    </div><!-- /.mk-grid--halves (postiz + anomaly) -->

  </section>

  <!-- ── Channel Health ──────────────────────────────────────────────────── -->
  <section aria-labelledby="channel-health-heading">
    <h2 class="mk-section-heading" id="channel-health-heading">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#activity"></use></svg>
      Channel Health
    </h2>
    <div class="mk-card" style="margin-bottom: var(--space-8);">
      <div class="mk-channel-health-scroll">
        <?php
          // Build row data for the 6 channels
          $channelRows = [
            ['platform' => 'Bluesky',  'badge' => 'bluesky',  'account' => 'Glyc',         'followers' => $glycBskyFollowers,  'platform_key' => 'glyc_bluesky'],
            ['platform' => 'Bluesky',  'badge' => 'bluesky',  'account' => 'IBD Movement',  'followers' => $ibdBskyFollowers,   'platform_key' => 'ibd_bluesky'],
            ['platform' => 'Mastodon', 'badge' => 'mastodon', 'account' => 'Glyc',         'followers' => $glycMastoFollowers, 'platform_key' => 'glyc_mastodon'],
            ['platform' => 'Mastodon', 'badge' => 'mastodon', 'account' => 'IBD Movement',  'followers' => $ibdMastoFollowers,  'platform_key' => 'ibd_mastodon'],
            ['platform' => 'X',        'badge' => 'twitter',  'account' => 'Glyc',         'followers' => $glycXFollowers,     'platform_key' => 'glyc_x'],
            ['platform' => 'X',        'badge' => 'twitter',  'account' => 'IBD Movement',  'followers' => $ibdXFollowers,      'platform_key' => 'ibd_x'],
          ];
        ?>
        <table class="mk-channel-health-table">
          <thead>
            <tr>
              <th scope="col">Platform</th>
              <th scope="col">Account</th>
              <th scope="col">Followers</th>
              <th scope="col">Posts (7d)</th>
              <th scope="col">Errors (7d)</th>
              <th scope="col">Last Post</th>
              <th scope="col">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($channelRows as $row): ?>
              <?php
                $key          = $row['platform_key'];
                $pData        = $postizByPlatform[$key] ?? null;
                $queued       = $pData !== null ? $pData['queued']        : null;
                $published7d  = $pData !== null ? $pData['published_7d']  : null;
                $errors7d     = $pData !== null ? $pData['errors_7d']     : null;
                $lastPublished = $pData !== null ? $pData['last_published'] : null;

                $status     = mkChannelStatus(
                    $errors7d,
                    $lastPublished,
                    $postizConfigured && !$postizError
                );
                $statusLabel = match ($status) {
                    'healthy' => 'Healthy',
                    'stale'   => 'Stale',
                    'error'   => 'Error',
                    'unknown' => '—',
                    default   => '—',
                };

                // Format last published as relative time
                $lastPostDisplay = '—';
                if ($lastPublished !== null) {
                    $ts = strtotime($lastPublished);
                    if ($ts !== false) {
                        $diff = time() - $ts;
                        if ($diff < 86400)          $lastPostDisplay = 'today';
                        elseif ($diff < 86400 * 2)  $lastPostDisplay = 'yesterday';
                        elseif ($diff < 86400 * 7)  $lastPostDisplay = (int)floor($diff / 86400) . 'd ago';
                        else                        $lastPostDisplay = (int)floor($diff / (86400 * 7)) . 'wk ago';
                    }
                }
              ?>
              <tr>
                <td><span class="mk-badge mk-badge--<?= h($row['badge']) ?>"><?= h($row['platform']) ?></span></td>
                <td><?= h($row['account']) ?></td>
                <td><?= $row['followers'] !== null ? h((string)$row['followers']) : '&mdash;' ?></td>
                <td><?= $published7d !== null ? h((string)$published7d) : '&mdash;' ?></td>
                <td><?= $errors7d !== null ? h((string)$errors7d) : '&mdash;' ?></td>
                <td><?= h($lastPostDisplay) ?></td>
                <td><span class="mk-status--<?= h($status) ?>"><?= h($statusLabel) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ── Quick links ──────────────────────────────────────────────────────── -->
  <section aria-labelledby="quicklinks-heading">
    <h2 class="mk-section-heading" id="quicklinks-heading">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#link"></use></svg>
      Quick Links
    </h2>
    <nav class="mk-quicklinks" aria-label="Marketing module quick links">
      <a href="/port/marketing/drafts.php" class="mk-quicklinks__link">
        <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#file-text"></use></svg>
        Drafts Queue
      </a>
      <a href="/port/marketing/published.php" class="mk-quicklinks__link">
        <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#send"></use></svg>
        Published Archive
      </a>
      <a href="/port/marketing/engagement.php" class="mk-quicklinks__link">
        <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#bar-chart-2"></use></svg>
        Engagement
      </a>
      <a href="/port/marketing/research.php" class="mk-quicklinks__link">
        <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#search"></use></svg>
        Research
      </a>
      <span class="mk-quicklinks__link mk-quicklinks__link--stub" title="Coming soon">
        <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#file-bar-chart"></use></svg>
        Reports
      </span>
    </nav>
  </section>

  <!-- ── Campaign Performance ───────────────────────────────────────────── -->
  <section aria-labelledby="campaign-heading">
    <h2 class="mk-section-heading" id="campaign-heading">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#bar-chart-2"></use></svg>
      Campaign Performance
    </h2>
    <div class="mk-card" style="margin-bottom: var(--space-8);">
      <?php if ($campaignData === null): ?>
        <div class="mk-card__body">
          <p class="mk-empty">No campaign data available.</p>
        </div>
      <?php elseif ($campaignData === []): ?>
        <div class="mk-card__body">
          <p class="mk-empty">No campaign data yet — UTM links needed for attribution</p>
        </div>
      <?php else: ?>
        <?php
          $totalSessions = 0;
          $totalSignups  = 0;
          foreach ($campaignData as $row) {
              $totalSessions += $row['sessions'];
              $totalSignups  += $row['signups'];
          }
        ?>
        <div class="mk-channel-health-scroll">
          <table class="mk-channel-health-table">
            <thead>
              <tr>
                <th scope="col">Source</th>
                <th scope="col">Medium</th>
                <th scope="col">Sessions (30d)</th>
                <th scope="col">Sign-ups</th>
                <th scope="col">Conv. Rate</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($campaignData as $row): ?>
                <?php $convRate = mkConvRate($row['sessions'], $row['signups']); ?>
                <tr>
                  <td><?= h($row['source']) ?></td>
                  <td><?= h($row['medium']) ?></td>
                  <td><?= h((string)$row['sessions']) ?></td>
                  <td><?= h((string)$row['signups']) ?></td>
                  <td><?= $convRate !== null ? h($convRate) : '&mdash;' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="2"><strong>Total</strong></td>
                <td><strong><?= h((string)$totalSessions) ?></strong></td>
                <td><strong><?= h((string)$totalSignups) ?></strong></td>
                <td><strong><?php
                  $totalConv = mkConvRate($totalSessions, $totalSignups);
                  echo $totalConv !== null ? h($totalConv) : '&mdash;';
                ?></strong></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <div class="mk-card__body" style="padding-top: var(--space-2); padding-bottom: var(--space-2);">
          <p class="mk-stub-notice">Source: GA4 UTM attribution, getglyc.com only (30d)</p>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ── Upcoming Calendar ────────────────────────────────────────────────── -->
  <section aria-labelledby="calendar-heading">
    <h2 class="mk-section-heading" id="calendar-heading">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#calendar"></use></svg>
      Upcoming Calendar
    </h2>
    <div class="mk-card" style="margin-bottom: var(--space-8);">
      <div class="mk-card__header">
        <h3 class="mk-card__title">Next 14 Days</h3>
        <?php if ($postizConfigured && $postizBaseUrl !== null): ?>
          <a href="<?= h($postizBaseUrl) ?>" class="mk-card__link" target="_blank" rel="noopener">Open Postiz</a>
        <?php endif; ?>
      </div>
      <div class="mk-card__body">
        <?php if (!$postizConfigured): ?>
          <p class="mk-empty">Postiz not configured.</p>
        <?php elseif ($calendarError): ?>
          <p class="mk-notice mk-notice--warn">Could not load calendar — check Postiz connection.</p>
        <?php elseif (empty($calendarPosts)): ?>
          <p class="mk-empty">Nothing scheduled in the next 14 days.
            <?php if ($postizBaseUrl !== null): ?>
              <a href="<?= h($postizBaseUrl) ?>" target="_blank" rel="noopener">Open Postiz</a>
            <?php endif; ?>
          </p>
        <?php else: ?>
          <?php
            $prevDate = null;
            foreach ($calendarPosts as $cp):
              $cpDate = '';
              $cpTs = isset($cp['publishDate']) ? strtotime($cp['publishDate']) : false;
              if ($cpTs !== false) {
                  $cpDate = date('Y-m-d', $cpTs);
              }
              $showDivider = ($cpDate !== $prevDate);
              $prevDate = $cpDate;
              $cpPlatform   = mkInferPlatform(
                  ($cp['integration']['providerIdentifier'] ?? '')
                  ?: ($cp['integration']['name'] ?? '')
                  ?: ($cp['content'][0]['group']['name'] ?? '')
              );
              $cpBadgeClass = mkPlatformBadgeClass($cpPlatform);
              $cpContent    = $cp['content'][0]['content'] ?? ($cp['content'] ?? '');
              if (is_array($cpContent)) {
                  $cpContent = '';
              }
              $cpPreview    = mkCalendarPreview((string)$cpContent);
              $cpFormatted  = isset($cp['publishDate']) ? mkCalendarFormatDate($cp['publishDate']) : '';
          ?>
            <?php if ($showDivider && $cpDate !== ''): ?>
              <div class="mk-calendar-date-divider"><?= h(date('D M j', strtotime($cpDate))) ?></div>
            <?php endif; ?>
            <div class="mk-calendar-item">
              <span class="mk-calendar-item__time"><?= h($cpFormatted) ?></span>
              <span class="mk-badge mk-badge--<?= h($cpBadgeClass) ?>"><?= h($cpPlatform) ?></span>
              <span class="mk-calendar-item__preview"><?= h($cpPreview) ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ── Recent Activity ──────────────────────────────────────────────────── -->
  <section aria-labelledby="activity-heading">
    <h2 class="mk-section-heading" id="activity-heading">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#activity"></use></svg>
      Recent Activity
    </h2>
    <div class="mk-card" style="margin-bottom: var(--space-8);">
      <div class="mk-card__header">
        <h3 class="mk-card__title">Last 5 Published Posts</h3>
        <?php if (!empty($recentPostizActivity) && $postizBaseUrl): ?>
          <a href="<?= h(rtrim($postizBaseUrl, '/')) ?>" class="mk-card__link" target="_blank" rel="noopener">View in Postiz</a>
        <?php else: ?>
          <a href="/port/marketing/published.php" class="mk-card__link">View all</a>
        <?php endif; ?>
      </div>
      <div class="mk-card__body">
        <?php if (!empty($recentPostizActivity)): ?>
          <!-- Postiz-sourced activity (preferred when bridge/API is configured) -->
          <ul class="mk-file-list">
            <?php foreach ($recentPostizActivity as $pPost): ?>
              <?php
                $pPlatform   = mkPostizPostPlatform($pPost);
                $pBadge      = mkPlatformBadgeClass($pPlatform);
                $pContent    = $pPost['content'] ?? $pPost['posts'][0]['content'] ?? '';
                $pPreview    = mb_strlen($pContent) > 80
                    ? mb_substr(mkCalendarStripHtml($pContent), 0, 80) . '…'
                    : mkCalendarStripHtml($pContent);
                $pTs         = strtotime($pPost['publishDate'] ?? $pPost['publishedAt'] ?? $pPost['createdAt'] ?? '');
                $pTime       = $pTs ? mkRelativeTimeTs($pTs) : '';
              ?>
              <li class="mk-file-list__item">
                <span class="mk-badge mk-badge--<?= h($pBadge) ?>"><?= h($pPlatform) ?></span>
                <span class="mk-file-list__name"><?= h($pPreview ?: '(no content)') ?></span>
                <?php if ($pTime !== ''): ?>
                  <span class="mk-file-list__time"><?= h($pTime) ?></span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php elseif ($publishedError): ?>
          <p class="mk-notice mk-notice--warn">Could not reach GitHub API.</p>
        <?php elseif ($publishedMissing || empty($recentPublished)): ?>
          <p class="mk-empty">No recent activity.</p>
        <?php else: ?>
          <ul class="mk-file-list">
            <?php foreach ($recentPublished as $file): ?>
              <?php
                $platform   = mkInferPlatform($file['path'] ?? $file['name']);
                $badgeClass = mkPlatformBadgeClass($platform);
                $encodedPath = urlencode($file['path'] ?? $file['name']);
                $relTime    = mkRelativeTime($file['name']);
              ?>
              <li class="mk-file-list__item">
                <span class="mk-badge mk-badge--<?= h($badgeClass) ?>"><?= h($platform) ?></span>
                <a href="/port/marketing/render.php?repo=glyc&amp;path=<?= $encodedPath ?>" class="mk-file-list__name">
                  <?= h($file['name']) ?>
                </a>
                <?php if ($relTime !== ''): ?>
                  <span class="mk-file-list__time"><?= h($relTime) ?></span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ── Drafts Queue card ────────────────────────────────────────────────── -->
  <section aria-labelledby="drafts-section-heading">
    <h2 class="mk-section-heading" id="drafts-section-heading">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#inbox"></use></svg>
      Drafts Queue
    </h2>
    <div class="mk-grid" style="margin-bottom: var(--space-8);">

      <section class="mk-card" aria-labelledby="card-drafts-title">
        <div class="mk-card__header">
          <h3 class="mk-card__title" id="card-drafts-title">
            <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#file-text"></use></svg>
            Drafts Queue
          </h3>
          <a href="/port/marketing/drafts.php" class="mk-card__link">View all</a>
        </div>
        <div class="mk-card__body">
          <?php if ($queueError): ?>
            <p class="mk-notice mk-notice--warn">Could not read drafts queue — check MK_WORKSPACE_PATH or MK_GITHUB_TOKEN.</p>
          <?php elseif ($queueMissing): ?>
            <p class="mk-empty">No queue directory found — create
              <code>docs/marketing/workspace/queue/</code> to start using the drafts queue.</p>
          <?php elseif ($draftCount === 0): ?>
            <p class="mk-empty">No drafts in queue.</p>
          <?php else: ?>
            <div class="mk-stat">
              <span class="mk-stat__value"><?= $draftCount ?></span>
              <span class="mk-stat__label">draft<?= $draftCount !== 1 ? 's' : '' ?> queued</span>
            </div>
          <?php endif; ?>
        </div>
      </section>

    </div><!-- /.mk-grid -->
  </section>

</div><!-- /.mk-dashboard -->

<?php renderFooter(); ?>
