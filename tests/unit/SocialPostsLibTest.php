<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/social-posts-lib.php';

/**
 * Tests for social-posts-lib.php — the pure thread-chain / settings helpers
 * used by social-posts-api.php's publish_now action.
 *
 * Issue: cobenrogers/bennernet-marketing#37
 */
class SocialPostsLibTest extends TestCase
{
    // ── mkDecodeImageField ────────────────────────────────────────────────────

    public function testDecodeImageFieldReturnsEmptyForNull(): void
    {
        $this->assertSame([], mkDecodeImageField(null));
    }

    public function testDecodeImageFieldDecodesJsonArray(): void
    {
        $raw = json_encode([['id' => 'a', 'url' => 'https://x/a.jpg', 'path' => 'https://x/a.jpg']]);
        $result = mkDecodeImageField($raw);
        $this->assertCount(1, $result);
        $this->assertSame('https://x/a.jpg', $result[0]['url']);
    }

    public function testDecodeImageFieldWrapsBareUrlString(): void
    {
        $result = mkDecodeImageField('https://example.com/img.jpg');
        $this->assertSame('https://example.com/img.jpg', $result[0]['url']);
    }

    public function testDecodeImageFieldPreservesAltKeyWhenPresent(): void
    {
        $raw = json_encode([['id' => 'a', 'url' => 'https://x/a.jpg', 'path' => 'https://x/a.jpg', 'alt' => 'A bowl of soup']]);
        $result = mkDecodeImageField($raw);
        $this->assertSame('A bowl of soup', $result[0]['alt']);
    }

    // ── mkFindThreadRoot ──────────────────────────────────────────────────────

    public function testFindThreadRootReturnsSelfWhenNoParent(): void
    {
        $fields = ['content' => 'main tweet', 'image' => null, 'parentPostId' => null];
        $root = mkFindThreadRoot('post-1', $fields, fn($id) => null);

        $this->assertSame('post-1', $root['id']);
        $this->assertSame('main tweet', $root['content']);
    }

    public function testFindThreadRootWalksSingleParent(): void
    {
        $startFields = ['content' => 'reply', 'image' => null, 'parentPostId' => 'post-1'];
        $fetchFields = function (string $id) {
            if ($id === 'post-1') {
                return ['content' => 'main tweet', 'image' => null, 'parentPostId' => null];
            }
            return null;
        };

        $root = mkFindThreadRoot('post-2', $startFields, $fetchFields);

        $this->assertSame('post-1', $root['id']);
        $this->assertSame('main tweet', $root['content']);
    }

    public function testFindThreadRootWalksMultiHopChain(): void
    {
        // post-7 -> post-6 -> ... -> post-1 (root)
        $chain = [];
        for ($i = 1; $i <= 7; $i++) {
            $chain["post-$i"] = [
                'content'      => "tweet $i",
                'image'        => null,
                'parentPostId' => $i > 1 ? 'post-' . ($i - 1) : null,
            ];
        }
        $fetchFields = fn(string $id) => $chain[$id] ?? null;

        $root = mkFindThreadRoot('post-7', $chain['post-7'], $fetchFields);

        $this->assertSame('post-1', $root['id']);
        $this->assertSame('tweet 1', $root['content']);
    }

    public function testFindThreadRootStopsAtMaxDepthOnCyclicData(): void
    {
        // Pathological: parentPostId points back to itself — must not infinite-loop.
        $startFields = ['content' => 'x', 'image' => null, 'parentPostId' => 'post-1'];
        $fetchFields = fn(string $id) => ['content' => 'x', 'image' => null, 'parentPostId' => 'post-1'];

        $root = mkFindThreadRoot('post-1', $startFields, $fetchFields, maxDepth: 5);

        // Should terminate (not hang) — exact id doesn't matter, just must return.
        $this->assertIsString($root['id']);
    }

    public function testFindThreadRootTreatsUnreadableParentAsRoot(): void
    {
        $startFields = ['content' => 'reply', 'image' => null, 'parentPostId' => 'ghost-id'];
        $root = mkFindThreadRoot('post-2', $startFields, fn($id) => null);

        $this->assertSame('post-2', $root['id']);
        $this->assertSame('reply', $root['content']);
    }

    // ── mkBuildThreadChain ────────────────────────────────────────────────────

    public function testBuildThreadChainSingleTweetNoChildren(): void
    {
        $result = mkBuildThreadChain('post-1', 'solo tweet', [], fn($id) => null);

        $this->assertCount(1, $result['entries']);
        $this->assertSame(['post-1'], $result['ids']);
        $this->assertSame('solo tweet', $result['entries'][0]['content']);
    }

    public function testBuildThreadChainTwoRowExistingBehavior(): void
    {
        $children = ['post-1' => ['id' => 'post-2', 'content' => 'check the link', 'image' => null]];
        $fetchChild = fn(string $parentId) => $children[$parentId] ?? null;

        $result = mkBuildThreadChain('post-1', 'main tweet', [], $fetchChild);

        $this->assertSame(['post-1', 'post-2'], $result['ids']);
        $this->assertCount(2, $result['entries']);
        $this->assertSame('main tweet', $result['entries'][0]['content']);
        $this->assertSame('check the link', $result['entries'][1]['content']);
    }

