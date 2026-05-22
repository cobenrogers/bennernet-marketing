<?php
/**
 * PHPUnit bootstrap for bennernet-marketing tests.
 *
 * Defines MODULE_ROOT (repo root) and PORT_ROOT (port-stub directory so that
 * tile.php / index.php can require_once PORT_ROOT . '/shared/shell.php' in
 * the test environment without a real Port installation).
 */

define('MODULE_ROOT', dirname(__DIR__));
define('PORT_ROOT',   __DIR__ . '/fixtures/port-stub');
define('PORT_ENV',    'testing');

// Use a per-process temp dir for caches and logs so test runs are isolated
// and never hit a stale cache from a previous run. tile.php's cache-hit path
// calls exit(), which flushes ob buffers and leaks JSON into test output —
// a fresh dir prevents that.
$testTmpDir = sys_get_temp_dir() . '/marketing_tests_' . getmypid();
mkdir($testTmpDir, 0755, true);
define('PORT_LOG_DIR', $testTmpDir . '/logs');
// MK_CACHE_DIR defined here so tile.php always misses the cache in tests.
define('MK_CACHE_DIR',  $testTmpDir . '/cache');
