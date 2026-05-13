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

$user = requireModuleAccess('marketing', 'viewer');

// ── Data: draft queue count ───────────────────────────────────────────────────
$queueUrl  = 'https://api.github.com/repos/cobenrogers/glyc/contents/docs/marketing/workspace/queue';
$queueData = mkGhGet($queueUrl, 120);
$draftCount   = 0;
$queueError   = false;
$queueMissing = false;

if ($queueData === null) {
    $queueError = true;
} elseif (isset($queueData['message'])) {
    $queueMissing = true;
} else {
    $files = array_filter($queueData, fn($i) => isset($i['type']) && $i['type'] === 'file');
    $dirs  = array_filter($queueData, fn($i) => isset($i['type']) && $i['type'] === 'dir');
    if (!empty($dirs) && empty($files)) {
        foreach ($dirs as $dir) {
            $subData = mkGhGet($dir['url'], 120);
            if (is_array($subData)) {
                $draftCount += count(array_filter($subData, fn($i) => isset($i['type']) && $i['type'] === 'file'));
            }
        }
    } else {
        $draftCount = count($files);
    }
}

// ── Data: recent published posts ──────────────────────────────────────────────
$publishedUrl     = 'https://api.github.com/repos/cobenrogers/glyc/contents/docs/marketing/workspace/published';
$publishedData    = mkGhGet($publishedUrl, 300);
$recentPublished  = [];
$publishedMissing = false;
$publishedError   = false;

if ($publishedData === null) {
    $publishedError = true;
} elseif (isset($publishedData['message'])) {
    $publishedMissing = true;
} else {
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
    $recentPublished = array_slice($allFiles, 0, 5);
}

// ── Data: tile cache (BlueSky followers, per-site metrics) ────────────────────
$tileCacheFile = (defined('MK_CACHE_DIR') ? MK_CACHE_DIR : sys_get_temp_dir() . '/mk-cache')
               . '/marketing-tile.json';
$tileCache = null;
if (file_exists($tileCacheFile)) {
    $raw = @file_get_contents($tileCacheFile);
    $decoded = $raw ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $tileCache = $decoded;
    }
}

// Extract BlueSky followers from top-level tile metrics
$bskyFollowersShared = null;
if ($tileCache && isset($tileCache['metrics']) && is_array($tileCache['metrics'])) {
    foreach ($tileCache['metrics'] as $metric) {
        if (isset($metric['label']) && stripos($metric['label'], 'bluesky') !== false) {
            $bskyFollowersShared = $metric['value'];
            break;
        }
    }
}

// Shared BlueSky account (bennernet.bsky.social) — see tile cache top-level metrics.
// Per-site handles will be added in #72 (IBD Movement dedicated account).
function mkFetchBskyFollowers(string $handle): ?int {
    $url  = 'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=' . urlencode($handle);
    $ctx  = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => 'User-Agent: bennernet-marketing/1.0',
        'timeout'       => 8,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if (!$body) {
        return null;
    }
    $data = json_decode($body, true);
    return isset($data['followersCount']) ? (int)$data['followersCount'] : null;
}

// ── Data: per-site metrics extracted from tile cache children ────────────────
$glycPostsPublished = null;
$ibdPostsPublished  = null;
$glycMastoFollowers = null;
$ibdMastoFollowers  = null;
$glycGscClicks      = null;
$ibdGscClicks       = null;
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
            } elseif (stripos($label, 'organic clicks') !== false) {
                if ($isGlyc) $glycGscClicks = $value;
                if ($isIbd)  $ibdGscClicks  = $value;
            }
        }
    }
}

// ── Data: Postiz queue status ─────────────────────────────────────────────────
$postizQueueCount = null;
$postizError      = false;
$postizConfigured = false;

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
    // Postiz requires startDate + endDate (ISO 8601); 7-day forward window captures the queue.
    $qs = 'startDate=' . urlencode(date('c', strtotime('-1 day')))
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
            // Filter to QUEUE only, client-side
            $queued = array_filter($posts, fn($p) => ($p['state'] ?? '') === 'QUEUE');
            $postizQueueCount = count($queued);
        } else {
            $postizError = true;
        }
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

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

/* Icon sizing */
.icon {
  width: 16px;
  height: 16px;
  flex-shrink: 0;
  vertical-align: middle;
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
              <span class="mk-metric-list__label">Mastodon followers</span>
              <?php if ($glycMastoFollowers !== null): ?>
                <span class="mk-metric-list__value"><?= h((string)$glycMastoFollowers) ?></span>
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
              <span class="mk-metric-list__label">Mastodon followers</span>
              <?php if ($ibdMastoFollowers !== null): ?>
                <span class="mk-metric-list__value"><?= h((string)$ibdMastoFollowers) ?></span>
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

      <!-- Anomaly flag block -->
      <div class="mk-card" aria-labelledby="card-anomaly-title">
        <div class="mk-card__header">
          <h3 class="mk-card__title" id="card-anomaly-title">
            <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#alert-triangle"></use></svg>
            Anomaly Check
          </h3>
        </div>
        <div class="mk-card__body">
          <p class="mk-notice mk-notice--success">No anomalies detected.</p>
        </div>
      </div>

    </div><!-- /.mk-grid--halves (postiz + anomaly) -->

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

  <!-- ── Recent Activity ──────────────────────────────────────────────────── -->
  <section aria-labelledby="activity-heading">
    <h2 class="mk-section-heading" id="activity-heading">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#activity"></use></svg>
      Recent Activity
    </h2>
    <div class="mk-card" style="margin-bottom: var(--space-8);">
      <div class="mk-card__header">
        <h3 class="mk-card__title">Last 5 Published Posts</h3>
        <a href="/port/marketing/published.php" class="mk-card__link">View all</a>
      </div>
      <div class="mk-card__body">
        <?php if ($publishedError): ?>
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
            <p class="mk-notice mk-notice--warn">Could not reach GitHub API — check MK_GITHUB_TOKEN.</p>
          <?php elseif ($queueMissing): ?>
            <p class="mk-empty">No queue directory found in <code>cobenrogers/glyc</code> at
              <code>docs/marketing/workspace/queue/</code> — create it to start using the drafts queue.</p>
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
