<?php
/**
 * Marketing module — main dashboard.
 *
 * Shows overview of all marketing surfaces:
 *   - Drafts queue summary (GitHub)
 *   - Recent published posts (GitHub)
 *   - Campaign status (Postiz — stub in v0)
 *   - Analytics (GSC — stub in v0)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once PORT_ROOT . '/shared/shell.php';
require_once __DIR__ . '/gh-helper.php';

$user = requireModuleAccess('marketing', 'viewer');

// ── Data: draft queue count ───────────────────────────────────────────────────
$queueUrl  = 'https://api.github.com/repos/cobenrogers/glyc/contents/docs/marketing/workspace/queue';
$queueData = mkGhGet($queueUrl, 120);
$draftCount    = 0;
$queueError    = false;
$queueMissing  = false;

if ($queueData === null) {
    $queueError = true;
} elseif (isset($queueData['message'])) {
    // 404 — directory not found
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
$publishedUrl  = 'https://api.github.com/repos/cobenrogers/glyc/contents/docs/marketing/workspace/published';
$publishedData = mkGhGet($publishedUrl, 300);
$recentPublished  = [];
$publishedMissing = false;
$publishedError   = false;

if ($publishedData === null) {
    $publishedError = true;
} elseif (isset($publishedData['message'])) {
    $publishedMissing = true;
} else {
    // Collect all files (handle both flat and sub-dir layouts)
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
    // Sort descending by name (filenames have date prefixes)
    usort($allFiles, fn($a, $b) => strcmp($b['name'], $a['name']));
    $recentPublished = array_slice($allFiles, 0, 5);
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

renderHeader('Marketing', [
    'user'        => $user,
    'module_slug' => 'marketing',
    'breadcrumb'  => [
        ['label' => 'Port',      'url' => '/port/'],
        ['label' => 'Marketing', 'url' => null],
    ],
]);
?>

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

  <!-- ── Nav ──────────────────────────────────────────────────────────────── -->
  <nav class="mk-nav" aria-label="Marketing module navigation">
    <a href="/port/marketing/" class="mk-nav__link mk-nav__link--active" aria-current="page">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#layout-dashboard"></use></svg>
      Dashboard
    </a>
    <a href="/port/marketing/drafts.php" class="mk-nav__link">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#file-text"></use></svg>
      Drafts Queue
    </a>
    <a href="/port/marketing/published.php" class="mk-nav__link">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#send"></use></svg>
      Published Archive
    </a>
    <a href="/port/marketing/engagement.php" class="mk-nav__link">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#bar-chart-2"></use></svg>
      Engagement
    </a>
    <a href="/port/marketing/research.php" class="mk-nav__link">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#search"></use></svg>
      Research
    </a>
    <span class="mk-nav__link mk-nav__link--stub" title="Coming in v0.2">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#calendar"></use></svg>
      Campaign Status
    </span>
    <span class="mk-nav__link mk-nav__link--stub" title="Coming in v0.2">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#trending-up"></use></svg>
      GSC Analytics
    </span>
  </nav>

  <!-- ── Dashboard grid ───────────────────────────────────────────────────── -->
  <div class="mk-grid">

    <!-- Card 1: Drafts Queue ──────────────────────────────────────────────── -->
    <section class="mk-card" aria-labelledby="card-drafts-title">
      <div class="mk-card__header">
        <h2 class="mk-card__title" id="card-drafts-title">
          <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#file-text"></use></svg>
          Drafts Queue
        </h2>
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

    <!-- Card 2: Recent Published ──────────────────────────────────────────── -->
    <section class="mk-card" aria-labelledby="card-published-title">
      <div class="mk-card__header">
        <h2 class="mk-card__title" id="card-published-title">
          <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#send"></use></svg>
          Recent Published
        </h2>
        <a href="/port/marketing/published.php" class="mk-card__link">View all</a>
      </div>
      <div class="mk-card__body">
        <?php if ($publishedError): ?>
          <p class="mk-notice mk-notice--warn">Could not reach GitHub API.</p>
        <?php elseif ($publishedMissing || empty($recentPublished)): ?>
          <p class="mk-empty">No published posts found.</p>
        <?php else: ?>
          <ul class="mk-file-list">
            <?php foreach ($recentPublished as $file): ?>
              <?php
                $platform  = mkInferPlatform($file['path'] ?? $file['name']);
                $badgeClass = mkPlatformBadgeClass($platform);
                $encodedPath = urlencode($file['path'] ?? $file['name']);
              ?>
              <li class="mk-file-list__item">
                <span class="mk-badge mk-badge--<?= h($badgeClass) ?>"><?= h($platform) ?></span>
                <a href="/port/marketing/render.php?repo=glyc&amp;path=<?= $encodedPath ?>" class="mk-file-list__name">
                  <?= h($file['name']) ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </section>

    <!-- Card 3: Campaign Status (stub) ───────────────────────────────────── -->
    <section class="mk-card mk-card--stub" aria-labelledby="card-campaign-title">
      <div class="mk-card__header">
        <h2 class="mk-card__title" id="card-campaign-title">
          <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#calendar"></use></svg>
          Campaign Status
        </h2>
        <span class="mk-badge mk-badge--stub">v0.2</span>
      </div>
      <div class="mk-card__body">
        <p class="mk-stub-notice">
          Postiz integration coming in v0.2 — drafts visible via Postiz UI at
          <code>http://localhost:4007</code>
        </p>
      </div>
    </section>

    <!-- Card 4: Analytics (stub) ─────────────────────────────────────────── -->
    <section class="mk-card mk-card--stub" aria-labelledby="card-gsc-title">
      <div class="mk-card__header">
        <h2 class="mk-card__title" id="card-gsc-title">
          <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#trending-up"></use></svg>
          GSC Analytics
        </h2>
        <span class="mk-badge mk-badge--stub">v0.2</span>
      </div>
      <div class="mk-card__body">
        <p class="mk-stub-notice">
          GSC bridge proxy not yet configured — coming in v0.2
        </p>
      </div>
    </section>

  </div><!-- /.mk-grid -->

</div><!-- /.mk-dashboard -->

<?php renderFooter(); ?>
