<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Timestamp;
use Tests\TestCase;

final class TimestampTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->original);

        parent::tearDown();
    }

    /**
     * The bug this guards: rows are UTC, the application runs in another
     * timezone, and a bare strtotime() read a live claim as hours expired.
     */
    public function testADatabaseTimestampIsReadAsUtcWhateverTheLocalZoneIs(): void
    {
        $stored = gmdate('Y-m-d H:i:s');

        foreach (['UTC', 'Asia/Kolkata', 'America/Los_Angeles', 'Pacific/Auckland'] as $zone) {
            date_default_timezone_set($zone);

            $this->assertLessThanOrEqual(
                2,
                abs(Timestamp::since($stored)),
                'now, stored as UTC, should read as now in ' . $zone
            );
        }
    }

    public function testAFutureTimestampIsNotPast(): void
    {
        date_default_timezone_set('Asia/Kolkata');

        $stored = gmdate('Y-m-d H:i:s', time() + 1800);

        $this->assertFalse(Timestamp::isPast($stored));
        $this->assertTrue(Timestamp::isPast(gmdate('Y-m-d H:i:s', time() - 60)));
    }

    public function testDaysSinceCountsWholeDays(): void
    {
        date_default_timezone_set('Asia/Kolkata');

        $this->assertSame(0, Timestamp::daysSince(gmdate('Y-m-d H:i:s', time() - 3600)));
        $this->assertSame(3, Timestamp::daysSince(gmdate('Y-m-d H:i:s', time() - (3 * 86400) - 60)));
    }

    public function testFormatUsesTheApplicationTimezone(): void
    {
        date_default_timezone_set('Asia/Kolkata');

        // 18:00 UTC is half past eleven at night in Kolkata, the next hour block.
        $this->assertSame('23:30', Timestamp::format('2026-08-29 18:00:00', 'H:i'));
    }
}
