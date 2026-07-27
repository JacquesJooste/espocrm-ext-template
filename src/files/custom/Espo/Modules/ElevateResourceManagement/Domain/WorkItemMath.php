<?php

namespace Espo\Modules\ElevateResourceManagement\Domain;

final class WorkItemMath
{
    public static function effectiveEstimate(int $defaultSeconds, ?int $overrideSeconds): int
    {
        return $overrideSeconds ?? $defaultSeconds;
    }

    /**
     * @param array<int, array{defaultSeconds:int, overrideSeconds:?int}> $items
     */
    public static function totalEstimate(array $items): int
    {
        return array_sum(array_map(
            static fn (array $item): int => self::effectiveEstimate(
                $item['defaultSeconds'],
                $item['overrideSeconds']
            ),
            $items
        ));
    }

    /**
     * @return array{
     *     nameSnapshot:string,
     *     descriptionSnapshot:string,
     *     estimatedSeconds:int,
     *     sequence:int
     * }
     */
    public static function snapshot(
        string $name,
        string $description,
        int $estimatedSeconds,
        int $sequence
    ): array {
        return [
            'nameSnapshot' => $name,
            'descriptionSnapshot' => $description,
            'estimatedSeconds' => $estimatedSeconds,
            'sequence' => $sequence,
        ];
    }

    /**
     * @param array<int, array{estimatedSeconds:int, status:string}> $items
     */
    public static function completionPercent(array $items): float
    {
        $total = 0;
        $completed = 0;
        foreach ($items as $item) {
            if ($item['status'] === 'Cancelled') {
                continue;
            }
            $total += $item['estimatedSeconds'];
            if ($item['status'] === 'Completed') {
                $completed += $item['estimatedSeconds'];
            }
        }

        return $total > 0 ? round($completed / $total * 100, 2) : 0.0;
    }

    /**
     * @param array<int, array{
     *     estimatedSeconds:int,
     *     actualElapsedSeconds:int,
     *     status:string
     * }> $items
     */
    public static function remainingSeconds(array $items): int
    {
        $seconds = 0;
        foreach ($items as $item) {
            if (!in_array($item['status'], ['Planned', 'In Progress'], true)) {
                continue;
            }
            $seconds += max(
                900,
                $item['estimatedSeconds'] - $item['actualElapsedSeconds']
            );
        }

        return $seconds;
    }
}
