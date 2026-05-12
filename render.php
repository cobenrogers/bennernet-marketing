<?php
/**
 * Marketing module — Markdown render view
 *
 * Renders a file from GitHub as HTML.
 * GET params:
 *   repo  — repo name (e.g. "glyc") — always resolved under cobenrogers/
 *   path  — path within the repo
 *   inline — if "1", output only the content fragment (no page chrome)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once PORT_ROOT . '/shared/shell.php';
require_once __DIR__ . '/gh-helper.php';

$user = requireModuleAccess('marketing', 'viewer');

$repo   = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['repo'] ?? 'glyc');
$path   = $_GET['path'] ?? '';
$inline = ($_GET['inline'] ?? '') === '1';

// Sanitize path — no traversal outside the repo
$path = ltrim(preg_replace('/\.\./', '', $path), '/');

$content    = null;
$fetchError = false;

if ($path !== '') {
    $content = mkGhRawContent('cobenrogers', $repo, $path, 300);
    if ($content === null) {
        $fetchError = true;
    }
}

/**
 * Strip YAML frontmatter (---...---) from markdown content.
 */
function mkStripFrontmatter(string $md): string {
    if (str_starts_with(ltrim($md), '---')) {
        $md   = ltrim($md);
        $rest = substr($md, 3);
        $end  = strpos($rest, "\n---");
        if ($end !== false) {
            return ltrim(substr($rest, $end + 4));
        }
    }
    return $md;
}

/**
 * Simple markdown-to-HTML renderer.
 * Handles: headings, bold, italic, links, inline code,
 * fenced code blocks, unordered lists, and paragraphs.
 */
function mkRenderMarkdown(string $md): string {
    $md = mkStripFrontmatter($md);

    $lines  = explode("\n", $md);
    $html   = '';
    $inCode = false;
    $codeLang = '';
    $inList = false;

    foreach ($lines as $line) {
        // Fenced code blocks
        if (preg_match('/^```(\w*)/', $line, $m)) {
            if (!$inCode) {
                if ($inList) {
                    $html  .= '</ul>';
                    $inList = false;
                }
                $codeLang = $m[1];
                $inCode   = true;
                $html    .= '<pre><code' . ($codeLang ? ' class="language-' . h($codeLang) . '"' : '') . '>';
            } else {
                $inCode = false;
                $html  .= '</code></pre>' . "\n";
            }
            continue;
        }

        if ($inCode) {
            $html .= h($line) . "\n";
            continue;
        }

        // Headings
        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
            if ($inList) {
                $html  .= '</ul>';
                $inList = false;
            }
            $level = strlen($m[1]);
            $html .= '<h' . $level . '>' . mkInlineMarkdown(h($m[2])) . '</h' . $level . '>' . "\n";
            continue;
        }

        // Unordered list items (-, *, +)
        if (preg_match('/^[\-\*\+]\s+(.+)$/', $line, $m)) {
            if (!$inList) {
                $html  .= '<ul>';
                $inList = true;
            }
            $html .= '<li>' . mkInlineMarkdown(h($m[1])) . '</li>' . "\n";
            continue;
        }

        // Close list if line is not a list item
        if ($inList) {
            $html  .= '</ul>' . "\n";
            $inList = false;
        }

        // Blank line — paragraph break
        if (trim($line) === '') {
            $html .= '<br>' . "\n";
            continue;
        }

        // Regular paragraph line
        $html .= '<p>' . mkInlineMarkdown(h($line)) . '</p>' . "\n";
    }

    if ($inCode) {
        $html .= '</code></pre>' . "\n";
    }
    if ($inList) {
        $html .= '</ul>' . "\n";
    }

    return $html;
}

/**
 * Apply inline markdown to an already HTML-escaped string.
 * Order matters: code first to avoid double-processing.
 */
function mkInlineMarkdown(string $s): string {
    // Inline code: `code` (already HTML-escaped, so backtick is literal)
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);

    // Bold: **text** or __text__
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $s);

    // Italic: *text* or _text_
    $s = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $s);
    $s = preg_replace('/_([^_]+)_/', '<em>$1</em>', $s);

    // Links: [text](url)
    // Note: $s is HTML-escaped, so & in URLs becomes &amp; — keep it
    $s = preg_replace(
        '/\[([^\]]+)\]\(([^)]+)\)/',
        '<a href="$2" rel="noopener noreferrer">$1</a>',
        $s
    );

    return $s;
}

// ── Determine breadcrumb parent ───────────────────────────────────────────────
$pathLower    = strtolower($path);
$parentLabel  = 'Marketing';
$parentUrl    = '/port/marketing/';
if (str_contains($pathLower, 'queue')) {
    $parentLabel = 'Drafts Queue';
    $parentUrl   = '/port/marketing/drafts.php';
} elseif (str_contains($pathLower, 'published')) {
    $parentLabel = 'Published Archive';
    $parentUrl   = '/port/marketing/published.php';
} elseif (str_contains($pathLower, 'research')) {
    $parentLabel = 'Research';
    $parentUrl   = '/port/marketing/research.php';
}

$filename = basename($path);

// ── Inline mode — content fragment only (for AJAX preview) ───────────────────
if ($inline) {
    if ($fetchError || $content === null) {
        echo '<p class="mk-notice mk-notice--warn">Could not load file.</p>';
    } else {
        echo '<div class="mk-render-inline">' . mkRenderMarkdown($content) . '</div>';
    }
    exit;
}

// ── Full page render ──────────────────────────────────────────────────────────
renderHeader(h($filename) . ' — Marketing', [
    'user'        => $user,
    'module_slug' => 'marketing',
    'breadcrumb'  => [
        ['label' => 'Port',          'url' => '/port/'],
        ['label' => 'Marketing',     'url' => '/port/marketing/'],
        ['label' => $parentLabel,    'url' => $parentUrl],
        ['label' => $filename,       'url' => null],
    ],
]);
?>

<div class="mk-page mk-render-page">

  <div class="mk-page-header">
    <h1 class="mk-page-header__title mk-render-title">
      <svg class="icon" aria-hidden="true"><use href="/port/shared/assets/icons/lucide.svg#file-text"></use></svg>
      <?= h($filename) ?>
    </h1>
    <a href="<?= h($parentUrl) ?>" class="mk-back-link">← <?= h($parentLabel) ?></a>
  </div>

  <div class="mk-render-meta">
    <code class="mk-render-path"><?= h('cobenrogers/' . $repo . ' · ' . $path) ?></code>
  </div>

  <?php if ($path === ''): ?>
    <div class="mk-notice mk-notice--warn" role="alert">No file path specified.</div>

  <?php elseif ($fetchError): ?>
    <div class="mk-notice mk-notice--warn" role="alert">
      Could not load <code><?= h($path) ?></code> from
      <code>cobenrogers/<?= h($repo) ?></code>.
      Check that the file exists and <code>MK_GITHUB_TOKEN</code> is set.
    </div>

  <?php else: ?>
    <article class="mk-render-content">
      <?= mkRenderMarkdown($content) ?>
    </article>
  <?php endif; ?>

</div><!-- /.mk-page -->

<?php renderFooter(); ?>
