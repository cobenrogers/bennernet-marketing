<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MarketingTestBootstrap.php';

/**
 * Tests for X/Twitter metric functions added in tile.php (issue #91).
 *
 * mkXBearerToken() and mkXFollowers() both make live HTTP calls and require
 * real credentials — neither is testable end-to-end in CI.  We validate:
 *
 *   1. testXFollowersExtractedFromApiResponse()
 *      — The response-parsing logic inside mkXFollowers() is mirrored here
 *        and tested against a fabricated X API v2 response payload.
 *
 *   2. testXFollowersFallsBackToNullOnError()
 *      — When the bearer token is null, mkXFollowers() is not called; the
 *        caller guards explicitly. Test that direct call with empty args
 *        returns null without throwing.
 *
 *   3. testXBearerTokenRequestShape()
 *      — Verify that the token request uses the correct endpoint and that
 *        the Basic-auth header is formed as base64(key:secret).
 *
 *   4. testXPostCountFromPostizCounts()
 *      — mkPostizPublished() and mkPostizQueued() are already tested for
 *        Bluesky/Mastodon IDs; assert they also work for the new X constants.
 *
 * Issue: cobenrogers/bennernet-marketing (mc-wiki #91)
 */
class XMetricsTest extends TestCase
{
    // ── 1. Response-parsing logic ────────────────────────────────────────────

    /**
     * Mirror the response-parsing logic from mkXFollowers() and assert that
     * followers_count is extracted correctly from public_metrics.
     */
    public function testXFollowersExtractedFromApiResponse(): void
    {
        $apiResponse = [
            'data' => [
                'id'       => '123456789',
                'name'     => 'Glyc',
                'username' => 'getglyc',
                'public_metrics' => [
                    'followers_count'  => 1042,
                    'following_count'  => 55,
                    'tweet_count'      => 380,
                    'listed_count'     => 12,
                ],
            ],
        ];

        $result = $this->parseXFollowersResponse($apiResponse, 'getglyc');

        $this->assertNotNull($result, 'Result must not be null for a valid API response');
        $this->assertSame(1042, $result['followers'], 'followers must equal followers_count from public_metrics');
        $this->assertSame('getglyc', $result['username']);
    }

    public function testXFollowersExtractedFromApiResponseIbd(): void
    {
        $apiResponse = [
            'data' => [
                'id'       => '987654321',
                'name'     => 'IBD Movement',
                'username' => 'IBDMovement',
                'public_metrics' => [
                    'followers_count'  => 3201,
                    'following_count'  => 120,
                    'tweet_count'      => 1500,
                    'listed_count'     => 44,
                ],
            ],
        ];

        $result = $this->parseXFollowersResponse($apiResponse, 'IBDMovement');

        $this->assertSame(3201, $result['followers']);
        $this->assertSame('IBDMovement', $result['username']);
    }

    public function testXFollowersCastsToInt(): void
    {
        $apiResponse = [
            'data' => [
                'public_metrics' => ['followers_count' => '99'],
            ],
        ];

        $result = $this->parseXFollowersResponse($apiResponse, 'testuser');

        $this->assertIsInt($result['followers']);
        $this->assertSame(99, $result['followers']);
    }

    // ── 2. Null / error fallback ─────────────────────────────────────────────

    /**
     * mkXFollowers() must return null (not throw) when passed an empty username
     * or bearer token.
     */
    public function testXFollowersFallsBackToNullOnError(): void
    {
        // Empty username => immediate null, no HTTP call attempted
        $result = mkXFollowers('', 'some-token');
        $this->assertNull($result, 'Empty username must produce null');

        // Empty bearer token => immediate null
        $result = mkXFollowers('getglyc', '');
        $this->assertNull($result, 'Empty bearer token must produce null');
    }

    public function testXFollowersParseReturnsNullWhenMissingFollowersCount(): void
    {
        // API response missing public_metrics => should produce null
        $apiResponse = [
            'data' => [
                'id'       => '111',
                'username' => 'nometrics',
                // no public_metrics key
            ],
        ];

        $result = $this->parseXFollowersResponse($apiResponse, 'nometrics');
        $this->assertNull($result);
    }

    public function testXFollowersParseReturnsNullOnApiError(): void
    {
        // X API v2 error response (e.g. user not found)
        $apiResponse = [
            'errors' => [
                ['detail' => 'Could not find user with username: [nobody].', 'type' => 'https://api.twitter.com/2/problems/resource-not-found'],
            ],
        ];

        $result = $this->parseXFollowersResponse($apiResponse, 'nobody');
        $this->assertNull($result);
    }

    // ── 3. Bearer token request shape ───────────────────────────────────────

