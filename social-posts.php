<?php
/**
 * Marketing module — Social Posts
 *
 * Journal-style list of Postiz social media posts with inline edit
 * and publish capability.
 *
 * Fetches all posts via Postiz public API, groups by date, and renders
 * with expandable inline panels for editing, scheduling, and publishing.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once PORT_ROOT . '/shared/shell.php';

$user      = requireModuleAccess('marketing', 'viewer');
$csrfToken = generateCsrfToken();

// ── Postiz + bridge configuration ────────────────────────────────────────────
$postizConfigured  = false;
$postizBaseUrl     = null;
$postizAuthHeader  = null;
$bridgeBaseUrl     = null;
$bridgeAuthHeader  = null;

if (defined('MK_BRIDGE_URL') && MK_BRIDGE_URL !== '' &&
    defined('MK_BRIDGE_TOKEN') && MK_BRIDGE_TOKEN !== '') {
    $bridgeBaseUrl    = rtrim(MK_BRIDGE_URL, '/');
    $bridgeAuthHeader = 'Authorization: Bearer ' . MK_BRIDGE_TOKEN;
    $postizBaseUrl    = $bridgeBaseUrl . '/postiz';
    $postizAuthHeader = $bridgeAuthHeader;
    $postizConfigured = true;
} elseif (defined('MK_POSTIZ_URL') && MK_POSTIZ_URL !== '' &&
          defined('MK_POSTIZ_TOKEN') && MK_POSTIZ_TOKEN !== '') {
    $postizBaseUrl    = rtrim(MK_POSTIZ_URL, '/');
    $postizAuthHeader = 'Authorization: Bearer ' . MK_POSTIZ_TOKEN;
    $postizConfigured = true;
}

// ── Fetch posts ───────────────────────────────────────────────────────────────
$posts      = [];
$postizError = false;
$integrations = [];

if ($postizConfigured) {
    $startDate = urlencode(date('c', strtotime('-30 days')));
    $endDate   = urlencode(date('c', strtotime('+30 days')));
    $postsUrl  = $postizBaseUrl . '/api/public/v1/posts?startDate=' . $startDate
               . '&endDate=' . $endDate . '&take=500';

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => $postizAuthHeader . "\r\nUser-Agent: bennernet-marketing/1.0",
        'timeout'       => 8,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($postsUrl, false, $ctx);
    if ($body === false) {
        $postizError = true;
    } else {
        $data = json_decode($body, true);
        if (is_array($data)) {
            $posts = $data['posts'] ?? (isset($data[0]) ? $data : []);
            if (!is_array($posts)) {
                $posts       = [];
                $postizError = true;
            }
        } else {
            $postizError = true;
        }
    }

    // Fetch integrations for account name display
    if (!$postizError) {
        $intgUrl = $postizBaseUrl . '/api/public/v1/integrations';
        $intgCtx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => $postizAuthHeader . "\r\nUser-Agent: bennernet-marketing/1.0",
            'timeout'       => 6,
            'ignore_errors' => true,
        ]]);
        $intgBody = @file_get_contents($intgUrl, false, $intgCtx);
        if ($intgBody) {
            $intgData = json_decode($intgBody, true);
            if (is_array($intgData)) {
                // Key by ID for fast lookup
                $list = $intgData['integrations'] ?? (isset($intgData[0]) ? $intgData : []);
                if (is_array($list)) {
                    foreach ($list as $intg) {
                        if (isset($intg['id'])) {
                            $integrations[$intg['id']] = $intg;
                        }
                    }
                }
            }
        }
    }
}

// ── Bulk-fetch images from DB via bridge ──────────────────────────────────────
// The Postiz public API does not return image URLs. Fetch them from the DB
// in a single batch call to avoid per-post queries.
$postImages = [];  // [postId => imageUrl]
$postImageAlt = []; // [postId => altText|null]
if (!$postizError && !empty($posts) && $bridgeBaseUrl !== null) {
    $postIds    = array_values(array_filter(array_column($posts, 'id')));
    $imgPayload = json_encode(['action' => 'fetch_images_bulk', 'ids' => $postIds]);
    $imgCtx     = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => ($bridgeAuthHeader ?? '') . "\r\nContent-Type: application/json\r\nUser-Agent: bennernet-marketing/1.0",
        'content'       => $imgPayload,
        'timeout'       => 8,
        'ignore_errors' => true,
    ]]);
    $imgBody = @file_get_contents($bridgeBaseUrl . '/postiz-db', false, $imgCtx);
    if ($imgBody) {
        $imgData = json_decode($imgBody, true);
        if (is_array($imgData) && ($imgData['ok'] ?? false)) {
            $postImages   = $imgData['images'] ?? [];
            $postImageAlt = $imgData['imageAlt'] ?? [];
        }
    }
}

// ── Fallback og:image for posts with no stored image ──────────────────────────
// Facebook posts always have image:[] (platform rule prevents 1366051 error),
// so we fetch og:image from the linked URL server-side for Port preview.
$postOgImages    = [];  // [postId => ogImageUrl]
$ogFetchedByUrl  = [];  // cache within request: [url => ogImageUrl|false]
if (!$postizError && !empty($posts)) {
    foreach ($posts as $p) {
        $pid = $p['id'] ?? '';
        if (!$pid || isset($postImages[$pid])) {
            continue;  // already has a stored image
        }
        $txt = $p['content'] ?? '';
        if (!preg_match('#https?://[^\s<>"\']+#', $txt, $m)) {
            continue;  // no URL in content to derive og:image from
        }
        $targetUrl = rtrim($m[0], '.,;!?)');
        if (!array_key_exists($targetUrl, $ogFetchedByUrl)) {
            $ogCtx = stream_context_create(['http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: Googlebot/2.1 (+http://www.google.com/bot.html)\r\n",
                'timeout' => 5,
                'ignore_errors' => true,
            ]]);
            $html = @file_get_contents($targetUrl, false, $ogCtx);
            $ogUrl = null;
            if ($html) {
                // og:image can appear in either attribute order
                if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $mm)
                 || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>/i', $html, $mm)) {
                    $ogUrl = $mm[1];
                }
            }
            $ogFetchedByUrl[$targetUrl] = $ogUrl;
        }
        if ($ogFetchedByUrl[$targetUrl]) {
            $postOgImages[$pid] = $ogFetchedByUrl[$targetUrl];
        }
    }
}

// ── Active filter tab ─────────────────────────────────────────────────────────
$validFilters = ['all', 'draft', 'scheduled', 'published', 'error'];
$activeFilter = strtolower($_GET['filter'] ?? 'all');
if (!in_array($activeFilter, $validFilters, true)) {
    $activeFilter = 'all';
}

// ── Apply filter ──────────────────────────────────────────────────────────────
$filteredPosts = array_filter($posts, function (array $p) use ($activeFilter): bool {
    $state = strtoupper($p['state'] ?? '');
    return match ($activeFilter) {
        'draft'     => $state === 'DRAFT',
        'scheduled' => $state === 'QUEUE',
        'published' => $state === 'PUBLISHED',
        'error'     => $state === 'ERROR',
        default     => true,
    };
});

// ── Group by date, newest first ───────────────────────────────────────────────
usort($filteredPosts, fn($a, $b) => strcmp(
    $b['publishDate'] ?? $b['createdAt'] ?? '',
    $a['publishDate'] ?? $a['createdAt'] ?? ''
));

$byDate = [];
foreach ($filteredPosts as $post) {
    $ts       = strtotime($post['publishDate'] ?? $post['createdAt'] ?? '');
    $dateKey  = $ts ? date('Y-m-d', $ts) : 'unknown';
    $byDate[$dateKey][] = $post;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Format a date key (Y-m-d) as a human-readable label, e.g. "Saturday, June 7".
 */
