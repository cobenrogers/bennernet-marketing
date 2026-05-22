<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for mkLocalDraftCount() and mkLocalRecentPublished() in local-fs-reader.php.
 *
 * Functions are loaded via a stub bootstrap that satisfies the mkGhGet() dependency
 * without touching the network.
 */

// Stub mkGhGet() so that the GitHub API fallback path never makes a real HTTP
// request during unit tests.  The stub returns null (simulating network failure)
// which causes mkLocalDraftCount / mkLocalRecentPublished to set error=true.
// This must be declared before local-fs-reader.php is included.
if (!function_exists('mkGhGet')) {
    function mkGhGet(string $url, int $ttl = 120): ?array {
        return null; // Simulate unreachable GitHub API
    }
}

// Load the functions under test.
require_once __DIR__ . '/../../local-fs-reader.php';

class LocalFsReaderTest extends TestCase
{
    /** @var string Temp directory created for each test */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/mk_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rrmdir($this->tmpDir);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function touch(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, '');
    }

    // ── mkLocalDraftCount ─────────────────────────────────────────────────────

    /**
     * @test
     */
    public function testDraftCountFromLocalFs(): void
    {
        $ws = $this->tmpDir;

        // 2 files directly in queue/
        $this->touch($ws . '/queue/2026-05-20-draft-a.md');
        $this->touch($ws . '/queue/2026-05-21-draft-b.md');

        // 3 files inside platform subdirs
        $this->touch($ws . '/queue/bluesky/2026-05-19-bs-draft.md');
        $this->touch($ws . '/queue/linkedin/2026-05-18-li-draft-1.md');
        $this->touch($ws . '/queue/linkedin/2026-05-17-li-draft-2.md');

        // A non-.md file that must NOT be counted
        $this->touch($ws . '/queue/notes.txt');

        $result = mkLocalDraftCount($ws);

        $this->assertFalse($result['error'],   'error flag must be false');
        $this->assertFalse($result['missing'], 'missing flag must be false');
        $this->assertSame(5, $result['count'], 'should count 5 .md drafts across queue/ and platform subdirs');
    }

    /**
     * @test
     * Empty queue/ directory should return count 0 without error.
     */
    public function testDraftCountEmptyQueueDir(): void
    {
        $ws = $this->tmpDir;
        mkdir($ws . '/queue', 0755, true);

        $result = mkLocalDraftCount($ws);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['missing']);
        $this->assertSame(0, $result['count']);
    }

    /**
     * @test
     * When the workspace path is not set (null) and mkGhGet returns null,
     * the function should report an error.
     */
    public function testFallsBackToGitHubApiWhenLocalMissing(): void
    {
        // Pass a path that does not exist to force the fallback.
        $result = mkLocalDraftCount('/nonexistent/path/that/cannot/exist');

        // mkGhGet() is the real implementation; without a token / network it returns
        // null (network failure) or an array with a 'message' key (404).  Either way
        // we only assert that the local-fs branch was NOT taken (count stays 0) and
        // one of the fallback flags is set.
        $this->assertSame(0, $result['count'], 'count must be 0 when local path missing');
        $atLeastOneFlag = $result['error'] || $result['missing'];
        $this->assertTrue($atLeastOneFlag, 'error or missing flag must be true when falling back to GitHub API with no token');
    }

    // ── mkLocalRecentPublished ────────────────────────────────────────────────

    /**
     * @test
     */
    public function testRecentPublishedSortedNewestFirst(): void
    {
        $ws = $this->tmpDir;

        // Mix of flat + platform-subdir files with date-prefixed names
        $this->touch($ws . '/published/bluesky/2026-05-20-post-b.md');
        $this->touch($ws . '/published/bluesky/2026-05-22-post-d.md');
        $this->touch($ws . '/published/linkedin/2026-05-18-post-a.md');
        $this->touch($ws . '/published/mastodon/2026-05-21-post-c.md');
        $this->touch($ws . '/published/mastodon/2026-05-19-post-e.md');
        $this->touch($ws . '/published/mastodon/2026-05-17-post-f.md'); // 6th — should be excluded

        $result = mkLocalRecentPublished($ws);

        $this->assertFalse($result['error'],   'error flag must be false');
        $this->assertFalse($result['missing'], 'missing flag must be false');

        $files = $result['files'];
        $this->assertCount(5, $files, 'should return exactly 5 files');

        // Assert descending date order by name
        $names = array_column($files, 'name');
        $this->assertSame('2026-05-22-post-d.md', $names[0], 'newest file must be first');
        $this->assertSame('2026-05-21-post-c.md', $names[1]);
        $this->assertSame('2026-05-20-post-b.md', $names[2]);
        $this->assertSame('2026-05-19-post-e.md', $names[3]);
        $this->assertSame('2026-05-18-post-a.md', $names[4]);

        // The 6th (oldest) file must be absent
        $this->assertNotContains('2026-05-17-post-f.md', $names, 'oldest file must be excluded from top-5');
    }

    /**
     * @test
     * Path returned for platform-subdir files must include the platform segment.
     */
    public function testRecentPublishedPathIncludesPlatformSubdir(): void
    {
        $ws = $this->tmpDir;
        $this->touch($ws . '/published/bluesky/2026-05-22-foo.md');

        $result = mkLocalRecentPublished($ws);
        $this->assertCount(1, $result['files']);

        $entry = $result['files'][0];
        $this->assertSame('2026-05-22-foo.md', $entry['name']);
        $this->assertSame('docs/marketing/workspace/published/bluesky/2026-05-22-foo.md', $entry['path']);
        $this->assertSame('file', $entry['type']);
    }

    /**
     * @test
     * When the workspace path is not set (null), the function falls back to the
     * GitHub API.  Without network/token, it returns an error or missing result.
     */
    public function testRecentPublishedFallsBackToGitHubApiWhenLocalMissing(): void
    {
        $result = mkLocalRecentPublished('/nonexistent/path/that/cannot/exist');

        $this->assertEmpty($result['files'], 'files must be empty when local path missing');
        $atLeastOneFlag = $result['error'] || $result['missing'];
        $this->assertTrue($atLeastOneFlag, 'error or missing flag must be true when falling back to GitHub API');
    }
}
