<?php
/**
 * Test config stub — used in CI to stand in for the live config.php.
 *
 * Defines all MK_* constants with safe placeholder values so tile.php and
 * index.php can be loaded without errors. No real credentials are present.
 *
 * PORT_ROOT is already defined by tests/bootstrap.php (points to port-stub).
 * The "already defined" suppression in MarketingTestBootstrap handles any
 * duplicate-define warnings if config.php is loaded after bootstrap.php.
 */

// Suppress re-definition warnings — bootstrap.php defines PORT_ROOT first.
if (!defined('PORT_ROOT')) {
    define('PORT_ROOT', __DIR__ . '/../port-stub');
}
if (!defined('AUTH_ROOT')) {
    define('AUTH_ROOT', __DIR__ . '/../port-stub');
}
if (!defined('PORT_ENV')) {
    define('PORT_ENV', 'testing');
}
if (!defined('PORT_ASSET_VERSION')) {
    define('PORT_ASSET_VERSION', '0.0.0-test');
}
if (!defined('PORT_LOG_DIR')) {
    define('PORT_LOG_DIR', sys_get_temp_dir());
}
if (!defined('PORT_DB_HOST')) {
    define('PORT_DB_HOST', 'localhost');
}
if (!defined('PORT_DB_NAME')) {
    define('PORT_DB_NAME', 'test');
}
if (!defined('PORT_DB_USER')) {
    define('PORT_DB_USER', 'test');
}
if (!defined('PORT_DB_PASS')) {
    define('PORT_DB_PASS', '');
}
define('MK_GITHUB_TOKEN',           'test-token');
define('MK_CACHE_DIR',              sys_get_temp_dir() . '/mk_cache_test');
define('MK_WORKSPACE_PATH',         sys_get_temp_dir() . '/mk_workspace_test');
define('MK_GA4_CREDENTIALS_PATH',   '');
define('MK_GA4_PROPERTY_GLYC',      '518966874');
define('MK_GA4_PROPERTY_IBD',       '501432462');
define('MK_MASTODON_INSTANCE_GLYC', 'mastodon.social');
define('MK_MASTODON_HANDLE_GLYC',   'glyc');
define('MK_MASTODON_INSTANCE_IBD',  'mastodon.social');
define('MK_MASTODON_HANDLE_IBD',    'theibdmovement');
define('MK_X_API_KEY',              '');
define('MK_X_API_SECRET',           '');
define('MK_X_USERNAME_GLYC',        'getglyc');
define('MK_X_USERNAME_IBD',         'IBDMovement');
define('MK_POSTIZ_API_KEY',         'test-postiz-key');
define('MK_POSTIZ_INTEGRATION_GLYC_BSKY', 'test-glyc-bsky-id');
define('MK_POSTIZ_INTEGRATION_IBD_BSKY',  'test-ibd-bsky-id');
define('MK_POSTIZ_INTEGRATION_GLYC_MASTO','test-glyc-masto-id');
define('MK_POSTIZ_INTEGRATION_IBD_MASTO', 'test-ibd-masto-id');
