<?php
/**
 * Marketing module — Engagement Timeline
 *
 * Fetches engagement-log.md from GitHub and renders it as a sortable
 * HTML table.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once PORT_ROOT . '/shared/shell.php';
require_once __DIR__ . '/gh-helper.php';

$user = requireModuleAccess('marketing', 'viewer');

// ── Fetch engagement log ──────────────────────────────────────────────────────
$logPath = 'docs/marketing/workspace/tracking/engagement-log.md';
$raw     = mkGhRawContent('cobenrogers', 'glyc', $logPath, 300);

$rows    = [];
$headers = [];
$fetchOk = ($raw !== null);

if ($raw !== null) {
    // Parse markdown table — look for | delimited rows
    $lines = explode("\n", $raw);
    $tableStarted = false;

    foreach ($lines as $line) {
        $line = trim($line);
        if (!str_starts_with($line, '|')) {
            continue;
        }
        // Skip separator rows like |---|---|
        if (preg_match('/^\|[-| :]+\|$/', $line)) {
            continue;
        }
        $cells = array_map('trim', explode('|', $line));
        // Remove leading/trailing empty cells from the split
        if (isset($cells[0]) && $cells[0] === '') {
            array_shift($cells);
        }
        if (isset($cells[count($cells) - 1]) && $cells[count($cells) - 1] === '') {
            array_pop($cells);
        }

        if (empty($cells)) {
            continue;
        }

        if (empty($headers)) {
            $headers = $cells;
            $tableStarted = true;
        } else {
            $rows[] = $cells;
        }
    }
}

renderHeader('Engagement — Marketing', [
    'user'        => $user,
    'module_slug' => 'marketing',
    'breadcrumb'  => [
        ['label' => 'Port',        'url' => '/port/'],
        ['label' => 'Marketing',   'url' => '/port/marketing/'],
        ['label' => 'Engagement',  'url' => null],
    ],
]);
?>

<div class="mk-page">

  <div class="mk-page-header">
    <h1 class="mk-page-header__title">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#bar-chart-2"></use></svg>
      Engagement Timeline
    </h1>
    <a href="/port/marketing/" class="mk-back-link">← Dashboard</a>
  </div>

  <?php if (!$fetchOk): ?>
    <div class="mk-notice mk-notice--info" role="alert">
      No engagement log found at <code>docs/marketing/workspace/tracking/engagement-log.md</code>
      in <code>cobenrogers/glyc</code>. Create it to start tracking engagement.
    </div>

  <?php elseif (empty($headers)): ?>
    <div class="mk-notice mk-notice--info" role="alert">
      Engagement log found but no table data could be parsed. Ensure the file contains
      a markdown table with pipe-separated columns.
    </div>

  <?php elseif (empty($rows)): ?>
    <div class="mk-empty-state">
      <svg class="icon mk-empty-state__icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#inbox"></use></svg>
      <p>No engagement entries yet.</p>
    </div>

  <?php else: ?>
    <p class="mk-count-summary"><?= count($rows) ?> entr<?= count($rows) !== 1 ? 'ies' : 'y' ?></p>
    <div class="mk-table-scroll">
      <table class="mk-table mk-table--sortable" id="engagement-table" role="table">
        <thead>
          <tr>
            <?php foreach ($headers as $i => $header): ?>
              <th scope="col" class="mk-table__sortable-header" data-col="<?= $i ?>" tabindex="0"
                  aria-sort="none">
                <?= h($header) ?>
                <svg class="icon mk-sort-icon" aria-hidden="true">
                  <use href="/port/shared/assets/icons/lucide.svg#chevrons-up-down"></use>
                </svg>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody id="engagement-tbody">
          <?php foreach ($rows as $row): ?>
            <tr>
              <?php
                $colCount = count($headers);
                for ($c = 0; $c < $colCount; $c++):
                  $cell = $row[$c] ?? '';
              ?>
              <td><?= h($cell) ?></td>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

</div><!-- /.mk-page -->

<script>
(function () {
  'use strict';

  var table = document.getElementById('engagement-table');
  if (!table) return;

  var tbody    = document.getElementById('engagement-tbody');
  var headers  = table.querySelectorAll('.mk-table__sortable-header');
  var sortCol  = -1;
  var sortAsc  = true;

  headers.forEach(function (th) {
    th.addEventListener('click', function () { sortBy(parseInt(th.dataset.col, 10)); });
    th.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        sortBy(parseInt(th.dataset.col, 10));
      }
    });
  });

  function sortBy(col) {
    if (sortCol === col) {
      sortAsc = !sortAsc;
    } else {
      sortCol = col;
      sortAsc = true;
    }

    headers.forEach(function (th) {
      th.setAttribute('aria-sort', 'none');
    });
    var activeHeader = table.querySelector('[data-col="' + col + '"]');
    if (activeHeader) {
      activeHeader.setAttribute('aria-sort', sortAsc ? 'ascending' : 'descending');
    }

    var rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort(function (a, b) {
      var aText = (a.cells[col] ? a.cells[col].textContent : '').trim();
      var bText = (b.cells[col] ? b.cells[col].textContent : '').trim();
      var aNum  = parseFloat(aText);
      var bNum  = parseFloat(bText);
      var cmp;
      if (!isNaN(aNum) && !isNaN(bNum)) {
        cmp = aNum - bNum;
      } else {
        cmp = aText.localeCompare(bText);
      }
      return sortAsc ? cmp : -cmp;
    });
    rows.forEach(function (r) { tbody.appendChild(r); });
  }
}());
</script>

<?php renderFooter(); ?>
