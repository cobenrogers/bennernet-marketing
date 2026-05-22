<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MarketingTestBootstrap.php';

/**
 * Tests for mkGa4Users() parsing logic.
 *
 * mkGa4Users() constructs a 14-day sparkline and a 7-day user total from
 * GA4 Data API rows. Since the function hard-codes HTTP calls and credential
 * file reads, we test it in two layers:
 *
 *   1. Guard tests — the function returns null when credentials are not
 *      configured or the property ID is empty.
 *
 *   2. Row-parsing logic — we mirror the date-map + sparkline-building logic
 *      from mkGa4Users() in a private helper so we can assert on:
 *        - daily row parsing (dimensionValues / metricValues)
 *        - 7-day total calculation (last 7 of the 14-element window)
 *        - 14-element sparkline shape (oldest → newest)
 *        - zero-fill for missing dates
 *
 * Issue: cobenrogers/mission-control-wiki#99
 */
class Ga4MetricsTest extends TestCase
{
    // ── 1. Guard: no credentials → null ─────────────────────────────────────

    public function test_returns_null_when_credentials_path_empty(): void
    {
        // MK_GA4_CREDENTIALS_PATH is '' in the test environment
        $result = mkGa4Users('518966874');
        $this->assertNull($result, 'mkGa4Users() must return null when credentials path is empty');
    }

    public function test_returns_null_when_property_id_is_empty(): void
    {
        $result = mkGa4Users('');
        $this->assertNull($result);
    }

    // ── 2. Row-parsing: daily rows → date map ────────────────────────────────

    public function test_parses_single_row_correctly(): void
    {
        $today = date('Ymd');
        $rows  = [
            $this->makeRow($today, 42),
        ];
        $result = $this->buildSparklineResult($rows);
        $this->assertSame(42, $result['users']);
    }

    public function test_missing_dates_are_zero_filled(): void
    {
        // Only provide one row — all other 13 days should be 0
        $dayMinus3 = date('Ymd', strtotime('-3 days'));
        $rows = [$this->makeRow($dayMinus3, 10)];
        $result = $this->buildSparklineResult($rows);

        // Total should only count last 7 days; -3 days is within the 7d window
        $this->assertGreaterThanOrEqual(10, $result['users']);
        $this->assertSame(14, count($result['sparkline']));
    }

    // ── 3. Sparkline shape ────────────────────────────────────────────────────

    public function test_sparkline_always_has_14_elements(): void
    {
        $result = $this->buildSparklineResult([]);
        $this->assertCount(14, $result['sparkline']);
    }

    public function test_sparkline_all_zeros_when_no_rows(): void
    {
        $result = $this->buildSparklineResult([]);
        $this->assertSame(array_fill(0, 14, 0), $result['sparkline']);
    }

    public function test_sparkline_has_14_elements_with_full_data(): void
    {
        $rows = [];
        for ($i = 13; $i >= 0; $i--) {
            $rows[] = $this->makeRow(date('Ymd', strtotime("-{$i} days")), $i + 1);
        }
        $result = $this->buildSparklineResult($rows);
        $this->assertCount(14, $result['sparkline']);
    }

    public function test_sparkline_is_ordered_oldest_to_newest(): void
    {
        // Day-13-ago gets value 14, day-0 (today) gets value 1
        $rows = [];
        for ($i = 13; $i >= 0; $i--) {
            $rows[] = $this->makeRow(date('Ymd', strtotime("-{$i} days")), 14 - $i);
        }
        $result = $this->buildSparklineResult($rows);
        // sparkline[0] = oldest (-13d) = value 1; sparkline[13] = today = value 14
        $this->assertSame(1, $result['sparkline'][0], 'First sparkline element should be oldest day');
        $this->assertSame(14, $result['sparkline'][13], 'Last sparkline element should be today');
    }

    // ── 4. 7-day total calculation ────────────────────────────────────────────

    public function test_7d_total_sums_last_7_days(): void
    {
        // Give each day value 10; only last 7 days count toward total
        $rows = [];
        for ($i = 13; $i >= 0; $i--) {
            $rows[] = $this->makeRow(date('Ymd', strtotime("-{$i} days")), 10);
        }
        $result = $this->buildSparklineResult($rows);
        $this->assertSame(70, $result['users'], '7-day total must sum exactly the last 7 days (inclusive of today)');
    }

    public function test_7d_total_excludes_days_older_than_7(): void
    {
        // Only set rows for days 8–13 (outside the 7-day window)
        $rows = [];
        for ($i = 13; $i >= 7; $i--) {
            $rows[] = $this->makeRow(date('Ymd', strtotime("-{$i} days")), 100);
        }
        $result = $this->buildSparklineResult($rows);
        $this->assertSame(0, $result['users'], 'Days older than 7 must not count toward the 7d total');
    }

    public function test_7d_total_is_zero_when_no_rows(): void
    {
        $result = $this->buildSparklineResult([]);
        $this->assertSame(0, $result['users']);
    }

    public function test_result_shape_has_users_and_sparkline_keys(): void
    {
        $result = $this->buildSparklineResult([]);
        $this->assertArrayHasKey('users', $result);
        $this->assertArrayHasKey('sparkline', $result);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build a GA4 API row in the format returned by the Data API.
     */
    private function makeRow(string $dateYmd, int $users): array
    {
        return [
            'dimensionValues' => [['value' => $dateYmd]],
            'metricValues'    => [['value' => (string)$users]],
        ];
    }

    /**
     * Mirror the date-map + sparkline + 7-day total logic from mkGa4Users().
     * This isolates the parsing logic so it can be tested without real HTTP calls.
     *
     * @param array[] $rows GA4 API rows (each with dimensionValues + metricValues)
     * @return array{'users': int, 'sparkline': int[]}
     */
    private function buildSparklineResult(array $rows): array
    {
        $dateMap = [];
        foreach ($rows as $row) {
            $date  = $row['dimensionValues'][0]['value'] ?? null;
            $count = (int)($row['metricValues'][0]['value'] ?? 0);
            if ($date) {
                $dateMap[$date] = $count;
            }
        }

        $sparkline  = [];
        $totalUsers = 0;
        $sevenStart = date('Ymd', strtotime('-6 days')); // -6d .. today = 7 days inclusive
        for ($i = 13; $i >= 0; $i--) {
            $date  = date('Ymd', strtotime("-{$i} days"));
            $count = $dateMap[$date] ?? 0;
            $sparkline[] = $count;
            if ($date >= $sevenStart) {
                $totalUsers += $count;
            }
        }

        return ['users' => $totalUsers, 'sparkline' => $sparkline];
    }
}
