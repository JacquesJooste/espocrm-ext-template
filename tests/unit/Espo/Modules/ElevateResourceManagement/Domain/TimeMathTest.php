<?php

namespace tests\unit\Espo\Modules\ElevateResourceManagement\Domain;

use Espo\Modules\ElevateResourceManagement\Domain\TimeMath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TimeMathTest extends TestCase
{
    public function testElapsedAndLabourAreDistinct(): void
    {
        $result = TimeMath::calculate('2026-07-26 08:00:00', '2026-07-26 10:00:00', 3);
        self::assertSame(7200, $result['elapsedSeconds']);
        self::assertSame(21600, $result['labourSeconds']);
    }

    public function testOverrunRequiresMoreThanOneHour(): void
    {
        self::assertFalse(TimeMath::isOverrun(7200, 3600));
        self::assertTrue(TimeMath::isOverrun(7201, 3600));
    }

    public function testOverlapIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TimeMath::assertNoOverlap([
            ['start' => '2026-07-26 08:00:00', 'end' => '2026-07-26 10:00:00'],
            ['start' => '2026-07-26 09:30:00', 'end' => '2026-07-26 11:00:00'],
        ]);
    }
}
