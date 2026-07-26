<?php

namespace Espo\Modules\ElevateResourceManagement\Domain;

final class Lifecycle
{
    public const OPEN = 'Open';
    public const ADD_TIME = 'ClosedAddTimeLogs';
    public const READY = 'ClosedReadyForBilling';
    public const INVOICED = 'ClosedInvoiced';

    public static function forCompletion(bool $targetCompleted, bool $hasActiveSession, int $requiredBlocks, int $blocksWithTime): string
    {
        if (!$targetCompleted) {
            return self::OPEN;
        }

        if ($hasActiveSession || $requiredBlocks === 0 || $blocksWithTime < $requiredBlocks) {
            return self::ADD_TIME;
        }

        return self::READY;
    }
}
