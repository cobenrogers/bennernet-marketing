<?php
define('PORT_ROOT', '/home2/mirdiwmy/public_html/website_70366ca4/port');
define('AUTH_ROOT', '/home2/mirdiwmy/public_html/website_70366ca4/auth');
define('PORT_DB_HOST', 'localhost');
define('PORT_DB_NAME', 'mirdiwmy_bennernet');
define('PORT_DB_USER', 'mirdiwmy_bennernet');
define('PORT_DB_PASS', 'YOUR_DB_PASSWORD_HERE');
define('PORT_ENV', 'production');
define('PORT_ASSET_VERSION', '1.0.0');
define('PORT_LOG_DIR', '/home2/mirdiwmy/logs/port');
define('MK_GITHUB_TOKEN', 'YOUR_GITHUB_TOKEN_HERE');
define('MK_CACHE_DIR', '/home2/mirdiwmy/cache/marketing');
// Local workspace path — directory containing queue/ and published/ subdirs.
// Set to the absolute path where glyc/docs/marketing/workspace lives on this host.
// When defined, index.php reads draft counts and recent published files from the
// local filesystem instead of the GitHub API (so unpushed files are visible).
define('MK_WORKSPACE_PATH', '/home/YOUR_USER/glyc/docs/marketing/workspace');
// GA4 — SA: bennernet-analytics-reader@bennernet-web-analytics.iam.gserviceaccount.com
// Key: upload JSON to ~/keys/ga4-marketing.json (chmod 600) on Bluehost
// Property IDs: GA4 Admin → Property Settings → Property ID (numeric)
define('MK_GA4_CREDENTIALS_PATH', '/home2/mirdiwmy/keys/ga4-marketing.json');
define('MK_GA4_PROPERTY_GLYC', '518966874');  // getglyc.com — account 379804471
define('MK_GA4_PROPERTY_IBD',  '501432462');  // ibdmovement.com — account 365443199
// Bluesky — public API (no token); follower counts via bsky.app actor profile
define('MK_BLUESKY_HANDLE_GLYC', 'bennernet.bsky.social');
define('MK_BLUESKY_HANDLE_IBD',  'ibdmovement.bsky.social');
// Mastodon — public API (no token); follower counts via /api/v1/accounts/lookup
define('MK_MASTODON_INSTANCE_GLYC', 'mastodon.social');
define('MK_MASTODON_HANDLE_GLYC',   'glyc');
define('MK_MASTODON_INSTANCE_IBD',  'mastodon.social');
define('MK_MASTODON_HANDLE_IBD',    'theibdmovement');
// Postiz proxy via bridge — add in v0.2
// define('MK_BRIDGE_TOKEN', 'YOUR_BRIDGE_TOKEN_HERE');
// define('MK_BRIDGE_URL', 'https://pop-os.tail3d9bfc.ts.net');
// X/Twitter — OAuth2 app-only credentials (Twitter Developer Portal → App → Keys and Tokens)
// API Key = Consumer Key, API Key Secret = Consumer Secret
define('MK_X_API_KEY',       'YOUR_X_API_KEY_HERE');
define('MK_X_API_SECRET',    'YOUR_X_API_SECRET_HERE');
define('MK_X_USERNAME_GLYC', 'getglyc');
define('MK_X_USERNAME_IBD',  'IBDMovement');
