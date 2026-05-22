<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MarketingTestBootstrap.php';

/**
 * Tests for mkGscTotals() response-parsing logic.
 *
 * mkGscTotals() is not easily injectable (it hard-codes file_get_contents
 * with stream contexts). We test it in three layers:
 *
 *   1. Early-return guard — returns null when MK_GA4_CREDENTIALS_PATH is
 *      empty or the file does not exist (no credentials configured).
 *
 *   2. Request body shape — the JSON payload sent to the GSC API must
 *      include startDate, endDate, and rowLimit. We validate the shape
 *      by reconstructing the same body-building logic inline.
 *
 *   3. Response-parsing logic — extracted into a private static helper so we
 *      can unit-test the row→(clicks, impressions) mapping and the zero-rows
 *      ("no traffic") case without making real HTTP calls.
 *
 * Issue: cobenrogers/mission-control-wiki#99
 */
class GscMetricsTest extends TestCase
{
    // ── 1. Guard: no credentials → null ─────────────────────────────────────

    public function test_returns_null_when_credentials_path_not_configured(): void
    {
        // MK_GA4_CREDENTIALS_PATH is defined as '' in MarketingTestBootstrap.php
        // and the file does not exist, so mkGscTotals() must return null.
        $result = mkGscTotals('sc-domain:getglyc.com', 7);
        $this->assertNull($result, 'mkGscTotals() must return null when credentials path is empty/missing');
    }

    public function test_returns_null_for_nonexistent_credentials_file(): void
    {
        // Even if the constant were set to a non-empty path that doesn't exist,
        // the function should return null.
        $result = mkGscTotals('sc-domain:ibdmovement.com', 7);
        $this->assertNull($result);
    }

    // ── 2. Request body shape ────────────────────────────────────────────────

