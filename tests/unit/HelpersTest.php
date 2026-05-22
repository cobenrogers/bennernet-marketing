<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MarketingTestBootstrap.php';

/**
 * Tests for pure helper functions from marketing/index.php and marketing/tile.php.
 *
 * Both files have side effects on include (requireModuleAccess(), renderHeader(),
 * header(), echo, exit). We extract only the function definitions by using
 * output buffering + a stub requireModuleAccess before the include, and by
 * wrapping the include in a way that prevents the top-level code from running.
 *
 * Strategy: define all required stubs first, then use require_once with
 * output buffering to silently swallow any HTML emitted.
 *
 * Issue: cobenrogers/mission-control-wiki#99
 */
class HelpersTest extends TestCase
{
    // ── mkInferPlatform ───────────────────────────────────────────────────────

    public function test_infer_platform_bluesky_from_bsky(): void
    {
        $this->assertSame('Bluesky', mkInferPlatform('2026-05-20-bsky-gut-health.md'));
    }

    public function test_infer_platform_bluesky_from_full_word(): void
    {
        $this->assertSame('Bluesky', mkInferPlatform('bluesky-thread-draft.md'));
    }

    public function test_infer_platform_mastodon_from_masto(): void
    {
        $this->assertSame('Mastodon', mkInferPlatform('masto-ibd-post.md'));
    }

    public function test_infer_platform_mastodon_from_full_word(): void
    {
        $this->assertSame('Mastodon', mkInferPlatform('mastodon/glyc/2026-05-15.md'));
    }

    public function test_infer_platform_linkedin(): void
    {
        $this->assertSame('LinkedIn', mkInferPlatform('linkedin-article.md'));
    }

    public function test_infer_platform_linkedin_dash_prefix(): void
    {
        $this->assertSame('LinkedIn', mkInferPlatform('glyc-li-post.md'));
    }

    public function test_infer_platform_reddit(): void
    {
        $this->assertSame('Reddit', mkInferPlatform('reddit-ama-draft.md'));
    }

    public function test_infer_platform_twitter(): void
    {
        $this->assertSame('X/Twitter', mkInferPlatform('twitter-thread.md'));
    }

    public function test_infer_platform_twitter_x_dash(): void
    {
        $this->assertSame('X/Twitter', mkInferPlatform('2026-05-01-x-post.md'));
    }

    public function test_infer_platform_instagram(): void
    {
        $this->assertSame('Instagram', mkInferPlatform('instagram-reel.md'));
    }

    public function test_infer_platform_insta_shortform(): void
    {
        $this->assertSame('Instagram', mkInferPlatform('insta-story.md'));
    }

    public function test_infer_platform_unknown_for_unrecognised(): void
    {
        $this->assertSame('Unknown', mkInferPlatform('random-post.md'));
    }

    public function test_infer_platform_case_insensitive(): void
    {
        $this->assertSame('Bluesky', mkInferPlatform('BSKY-POST.MD'));
    }

    // ── mkPlatformBadgeClass ─────────────────────────────────────────────────

    public function test_badge_class_bluesky(): void
    {
        $this->assertSame('bluesky', mkPlatformBadgeClass('Bluesky'));
    }

    public function test_badge_class_mastodon(): void
    {
        $this->assertSame('mastodon', mkPlatformBadgeClass('Mastodon'));
    }

    public function test_badge_class_linkedin(): void
    {
        $this->assertSame('linkedin', mkPlatformBadgeClass('LinkedIn'));
    }

    public function test_badge_class_reddit(): void
    {
        $this->assertSame('reddit', mkPlatformBadgeClass('Reddit'));
    }

    public function test_badge_class_twitter(): void
    {
        $this->assertSame('twitter', mkPlatformBadgeClass('X/Twitter'));
    }

    public function test_badge_class_instagram(): void
    {
        $this->assertSame('instagram', mkPlatformBadgeClass('Instagram'));
    }

