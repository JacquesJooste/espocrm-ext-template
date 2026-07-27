<?php

namespace tests\unit\Espo\Modules\ElevateResourceManagement\Domain;

use Espo\Modules\ElevateResourceManagement\Domain\Duration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    /** @return iterable<string, array{int,int,int}> */
    public static function validDurations(): iterable
    {
        yield 'quarter hour' => [0, 15, 900];
        yield 'mixed duration' => [3, 45, 13500];
        yield 'maximum' => [24, 0, 86400];
    }

    #[DataProvider('validDurations')]
    public function testConvertsValidatedParts(int $hours, int $minutes, int $expected): void
    {
        self::assertSame($expected, Duration::fromParts($hours, $minutes));
        self::assertTrue(Duration::isQuarterHour($expected));
    }

    /** @return iterable<string, array{int,int}> */
    public static function invalidDurations(): iterable
    {
        yield 'zero' => [0, 0];
        yield 'non-quarter minute' => [1, 10];
        yield 'above maximum' => [24, 15];
        yield 'negative' => [-1, 0];
    }

    #[DataProvider('invalidDurations')]
    public function testRejectsInvalidParts(int $hours, int $minutes): void
    {
        $this->expectException(InvalidArgumentException::class);
        Duration::fromParts($hours, $minutes);
    }
}
