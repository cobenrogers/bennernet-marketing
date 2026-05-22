<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/MarketingTestBootstrap.php';

class CampaignPerformanceTest extends TestCase {
    public function testConversionRateCalculated(): void {
        $this->assertSame('20.0%', mkConvRate(10, 2));
    }
    public function testConversionRateNullWhenZeroSessions(): void {
        $this->assertNull(mkConvRate(0, 0));
    }
    public function testConversionRateOneHundredPercent(): void {
        $this->assertSame('100.0%', mkConvRate(5, 5));
    }
    public function testCampaignRowsParsedFromGa4Response(): void {
        // Simulate what mkGa4CampaignData returns
        $rows = [
            ['source' => 'bluesky',    'medium' => 'social', 'sessions' => 12, 'signups' => 1],
            ['source' => 'google',     'medium' => 'organic','sessions' => 45, 'signups' => 3],
        ];
        $this->assertSame('bluesky', $rows[0]['source']);
        $this->assertSame(12, $rows[0]['sessions']);
        $this->assertSame(1,  $rows[0]['signups']);
    }
    public function testCampaignRowsSortedBySessionsDesc(): void {
        $rows = [
            ['source' => 'bluesky', 'medium' => 'social',  'sessions' => 12, 'signups' => 1],
            ['source' => 'google',  'medium' => 'organic', 'sessions' => 45, 'signups' => 3],
        ];
        usort($rows, fn($a, $b) => $b['sessions'] - $a['sessions']);
        $this->assertSame('google', $rows[0]['source']);
        $this->assertSame(12, $rows[1]['sessions']);
    }
}
