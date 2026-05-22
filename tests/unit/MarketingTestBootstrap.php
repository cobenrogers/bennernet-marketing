<?php
/**
 * Marketing test bootstrap — loaded via require_once at the top of each
 * marketing unit test file.
 *
 * tile.php and index.php mix top-level executable code (HTTP headers, JSON
 * output, full HTML pages) with pure helper function definitions. This helper
 * loads both files exactly once, using output buffering to discard the rendered
 * output, and suppresses "constant already defined" warnings that arise because
 * marketing/config.php defines the same constants as tests/bootstrap.php.
 *
 * After this file runs, the following functions are available:
 *   From tile.php:  mkMetric, mkMetricStub, mkGscTotals, mkGa4Users,
 *                   mkPostizPostCounts, mkBlueskyFollowers, mkMastodonFollowers
 *   From index.php: mkInferPlatform, mkPlatformBadgeClass, mkRelativeTime
 *
 * Issue: cobenrogers/mission-control-wiki#99
 */

declare(strict_types=1);

static $marketingLoaded = false;
if ($marketingLoaded) {
    return;
}
$marketingLoaded = true;

// ── Stub auth user so requireModuleAccess() in index.php returns immediately ──
if (!isset($GLOBALS['_stub_auth_user'])) {
    $GLOBALS['_stub_auth_user'] = [
        'id'          => 'test-admin-001',
        'name'        => 'Test Admin',
        'email'       => 'admin@test.local',
        'avatar_url'  => null,
        'is_admin'    => 1,
        'is_approved' => 1,
    ];
}

// ── Suppress constant-redefinition warnings from marketing/config.php ─────────
$prevHandler = set_error_handler(static function (int $errno, string $errstr): bool {
    return str_contains($errstr, 'already defined');
});

// ── Load tile.php — CLI SAPI, so requireModuleAccess() is skipped ─────────────
ob_start();
require_once MODULE_ROOT . '/tile.php';
ob_end_clean();

// ── Load index.php — emits full HTML page, discard output ────────────────────
ob_start();
require_once MODULE_ROOT . '/index.php';
ob_end_clean();

restore_error_handler();
