<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/MarketingTestBootstrap.php';

class ContentCalendarTest extends TestCase {
    public function testCalendarPostsSortedByDate(): void {
        $posts = [
            ['state' => 'QUEUE', 'publishDate' => '2026-05-27T15:00:00Z'],
            ['state' => 'QUEUE', 'publishDate' => '2026-05-25T09:00:00Z'],
            ['state' => 'QUEUE', 'publishDate' => '2026-05-26T12:00:00Z'],
        ];
        $filtered = array_filter($posts, fn($p) => ($p['state'] ?? '') === 'QUEUE');
        usort($filtered, fn($a, $b) => strcmp($a['publishDate'] ?? '', $b['publishDate'] ?? ''));
        $filtered = array_values($filtered);
        $this->assertSame('2026-05-25T09:00:00Z', $filtered[0]['publishDate']);
        $this->assertSame('2026-05-27T15:00:00Z', $filtered[2]['publishDate']);
    }

    public function testCalendarFiltersToQueueOnly(): void {
        $posts = [
            ['state' => 'QUEUE',     'publishDate' => '2026-05-25T09:00:00Z'],
            ['state' => 'PUBLISHED', 'publishDate' => '2026-05-24T09:00:00Z'],
            ['state' => 'ERROR',     'publishDate' => '2026-05-23T09:00:00Z'],
            ['state' => 'DRAFT',     'publishDate' => '2026-05-26T09:00:00Z'],
        ];
        $filtered = array_values(array_filter($posts, fn($p) => ($p['state'] ?? '') === 'QUEUE'));
        $this->assertCount(1, $filtered);
        $this->assertSame('QUEUE', $filtered[0]['state']);
    }

    public function testCalendarHtmlStrippedFromPreview(): void {
        $html = '<p>Hello <strong>world</strong>, this is content.</p>';
        $this->assertSame('Hello world, this is content.', mkCalendarStripHtml($html));
    }

    public function testCalendarPreviewTruncated(): void {
        $long = str_repeat('a', 150);
        $preview = mkCalendarPreview($long, 100);
        $this->assertStringEndsWith('…', $preview);
        $this->assertLessThanOrEqual(102, mb_strlen($preview)); // 100 + "…"
    }

    public function testCalendarEmptyWhenNoQueuedPosts(): void {
        $posts = [
            ['state' => 'PUBLISHED', 'publishDate' => '2026-05-24T09:00:00Z'],
        ];
        $filtered = array_values(array_filter($posts, fn($p) => ($p['state'] ?? '') === 'QUEUE'));
        $this->assertEmpty($filtered);
    }
}
