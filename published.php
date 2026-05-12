<?php
/**
 * Marketing module — Published Archive
 *
 * Lists all published posts from GitHub, sorted newest first.
 * Handles both flat and sub-directory layouts.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once PORT_ROOT . '/shared/shell.php';
require_once __DIR__ . '/gh-helper.php';

$user = requireModuleAccess('marketing', 'viewer');

// ── Fetch published directory ─────────────────────────────────────────────────
$pubUrl  = 'https://api.github.com/repos/cobenrogers/glyc/contents/docs/marketing/workspace/published';
$pubData = mkGhGet($pubUrl, 300);

$pubError   = false;
$pubMissing = false;
$allFiles   = [];

if ($pubData === null) {
    $pubError = true;
} elseif (isset($pubData['message'])) {
    $pubMissing = true;
} else {
    foreach ($pubData as $item) {
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
    // Sort descending by filename (date prefix makes this chronological)
    usort($allFiles, fn($a, $b) => strcmp($b['name'], $a['name']));
}

/**
 * Extract date from filename like 2026-05-12-some-title.md
 */
function mkExtractDate(string $filename): string {
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $filename, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Extract a human-readable title from filename (strip date prefix and extension).
 */
function mkExtractTitle(string $filename): string {
    $s = preg_replace('/^\d{4}-\d{2}-\d{2}[-_]?/', '', $filename);
    $s = preg_replace('/\.\w+$/', '', $s);
    $s = str_replace(['-', '_'], ' ', $s);
    return ucfirst(trim($s)) ?: $filename;
}

/**
 * Infer platform from path or filename.
 */
function mkInferPlatform(string $nameOrPath): string {
    $s = strtolower($nameOrPath);
    if (str_contains($s, 'bsky') || str_contains($s, 'bluesky')) {
        return 'Bluesky';
    }
    if (str_contains($s, 'mastodon') || str_contains($s, 'masto')) {
        return 'Mastodon';
    }
    if (str_contains($s, 'linkedin') || str_contains($s, '-li-') || str_contains($s, '/li/')) {
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
    return 'General';
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

renderHeader('Published Archive — Marketing', [
    'user'        => $user,
    'module_slug' => 'marketing',
    'breadcrumb'  => [
        ['label' => 'Port',              'url' => '/port/'],
        ['label' => 'Marketing',         'url' => '/port/marketing/'],
        ['label' => 'Published Archive', 'url' => null],
    ],
]);
?>

<div class="mk-page">

  <div class="mk-page-header">
    <h1 class="mk-page-header__title">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#send"></use></svg>
      Published Archive
    </h1>
    <a href="/port/marketing/" class="mk-back-link">← Dashboard</a>
  </div>

  <?php if ($pubError): ?>
    <div class="mk-notice mk-notice--warn" role="alert">
      Could not reach GitHub API — check that <code>MK_GITHUB_TOKEN</code> is set in <code>config.php</code>.
    </div>

  <?php elseif ($pubMissing): ?>
    <div class="mk-notice mk-notice--info" role="alert">
      No published directory found in <code>cobenrogers/glyc</code> at
      <code>docs/marketing/workspace/published/</code>.
    </div>

  <?php elseif (empty($allFiles)): ?>
    <div class="mk-empty-state">
      <svg class="icon mk-empty-state__icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#inbox"></use></svg>
      <p>No published posts found.</p>
    </div>

  <?php else: ?>
    <p class="mk-count-summary"><?= count($allFiles) ?> post<?= count($allFiles) !== 1 ? 's' : '' ?> published</p>
    <table class="mk-table" role="table">
      <thead>
        <tr>
          <th scope="col">Date</th>
          <th scope="col">Title</th>
          <th scope="col">Platform</th>
          <th scope="col" class="mk-table__actions-col">View</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allFiles as $file): ?>
          <?php
            $date      = mkExtractDate($file['name']);
            $title     = mkExtractTitle($file['name']);
            $platform  = mkInferPlatform($file['path'] ?? $file['name']);
            $badge     = mkPlatformBadgeClass($platform);
            $encPath   = urlencode($file['path'] ?? $file['name']);
          ?>
          <tr>
            <td class="mk-table__date"><?= $date ? h($date) : '—' ?></td>
            <td><?= h($title) ?></td>
            <td><span class="mk-badge mk-badge--<?= h($badge) ?>"><?= h($platform) ?></span></td>
            <td>
              <a href="/port/marketing/render.php?repo=glyc&amp;path=<?= $encPath ?>"
                 class="btn btn--sm btn--secondary">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

</div><!-- /.mk-page -->

<?php renderFooter(); ?>
