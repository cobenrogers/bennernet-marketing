<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MarketingTestBootstrap.php';

/**
 * Tests for mkPostizByPlatform() — pure grouping/counting function defined in index.php.
 *
 * The bootstrap loads index.php via ob_start/ob_end_clean so the function is
 * available here without triggering any HTTP output.
 *
 * Issue: cobenrogers/bennernet-marketing#94
 */
class PostizPipelineTest extends TestCase
{
    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * A recent ISO timestamp (within 7 days).
     */
    private function recentTs(): string
    {
        return date('c', strtotime('-2 days'));
    }

    /**
     * An old ISO timestamp (older than 7 days).
     */
    private function oldTs(): string
    {
        return date('c', strtotime('-10 days'));
    }

    /**
     * Build a minimal Postiz post array entry.
     */
    private function makePost(string $integrationId, string $state, string $publishedAt): array
    {
        return [
            'integration'  => ['id' => $integrationId],
            'state'        => $state,
            'publishDate'  => $publishedAt,
        ];
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * Groups posts correctly by platform key with accurate counts.
     */
    public function testPostizByPlatformGroupsCorrectly(): void
    {
        $glycMasto = POSTIZ_ID_GLYC_MASTODON;
        $ibdBsky   = POSTIZ_ID_IBD_BLUESKY;
        $recent    = $this->recentTs();

        $posts = [
            $this->makePost($glycMasto, 'QUEUE',     $recent),
            $this->makePost($glycMasto, 'QUEUE',     $recent),
            $this->makePost($glycMasto, 'PUBLISHED', $recent),
            $this->makePost($ibdBsky,   'PUBLISHED', $recent),
            $this->makePost($ibdBsky,   'ERROR',     $recent),
        ];

        $result = mkPostizByPlatform($posts);

        $this->assertArrayHasKey('glyc_mastodon', $result);
        $this->assertArrayHasKey('ibd_bluesky', $result);

        $this->assertSame(2, $result['glyc_mastodon']['queued']);
        $this->assertSame(1, $result['glyc_mastodon']['published_7d']);
        $this->assertSame(0, $result['glyc_mastodon']['errors_7d']);

        $this->assertSame(0, $result['ibd_bluesky']['queued']);
        $this->assertSame(1, $result['ibd_bluesky']['published_7d']);
        $this->assertSame(1, $result['ibd_bluesky']['errors_7d']);
    }

    /**
     * Empty post array results in all-zero counts for all four core platforms.
     */
    public function testPostizByPlatformHandlesEmptyResponse(): void
    {
        $result = mkPostizByPlatform([]);

        foreach (['glyc_mastodon', 'ibd_mastodon', 'glyc_bluesky', 'ibd_bluesky'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
            $this->assertSame(0, $result[$key]['queued'],        "{$key}.queued should be 0");
            $this->assertSame(0, $result[$key]['published_7d'], "{$key}.published_7d should be 0");
            $this->assertSame(0, $result[$key]['errors_7d'],    "{$key}.errors_7d should be 0");
            $this->assertNull($result[$key]['last_published'],  "{$key}.last_published should be null");
        }
    }

    /**
     * ERROR posts within 7 days are counted in errors_7d.
     */
    public function testPostizErrorCountFlagged(): void
    {
        $posts = [
            $this->makePost(POSTIZ_ID_GLYC_BLUESKY, 'ERROR', $this->recentTs()),
            $this->makePost(POSTIZ_ID_GLYC_BLUESKY, 'ERROR', $this->recentTs()),
        ];

        $result = mkPostizByPlatform($posts);

        $this->assertSame(2, $result['glyc_bluesky']['errors_7d']);
        $this->assertSame(0, $result['glyc_bluesky']['queued']);
        $this->assertSame(0, $result['glyc_bluesky']['published_7d']);
    }

    /**
     * last_published contains the ISO timestamp of the most recently published post.
     */
    public function testPostizLastPublishedExtracted(): void
    {
        $older  = date('c', strtotime('-3 days'));
        $newer  = date('c', strtotime('-1 day'));

        $posts = [
            $this->makePost(POSTIZ_ID_IBD_MASTODON, 'PUBLISHED', $older),
            $this->makePost(POSTIZ_ID_IBD_MASTODON, 'PUBLISHED', $newer),
        ];

        $result = mkPostizByPlatform($posts);

        $this->assertSame($newer, $result['ibd_mastodon']['last_published']);
    }

    /**
     * PUBLISHED posts older than 7 days are NOT counted in published_7d, but
     * last_published is still updated if there is no newer post.
     */
    public function testPostizPublishedOutsideWindowNotCounted(): void
    {
        $old    = $this->oldTs();
        $recent = $this->recentTs();

        $posts = [
            $this->makePost(POSTIZ_ID_GLYC_MASTODON, 'PUBLISHED', $old),
            $this->makePost(POSTIZ_ID_GLYC_MASTODON, 'PUBLISHED', $recent),
        ];

        $result = mkPostizByPlatform($posts);

        // Only the recent one counts toward published_7d
        $this->assertSame(1, $result['glyc_mastodon']['published_7d']);
        // Both are PUBLISHED so last_published is the more recent one
        $this->assertSame($recent, $result['glyc_mastodon']['last_published']);
    }
}
