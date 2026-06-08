<?php
/**
 * Channel health helpers — extracted for unit-testability.
 * Included by marketing/index.php and tests/unit/marketing/ChannelHealthTest.php.
 */

declare(strict_types=1);

/**
 * Compute a channel health status string from Postiz error count and last published timestamp.
 *
 * Returns 'healthy', 'stale', or 'error'.
 * Issue: cobenrogers/bennernet-marketing#95
 */
function mkChannelStatus(?int $errors7d, ?string $lastPublished, bool $postizAvailable = true): string
{
    if (!$postizAvailable) return 'unknown';
    if ($errors7d !== null && $errors7d > 0) return 'error';
    if ($lastPublished === null) return 'stale';
    $age = (time() - strtotime($lastPublished)) / 86400;
    return $age <= 14 ? 'healthy' : 'stale';
}

/**
 * Group a Postiz posts array by known integration platform keys.
 *
 * Returns counts per platform: queued, published_7d, errors_7d, last_published.
 * Uses the POSTIZ_ID_* constants (must be defined before calling).
 *
 * Issue: cobenrogers/bennernet-marketing#94
 */
function mkPostizByPlatform(array $posts): array
{
    $idMap = [];
    if (defined('POSTIZ_ID_GLYC_MASTODON'))  $idMap[POSTIZ_ID_GLYC_MASTODON]  = 'glyc_mastodon';
    if (defined('POSTIZ_ID_IBD_MASTODON'))   $idMap[POSTIZ_ID_IBD_MASTODON]   = 'ibd_mastodon';
    if (defined('POSTIZ_ID_GLYC_BLUESKY'))   $idMap[POSTIZ_ID_GLYC_BLUESKY]   = 'glyc_bluesky';
    if (defined('POSTIZ_ID_IBD_BLUESKY'))    $idMap[POSTIZ_ID_IBD_BLUESKY]    = 'ibd_bluesky';
    if (defined('POSTIZ_ID_GLYC_X'))         $idMap[POSTIZ_ID_GLYC_X]         = 'glyc_x';
    if (defined('POSTIZ_ID_IBD_X'))          $idMap[POSTIZ_ID_IBD_X]          = 'ibd_x';
    if (defined('POSTIZ_ID_GLYC_INSTAGRAM')) $idMap[POSTIZ_ID_GLYC_INSTAGRAM] = 'glyc_instagram';
    if (defined('POSTIZ_ID_IBD_INSTAGRAM'))  $idMap[POSTIZ_ID_IBD_INSTAGRAM]  = 'ibd_instagram';

    $result = [];
    foreach (array_unique(array_values($idMap)) as $key) {
        $result[$key] = ['queued' => 0, 'published_7d' => 0, 'errors_7d' => 0, 'last_published' => null];
    }
    foreach (['glyc_mastodon', 'ibd_mastodon', 'glyc_bluesky', 'ibd_bluesky', 'glyc_x', 'ibd_x', 'glyc_instagram', 'ibd_instagram'] as $key) {
        if (!isset($result[$key])) {
            $result[$key] = ['queued' => 0, 'published_7d' => 0, 'errors_7d' => 0, 'last_published' => null];
        }
    }

    $cutoff7d = time() - 7 * 86400;

    foreach ($posts as $post) {
        $integrationId = $post['integration']['id'] ?? ($post['integrationId'] ?? '');
        $platformKey   = $idMap[$integrationId] ?? null;
        if ($platformKey === null) {
            continue;
        }
        $state       = $post['state'] ?? '';
        $publishedAt = $post['publishDate'] ?? ($post['publishedAt'] ?? ($post['createdAt'] ?? null));
        $postTs      = $publishedAt !== null ? strtotime($publishedAt) : false;

        if ($state === 'QUEUE') {
            $result[$platformKey]['queued']++;
        } elseif ($state === 'PUBLISHED') {
            if ($postTs !== false && $postTs >= $cutoff7d) {
                $result[$platformKey]['published_7d']++;
            }
            $current = $result[$platformKey]['last_published'];
            if ($publishedAt !== null && ($current === null || $publishedAt > $current)) {
                $result[$platformKey]['last_published'] = $publishedAt;
            }
        } elseif ($state === 'ERROR') {
            if ($postTs !== false && $postTs >= $cutoff7d) {
                $result[$platformKey]['errors_7d']++;
            }
        }
    }

    return $result;
}