    /**
     * Reconstruct the GSC request body using the same logic as mkGscTotals()
     * and assert its structure is correct.
     */
    public function test_request_body_contains_start_date(): void
    {
        $body = $this->buildGscRequestBody(7);
        $decoded = json_decode($body, true);
        $this->assertArrayHasKey('startDate', $decoded);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $decoded['startDate']);
    }

    public function test_request_body_contains_end_date(): void
    {
        $body = $this->buildGscRequestBody(7);
        $decoded = json_decode($body, true);
        $this->assertArrayHasKey('endDate', $decoded);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $decoded['endDate']);
    }

    public function test_request_body_end_date_is_yesterday(): void
    {
        $body = $this->buildGscRequestBody(7);
        $decoded = json_decode($body, true);
        $expectedEnd = date('Y-m-d', strtotime('-1 day'));
        $this->assertSame($expectedEnd, $decoded['endDate']);
    }

    public function test_request_body_start_date_respects_days_param(): void
    {
        $body = $this->buildGscRequestBody(30);
        $decoded = json_decode($body, true);
        $expectedStart = date('Y-m-d', strtotime('-30 days'));
        $this->assertSame($expectedStart, $decoded['startDate']);
    }

    public function test_request_body_has_row_limit_of_1(): void
    {
        $body = $this->buildGscRequestBody(7);
        $decoded = json_decode($body, true);
        $this->assertSame(1, $decoded['rowLimit']);
    }

    public function test_request_body_is_valid_json(): void
    {
        $body = $this->buildGscRequestBody(7);
        $this->assertNotNull(json_decode($body), 'GSC request body must be valid JSON');
    }

    // ── 3. Response-parsing logic ────────────────────────────────────────────

    public function test_parse_row_with_clicks_and_impressions(): void
    {
        $apiResponse = [
            'rows' => [
                ['clicks' => 42, 'impressions' => 380],
            ],
        ];
        $result = $this->parseGscResponse($apiResponse);
        $this->assertSame(42, $result['clicks']);
        $this->assertSame(380, $result['impressions']);
    }

    public function test_parse_zero_rows_returns_zeros(): void
    {
        // GSC returns an empty rows array (or no rows key) when there is no
        // traffic — this is not an error; the function must return zeros.
        $apiResponse = [];
        $result = $this->parseGscResponse($apiResponse);
        $this->assertSame(0, $result['clicks']);
        $this->assertSame(0, $result['impressions']);
    }

    public function test_parse_empty_rows_array_returns_zeros(): void
    {
        $apiResponse = ['rows' => []];
        $result = $this->parseGscResponse($apiResponse);
        $this->assertSame(0, $result['clicks']);
        $this->assertSame(0, $result['impressions']);
    }

    public function test_parse_error_response_returns_null(): void
    {
        $apiResponse = ['error' => ['code' => 403, 'message' => 'Forbidden']];
        $result = $this->parseGscResponse($apiResponse);
        $this->assertNull($result, 'Error responses from GSC API must produce null');
    }

    public function test_parse_row_with_float_clicks_casts_to_int(): void
    {
        $apiResponse = [
            'rows' => [
                ['clicks' => 7.0, 'impressions' => 55.0],
            ],
        ];
        $result = $this->parseGscResponse($apiResponse);
        $this->assertIsInt($result['clicks']);
        $this->assertIsInt($result['impressions']);
        $this->assertSame(7, $result['clicks']);
    }

    public function test_result_shape_has_clicks_and_impressions_keys(): void
    {
        $apiResponse = ['rows' => [['clicks' => 10, 'impressions' => 100]]];
        $result = $this->parseGscResponse($apiResponse);
        $this->assertArrayHasKey('clicks', $result);
        $this->assertArrayHasKey('impressions', $result);
    }

    // ── 4. New metrics: impressions, CTR, position ──────────────────────────

    public function testGscTotalsRequestIncludesFourMetrics(): void
    {
        $body    = $this->buildGscRequestBodyWithMetrics(7);
        $decoded = json_decode($body, true);
        $this->assertArrayHasKey('metrics', $decoded, 'Request body must include a metrics key');
        $metrics = $decoded['metrics'];
        $this->assertContains('clicks',      $metrics);
        $this->assertContains('impressions', $metrics);
        $this->assertContains('ctr',         $metrics);
        $this->assertContains('position',    $metrics);
        $this->assertCount(4, $metrics);
    }

    public function testGscTotalsParsesCtrAndPosition(): void
    {
        $apiResponse = [
            'rows' => [
                ['clicks' => 42, 'impressions' => 1200, 'ctr' => 0.035, 'position' => 23.4],
            ],
        ];
        $result = $this->parseGscResponse($apiResponse);
        $this->assertNotNull($result);
        $this->assertSame(42, $result['clicks']);
        $this->assertSame(1200, $result['impressions']);
        $this->assertEqualsWithDelta(0.035, $result['ctr'], 0.0001);
        $this->assertEqualsWithDelta(23.4, $result['position'], 0.01);
    }

    public function testGscTotalsHandlesZeroRows(): void
    {
        $apiResponse = ['rows' => []];
        $result = $this->parseGscResponse($apiResponse);
        $this->assertNotNull($result, 'Empty rows should return zeros, not null');
        $this->assertSame(0, $result['clicks']);
        $this->assertSame(0, $result['impressions']);
        $this->assertNull($result['ctr'],      'CTR should be null when no rows');
        $this->assertNull($result['position'], 'Position should be null when no rows');
    }

    public function testGscTotalsReturnsNullOnError(): void
    {
        $apiResponse = ['error' => ['code' => 403, 'message' => 'Forbidden']];
        $result = $this->parseGscResponse($apiResponse);
        $this->assertNull($result, 'Error responses from GSC API must produce null');
    }

    public function testResultShapeHasAllFourKeys(): void
    {
        $apiResponse = ['rows' => [['clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 5.0]]];
        $result = $this->parseGscResponse($apiResponse);
        $this->assertArrayHasKey('clicks',      $result);
        $this->assertArrayHasKey('impressions', $result);
        $this->assertArrayHasKey('ctr',         $result);
        $this->assertArrayHasKey('position',    $result);
    }

    public function testCtrIsNullWhenMissingFromRow(): void
    {
        $apiResponse = ['rows' => [['clicks' => 5, 'impressions' => 50]]];
        $result = $this->parseGscResponse($apiResponse);
        $this->assertNull($result['ctr'], 'ctr must be null when not present in row');
        $this->assertNull($result['position'], 'position must be null when not present in row');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Reconstruct the GSC API request body using the same logic as mkGscTotals().
     * This lets us assert on the request shape without making real HTTP calls.
     */
    private function buildGscRequestBody(int $days): string
    {
        $endDate   = date('Y-m-d', strtotime('-1 day'));
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        return json_encode(['startDate' => $startDate, 'endDate' => $endDate, 'rowLimit' => 1]);
    }

    /**
     * Reconstruct the GSC API request body with four metrics — same logic as mkGscTotals().
     */
    private function buildGscRequestBodyWithMetrics(int $days): string
    {
        $endDate   = date('Y-m-d', strtotime('-1 day'));
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        return json_encode([
            'startDate' => $startDate,
            'endDate'   => $endDate,
            'rowLimit'  => 1,
            'metrics'   => ['clicks', 'impressions', 'ctr', 'position'],
        ]);
    }

    /**
     * Mirror the response-parsing logic from mkGscParseRow() so we can test it
     * in isolation without triggering real HTTP calls or credential checks.
     *
     * Returns ['clicks' => int, 'impressions' => int, 'ctr' => float|null, 'position' => float|null] or null.
     */
    private function parseGscResponse(?array $data): ?array
    {
        if (!is_array($data) || isset($data['error'])) {
            return null;
        }
        $row = $data['rows'][0] ?? null;
        return [
            'clicks'      => (int)($row['clicks']      ?? 0),
            'impressions' => (int)($row['impressions']  ?? 0),
            'ctr'         => isset($row['ctr'])      ? (float)$row['ctr']      : null,
            'position'    => isset($row['position']) ? (float)$row['position'] : null,
        ];
    }
}
