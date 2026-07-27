<?php

namespace tests\unit\Espo\Modules\ElevateResourceManagement\Domain;

use Espo\Modules\ElevateResourceManagement\Domain\WorkItemMath;
use PHPUnit\Framework\TestCase;

final class WorkItemMathTest extends TestCase
{
    public function testDerivedTotalUsesOverrides(): void
    {
        self::assertSame(6300, WorkItemMath::totalEstimate([
            ['defaultSeconds' => 1800, 'overrideSeconds' => null],
            ['defaultSeconds' => 7200, 'overrideSeconds' => 4500],
        ]));
    }

    public function testSnapshotIsIndependentFromLaterLibraryChanges(): void
    {
        $definition = [
            'name' => 'Replace access point',
            'description' => 'Remove, configure, fit and validate.',
        ];
        $snapshot = WorkItemMath::snapshot(
            $definition['name'],
            $definition['description'],
            5400,
            2
        );

        $definition['name'] = 'Different library name';
        $definition['description'] = 'Different library description';

        self::assertSame('Replace access point', $snapshot['nameSnapshot']);
        self::assertSame(
            'Remove, configure, fit and validate.',
            $snapshot['descriptionSnapshot']
        );
        self::assertSame(5400, $snapshot['estimatedSeconds']);
        self::assertSame(2, $snapshot['sequence']);
    }

    public function testProgressIsWeightedByEstimate(): void
    {
        self::assertSame(25.0, WorkItemMath::completionPercent([
            ['estimatedSeconds' => 900, 'status' => 'Completed'],
            ['estimatedSeconds' => 2700, 'status' => 'In Progress'],
        ]));
    }

    public function testPartialReschedulingUsesOnlyRemainingWork(): void
    {
        self::assertSame(4500, WorkItemMath::remainingSeconds([
            [
                'estimatedSeconds' => 3600,
                'actualElapsedSeconds' => 1800,
                'status' => 'In Progress',
            ],
            [
                'estimatedSeconds' => 2700,
                'actualElapsedSeconds' => 0,
                'status' => 'Planned',
            ],
            [
                'estimatedSeconds' => 7200,
                'actualElapsedSeconds' => 7200,
                'status' => 'Completed',
            ],
        ]));
    }

    public function testRemainingWorkKeepsMinimumQuarterHourSlot(): void
    {
        self::assertSame(900, WorkItemMath::remainingSeconds([
            [
                'estimatedSeconds' => 1800,
                'actualElapsedSeconds' => 1750,
                'status' => 'In Progress',
            ],
        ]));
    }
}
