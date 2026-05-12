<?php
/**
 * Marketing module — Research
 *
 * Lists research files from GitHub docs/marketing/workspace/research/
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once PORT_ROOT . '/shared/shell.php';
require_once __DIR__ . '/gh-helper.php';

$user = requireModuleAccess('marketing', 'viewer');

// ── Fetch research directory ──────────────────────────────────────────────────
$researchUrl  = 'https://api.github.com/repos/cobenrogers/glyc/contents/docs/marketing/workspace/research';
$researchData = mkGhGet($researchUrl, 300);

$researchError   = false;
$researchMissing = false;
$allFiles        = [];

if ($researchData === null) {
    $researchError = true;
} elseif (isset($researchData['message'])) {
    $researchMissing = true;
} else {
    foreach ($researchData as $item) {
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
}

/**
 * Extract date from filename.
 */
function mkExtractDate(string $filename): string {
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $filename, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Human-readable title from filename.
 */
function mkExtractTitle(string $filename): string {
    $s = preg_replace('/^\d{4}-\d{2}-\d{2}[-_]?/', '', $filename);
    $s = preg_replace('/\.\w+$/', '', $s);
    $s = str_replace(['-', '_'], ' ', $s);
    return ucfirst(trim($s)) ?: $filename;
}

renderHeader('Research — Marketing', [
    'user'        => $user,
    'module_slug' => 'marketing',
    'breadcrumb'  => [
        ['label' => 'Port',       'url' => '/port/'],
        ['label' => 'Marketing',  'url' => '/port/marketing/'],
        ['label' => 'Research',   'url' => null],
    ],
]);
?>

<div class="mk-page">

  <div class="mk-page-header">
    <h1 class="mk-page-header__title">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#search"></use></svg>
      Research
    </h1>
    <a href="/port/marketing/" class="mk-back-link">← Dashboard</a>
  </div>

  <?php if ($researchError): ?>
    <div class="mk-notice mk-notice--warn" role="alert">
      Could not reach GitHub API — check that <code>MK_GITHUB_TOKEN</code> is set in <code>config.php</code>.
    </div>

  <?php elseif ($researchMissing): ?>
    <div class="mk-notice mk-notice--info" role="alert">
      No research directory found in <code>cobenrogers/glyc</code> at
      <code>docs/marketing/workspace/research/</code>.
    </div>

  <?php elseif (empty($allFiles)): ?>
    <div class="mk-empty-state">
      <svg class="icon mk-empty-state__icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#inbox"></use></svg>
      <p>No research files found.</p>
    </div>

  <?php else: ?>
    <p class="mk-count-summary"><?= count($allFiles) ?> file<?= count($allFiles) !== 1 ? 's' : '' ?></p>
    <ul class="mk-file-list mk-file-list--full">
      <?php foreach ($allFiles as $file): ?>
        <?php
          $date    = mkExtractDate($file['name']);
          $title   = mkExtractTitle($file['name']);
          $encPath = urlencode($file['path'] ?? $file['name']);
        ?>
        <li class="mk-file-list__item">
          <?php if ($date): ?>
            <span class="mk-file-list__date"><?= h($date) ?></span>
          <?php endif; ?>
          <a href="/port/marketing/render.php?repo=glyc&amp;path=<?= $encPath ?>"
             class="mk-file-list__name"><?= h($title) ?></a>
          <span class="mk-file-list__raw"><?= h($file['name']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

</div><!-- /.mk-page -->

<?php renderFooter(); ?>
