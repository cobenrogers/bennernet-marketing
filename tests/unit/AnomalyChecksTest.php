<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/MarketingTestBootstrap.php';

class AnomalyChecksTest extends TestCase {
    public function testPostizErrorCheckFlagsCorrectly(): void {
        $platform = ['glyc_bluesky' => ['errors_7d' => 2, 'published_7d' => 3, 'queued' => 1, 'last_published' => null]];
        $result = mkCheckPostizErrors($platform);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('2', $result['message']);
        $this->assertSame('error', $result['severity']);
    }
    public function testPostizErrorCheckClearWhenNoErrors(): void {
        $platform = ['glyc_bluesky' => ['errors_7d' => 0, 'published_7d' => 3, 'queued' => 1, 'last_published' => null]];
        $result = mkCheckPostizErrors($platform);
        $this->assertTrue($result['ok']);
        $this->assertSame('info', $result['severity']);
    }
    public function testGscDropCheckFlagsOn25PercentDrop(): void {
        $result = mkCheckGscDrop(50, 100, 'getglyc.com');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('50%', $result['message']);
        $this->assertSame('warn', $result['severity']);
    }
    public function testGscDropCheckClearWhenTrafficStable(): void {
        $result = mkCheckGscDrop(90, 100, 'getglyc.com');
        $this->assertTrue($result['ok']);
    }
    public function testGscDropCheckClearWhenPriorNull(): void {
        $result = mkCheckGscDrop(90, null, 'getglyc.com');
        $this->assertTrue($result['ok']);
    }
    public function testAnomalyCheckReturnsCorrectShape(): void {
        $result = mkCheckPostizErrors([]);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('severity', $result);
        $this->assertIsBool($result['ok']);
        $this->assertIsString($result['message']);
        $this->assertContains($result['severity'], ['info', 'warn', 'error']);
    }
    public function testEngagementCheckInOverdueFlagsParsed(): void {
        // With no workspace path configured, should return ok gracefully
        $result = mkCheckEngagementOverdue();
        $this->assertArrayHasKey('ok', $result);
        $this->assertTrue($result['ok']); // no workspace path in test env
    }
}
