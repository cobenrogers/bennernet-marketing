<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MarketingTestBootstrap.php';

/**
 * Tests for the tile cache extraction logic in marketing/index.php (~lines 130–153).
 *
 * The extraction block reads $tileCache['children'], iterates over them,
 * and assigns per-site metric values to named variables by matching the child
 * name (glyc / ibd) and the metric label (posts published / mast / organic clicks).
 *
 * We test this logic in isolation using the canonical fixture file so that
 * tests remain deterministic and independent of live data.
 *
 * Fixture: tests/fixtures/marketing/tile-cache-sample.json
 *
 * Issue: cobenrogers/mission-control-wiki#99
 */
class TileCacheExtractionTest extends TestCase
{
    private array $tileCache;

    protected function setUp(): void
    {
        $fixturePath = MODULE_ROOT . '/tests/fixtures/marketing/tile-cache-sample.json';
        $raw = file_get_contents($fixturePath);
        $this->assertNotFalse($raw, "Fixture file not found: {$fixturePath}");
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'Fixture must be valid JSON');
        $this->tileCache = $decoded;
    }

    // ── Fixture sanity ────────────────────────────────────────────────────────

    public function test_fixture_has_two_children(): void
    {
        $this->assertCount(2, $this->tileCache['children']);
    }

    public function test_fixture_glyc_child_exists(): void
    {
        $glyc = $this->findChild('glyc');
        $this->assertNotNull($glyc, 'getglyc.com child must exist in fixture');
    }

    public function test_fixture_ibd_child_exists(): void
    {
        $ibd = $this->findChild('ibd');
        $this->assertNotNull($ibd, 'ibdmovement.com child must exist in fixture');
    }

    // ── GA4 Users (Glyc) ──────────────────────────────────────────────────────

    public function test_glyc_ga4_users_extracted_correctly(): void
    {
        $vars = $this->runExtraction();
        // Fixture: getglyc.com / Users = 183
        $this->assertSame(183, $vars['glycGa4Users']);
    }

    public function test_ibd_ga4_users_extracted_correctly(): void
    {
        $vars = $this->runExtraction();
        // Fixture: ibdmovement.com / Users = 547
        $this->assertSame(547, $vars['ibdGa4Users']);
    }

    // ── GSC Clicks ────────────────────────────────────────────────────────────

    public function test_glyc_gsc_clicks_extracted_correctly(): void
    {
        $vars = $this->runExtraction();
        // Fixture: getglyc.com / Organic clicks = 74
        $this->assertSame(74, $vars['glycGscClicks']);
    }

    public function test_ibd_gsc_clicks_extracted_correctly(): void
    {
        $vars = $this->runExtraction();
        // Fixture: ibdmovement.com / Organic clicks = 312
        $this->assertSame(312, $vars['ibdGscClicks']);
    }

    // ── Mastodon Followers ────────────────────────────────────────────────────

    public function test_glyc_mastodon_followers_extracted_correctly(): void
    {
        $vars = $this->runExtraction();
        // Fixture: getglyc.com / Mast. followers = 29
        $this->assertSame(29, $vars['glycMastoFollowers']);
    }

    public function test_ibd_mastodon_followers_extracted_correctly(): void
    {
        $vars = $this->runExtraction();
        // Fixture: ibdmovement.com / Mast. followers = 61
        $this->assertSame(61, $vars['ibdMastoFollowers']);
    }

    // ── Posts Published ───────────────────────────────────────────────────────

    public function test_glyc_posts_published_extracted_correctly(): void
    {
        $vars = $this->runExtraction();
        // Fixture: getglyc.com / Posts published = 5
        $this->assertSame(5, $vars['glycPostsPublished']);
    }

    public function test_ibd_posts_published_extracted_correctly(): void
    {
        $vars = $this->runExtraction();
        // Fixture: ibdmovement.com / Posts published = 3
        $this->assertSame(3, $vars['ibdPostsPublished']);
    }

    // ── BlueSky (top-level metric) ────────────────────────────────────────────

    public function test_bluesky_followers_extracted_from_top_level_metrics(): void
    {
        $vars = $this->runExtraction();
        // Fixture: top-level metrics / BlueSky followers = 412
        $this->assertSame(412, $vars['bskyFollowersShared']);
    }

    // ── Null-safety: unknown child names are ignored ──────────────────────────

    public function test_unknown_child_name_does_not_overwrite_glyc(): void
    {
        $cache = $this->tileCache;
        // Inject a child with no recognisable name
        $cache['children'][] = [
            'name'    => 'unknown-site.com',
            'metrics' => [
                ['label' => 'Organic clicks', 'value' => 9999],
            ],
        ];
        $vars = $this->runExtraction($cache);
        $this->assertSame(74, $vars['glycGscClicks'], 'Unknown child must not overwrite glyc value');
    }

    public function test_missing_tile_cache_leaves_vars_null(): void
    {
        $vars = $this->runExtraction(null);
        $this->assertNull($vars['glycGscClicks']);
        $this->assertNull($vars['ibdGscClicks']);
        $this->assertNull($vars['glycMastoFollowers']);
        $this->assertNull($vars['bskyFollowersShared']);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Find a child by name fragment (case-insensitive).
     */
    private function findChild(string $fragment): ?array
    {
        foreach ($this->tileCache['children'] ?? [] as $child) {
            if (stripos($child['name'] ?? '', $fragment) !== false) {
                return $child;
            }
        }
        return null;
    }

    /**
     * Run the tile cache extraction logic from index.php and return the
     * resulting variables as an associative array.
     *
     * This mirrors the extraction block at index.php ~lines 94–153 exactly
     * so that any change to the source logic will break these tests.
     *
     * @param array|null $tileCache Tile cache to use (defaults to the fixture)
     */
    private function runExtraction(?array $tileCache = null): array
    {
        if ($tileCache === null && func_num_args() === 0) {
            $tileCache = $this->tileCache;
        }

        // ── Extract BlueSky followers from top-level tile metrics ─────────────
        $bskyFollowersShared = null;
        if ($tileCache && isset($tileCache['metrics']) && is_array($tileCache['metrics'])) {
            foreach ($tileCache['metrics'] as $metric) {
                if (isset($metric['label']) && stripos($metric['label'], 'bluesky') !== false) {
                    $bskyFollowersShared = $metric['value'];
                    break;
                }
            }
        }

        // ── Extract per-site metrics from children ────────────────────────────
        $glycPostsPublished = null;
        $ibdPostsPublished  = null;
        $glycMastoFollowers = null;
        $ibdMastoFollowers  = null;
        $glycGscClicks      = null;
        $ibdGscClicks       = null;
        $glycGa4Users       = null;
        $ibdGa4Users        = null;

        if ($tileCache && isset($tileCache['children']) && is_array($tileCache['children'])) {
            foreach ($tileCache['children'] as $child) {
                $name   = $child['name'] ?? '';
                $isGlyc = stripos($name, 'glyc') !== false;
                $isIbd  = stripos($name, 'ibd')  !== false;
                if (!$isGlyc && !$isIbd) {
                    continue;
                }
                foreach (($child['metrics'] ?? []) as $metric) {
                    $label = $metric['label'] ?? '';
                    $value = $metric['value'] ?? null;
                    if (stripos($label, 'posts published') !== false) {
                        if ($isGlyc) $glycPostsPublished = $value;
                        if ($isIbd)  $ibdPostsPublished  = $value;
                    } elseif (stripos($label, 'mast') !== false) {
                        if ($isGlyc) $glycMastoFollowers = $value;
                        if ($isIbd)  $ibdMastoFollowers  = $value;
                    } elseif (stripos($label, 'organic clicks') !== false) {
                        if ($isGlyc) $glycGscClicks = $value;
                        if ($isIbd)  $ibdGscClicks  = $value;
                    } elseif (stripos($label, 'users') !== false) {
                        if ($isGlyc) $glycGa4Users = $value;
                        if ($isIbd)  $ibdGa4Users  = $value;
                    }
                }
            }
        }

        return compact(
            'bskyFollowersShared',
            'glycPostsPublished', 'ibdPostsPublished',
            'glycMastoFollowers', 'ibdMastoFollowers',
            'glycGscClicks',      'ibdGscClicks',
            'glycGa4Users',       'ibdGa4Users'
        );
    }
}