function mkFormatDateHeader(string $dateKey): string {
    if ($dateKey === 'unknown') {
        return 'Unknown Date';
    }
    $ts = strtotime($dateKey);
    return $ts ? date('l, F j', $ts) : $dateKey;
}

/**
 * Derive a human-readable platform name from a Postiz post object.
 */
function mkSocialPostPlatform(array $post): string {
    $intg = $post['integration'] ?? [];
    $type = strtolower($intg['type'] ?? $intg['provider'] ?? $intg['providerIdentifier'] ?? '');
    if (str_contains($type, 'bluesky') || str_contains($type, 'bsky')) return 'Bluesky';
    if (str_contains($type, 'mastodon'))                                return 'Mastodon';
    if (str_contains($type, 'twitter') || $type === 'x')               return 'X/Twitter';
    if (str_contains($type, 'linkedin'))                                return 'LinkedIn';
    if (str_contains($type, 'threads'))                                 return 'Threads';
    if (str_contains($type, 'instagram'))                               return 'Instagram';
    if (str_contains($type, 'facebook'))                                return 'Facebook';
    if (str_contains($type, 'reddit'))                                  return 'Reddit';
    $name = strtolower($intg['name'] ?? '');
    if (str_contains($name, 'bluesky') || str_contains($name, 'bsky')) return 'Bluesky';
    if (str_contains($name, 'mastodon') || str_contains($name, 'masto')) return 'Mastodon';
    if (str_contains($name, 'twitter') || str_contains($name, 'x'))    return 'X/Twitter';
    if (str_contains($name, 'linkedin'))                                return 'LinkedIn';
    if (str_contains($name, 'threads'))                                 return 'Threads';
    if (str_contains($name, 'instagram'))                               return 'Instagram';
    if (str_contains($name, 'facebook'))                                return 'Facebook';
    if (str_contains($name, 'reddit'))                                  return 'Reddit';
    return 'Social';
}

/**
 * Return CSS class suffix for a platform badge.
 */
function mkSocialPlatformBadgeClass(string $platform): string {
    return match ($platform) {
        'Bluesky'   => 'bluesky',
        'Mastodon'  => 'mastodon',
        'LinkedIn'  => 'linkedin',
        'Reddit'    => 'reddit',
        'X/Twitter' => 'twitter',
        'Instagram' => 'instagram',
        'Threads'   => 'threads',
        'Facebook'  => 'facebook',
        default     => 'neutral',
    };
}