    public function test_badge_class_unknown_returns_neutral(): void
    {
        $this->assertSame('neutral', mkPlatformBadgeClass('Unknown'));
    }

    public function test_badge_class_arbitrary_string_returns_neutral(): void
    {
        $this->assertSame('neutral', mkPlatformBadgeClass('SomeNewPlatform'));
    }

    // ── mkRelativeTime ────────────────────────────────────────────────────────

    public function test_relative_time_today(): void
    {
        $filename = date('Y-m-d') . '-some-post.md';
        $this->assertSame('today', mkRelativeTime($filename));
    }

    public function test_relative_time_yesterday(): void
    {
        $filename = date('Y-m-d', strtotime('-1 day')) . '-old-post.md';
        $this->assertSame('yesterday', mkRelativeTime($filename));
    }

    public function test_relative_time_three_days_ago(): void
    {
        $filename = date('Y-m-d', strtotime('-3 days')) . '-post.md';
        $this->assertSame('3d ago', mkRelativeTime($filename));
    }

    public function test_relative_time_six_days_ago(): void
    {
        $filename = date('Y-m-d', strtotime('-6 days')) . '-post.md';
        $this->assertSame('6d ago', mkRelativeTime($filename));
    }

    public function test_relative_time_two_weeks_ago(): void
    {
        $filename = date('Y-m-d', strtotime('-14 days')) . '-post.md';
        $this->assertSame('2wk ago', mkRelativeTime($filename));
    }

    public function test_relative_time_no_date_prefix_returns_empty(): void
    {
        $this->assertSame('', mkRelativeTime('no-date-here.md'));
    }

    public function test_relative_time_empty_string_returns_empty(): void
    {
        $this->assertSame('', mkRelativeTime(''));
    }

    // ── mkMetric ─────────────────────────────────────────────────────────────

    public function test_metric_returns_correct_shape(): void
    {
        $m = mkMetric('Users', 42, null);
        $this->assertSame('Users', $m['label']);
        $this->assertSame(42, $m['value']);
        $this->assertNull($m['delta']);
        $this->assertSame('raw', $m['delta_format']);
        $this->assertSame('neutral', $m['delta_direction']);
    }

    public function test_metric_accepts_custom_format_and_direction(): void
    {
        $m = mkMetric('Clicks', 100, '+5', 'percent', 'up');
        $this->assertSame('percent', $m['delta_format']);
        $this->assertSame('up', $m['delta_direction']);
        $this->assertSame('+5', $m['delta']);
    }

    public function test_metric_with_zero_value(): void
    {
        $m = mkMetric('Posts', 0, null);
        $this->assertSame(0, $m['value']);
    }

    public function test_metric_has_all_required_keys(): void
    {
        $m = mkMetric('Followers', 99, null);
        foreach (['label', 'value', 'delta', 'delta_format', 'delta_direction'] as $key) {
            $this->assertArrayHasKey($key, $m, "Missing key: {$key}");
        }
    }

    // ── mkMetricStub ──────────────────────────────────────────────────────────

    public function test_metric_stub_returns_correct_label(): void
    {
        $m = mkMetricStub('Organic clicks');
        $this->assertSame('Organic clicks', $m['label']);
    }

    public function test_metric_stub_value_is_null(): void
    {
        $m = mkMetricStub('Users');
        $this->assertNull($m['value']);
    }

    public function test_metric_stub_delta_is_null(): void
    {
        $m = mkMetricStub('Users');
        $this->assertNull($m['delta']);
    }

    public function test_metric_stub_has_all_required_keys(): void
    {
        $m = mkMetricStub('Test');
        foreach (['label', 'value', 'delta', 'delta_format', 'delta_direction'] as $key) {
            $this->assertArrayHasKey($key, $m, "Missing key: {$key}");
        }
    }

    public function test_metric_stub_delta_direction_is_neutral(): void
    {
        $m = mkMetricStub('Test');
        $this->assertSame('neutral', $m['delta_direction']);
    }
}
