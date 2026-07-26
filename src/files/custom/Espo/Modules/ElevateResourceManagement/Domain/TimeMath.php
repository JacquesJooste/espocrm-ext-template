<?php

namespace Espo\Modules\ElevateResourceManagement\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class TimeMath
{
    /** @return array{elapsedSeconds:int, labourSeconds:int} */
    public static function calculate(string $start, string $end, int $attendeeCount): array
    {
        if ($attendeeCount < 1) {
            throw new InvalidArgumentException('At least one attendee is required.');
        }

        $timezone = new DateTimeZone('UTC');
        $startAt = new DateTimeImmutable($start, $timezone);
        $endAt = new DateTimeImmutable($end, $timezone);
        $elapsed = $endAt->getTimestamp() - $startAt->getTimestamp();

        if ($elapsed < 1) {
            throw new InvalidArgumentException('End time must be after start time.');
        }

        return [
            'elapsedSeconds' => $elapsed,
            'labourSeconds' => $elapsed * $attendeeCount,
        ];
    }

    public static function isOverrun(int $actualSeconds, int $estimatedSeconds): bool
    {
        return $actualSeconds > $estimatedSeconds + 3600;
    }

    /** @param array<int, array{start:string,end:string}> $segments */
    public static function assertNoOverlap(array $segments): void
    {
        usort($segments, fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        $previousEnd = null;

        foreach ($segments as $segment) {
            self::calculate($segment['start'], $segment['end'], 1);

            if ($previousEnd !== null && $segment['start'] < $previousEnd) {
                throw new InvalidArgumentException('Time segments must not overlap.');
            }

            $previousEnd = $segment['end'];
        }
    }
}
