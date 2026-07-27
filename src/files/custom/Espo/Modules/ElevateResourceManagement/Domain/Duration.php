<?php

namespace Espo\Modules\ElevateResourceManagement\Domain;

use InvalidArgumentException;

final class Duration
{
    /** @var int[] */
    private const MINUTE_OPTIONS = [0, 15, 30, 45];

    public static function fromParts(int $hours, int $minutes): int
    {
        if ($hours < 0 || $hours > 24) {
            throw new InvalidArgumentException('Hours must be between 0 and 24.');
        }

        if (!in_array($minutes, self::MINUTE_OPTIONS, true)) {
            throw new InvalidArgumentException('Minutes must be 00, 15, 30, or 45.');
        }

        if ($hours === 24 && $minutes !== 0) {
            throw new InvalidArgumentException('A duration cannot exceed 24 hours.');
        }

        $seconds = $hours * 3600 + $minutes * 60;

        if ($seconds === 0) {
            throw new InvalidArgumentException('A duration must be at least 15 minutes.');
        }

        return $seconds;
    }

    public static function isQuarterHour(int $seconds): bool
    {
        return $seconds >= 900 && $seconds <= 86400 && $seconds % 900 === 0;
    }
}
