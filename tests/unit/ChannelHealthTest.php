<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/MarketingTestBootstrap.php';

class ChannelHealthTest extends TestCase {
    public function testChannelStatusHealthy(): void {
        $ts = date('c', time() - 3 * 86400); // 3 days ago
        $this->assertSame('healthy', mkChannelStatus(0, $ts));
    }
    public function testChannelStatusStale(): void {
        $ts = date('c', time() - 20 * 86400); // 20 days ago
        $this->assertSame('stale', mkChannelStatus(0, $ts));
    }
    public function testChannelStatusStaleWhenNullButPostizAvailable(): void {
        $this->assertSame('stale', mkChannelStatus(0, null, true));
    }
    public function testChannelStatusUnknownWhenPostizNotAvailable(): void {
        $this->assertSame('unknown', mkChannelStatus(0, null, false));
    }
    public function testChannelStatusUnknownEvenWithLastPublished(): void {
        $ts = date('c', time() - 3 * 86400);
        $this->assertSame('unknown', mkChannelStatus(null, $ts, false));
    }
    public function testChannelStatusError(): void {
        $ts = date('c', time() - 1 * 86400);
        $this->assertSame('error', mkChannelStatus(3, $ts));
    }
    public function testChannelStatusErrorEvenWhenRecent(): void {
        $ts = date('c', time() - 1 * 86400);
        $this->assertSame('error', mkChannelStatus(1, $ts));
    }
}