/**
 * Return CSS class suffix for a state badge.
 * DRAFT=secondary, QUEUE=accent, PUBLISHED=success, ERROR=danger
 */
function mkStateBadgeClass(string $state): string {
    return match (strtoupper($state)) {
        'DRAFT'     => 'draft',
        'QUEUE'     => 'queue',
        'PUBLISHED' => 'published',
        'ERROR'     => 'error',
        default     => 'neutral',
    };
}

/**
 * Human-readable state label.
 */
function mkStateLabel(string $state): string {
    return match (strtoupper($state)) {
        'DRAFT'     => 'Draft',
        'QUEUE'     => 'Scheduled',
        'PUBLISHED' => 'Published',
        'ERROR'     => 'Error',
        default     => ucfirst(strtolower($state)),
    };
}

/**
 * Count posts by state for filter tab badges.
 */
function mkCountByState(array $posts, string $state): int {
    return count(array_filter($posts, fn($p) => strtoupper($p['state'] ?? '') === strtoupper($state)));
}

// Tab counts
$countAll       = count($posts);
$countDraft     = mkCountByState($posts, 'DRAFT');
$countScheduled = mkCountByState($posts, 'QUEUE');
$countPublished = mkCountByState($posts, 'PUBLISHED');
$countError     = mkCountByState($posts, 'ERROR');

renderHeader('Social Posts — Marketing', [
    'user'        => $user,
    'module_slug' => 'marketing',
    'breadcrumb'  => [
        ['label' => 'Port',         'url' => '/port/'],
        ['label' => 'Marketing',    'url' => '/port/marketing/'],
        ['label' => 'Social Posts', 'url' => null],
    ],
]);
?>

<style>
/* ── Social Posts page — scoped styles ───────────────────────────────────────
   All colors use Port CSS token variables. No hardcoded hex values.         */

/* Page wrapper */
.mk-page {
  padding: var(--space-6);
  max-width: 900px;
  font-family: var(--font-sans);
}

/* Page header row */
.mk-page-header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-bottom: var(--space-6);
}
.mk-page-header__title {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-family: var(--font-heading);
  font-size: var(--text-2xl);
  color: var(--color-text-primary);
  margin: 0;
}
.mk-back-link {
  font-size: var(--text-sm);
  color: var(--color-accent);
  text-decoration: none;
  white-space: nowrap;
}
.mk-back-link:hover {
  color: var(--color-accent-hover);
  text-decoration: underline;
}

/* Icon sizing */
.icon {
  width: 16px;
  height: 16px;
  flex-shrink: 0;
  vertical-align: middle;
}