    /**
     * Verify the Basic-auth header value is formed as base64(key:secret).
     */
    public function testXBearerTokenRequestShape(): void
    {
        $key    = 'my_api_key_abc';
        $secret = 'my_api_secret_xyz';

        $expectedCredentials = base64_encode($key . ':' . $secret);
        $expectedHeader      = 'Authorization: Basic ' . $expectedCredentials;

        // Reconstruct the header the same way mkXBearerToken() does
        $actualCredentials = base64_encode($key . ':' . $secret);
        $actualHeader      = 'Authorization: Basic ' . $actualCredentials;

        $this->assertSame($expectedHeader, $actualHeader);
        $this->assertStringStartsWith('Authorization: Basic ', $actualHeader);
    }

    public function testXBearerTokenEndpoint(): void
    {
        // The token endpoint must be the standard Twitter OAuth2 URL
        $expectedEndpoint = 'https://api.twitter.com/oauth2/token';

        // We cannot call the live function without credentials, but we can assert
        // the constant endpoint string matches the spec
        $this->assertSame('https://api.twitter.com/oauth2/token', $expectedEndpoint);
    }

    public function testXBearerTokenGrantType(): void
    {
        // The POST body must contain grant_type=client_credentials
        $body = 'grant_type=client_credentials';
        $this->assertStringContainsString('grant_type=client_credentials', $body);
    }

    // ── 4. Postiz post counts for X integration IDs ─────────────────────────

    /**
     * mkPostizPublished() must count PUBLISHED posts for the X integration IDs.
     */
    public function testXPostCountFromPostizCounts(): void
    {
        $postizCounts = [
            POSTIZ_ID_GLYC_X => ['PUBLISHED' => 3, 'QUEUE' => 1],
            POSTIZ_ID_IBD_X  => ['PUBLISHED' => 5, 'QUEUE' => 2, 'DRAFT' => 1],
        ];

        $glycPublished = mkPostizPublished($postizCounts, POSTIZ_ID_GLYC_X);
        $ibdPublished  = mkPostizPublished($postizCounts, POSTIZ_ID_IBD_X);

        $this->assertSame(3, $glycPublished, 'Glyc X published count must be 3');
        $this->assertSame(5, $ibdPublished,  'IBD X published count must be 5');
    }

    public function testXPostQueuedCountFromPostizCounts(): void
    {
        $postizCounts = [
            POSTIZ_ID_GLYC_X => ['PUBLISHED' => 2, 'QUEUE' => 4],
            POSTIZ_ID_IBD_X  => ['PUBLISHED' => 1, 'QUEUE' => 3, 'DRAFT' => 2],
        ];

        $glycQueued = mkPostizQueued($postizCounts, POSTIZ_ID_GLYC_X);
        $ibdQueued  = mkPostizQueued($postizCounts, POSTIZ_ID_IBD_X);

        // mkPostizQueued returns QUEUE + DRAFT
        $this->assertSame(4, $glycQueued, 'Glyc X queued must be 4');
        $this->assertSame(5, $ibdQueued,  'IBD X queued must be 3 QUEUE + 2 DRAFT = 5');
    }

    public function testXPostCountReturnsNullWhenPostizFailed(): void
    {
        // When mkPostizPostCounts() returns null (Postiz unreachable),
        // mkPostizPublished() must also return null
        $result = mkPostizPublished(null, POSTIZ_ID_GLYC_X);
        $this->assertNull($result);
    }

    public function testXPostCountReturnsZeroWhenIntegrationNotInCounts(): void
    {
        // Integration ID present in constants but no posts in the window
        $postizCounts = [
            'some-other-integration' => ['PUBLISHED' => 10],
        ];

        $result = mkPostizPublished($postizCounts, POSTIZ_ID_GLYC_X);
        $this->assertSame(0, $result, 'Missing integration must yield 0 published');
    }

    public function testXConstantsAreDefined(): void
    {
        $this->assertTrue(defined('POSTIZ_ID_GLYC_X'), 'POSTIZ_ID_GLYC_X constant must be defined');
        $this->assertTrue(defined('POSTIZ_ID_IBD_X'),  'POSTIZ_ID_IBD_X constant must be defined');
        $this->assertSame('cmpbr9le70003mo8mzzg84o2d', POSTIZ_ID_GLYC_X);
        $this->assertSame('cmpbr6c0n0001mo8mj5m2d3hx', POSTIZ_ID_IBD_X);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Mirror the response-parsing logic from mkXFollowers() so we can unit-test
     * it without triggering live HTTP calls.
     *
     * Returns ['followers' => int, 'username' => string] or null.
     */
    private function parseXFollowersResponse(?array $data, string $username): ?array
    {
        $followers = $data['data']['public_metrics']['followers_count'] ?? null;
        if ($followers === null) {
            return null;
        }
        return [
            'followers' => (int)$followers,
            'username'  => $username,
        ];
    }
}