    public function testBuildThreadChainSevenRowThread(): void
    {
        // post-1 (root) -> post-2 -> ... -> post-7
        $children = [];
        for ($i = 1; $i <= 6; $i++) {
            $children["post-$i"] = ['id' => 'post-' . ($i + 1), 'content' => "tweet " . ($i + 1), 'image' => null];
        }
        $fetchChild = fn(string $parentId) => $children[$parentId] ?? null;

        $result = mkBuildThreadChain('post-1', 'tweet 1', [], $fetchChild);

        $this->assertCount(7, $result['entries']);
        $this->assertSame(
            ['post-1', 'post-2', 'post-3', 'post-4', 'post-5', 'post-6', 'post-7'],
            $result['ids']
        );
        $this->assertSame('tweet 1', $result['entries'][0]['content']);
        $this->assertSame('tweet 7', $result['entries'][6]['content']);
    }

    public function testBuildThreadChainPreservesRootImageOnlyOnFirstEntry(): void
    {
        $children = ['post-1' => ['id' => 'post-2', 'content' => 'reply', 'image' => null]];
        $fetchChild = fn(string $parentId) => $children[$parentId] ?? null;

        $result = mkBuildThreadChain('post-1', 'main', [['url' => 'https://x/img.jpg']], $fetchChild);

        $this->assertSame([['url' => 'https://x/img.jpg']], $result['entries'][0]['image']);
        $this->assertSame([], $result['entries'][1]['image']);
    }

    public function testBuildThreadChainStopsAtMaxDepthOnCyclicChildData(): void
    {
        // Pathological: every parent reports the same child id forever.
        $fetchChild = fn(string $parentId) => ['id' => 'post-loop', 'content' => 'x', 'image' => null];

        $result = mkBuildThreadChain('post-1', 'root', [], $fetchChild, maxDepth: 5);

        // Must terminate — root + at most maxDepth children.
        $this->assertLessThanOrEqual(6, count($result['entries']));
    }

    // ── mkBuildPostSettings ───────────────────────────────────────────────────

    public function testBuildPostSettingsForX(): void
    {
        $settings = mkBuildPostSettings('x');
        $this->assertSame('x', $settings['__type']);
        $this->assertSame('everyone', $settings['who_can_reply_post']);
        $this->assertArrayNotHasKey('post_type', $settings);
    }

    public function testBuildPostSettingsForTwitterAliasTreatedAsX(): void
    {
        $settings = mkBuildPostSettings('twitter');
        $this->assertArrayHasKey('who_can_reply_post', $settings);
    }

    public function testBuildPostSettingsForMastodon(): void
    {
        $settings = mkBuildPostSettings('mastodon');
        $this->assertSame('mastodon', $settings['__type']);
        $this->assertSame('post', $settings['post_type']);
        // No content-warning field — Postiz's Mastodon provider does not support it.
        $this->assertArrayNotHasKey('spoiler_text', $settings);
        $this->assertArrayNotHasKey('content_warning', $settings);
    }

    // ── mkClassifyPublishFailure ──────────────────────────────────────────────
    //
    // Two distinct failure modes need different rollback behavior:
    //   'rejected' — Postiz gave us a clear error/non-ok response. We KNOW
    //                nothing was published. Safe to restore the draft as DRAFT
    //                for an immediate retry.
    //   'timeout'  — no response at all (network/timeout). We do NOT know
    //                whether Postiz actually processed the post. Restoring as
    //                DRAFT here would let a retry silently double-publish if
    //                Postiz's request actually succeeded after our timeout.
    //                Must restore as ERROR instead, forcing a human to check
    //                the platform before retrying.

    public function testClassifyPublishFailureNullResponseIsTimeout(): void
    {
        $result = mkClassifyPublishFailure(null);
        $this->assertSame('timeout', $result['kind']);
    }

    public function testClassifyPublishFailureErrorKeyIsRejected(): void
    {
        $result = mkClassifyPublishFailure(['error' => 'Invalid integration']);
        $this->assertSame('rejected', $result['kind']);
        $this->assertStringContainsString('Invalid integration', $result['message']);
    }

    public function testClassifyPublishFailureNonOkStatusIsRejected(): void
    {
        $result = mkClassifyPublishFailure(['status' => 'error', 'message' => 'Bad request']);
        $this->assertSame('rejected', $result['kind']);
        $this->assertStringContainsString('Bad request', $result['message']);
    }

    public function testClassifyPublishFailureOkResponseReturnsNoFailure(): void
    {
        $result = mkClassifyPublishFailure(['status' => 'ok', 'postId' => 'abc']);
        $this->assertNull($result);
    }

    // ── mkRollbackStateForFailureKind ─────────────────────────────────────────

    public function testRollbackStateForRejectedIsDraft(): void
    {
        $this->assertSame('DRAFT', mkRollbackStateForFailureKind('rejected'));
    }

    public function testRollbackStateForTimeoutIsError(): void
    {
        $this->assertSame('ERROR', mkRollbackStateForFailureKind('timeout'));
    }
}