/* Badges — shared base already in index.php inline style, re-declare here */
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
  white-space: nowrap;
}
.mk-badge--bluesky   { background: #0085ff; color: #fff; }
.mk-badge--mastodon  { background: #6364ff; color: #fff; }
.mk-badge--linkedin  { background: #0a66c2; color: #fff; }
.mk-badge--reddit    { background: #ff4500; color: #fff; }
.mk-badge--twitter   { background: #000;    color: #fff; }
.mk-badge--instagram { background: #e1306c; color: #fff; }
.mk-badge--threads   { background: #000;    color: #fff; }
.mk-badge--facebook  { background: #1877f2; color: #fff; }
.mk-badge--neutral   { background: var(--color-text-secondary); color: var(--color-surface); }

/* State badges */
.mk-state-badge {
  display: inline-block;
  padding: 2px var(--space-2);
  border-radius: var(--radius-sm);
  font-size: var(--text-xs);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  white-space: nowrap;
}
.mk-state-badge--draft     { background: var(--color-border); color: var(--color-text-secondary); }
.mk-state-badge--queue     { background: var(--color-accent-soft); color: var(--color-accent); border: 1px solid var(--color-accent); }
.mk-state-badge--published { background: var(--color-success-bg); color: var(--color-success); border: 1px solid var(--color-success); }
.mk-state-badge--error     { background: var(--color-danger-bg); color: var(--color-danger); border: 1px solid var(--color-danger); }
.mk-state-badge--neutral   { background: var(--color-border); color: var(--color-text-secondary); }

/* Notice / empty */
.mk-notice {
  font-size: var(--text-sm);
  padding: var(--space-3) var(--space-4);
  border-radius: var(--radius);
  color: var(--color-text-primary);
  margin-bottom: var(--space-4);
}
.mk-notice--warn {
  background: var(--color-warning-bg);
  border: 1px solid var(--color-warning);
  color: var(--color-warning);
}
.mk-notice--info {
  background: var(--color-accent-soft);
  border: 1px solid var(--color-accent);
  color: var(--color-accent);
}
.mk-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-12) var(--space-4);
  color: var(--color-text-secondary);
  font-size: var(--text-sm);
}
.mk-empty-state__icon {
  width: 40px;
  height: 40px;
  opacity: 0.4;
}

/* Filter tabs */
.mk-filter-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
  margin-bottom: var(--space-6);
  border-bottom: 1px solid var(--color-border);
  padding-bottom: var(--space-3);
}
.mk-filter-tabs__tab {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-sm) var(--radius-sm) 0 0;
  font-size: var(--text-sm);
  font-weight: 600;
  color: var(--color-text-secondary);
  text-decoration: none;
  border: 1px solid transparent;
  border-bottom: none;
  transition: color var(--transition-fast), background var(--transition-fast);
}
.mk-filter-tabs__tab:hover {
  color: var(--color-text-primary);
  background: var(--color-surface-raised);
}
.mk-filter-tabs__tab--active {
  color: var(--color-accent);
  background: var(--color-surface);
  border-color: var(--color-border);
  border-bottom-color: var(--color-surface);
}
.mk-filter-tabs__count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  background: var(--color-border);
  color: var(--color-text-secondary);
  border-radius: 9999px;
  font-size: var(--text-xs);
  font-weight: 700;
}
.mk-filter-tabs__tab--active .mk-filter-tabs__count {
  background: var(--color-accent-soft);
  color: var(--color-accent);
}

/* Date group */
.mk-date-group {
  margin-bottom: var(--space-8);
}
.mk-date-group__header {
  font-size: var(--text-xs);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--color-text-secondary);
  padding-bottom: var(--space-2);
  margin-bottom: var(--space-3);
  border-bottom: 1px solid var(--color-border);
}

/* Post list */
.mk-post-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

/* Post card row */
.mk-post-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  overflow: hidden;
  transition: border-color var(--transition-fast);
}
.mk-post-card:hover {
  border-color: var(--color-accent);
}
.mk-post-card--expanded {
  border-color: var(--color-accent);
}

/* Post card summary row (always visible) */
.mk-post-card__summary {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  cursor: pointer;
  user-select: none;
  width: 100%;
  background: none;
  border: none;
  text-align: left;
  color: inherit;
  font-family: inherit;
}
.mk-post-card__summary:focus-visible {
  outline: 2px solid var(--color-focus-ring);
  outline-offset: -2px;
}
.mk-post-card__platform {
  flex-shrink: 0;
}
.mk-post-card__account {
  font-size: var(--text-xs);
  color: var(--color-text-secondary);
  white-space: nowrap;
  flex-shrink: 0;
  min-width: 80px;
}
.mk-post-card__preview {
  flex: 1;
  font-size: var(--text-sm);
  color: var(--color-text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
}
.mk-post-card__meta {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  flex-shrink: 0;
}
.mk-post-card__time {
  font-size: var(--text-xs);
  color: var(--color-text-secondary);
  font-family: var(--font-mono);
  white-space: nowrap;
}
.mk-post-card__chevron {
  width: 14px;
  height: 14px;
  color: var(--color-text-secondary);
  flex-shrink: 0;
  transition: transform var(--transition-fast);
}
.mk-post-card--expanded .mk-post-card__chevron {
  transform: rotate(180deg);
}

/* Inline expand panel (max-height transition) */
.mk-post-panel {
  max-height: 0;
  overflow: hidden;
  transition: max-height 0.3s ease;
}
.mk-post-panel--open {
  max-height: 1200px;
}
.mk-post-panel__inner {
  padding: var(--space-4);
  border-top: 1px solid var(--color-border);
  background: var(--color-surface-raised);
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

/* Image area */
.mk-post-panel__image-wrap {
  background: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.mk-post-panel__image {
  max-width: 100%;
  max-height: 280px;
  object-fit: contain;
  display: block;
}
.mk-post-panel__image-placeholder {
  padding: var(--space-6);
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
  text-align: center;
}
.mk-post-panel__image-note {
  font-size: var(--text-xs, 0.75rem);
  color: var(--color-text-secondary);
  text-align: center;
  padding: var(--space-1) var(--space-2);
  background: var(--color-bg-secondary, #f5f5f5);
  border-top: 1px solid var(--color-border);
  margin: 0;
}

/* Published URL */
.mk-post-panel__release-url {
  font-size: var(--text-sm);
}
.mk-post-panel__release-url a {
  color: var(--color-accent);
  word-break: break-all;
}
.mk-post-panel__release-url a:hover {
  color: var(--color-accent-hover);
}

/* Textarea */
.mk-post-panel__textarea {
  width: 100%;
  min-height: 120px;
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  color: var(--color-text-primary);
  font-family: var(--font-sans);
  font-size: var(--text-sm);
  resize: vertical;
  transition: border-color var(--transition-fast);
}
.mk-post-panel__textarea:focus {
  outline: none;
  border-color: var(--color-accent);
}
.mk-post-panel__textarea[readonly] {
  color: var(--color-text-secondary);
  background: var(--color-bg);
  cursor: default;
}

/* Alt text */
.mk-post-panel__alt-label {
  display: block;
  font-size: var(--text-xs, 0.75rem);
  color: var(--color-text-secondary);
  margin-top: var(--space-2);
  margin-bottom: var(--space-1);
}
.mk-post-panel__alt-hint {
  font-weight: normal;
  opacity: 0.75;
}
.mk-post-panel__alt-input {
  width: 100%;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  color: var(--color-text-primary);
  font-family: var(--font-sans);
  font-size: var(--text-sm);
  transition: border-color var(--transition-fast);
}
.mk-post-panel__alt-input:focus {
  outline: none;
  border-color: var(--color-accent);
}
.mk-post-panel__alt-input[readonly] {
  color: var(--color-text-secondary);
  background: var(--color-bg);
  cursor: default;
}

/* Actions row */
.mk-post-panel__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
.mk-post-panel__schedule-row {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  flex-wrap: wrap;
}
.mk-post-panel__datetime {
  padding: var(--space-1) var(--space-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  color: var(--color-text-primary);
  font-family: var(--font-mono);
  font-size: var(--text-sm);
  transition: border-color var(--transition-fast);
}
.mk-post-panel__datetime:focus {
  outline: none;
  border-color: var(--color-accent);
}

/* Feedback message inside panel */
.mk-post-panel__feedback {
  font-size: var(--text-sm);
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-sm);
  display: none;
}
.mk-post-panel__feedback--success {
  background: var(--color-success-bg);
  border: 1px solid var(--color-success);
  color: var(--color-success);
}
.mk-post-panel__feedback--error {
  background: var(--color-danger-bg);
  border: 1px solid var(--color-danger);
  color: var(--color-danger);
}

/* Count summary */
.mk-count-summary {
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
  margin-bottom: var(--space-4);
}

/* Responsive: hide account name on tiny screens */
@media (max-width: 480px) {
  .mk-post-card__account { display: none; }
}

/* Confirm modal */
.mk-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-4);
}
.mk-modal-backdrop[hidden] { display: none; }
.mk-modal {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  padding: var(--space-6);
  max-width: 420px;
  width: 100%;
  box-shadow: 0 8px 32px rgba(0,0,0,0.18);
}
.mk-modal__title {
  font-family: var(--font-heading);
  font-size: var(--text-lg);
  font-weight: 700;
  color: var(--color-text-primary);
  margin: 0 0 var(--space-3);
}
.mk-modal__body {
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
  margin: 0 0 var(--space-6);
  line-height: 1.5;
}
.mk-modal__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-2);
}
</style>

<meta name="csrf-token" content="<?= h($csrfToken) ?>">

<div class="mk-page">

  <!-- ── Page header ───────────────────────────────────────────────────────── -->
  <div class="mk-page-header">
    <h1 class="mk-page-header__title">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#calendar-days"></use></svg>
      Social Posts
    </h1>
    <a href="/port/marketing/" class="mk-back-link">← Dashboard</a>
  </div>

  <?php if (!$postizConfigured): ?>
    <div class="mk-notice mk-notice--warn" role="alert">
      Postiz not configured — add <code>MK_POSTIZ_URL</code> and <code>MK_POSTIZ_TOKEN</code>
      to <code>config.php</code>.
    </div>

  <?php elseif ($postizError): ?>
    <div class="mk-notice mk-notice--warn" role="alert">
      Could not reach Postiz API. Check that the service is running and
      <code>MK_POSTIZ_URL</code> / <code>MK_POSTIZ_TOKEN</code> are correct.
    </div>

  <?php else: ?>

    <!-- ── Filter tabs ─────────────────────────────────────────────────────── -->
    <nav class="mk-filter-tabs" aria-label="Filter posts by state">
      <?php
        $tabs = [
            'all'       => ['label' => 'All',       'count' => $countAll],
            'draft'     => ['label' => 'Drafts',    'count' => $countDraft],
            'scheduled' => ['label' => 'Scheduled', 'count' => $countScheduled],
            'published' => ['label' => 'Published', 'count' => $countPublished],
            'error'     => ['label' => 'Errors',    'count' => $countError],
        ];
        foreach ($tabs as $key => $tab):
          $isActive = ($activeFilter === $key);
          $url      = '?filter=' . urlencode($key);
      ?>
        <a href="<?= h($url) ?>"
           class="mk-filter-tabs__tab<?= $isActive ? ' mk-filter-tabs__tab--active' : '' ?>"
           <?= $isActive ? 'aria-current="page"' : '' ?>>
          <?= h($tab['label']) ?>
          <span class="mk-filter-tabs__count"><?= $tab['count'] ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if (empty($byDate)): ?>
      <div class="mk-empty-state">
        <svg class="icon mk-empty-state__icon" aria-hidden="true">
          <use href="/port/shared/assets/icons/lucide.svg#inbox"></use>
        </svg>
        <p>No posts found<?= $activeFilter !== 'all' ? ' for this filter' : '' ?>.</p>
      </div>

    <?php else: ?>

      <p class="mk-count-summary"><?= count($filteredPosts) ?> post<?= count($filteredPosts) !== 1 ? 's' : '' ?></p>

      <?php foreach ($byDate as $dateKey => $datePosts): ?>
        <section class="mk-date-group" aria-labelledby="date-<?= h($dateKey) ?>">
          <h2 class="mk-date-group__header" id="date-<?= h($dateKey) ?>">
            <?= h(mkFormatDateHeader($dateKey)) ?>
          </h2>
          <ul class="mk-post-list">
            <?php foreach ($datePosts as $post):
              $postId    = $post['id'] ?? '';
              $state     = strtoupper($post['state'] ?? 'DRAFT');
              $content   = $post['content'] ?? '';
              $preview   = mb_strlen($content) > 100
                         ? mb_substr($content, 0, 97) . '…'
                         : $content;
              $platform  = mkSocialPostPlatform($post);
              $platBadge = mkSocialPlatformBadgeClass($platform);
              $stateCls  = mkStateBadgeClass($state);
              $stateLabel = mkStateLabel($state);

              // Account name from integration
              $intgId     = $post['integration']['id'] ?? ($post['integrationId'] ?? '');
              $intgObj    = $integrations[$intgId] ?? ($post['integration'] ?? []);
              $accountName = $intgObj['name'] ?? $intgObj['displayName'] ?? '';

              // Time
              $ts   = strtotime($post['publishDate'] ?? $post['createdAt'] ?? '');
              $time = $ts ? date('g:i A', $ts) : '';

              // Image URL — DB-stored image preferred; og:image from linked URL as fallback
              // (Facebook posts always have image:[] in DB; og:image shown for preview only)
              $imageUrl   = $postImages[$postId] ?? null;
              $isOgImage  = false;
              if (!$imageUrl && isset($postOgImages[$postId])) {
                  $imageUrl  = $postOgImages[$postId];
                  $isOgImage = true;
              }

              $releaseUrl = $post['releaseURL'] ?? $post['releaseUrl'] ?? null;
              $isPublished = ($state === 'PUBLISHED');
              $isDraft     = ($state === 'DRAFT');
              $panelId     = 'panel-' . preg_replace('/[^a-z0-9]/i', '-', $postId);
              $summaryId   = 'summary-' . preg_replace('/[^a-z0-9]/i', '-', $postId);

              // Scheduled datetime for input default value
              $scheduledDt = '';
              if (!empty($post['publishDate'])) {
                  $dts = strtotime($post['publishDate']);
                  if ($dts) {
                      $scheduledDt = date('Y-m-d\TH:i', $dts);
                  }
              }
            ?>
            <li class="mk-post-card" id="card-<?= h($postId) ?>"
                data-post-id="<?= h($postId) ?>"
                data-state="<?= h($state) ?>">

              <!-- Summary row (toggle) -->
              <button type="button"
                      class="mk-post-card__summary"
                      aria-expanded="false"
                      aria-controls="<?= h($panelId) ?>"
                      id="<?= h($summaryId) ?>">
                <span class="mk-post-card__platform">
                  <span class="mk-badge mk-badge--<?= h($platBadge) ?>"><?= h($platform) ?></span>
                </span>
                <?php if ($accountName): ?>
                  <span class="mk-post-card__account"><?= h($accountName) ?></span>
                <?php endif; ?>
                <span class="mk-post-card__preview"><?= h($preview ?: '(no content)') ?></span>
                <span class="mk-post-card__meta">
                  <span class="mk-state-badge mk-state-badge--<?= h($stateCls) ?>"><?= h($stateLabel) ?></span>
                  <?php if ($time): ?>
                    <span class="mk-post-card__time"><?= h($time) ?></span>
                  <?php endif; ?>
                </span>
                <svg class="mk-post-card__chevron" aria-hidden="true"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
              </button>

              <!-- Expand panel -->
              <div class="mk-post-panel"
                   id="<?= h($panelId) ?>"
                   role="region"
                   aria-labelledby="<?= h($summaryId) ?>"
                   hidden>
                <div class="mk-post-panel__inner">

                  <!-- Image -->
                  <div class="mk-post-panel__image-wrap">
                    <?php if ($imageUrl): ?>
                      <img class="mk-post-panel__image"
                           src="<?= h($imageUrl) ?>"
                           alt="Post image"
                           loading="lazy">
                      <?php if ($isOgImage): ?>
                        <p class="mk-post-panel__image-note">og:image preview — not published with post</p>
                      <?php endif; ?>
                    <?php else: ?>
                      <p class="mk-post-panel__image-placeholder">
                        <svg class="icon" aria-hidden="true" style="width:24px;height:24px;opacity:0.3;margin-bottom:var(--space-2);display:block;margin-inline:auto"><use href="/port/shared/assets/icons/lucide.svg#image"></use></svg>
                        No image attached
                      </p>
                    <?php endif; ?>
                  </div>

                  <!-- Alt text (real stored images only — og:image previews aren't published) -->
                  <?php if ($imageUrl && !$isOgImage): ?>
                    <label class="mk-post-panel__alt-label" for="alt-<?= h($postId) ?>">
                      Image alt text <span class="mk-post-panel__alt-hint">(read by screen readers; used by Mastodon)</span>
                    </label>
                    <input type="text"
                           class="mk-post-panel__alt-input"
                           id="alt-<?= h($postId) ?>"
                           data-post-id="<?= h($postId) ?>"
                           value="<?= h($postImageAlt[$postId] ?? '') ?>"
                           <?= $isPublished ? 'readonly' : '' ?>
                           placeholder="Describe the image for screen-reader users">
                  <?php endif; ?>

                  <!-- Published: view link -->
                  <?php if ($isPublished && $releaseUrl): ?>
                    <p class="mk-post-panel__release-url">
                      <strong>View on <?= h($platform) ?>:</strong>
                      <a href="<?= h($releaseUrl) ?>" target="_blank" rel="noopener noreferrer">
                        <?= h($releaseUrl) ?>
                      </a>
                    </p>
                  <?php endif; ?>

                  <!-- Content textarea -->
                  <textarea class="mk-post-panel__textarea"
                            id="content-<?= h($postId) ?>"
                            data-post-id="<?= h($postId) ?>"
                            <?= $isPublished ? 'readonly' : '' ?>
                            rows="6"><?= h($content) ?></textarea>

                  <!-- Feedback -->
                  <div class="mk-post-panel__feedback" id="feedback-<?= h($postId) ?>"
                       role="status" aria-live="polite"></div>

                  <!-- Actions (hide for published) -->
                  <?php if (!$isPublished): ?>
                    <div class="mk-post-panel__actions">

                      <!-- Save edits -->
                      <button type="button"
                              class="btn btn--sm btn--secondary mk-action-save"
                              data-post-id="<?= h($postId) ?>">
                        Save Edits
                      </button>

                      <!-- Post Now -->
                      <button type="button"
                              class="btn btn--sm btn--primary mk-action-publish-now"
                              data-post-id="<?= h($postId) ?>">
                        Post Now
                      </button>

                      <!-- Schedule row -->
                      <span class="mk-post-panel__schedule-row">
                        <input type="datetime-local"
                               class="mk-post-panel__datetime"
                               id="schedule-dt-<?= h($postId) ?>"
                               value="<?= h($scheduledDt) ?>"
                               aria-label="Schedule date and time">
                        <button type="button"
                                class="btn btn--sm btn--secondary mk-action-reschedule"
                                data-post-id="<?= h($postId) ?>">
                          Schedule
                        </button>
                      </span>

                      <?php if ($isDraft): ?>
                        <!-- Delete Draft -->
                        <button type="button"
                                class="btn btn--sm btn--danger mk-action-delete"
                                data-post-id="<?= h($postId) ?>">
                          Delete Draft
                        </button>
                      <?php endif; ?>

                    </div>
                  <?php endif; ?>

                </div><!-- /.mk-post-panel__inner -->
              </div><!-- /.mk-post-panel -->

            </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endforeach; ?>

    <?php endif; // byDate ?>

  <?php endif; // postizConfigured / error ?>

</div><!-- /.mk-page -->

<!-- ── Confirm modal ─────────────────────────────────────────────────────────── -->
<div class="mk-modal-backdrop" id="mk-confirm-modal" hidden role="dialog"
     aria-modal="true" aria-labelledby="mk-modal-title">
  <div class="mk-modal">
    <h2 class="mk-modal__title" id="mk-modal-title"></h2>
    <p class="mk-modal__body" id="mk-modal-body"></p>
    <div class="mk-modal__actions">
      <button type="button" class="btn btn--sm btn--secondary" id="mk-modal-cancel">Cancel</button>
      <button type="button" class="btn btn--sm btn--primary"   id="mk-modal-confirm" data-variant="primary">Confirm</button>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  // ── Expand / collapse panels ──────────────────────────────────────────────
  document.querySelectorAll('.mk-post-card__summary').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var card     = btn.closest('.mk-post-card');
      var panelId  = btn.getAttribute('aria-controls');
      var panel    = document.getElementById(panelId);
      var expanded = btn.getAttribute('aria-expanded') === 'true';

      if (expanded) {
        btn.setAttribute('aria-expanded', 'false');
        panel.classList.remove('mk-post-panel--open');
        card.classList.remove('mk-post-card--expanded');
        // Re-add hidden after transition
        panel.addEventListener('transitionend', function onEnd() {
          if (panel.classList.contains('mk-post-panel--open')) { return; }
          panel.hidden = true;
          panel.removeEventListener('transitionend', onEnd);
        });
      } else {
        panel.hidden = false;
        // Force reflow so the transition fires
        panel.getBoundingClientRect();
        btn.setAttribute('aria-expanded', 'true');
        panel.classList.add('mk-post-panel--open');
        card.classList.add('mk-post-card--expanded');
      }
    });
  });

  // ── API helper ────────────────────────────────────────────────────────────
  function showFeedback(postId, type, message) {
    var el = document.getElementById('feedback-' + postId);
    if (!el) { return; }
    el.className = 'mk-post-panel__feedback mk-post-panel__feedback--' + type;
    el.textContent = message;
    el.style.display = 'block';
    if (type === 'success') {
      setTimeout(function () { el.style.display = 'none'; }, 4000);
    }
  }

  var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  // ── Confirm modal ─────────────────────────────────────────────────────────
  var _modalBackdrop = document.getElementById('mk-confirm-modal');
  var _modalTitle    = document.getElementById('mk-modal-title');
  var _modalBody     = document.getElementById('mk-modal-body');
  var _modalCancel   = document.getElementById('mk-modal-cancel');
  var _modalConfirm  = document.getElementById('mk-modal-confirm');
  var _modalCallback = null;
  var _modalPrevFocus = null;

  function showConfirm(title, body, confirmLabel, onConfirm, danger) {
    _modalTitle.textContent   = title;
    _modalBody.textContent    = body;
    _modalConfirm.textContent = confirmLabel || 'Confirm';
    _modalConfirm.className   = 'btn btn--sm ' + (danger ? 'btn--danger' : 'btn--primary');
    _modalCallback   = onConfirm;
    _modalPrevFocus  = document.activeElement;
    _modalBackdrop.hidden = false;
    _modalConfirm.focus();
  }

  function _closeModal(confirmed) {
    _modalBackdrop.hidden = true;
    if (_modalPrevFocus) { _modalPrevFocus.focus(); }
    if (confirmed && _modalCallback) { _modalCallback(); }
    _modalCallback = null;
  }

  _modalCancel.addEventListener('click',  function () { _closeModal(false); });
  _modalConfirm.addEventListener('click', function () { _closeModal(true); });
  _modalBackdrop.addEventListener('click', function (e) {
    if (e.target === _modalBackdrop) { _closeModal(false); }
  });
  document.addEventListener('keydown', function (e) {
    if (!_modalBackdrop.hidden && e.key === 'Escape') { _closeModal(false); }
  });

  function callApi(postId, payload, onSuccess, onError) {
    var url = '/port/marketing/social-posts-api.php';
    fetch(url, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body:    JSON.stringify(payload),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.ok) {
        onSuccess(data);
      } else {
        onError((data && data.error) ? data.error : 'Unknown error');
      }
    })
    .catch(function (err) {
      onError('Network error: ' + err.message);
    });
  }

  // ── Save edits (content + alt text, if an alt-text field exists) ───────────
  document.querySelectorAll('.mk-action-save').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var postId  = btn.dataset.postId;
      var content = document.getElementById('content-' + postId).value;
      var altEl   = document.getElementById('alt-' + postId);
      btn.disabled = true;

      function saveContent() {
        return new Promise(function (resolve, reject) {
          callApi(postId, { action: 'edit_content', id: postId, content: content }, resolve, reject);
        });
      }
      function saveAlt() {
        if (!altEl) { return Promise.resolve(); }
        return new Promise(function (resolve, reject) {
          callApi(postId, { action: 'edit_image_alt', id: postId, alt: altEl.value }, resolve, reject);
        });
      }

      Promise.all([saveContent(), saveAlt()])
        .then(function () {
          showFeedback(postId, 'success', 'Saved.');
          btn.disabled = false;
        })
        .catch(function (err) {
          showFeedback(postId, 'error', 'Save failed: ' + err);
          btn.disabled = false;
        });
    });
  });

  // ── Post Now ──────────────────────────────────────────────────────────────
  document.querySelectorAll('.mk-action-publish-now').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var postId  = btn.dataset.postId;
      var content = document.getElementById('content-' + postId).value;
      showConfirm(
        'Post Now',
        'This will publish immediately to the platform. Are you sure?',
        'Post Now',
        function () {
          btn.disabled = true;
          btn.textContent = 'Posting…';
          callApi(
            postId,
            { action: 'publish_now', id: postId, content: content },
            function () {
              showFeedback(postId, 'success', 'Posted! Refresh to see updated state.');
              btn.textContent = 'Posted ✓';
            },
            function (err) {
              showFeedback(postId, 'error', 'Failed: ' + err);
              btn.disabled = false;
              btn.textContent = 'Post Now';
            }
          );
        }
      );
    });
  });

  // ── Reschedule ────────────────────────────────────────────────────────────
  document.querySelectorAll('.mk-action-reschedule').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var postId = btn.dataset.postId;
      var dtEl   = document.getElementById('schedule-dt-' + postId);
      var dt     = dtEl ? dtEl.value : '';
      if (!dt) {
        showFeedback(postId, 'error', 'Pick a date and time first.');
        return;
      }
      btn.disabled = true;
      callApi(
        postId,
        { action: 'reschedule', id: postId, publishDate: dt },
        function () {
          showFeedback(postId, 'success', 'Rescheduled to ' + dt.replace('T', ' ') + '.');
          btn.disabled = false;
        },
        function (err) {
          showFeedback(postId, 'error', 'Failed: ' + err);
          btn.disabled = false;
        }
      );
    });
  });

  // ── Delete draft ──────────────────────────────────────────────────────────
  document.querySelectorAll('.mk-action-delete').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var postId = btn.dataset.postId;
      showConfirm(
        'Delete Draft',
        'This will permanently delete the draft. This cannot be undone.',
        'Delete',
        function () {
          btn.disabled = true;
          callApi(
            postId,
            { action: 'delete', id: postId },
            function () {
              var card = document.getElementById('card-' + postId);
              if (card) {
                card.style.opacity = '0.4';
                card.style.transition = 'opacity 0.3s';
                setTimeout(function () { card.remove(); }, 350);
              }
            },
            function (err) {
              showFeedback(postId, 'error', 'Delete failed: ' + err);
              btn.disabled = false;
            }
          );
        },
        true  /* danger — red confirm button */
      );
    });
  });

}());
</script>

<?php renderFooter(); ?>
