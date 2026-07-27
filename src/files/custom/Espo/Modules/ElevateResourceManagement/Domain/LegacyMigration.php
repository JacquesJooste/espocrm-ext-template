<?php

namespace Espo\Modules\ElevateResourceManagement\Domain;

final class LegacyMigration
{
    /**
     * @param string[] $activities
     * @return array<string, mixed>
     */
    public static function workItem(
        string $legacyId,
        string $name,
        array $activities,
        int $estimatedSeconds,
        bool $active
    ): array {
        return [
            'name' => $name,
            'description' => implode("\n", $activities),
            'defaultEstimateSeconds' => max(60, $estimatedSeconds),
            'active' => $active,
            'legacyWorkBlockId' => $legacyId,
        ];
    }

    /** @return array<string, mixed> */
    public static function workBlockRun(
        string $legacyScheduledBlockId,
        string $name,
        string $status,
        string $milestoneKind,
        int $sequence,
        int $estimatedSeconds
    ): array {
        return [
            'name' => $name,
            'status' => $status,
            'milestoneKind' => $milestoneKind !== '' ? $milestoneKind : 'Normal',
            'sequence' => $sequence,
            'totalEstimateSeconds' => max(60, $estimatedSeconds),
            'completionPercent' => $status === 'Completed' ? 100 : 0,
            'legacyScheduledBlockId' => $legacyScheduledBlockId,
        ];
    }

    /** @return array<string, mixed> */
    public static function workItemRun(
        string $name,
        string $description,
        int $estimatedSeconds,
        string $status,
        ?string $completedAt
    ): array {
        return [
            'name' => $name,
            'nameSnapshot' => $name,
            'descriptionSnapshot' => $description,
            'estimatedSeconds' => max(60, $estimatedSeconds),
            'actualElapsedSeconds' => 0,
            'actualLabourSeconds' => 0,
            'sequence' => 0,
            'status' => $status,
            'completedAt' => $completedAt,
        ];
    }

    /**
     * @param string[] $attendeeIds
     * @return array{workBlockRunId:string, workItemRunId:string, usersIds:string[]}
     */
    public static function timeEntryLinks(
        string $workBlockRunId,
        string $workItemRunId,
        array $attendeeIds
    ): array {
        return [
            'workBlockRunId' => $workBlockRunId,
            'workItemRunId' => $workItemRunId,
            'usersIds' => array_values(array_unique(array_filter(
                $attendeeIds,
                static fn (mixed $id): bool => is_string($id) && $id !== ''
            ))),
        ];
    }
}
