<?php
/**
 * Marketing module — Drafts Queue
 *
 * Full drafts queue view. Fetches directory listing from GitHub.
 * Handles both flat and sub-directory (bluesky/, reddit/, etc.) layouts.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once PORT_ROOT . '/shared/shell.php';
require_once __DIR__ . '/gh-helper.php';

$user = requireModuleAccess('marketing', 'viewer');

// ── Fetch queue directory ─────────────────────────────────────────────────────
$queueUrl  = 'https://api.github.com/repos/cobenrogers/glyc/contents/docs/marketing/workspace/queue';
$queueData = mkGhGet($queueUrl, 120);

$queueError   = false;
$queueMissing = false;
$byPlatform   = [];  // ['Platform' => [file, ...], ...]

if ($queueData === null) {
    $queueError = true;
} elseif (isset($queueData['message'])) {
    $queueMissing = true;
} else {
    $files = array_filter($queueData, fn($i) => isset($i['type']) && $i['type'] === 'file');
    $dirs  = array_filter($queueData, fn($i) => isset($i['type']) && $i['type'] === 'dir');

    if (!empty($dirs)) {
        // Sub-directory layout — group by directory name
        foreach ($dirs as $dir) {
            $platform = ucfirst(strtolower($dir['name']));
            // Normalize well-known slugs
            $platform = match (strtolower($dir['name'])) {
                'bluesky', 'bsky' => 'Bluesky',
                'mastodon', 'masto' => 'Mastodon',
                'linkedin', 'li'  => 'LinkedIn',
                'reddit'          => 'Reddit',
                'twitter', 'x'    => 'X/Twitter',
                'instagram', 'insta' => 'Instagram',
                default           => ucfirst($dir['name']),
            };
            $subData = mkGhGet($dir['url'], 120);
            if (is_array($subData)) {
                $subFiles = array_filter($subData, fn($i) => isset($i['type']) && $i['type'] === 'file');
                if (!empty($subFiles)) {
                    $byPlatform[$platform] = array_values($subFiles);
                }
            }
        }
    }

    // Also include any flat files in the root of queue/
    if (!empty($files)) {
        foreach ($files as $file) {
            $platform = mkInferPlatformFromName($file['name']);
            if (!isset($byPlatform[$platform])) {
                $byPlatform[$platform] = [];
            }
            $byPlatform[$platform][] = $file;
        }
    }
}

/**
 * Infer platform from a filename.
 */
function mkInferPlatformFromName(string $name): string {
    $s = strtolower($name);
    if (str_contains($s, 'bsky') || str_contains($s, 'bluesky')) {
        return 'Bluesky';
    }
    if (str_contains($s, 'mastodon') || str_contains($s, 'masto')) {
        return 'Mastodon';
    }
    if (str_contains($s, 'linkedin') || str_contains($s, '-li-') || str_contains($s, '_li_')) {
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

/**
 * Extract a date string from a filename like 2026-05-12-post-title.md
 */
function mkExtractDate(string $filename): string {
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $filename, $m)) {
        return $m[1];
    }
    return '';
}

renderHeader('Drafts Queue — Marketing', [
    'user'        => $user,
    'module_slug' => 'marketing',
    'breadcrumb'  => [
        ['label' => 'Port',         'url' => '/port/'],
        ['label' => 'Marketing',    'url' => '/port/marketing/'],
        ['label' => 'Drafts Queue', 'url' => null],
    ],
]);
?>

<div class="mk-page">

  <div class="mk-page-header">
    <h1 class="mk-page-header__title">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#file-text"></use></svg>
      Drafts Queue
    </h1>
    <a href="/port/marketing/" class="mk-back-link">← Dashboard</a>
  </div>

  <?php if ($queueError): ?>
    <div class="mk-notice mk-notice--warn" role="alert">
      Could not reach GitHub API — check that <code>MK_GITHUB_TOKEN</code> is set in <code>config.php</code>.
    </div>

  <?php elseif ($queueMissing): ?>
    <div class="mk-notice mk-notice--info" role="alert">
      No queue directory found in <code>cobenrogers/glyc</code> at
      <code>docs/marketing/workspace/queue/</code> — create it to start using the drafts queue.
    </div>

  <?php elseif (empty($byPlatform)): ?>
    <div class="mk-empty-state">
      <svg class="icon mk-empty-state__icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#inbox"></use></svg>
      <p>No drafts in queue.</p>
    </div>

  <?php else: ?>
    <?php foreach ($byPlatform as $platform => $files): ?>
      <section class="mk-platform-section" aria-labelledby="platform-<?= h(strtolower($platform)) ?>">
        <h2 class="mk-platform-section__heading" id="platform-<?= h(strtolower($platform)) ?>">
          <span class="mk-badge mk-badge--<?= h(mkPlatformBadgeClass($platform)) ?>"><?= h($platform) ?></span>
          <span class="mk-platform-count"><?= count($files) ?> draft<?= count($files) !== 1 ? 's' : '' ?></span>
        </h2>
        <ul class="mk-draft-list" data-platform="<?= h($platform) ?>">
          <?php foreach ($files as $file): ?>
            <?php
              $date    = mkExtractDate($file['name']);
              $encPath = urlencode($file['path'] ?? $file['name']);
            ?>
            <li class="mk-draft-list__item" data-file="<?= h($file['name']) ?>">
              <div class="mk-draft-list__meta">
                <?php if ($date): ?>
                  <span class="mk-draft-list__date"><?= h($date) ?></span>
                <?php endif; ?>
                <span class="mk-draft-list__name"><?= h($file['name']) ?></span>
              </div>
              <div class="mk-draft-list__actions">
                <a href="/port/marketing/render.php?repo=glyc&amp;path=<?= $encPath ?>"
                   class="btn btn--sm btn--secondary">View</a>
                <button type="button" class="btn btn--sm btn--link mk-expand-btn"
                        data-repo="glyc"
                        data-path="<?= h($file['path'] ?? $file['name']) ?>"
                        aria-expanded="false">
                  Preview
                </button>
              </div>
              <div class="mk-draft-preview" hidden aria-live="polite"></div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>

</div><!-- /.mk-page -->

<script>
(function () {
  'use strict';

  document.querySelectorAll('.mk-expand-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var item    = btn.closest('.mk-draft-list__item');
      var preview = item.querySelector('.mk-draft-preview');
      var expanded = btn.getAttribute('aria-expanded') === 'true';

      if (expanded) {
        preview.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        btn.textContent = 'Preview';
        return;
      }

      if (preview.dataset.loaded) {
        preview.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        btn.textContent = 'Collapse';
        return;
      }

      btn.textContent = 'Loading…';
      btn.disabled    = true;

      var repo = btn.dataset.repo;
      var path = btn.dataset.path;
      var url  = '/port/marketing/render.php?repo=' + encodeURIComponent(repo)
               + '&path=' + encodeURIComponent(path) + '&inline=1';

      fetch(url)
        .then(function (r) { return r.text(); })
        .then(function (html) {
          preview.innerHTML      = html;
          preview.dataset.loaded = '1';
          preview.hidden         = false;
          btn.setAttribute('aria-expanded', 'true');
          btn.textContent = 'Collapse';
          btn.disabled    = false;
        })
        .catch(function () {
          preview.textContent = 'Failed to load preview.';
          preview.hidden      = false;
          btn.disabled        = false;
          btn.textContent     = 'Preview';
        });
    });
  });
}());
</script>

<?php renderFooter(); ?>
