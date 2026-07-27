<?php

namespace Espo\Modules\ElevateResourceManagement\Service;

use DateTimeImmutable;
use Espo\Core\Acl;
use Espo\Core\Field\DateTime as FieldDateTime;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\DateTime;
use Espo\Entities\User;
use Espo\Modules\ElevateResourceManagement\Domain\Eligibility;
use Espo\Modules\ElevateResourceManagement\Domain\Duration;
use Espo\Modules\ElevateResourceManagement\Domain\Lifecycle;
use Espo\Modules\ElevateResourceManagement\Domain\TimeMath;
use Espo\Modules\ElevateResourceManagement\Domain\WorkItemMath;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\WorkingTime\CalendarUtilityFactory;
use InvalidArgumentException;
use stdClass;

final class ApplicationService
{
    private const INSTANCE = 'ElevateRmInstance';
    private const PACKAGE = 'ElevateRmWorkPackage';
    private const TEMPLATE = 'ElevateRmWorkBlockTemplate';
    private const BLOCK = 'ElevateRmScheduledBlock';
    private const SESSION = 'ElevateRmWorkSession';
    private const ENTRY = 'ElevateRmTimeEntry';
    private const SETTINGS = 'ElevateRmSettings';
    private const SNAPSHOT = 'ElevateRmBillingSnapshot';
    private const WORK_ITEM = 'ElevateRmWorkItem';
    private const BLOCK_ITEM = 'ElevateRmWorkBlockItem';
    private const BLOCK_RUN = 'ElevateRmWorkBlockRun';
    private const ITEM_RUN = 'ElevateRmWorkItemRun';

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private CalendarUtilityFactory $calendarUtilityFactory,
        private ServiceContainer $recordServiceContainer,
    ) {}

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $this->assertManager();

        return $this->entityDto($this->settingsEntity());
    }

    /** @return array<string, bool> */
    public function permissions(): array
    {
        $this->assertInternalUser();
        $settings = $this->entityManager->getRDBRepository(self::SETTINGS)->findOne();
        $isOperationsManager = $this->user->isAdmin() ||
            ($settings && $settings->get('operationsManagerId') === $this->user->getId());
        $isBillingManager = $this->user->isAdmin() ||
            ($settings && $settings->get('billingAdministratorId') === $this->user->getId());

        return [
            'manager' => $isOperationsManager || $isBillingManager,
            'operationsManager' => $isOperationsManager,
            'billingManager' => $isBillingManager,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateSettings(array $input): array
    {
        $this->assertManager();
        $settings = $this->settingsEntity();

        foreach (['operationsManagerId', 'billingAdministratorId'] as $field) {
            if (array_key_exists($field, $input)) {
                $settings->set($field, $this->requiredString($input, $field));
            }
        }

        if (array_key_exists('autoMarkInvoicedOnExport', $input)) {
            $settings->set('autoMarkInvoicedOnExport', (bool) $input['autoMarkInvoicedOnExport']);
        }

        $this->entityManager->saveEntity($settings);

        return $this->entityDto($settings);
    }

    /** @return array<string, mixed> */
    public function workBlockComposition(string $id): array
    {
        $this->assertManager();
        $workBlock = $this->get(self::TEMPLATE, $id);

        return $this->workBlockDefinitionDto($workBlock);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createWorkBlock(array $input): array
    {
        $this->assertManager();

        return $this->entityManager->getTransactionManager()->run(
            fn (): array => $this->saveWorkBlockDefinition(null, $input)
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateWorkBlockDefinition(string $id, array $input): array
    {
        $this->assertManager();

        return $this->entityManager->getTransactionManager()->run(
            fn (): array => $this->saveWorkBlockDefinition($this->get(self::TEMPLATE, $id), $input)
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function saveWorkBlockDefinition(?Entity $workBlock, array $input): array
    {
        $values = [
            'name' => $this->requiredString($input, 'name'),
            'instanceId' => $this->requiredString($input, 'instanceId'),
            'active' => (bool) ($input['active'] ?? true),
            'isDefault' => (bool) ($input['isDefault'] ?? false),
            'defaultOrder' => (int) ($input['defaultOrder'] ?? 0),
            'milestoneKind' => (string) ($input['milestoneKind'] ?? 'Normal'),
        ];

        if ($workBlock) {
            $workBlock->setMultiple($values);
            $this->entityManager->saveEntity($workBlock);
        } else {
            $workBlock = $this->entityManager->createEntity(self::TEMPLATE, [
                ...$values,
                'estimatedSeconds' => 0,
                'activities' => [],
            ]);
        }

        $items = $input['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new BadRequest('A Work Block requires at least one Work Item.');
        }
        $orderedItems = [];
        foreach (array_values($items) as $inputOrder => $row) {
            if (!is_array($row)) {
                throw new BadRequest('Invalid Work Item row.');
            }
            $sequence = $row['sequence'] ?? $inputOrder;
            if (!is_int($sequence) && !is_numeric($sequence)) {
                throw new BadRequest('Work Item sequence must be numeric.');
            }
            $orderedItems[] = [
                'row' => $row,
                'sequence' => (int) $sequence,
                'inputOrder' => $inputOrder,
            ];
        }
        usort($orderedItems, static fn (array $a, array $b): int =>
            $a['sequence'] <=> $b['sequence'] ?: $a['inputOrder'] <=> $b['inputOrder']
        );

        $existing = [];
        foreach ($this->entityManager->getRDBRepository(self::BLOCK_ITEM)
            ->where(['workBlockId' => $workBlock->getId()])
            ->find() as $membership) {
            $existing[$membership->getId()] = $membership;
        }

        $kept = [];
        $total = 0;
        foreach ($orderedItems as $sequence => $orderedItem) {
            $row = $orderedItem['row'];

            $workItem = $this->resolveCompositionWorkItem($row);
            $membershipId = $row['id'] ?? null;
            $membership = is_string($membershipId) && isset($existing[$membershipId])
                ? $existing[$membershipId]
                : null;
            $override = $this->optionalDurationSeconds(
                $row['estimateOverride'] ?? $row['estimateOverrideSeconds'] ?? null
            );
            $effective = WorkItemMath::effectiveEstimate(
                (int) $workItem->get('defaultEstimateSeconds'),
                $override
            );
            $membershipValues = [
                'name' => (string) $workItem->get('name') . ' #' . ($sequence + 1),
                'workBlockId' => $workBlock->getId(),
                'workItemId' => $workItem->getId(),
                'sequence' => $sequence,
                'estimateOverrideSeconds' => $override,
                'effectiveEstimateSeconds' => $effective,
            ];

            if ($membership) {
                $membership->setMultiple($membershipValues);
                $this->entityManager->saveEntity($membership);
            } else {
                $membership = $this->entityManager->createEntity(self::BLOCK_ITEM, $membershipValues);
            }

            $kept[$membership->getId()] = true;
            $total += $effective;
        }

        foreach ($existing as $id => $membership) {
            if (!isset($kept[$id])) {
                $this->entityManager->removeEntity($membership);
            }
        }

        $workBlock->set('estimatedSeconds', $total);
        $this->entityManager->saveEntity($workBlock);

        return $this->workBlockDefinitionDto($workBlock);
    }

    /** @param array<string, mixed> $row */
    private function resolveCompositionWorkItem(array $row): Entity
    {
        if (isset($row['workItemId']) && is_string($row['workItemId'])) {
            return $this->get(self::WORK_ITEM, $row['workItemId']);
        }

        $create = $row['create'] ?? null;
        if (!is_array($create)) {
            throw new BadRequest('Select or create a Work Item.');
        }

        return $this->entityManager->createEntity(self::WORK_ITEM, [
            'name' => $this->requiredString($create, 'name'),
            'description' => (string) ($create['description'] ?? ''),
            'defaultEstimateSeconds' => $this->durationSeconds(
                $create['duration'] ?? $create['defaultEstimateSeconds'] ?? null
            ),
            'active' => (bool) ($create['active'] ?? true),
        ]);
    }

    /** @return array<string, mixed> */
    public function context(string $entityType, string $id): array
    {
        $this->assertInternalUser();
        $target = $this->target($entityType, $id, false);
        $instances = $this->matchingInstances($target);
        $package = $this->entityManager->getRDBRepository(self::PACKAGE)
            ->where(['targetType' => $entityType, 'targetId' => $id])
            ->findOne();

        $session = $package ? $this->activeSession($package->getId()) : null;
        $blocks = [];
        $workBlocks = [];
        $availableWorkBlocks = [];
        $timeEntries = [];

        foreach ($instances as $instance) {
            foreach ($this->entityManager->getRDBRepository(self::TEMPLATE)
                ->where([
                    'instanceId' => $instance->getId(),
                    'active' => true,
                ])
                ->order('defaultOrder')
                ->find() as $definition) {
                $availableWorkBlocks[] = $this->workBlockDefinitionDto($definition);
            }
        }

        if ($package) {
            foreach ($this->entityManager->getRDBRepository(self::BLOCK)
                ->where(['workPackageId' => $package->getId(), 'status!=' => 'Cancelled'])
                ->order('sequence')
                ->find() as $block) {
                $blocks[] = $this->entityDto($block);
            }
            foreach ($this->entityManager->getRDBRepository(self::BLOCK_RUN)
                ->where(['workPackageId' => $package->getId(), 'status!=' => 'Cancelled'])
                ->order('sequence')
                ->find() as $run) {
                $workBlocks[] = $this->workBlockRunDto($run);
            }
            foreach ($this->entityManager->getRDBRepository(self::ENTRY)
                ->where(['workPackageId' => $package->getId()])
                ->order('dateStart', 'DESC')
                ->limit(10)
                ->find() as $entry) {
                $dto = $this->entityDto($entry);
                $itemRunId = $entry->get('workItemRunId');
                $itemRun = is_string($itemRunId) && $itemRunId !== ''
                    ? $this->entityManager->getRDBRepository(self::ITEM_RUN)->getById($itemRunId)
                    : null;
                $dto['workItemName'] = $itemRun?->get('nameSnapshot');
                $timeEntries[] = $dto;
            }
        }

        return [
            'eligible' => count($instances) > 0,
            'instances' => array_map(fn (Entity $instance): array => [
                'id' => $instance->getId(),
                'name' => $instance->get('name'),
                'mode' => $instance->get('mode'),
            ], $instances),
            'package' => $package ? $this->packageDto($package) : null,
            'activeSession' => $session ? $this->sessionDto($session) : null,
            'blocks' => $blocks,
            'workBlocks' => $workBlocks,
            'timeEntries' => $timeEntries,
            'availableWorkBlocks' => $availableWorkBlocks,
            'actions' => [
                'plan' => !$package && count($instances) > 0,
                'reportIn' => (bool) $package && (bool) $package->get('scheduledStart') && !$session,
                'milestone' => (bool) $session,
                'finish' => (bool) $session,
                'manualEntry' => (bool) $package && (bool) $package->get('scheduledStart'),
                'logTime' => (bool) $package && $workBlocks !== [] && !$session,
                'stopTimer' => (bool) $session,
                'workBlocks' => count($instances) > 0,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function myWork(): array
    {
        $this->assertInternalUser();
        $activeSession = null;
        foreach ($this->entityManager->getRDBRepository(self::SESSION)
            ->where(['status' => 'Active'])
            ->find() as $session) {
            if (in_array($this->user->getId(), (array) $session->get('attendeeIds'), true)) {
                $activeSession = $session;
                break;
            }
        }

        $items = [];
        foreach ($this->entityManager->getRDBRepository(self::BLOCK)
            ->where([
                'status' => ['Planned', 'In Progress'],
                'dateEnd>=' => DateTime::getSystemNowString(),
            ])
            ->order('dateStart')
            ->limit(200)
            ->find() as $block) {
            if (!in_array($this->user->getId(), (array) $block->get('assignedUsersIds'), true)) {
                continue;
            }

            $package = $this->get(self::PACKAGE, (string) $block->get('workPackageId'));
            foreach ($this->entityManager->getRDBRepository(self::ITEM_RUN)
                ->where([
                    'scheduledBlockId' => $block->getId(),
                    'status' => ['Planned', 'In Progress'],
                ])
                ->order('sequence')
                ->find() as $itemRun) {
                $items[] = array_merge($this->entityDto($itemRun), [
                    'scheduledBlockId' => $block->getId(),
                    'dateStart' => $block->get('dateStart'),
                    'dateEnd' => $block->get('dateEnd'),
                    'targetType' => $package->get('targetType'),
                    'targetId' => $package->get('targetId'),
                    'targetIdentifier' => $package->get('targetIdentifier'),
                    'targetName' => $package->get('targetName'),
                ]);
            }
        }

        $activeTarget = null;
        if ($activeSession) {
            $activePackage = $this->get(
                self::PACKAGE,
                (string) $activeSession->get('workPackageId')
            );
            $activeTarget = [
                'entityType' => $activePackage->get('targetType'),
                'id' => $activePackage->get('targetId'),
                'identifier' => $activePackage->get('targetIdentifier'),
                'name' => $activePackage->get('targetName'),
            ];
        }

        return [
            'activeSession' => $activeSession ? $this->sessionDto($activeSession) : null,
            'activeTarget' => $activeTarget,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function contextBulk(array $input): array
    {
        $entityType = $this->requiredString($input, 'entityType');
        $ids = $input['ids'] ?? null;

        if (!is_array($ids) || count($ids) > 200) {
            throw new BadRequest('ids must be an array with at most 200 values.');
        }

        $result = [];

        foreach ($ids as $id) {
            if (is_string($id)) {
                try {
                    $result[$id] = $this->context($entityType, $id);
                } catch (Forbidden|NotFound) {
                    $result[$id] = ['eligible' => false];
                }
            }
        }

        return ['items' => $result];
    }

    /** @return array<string, mixed> */
    public function rollup(string $entityType, string $id): array
    {
        $this->assertInternalUser();
        $this->target($entityType, $id, false);

        if ($entityType === 'User') {
            return $this->userRollup($id);
        }
        if (!in_array($entityType, ['Account', 'Contact'], true)) {
            throw new BadRequest('Rollups are available for User, Account and Contact records.');
        }

        $snapshotField = $entityType === 'Account'
            ? 'accountIdSnapshot'
            : 'contactIdSnapshot';
        $packages = iterator_to_array(
            $this->entityManager->getRDBRepository(self::PACKAGE)
                ->where([$snapshotField => $id])
                ->order('plannedStart', 'DESC')
                ->limit(200)
                ->find()
        );
        $elapsed = 0;
        $labour = 0;
        $entryCount = 0;
        $targets = [];
        $visiblePackageCount = 0;

        foreach ($packages as $package) {
            try {
                $this->target(
                    (string) $package->get('targetType'),
                    (string) $package->get('targetId'),
                    false
                );
            } catch (Forbidden|NotFound) {
                continue;
            }
            $visiblePackageCount++;
            foreach ($this->entriesForPackage($package->getId()) as $entry) {
                $elapsed += (int) $entry->get('elapsedSeconds');
                $labour += (int) $entry->get('labourSeconds');
                $entryCount++;
            }
            if (count($targets) >= 10) {
                continue;
            }
            $targets[] = [
                'entityType' => $package->get('targetType'),
                'id' => $package->get('targetId'),
                'identifier' => $package->get('targetIdentifier'),
                'name' => $package->get('targetName'),
                'lifecycle' => $package->get('lifecycle'),
                'completionPercent' => $package->get('completionPercent'),
            ];
        }

        return [
            'kind' => $entityType,
            'packageCount' => $visiblePackageCount,
            'entryCount' => $entryCount,
            'elapsedSeconds' => $elapsed,
            'labourSeconds' => $labour,
            'recentTargets' => $targets,
        ];
    }

    /** @return array<string, mixed> */
    private function userRollup(string $userId): array
    {
        if ($userId !== $this->user->getId()) {
            $this->assertManager();
        }

        $resource = $this->entityManager->getRDBRepositoryByClass(User::class)->getById($userId);
        if (!$resource) {
            throw new NotFound();
        }

        $now = new DateTimeImmutable(DateTime::getSystemNowString());
        $to = $now->modify('+7 days');
        $scheduledSeconds = 0;
        $upcoming = [];
        foreach ($this->entityManager->getRDBRepository(self::BLOCK)
            ->where([
                'status' => ['Planned', 'In Progress'],
                'dateEnd>=' => $now->format('Y-m-d H:i:s'),
                'dateStart<' => $to->format('Y-m-d H:i:s'),
            ])
            ->order('dateStart')
            ->limit(500)
            ->find() as $block) {
            if (!in_array($userId, (array) ($block->get('assignedUsersIds') ?? []), true)) {
                continue;
            }
            $scheduledSeconds += TimeMath::calculate(
                (string) $block->get('dateStart'),
                (string) $block->get('dateEnd'),
                1
            )['elapsedSeconds'];
            if (count($upcoming) < 10) {
                $package = $this->get(self::PACKAGE, (string) $block->get('workPackageId'));
                try {
                    $this->target(
                        (string) $package->get('targetType'),
                        (string) $package->get('targetId'),
                        false
                    );
                } catch (Forbidden|NotFound) {
                    continue;
                }
                $upcoming[] = [
                    'name' => $block->get('name'),
                    'dateStart' => $block->get('dateStart'),
                    'dateEnd' => $block->get('dateEnd'),
                    'targetType' => $package->get('targetType'),
                    'targetId' => $package->get('targetId'),
                    'targetIdentifier' => $package->get('targetIdentifier'),
                ];
            }
        }

        $bookableSeconds = (int) round(
            $this->calendarUtilityFactory->createForUser($resource)->getSummedWorkingHours(
                FieldDateTime::fromString($now->format('Y-m-d H:i:s')),
                FieldDateTime::fromString($to->format('Y-m-d H:i:s'))
            ) * 3600
        );
        $active = null;
        foreach ($this->entityManager->getRDBRepository(self::SESSION)
            ->where(['status' => 'Active'])
            ->find() as $session) {
            if (in_array($userId, (array) ($session->get('attendeeIds') ?? []), true)) {
                $active = $this->sessionDto($session);
                break;
            }
        }

        return [
            'kind' => 'User',
            'activeSession' => $active,
            'scheduledSeconds' => $scheduledSeconds,
            'bookableSeconds' => $bookableSeconds,
            'utilizationPercent' => $bookableSeconds > 0
                ? round($scheduledSeconds / $bookableSeconds * 100, 1)
                : 0,
            'upcoming' => $upcoming,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createPackage(array $input): array
    {
        return $this->entityManager->getTransactionManager()->run(
            fn (): array => $this->doCreatePackage($input)
        );
    }

    /**
     * Attach additional Work Block definitions to an existing target package.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function attachWorkBlocks(string $packageId, array $input): array
    {
        return $this->entityManager->getTransactionManager()->run(
            function () use ($packageId, $input): array {
                $package = $this->get(self::PACKAGE, $packageId);
                $instance = $this->get(self::INSTANCE, (string) $package->get('instanceId'));
                $this->target(
                    (string) $package->get('targetType'),
                    (string) $package->get('targetId'),
                    true
                );

                $templateIds = $input['templateIds'] ?? null;
                if (!is_array($templateIds) || $templateIds === []) {
                    throw new BadRequest('At least one Work Block is required.');
                }

                $attendeeIds = $this->stringList($input['attendeeIds'] ?? []);
                $start = new DateTimeImmutable($this->requiredString($input, 'scheduledStart'));
                $sequence = 0;

                foreach ($this->entityManager->getRDBRepository(self::BLOCK_RUN)
                    ->where(['workPackageId' => $package->getId()])
                    ->find() as $existingRun) {
                    $sequence = max($sequence, (int) $existingRun->get('sequence') + 1);
                }

                $addedEstimate = 0;
                $runs = [];

                foreach ($templateIds as $templateId) {
                    if (!is_string($templateId)) {
                        throw new BadRequest('Invalid Work Block selection.');
                    }

                    $template = $this->get(self::TEMPLATE, $templateId);
                    if (
                        $template->get('instanceId') !== $instance->getId() ||
                        !$template->get('active')
                    ) {
                        throw new BadRequest('Invalid Work Block selection.');
                    }

                    $memberships = iterator_to_array(
                        $this->entityManager->getRDBRepository(self::BLOCK_ITEM)
                            ->where(['workBlockId' => $template->getId()])
                            ->order('sequence')
                            ->find()
                    );
                    if ($memberships === []) {
                        throw new BadRequest(
                            'Every selected Work Block must contain at least one Work Item.'
                        );
                    }

                    $seconds = array_sum(array_map(
                        static fn (Entity $membership): int =>
                            (int) $membership->get('effectiveEstimateSeconds'),
                        $memberships
                    ));
                    $end = $start->modify("+$seconds seconds");
                    $run = $this->entityManager->createEntity(self::BLOCK_RUN, [
                        'name' => $template->get('name'),
                        'workPackageId' => $package->getId(),
                        'definitionId' => $template->getId(),
                        'status' => 'Planned',
                        'milestoneKind' => $template->get('milestoneKind') ?? 'Normal',
                        'sequence' => $sequence,
                        'totalEstimateSeconds' => $seconds,
                    ]);
                    $block = $this->entityManager->createEntity(self::BLOCK, [
                        'name' => $template->get('name'),
                        'status' => 'Planned',
                        'dateStart' => $start->format('Y-m-d H:i:s'),
                        'dateEnd' => $end->format('Y-m-d H:i:s'),
                        'workPackageId' => $package->getId(),
                        'workBlockRunId' => $run->getId(),
                        'templateId' => $template->getId(),
                        'instanceId' => $instance->getId(),
                        'activitiesSnapshot' => [],
                        'estimatedSeconds' => $seconds,
                        'sequence' => $sequence,
                        'milestoneKind' => $template->get('milestoneKind') ?? 'Normal',
                        'assignedUsersIds' => $attendeeIds,
                    ]);

                    $activityNames = [];
                    foreach ($memberships as $membership) {
                        $workItem = $this->get(
                            self::WORK_ITEM,
                            (string) $membership->get('workItemId')
                        );
                        $activityNames[] = (string) $workItem->get('name');
                        $this->entityManager->createEntity(self::ITEM_RUN, [
                            'name' => $workItem->get('name'),
                            'workBlockRunId' => $run->getId(),
                            'sourceWorkItemId' => $workItem->getId(),
                            'scheduledBlockId' => $block->getId(),
                            ...WorkItemMath::snapshot(
                                (string) $workItem->get('name'),
                                (string) ($workItem->get('description') ?? ''),
                                (int) $membership->get('effectiveEstimateSeconds'),
                                (int) $membership->get('sequence')
                            ),
                            'status' => 'Planned',
                        ]);
                    }

                    $block->set('activitiesSnapshot', $activityNames);
                    $this->entityManager->saveEntity($block);
                    $runs[] = $this->workBlockRunDto($run);
                    $addedEstimate += $seconds;
                    $start = $end;
                    $sequence++;
                }

                $plannedEnd = (string) ($package->get('plannedEnd') ?? '');
                $newPlannedEnd = $start->format('Y-m-d H:i:s');
                $package->setMultiple([
                    'plannedEnd' => $plannedEnd === '' || $newPlannedEnd > $plannedEnd
                        ? $newPlannedEnd
                        : $plannedEnd,
                    'totalEstimateSeconds' =>
                        (int) $package->get('totalEstimateSeconds') + $addedEstimate,
                    'revision' => (int) $package->get('revision') + 1,
                ]);
                $this->entityManager->saveEntity($package);
                $this->recomputePackage($package->getId());

                return [
                    'package' => $this->packageDto($package),
                    'workBlocks' => $runs,
                ];
            }
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function doCreatePackage(array $input): array
    {
        $instance = $this->get(self::INSTANCE, $this->requiredString($input, 'instanceId'));
        $targetType = $this->requiredString($input, 'targetType');
        $targetId = $this->requiredString($input, 'targetId');
        $scheduledStart = $this->requiredString($input, 'scheduledStart');
        $target = $this->target($targetType, $targetId, true);

        if ($instance->get('status') !== 'Active' || $instance->get('targetEntityType') !== $targetType) {
            throw new BadRequest('The instance cannot be used for this target.');
        }

        if (!Eligibility::matches($target, (array) ($instance->get('eligibilityCriteria') ?? []))) {
            throw new BadRequest('The target does not meet the instance eligibility criteria.');
        }

        $existing = $this->entityManager->getRDBRepository(self::PACKAGE)
            ->where(['instanceId' => $instance->getId(), 'targetType' => $targetType, 'targetId' => $targetId])
            ->findOne();

        if ($existing) {
            return $this->packageDto($existing);
        }

        $templateIds = isset($input['templateIds']) && is_array($input['templateIds']) && $input['templateIds'] !== []
            ? $input['templateIds']
            : $this->defaultWorkBlockIds($instance);
        $attendeeIds = $this->stringList($input['attendeeIds'] ?? []);

        if ($templateIds === []) {
            throw new BadRequest('At least one Work Block template is required.');
        }

        $start = new DateTimeImmutable($scheduledStart);
        $estimateTotal = 0;
        $accountField = (string) ($instance->get('accountField') ?? '');
        $contactField = (string) ($instance->get('contactField') ?? '');
        $package = $this->entityManager->createEntity(self::PACKAGE, [
            'name' => (string) ($target->get($instance->get('identifierField')) ?: $target->getId()),
            'instanceId' => $instance->getId(),
            'targetType' => $targetType,
            'targetId' => $targetId,
            'targetIdentifier' => (string) ($target->get($instance->get('identifierField')) ?? $target->getId()),
            'targetName' => (string) ($target->get($instance->get('nameField')) ?? ''),
            'accountIdSnapshot' => $accountField ? $target->get($accountField . 'Id') : null,
            'accountNameSnapshot' => $accountField ? $target->get($accountField . 'Name') : null,
            'contactIdSnapshot' => $contactField ? $target->get($contactField . 'Id') : null,
            'contactNameSnapshot' => $contactField ? $target->get($contactField . 'Name') : null,
            'scheduledStart' => $start->format('Y-m-d H:i:s'),
            'plannedStart' => $start->format('Y-m-d H:i:s'),
            'lifecycle' => Lifecycle::OPEN,
        ]);

        $sequence = 0;

        foreach ($templateIds as $templateId) {
            if (!is_string($templateId)) {
                continue;
            }

            $template = $this->get(self::TEMPLATE, $templateId);

            if ($template->get('instanceId') !== $instance->getId() || !$template->get('active')) {
                throw new BadRequest('Invalid Work Block template.');
            }

            $memberships = iterator_to_array(
                $this->entityManager->getRDBRepository(self::BLOCK_ITEM)
                    ->where(['workBlockId' => $template->getId()])
                    ->order('sequence')
                    ->find()
            );

            if ($memberships === []) {
                throw new BadRequest('Every selected Work Block must contain at least one Work Item.');
            }

            $seconds = array_sum(array_map(
                static fn (Entity $membership): int => (int) $membership->get('effectiveEstimateSeconds'),
                $memberships
            ));
            $end = $start->modify("+$seconds seconds");
            $run = $this->entityManager->createEntity(self::BLOCK_RUN, [
                'name' => $template->get('name'),
                'workPackageId' => $package->getId(),
                'definitionId' => $template->getId(),
                'status' => 'Planned',
                'milestoneKind' => $template->get('milestoneKind') ?? 'Normal',
                'sequence' => $sequence,
                'totalEstimateSeconds' => $seconds,
            ]);
            $block = $this->entityManager->createEntity(self::BLOCK, [
                'name' => $template->get('name'),
                'status' => 'Planned',
                'dateStart' => $start->format('Y-m-d H:i:s'),
                'dateEnd' => $end->format('Y-m-d H:i:s'),
                'workPackageId' => $package->getId(),
                'workBlockRunId' => $run->getId(),
                'templateId' => $template->getId(),
                'instanceId' => $instance->getId(),
                'activitiesSnapshot' => [],
                'estimatedSeconds' => $seconds,
                'sequence' => $sequence,
                'milestoneKind' => $template->get('milestoneKind') ?? 'Normal',
                'assignedUsersIds' => $attendeeIds,
            ]);

            $activityNames = [];
            foreach ($memberships as $membership) {
                $workItem = $this->get(self::WORK_ITEM, (string) $membership->get('workItemId'));
                $activityNames[] = (string) $workItem->get('name');
                $this->entityManager->createEntity(self::ITEM_RUN, [
                    'name' => $workItem->get('name'),
                    'workBlockRunId' => $run->getId(),
                    'sourceWorkItemId' => $workItem->getId(),
                    'scheduledBlockId' => $block->getId(),
                    ...WorkItemMath::snapshot(
                        (string) $workItem->get('name'),
                        (string) ($workItem->get('description') ?? ''),
                        (int) $membership->get('effectiveEstimateSeconds'),
                        (int) $membership->get('sequence')
                    ),
                    'status' => 'Planned',
                ]);
            }

            $block->set('activitiesSnapshot', $activityNames);
            $this->entityManager->saveEntity($block);
            $estimateTotal += $seconds;
            $start = $end;
            $sequence++;
        }

        $package->setMultiple([
            'plannedEnd' => $start->format('Y-m-d H:i:s'),
            'totalEstimateSeconds' => $estimateTotal,
        ]);
        $this->entityManager->saveEntity($package);

        return $this->packageDto($package);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateScheduledBlock(string $id, array $input): array
    {
        $block = $this->get(self::BLOCK, $id);
        $package = $this->get(self::PACKAGE, (string) $block->get('workPackageId'));
        $this->target((string) $package->get('targetType'), (string) $package->get('targetId'), true);
        $this->assertRevision($block, $input);

        foreach (['dateStart', 'dateEnd', 'status', 'sequence', 'estimatedSeconds'] as $field) {
            if (array_key_exists($field, $input)) {
                $block->set($field, $input[$field]);
            }
        }

        if (isset($input['attendeeIds'])) {
            $block->set('assignedUsersIds', $this->stringList($input['attendeeIds'], true));
        }

        $time = TimeMath::calculate((string) $block->get('dateStart'), (string) $block->get('dateEnd'), 1);
        $warnings = $this->scheduleWarnings($block, $time['elapsedSeconds']);
        $instance = $this->get(self::INSTANCE, (string) $block->get('instanceId'));

        $blockingWarnings = array_filter($warnings, function (array $warning) use ($instance): bool {
            return $warning['type'] === 'OutsideHours'
                ? $instance->get('outsideHoursMode') === 'Block'
                : $instance->get('capacityConflictMode') === 'Block';
        });

        if ($blockingWarnings !== []) {
            throw new Conflict(implode(' ', array_column($blockingWarnings, 'message')));
        }

        $block->set('revision', (int) $block->get('revision') + 1);
        $this->entityManager->saveEntity($block);

        return ['block' => $this->entityDto($block), 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function rescheduleRemaining(string $id, array $input): array
    {
        return $this->entityManager->getTransactionManager()->run(
            function () use ($id, $input): array {
                $block = $this->get(self::BLOCK, $id);
                $package = $this->get(self::PACKAGE, (string) $block->get('workPackageId'));
                $this->target(
                    (string) $package->get('targetType'),
                    (string) $package->get('targetId'),
                    true
                );
                $runId = (string) $block->get('workBlockRunId');
                if ($runId === '') {
                    throw new BadRequest('Legacy Work Blocks must be migrated before rescheduling.');
                }

                $remaining = [];
                $remainingValues = [];
                foreach ($this->entityManager->getRDBRepository(self::ITEM_RUN)
                    ->where([
                        'workBlockRunId' => $runId,
                        'status' => ['Planned', 'In Progress'],
                    ])
                    ->order('sequence')
                    ->find() as $itemRun) {
                    $remaining[] = $itemRun;
                    $remainingValues[] = [
                        'estimatedSeconds' => (int) $itemRun->get('estimatedSeconds'),
                        'actualElapsedSeconds' => (int) $itemRun->get('actualElapsedSeconds'),
                        'status' => (string) $itemRun->get('status'),
                    ];
                }

                if ($remaining === []) {
                    throw new Conflict('This Work Block has no unfinished Work Items.');
                }
                $remainingSeconds = WorkItemMath::remainingSeconds($remainingValues);

                $start = new DateTimeImmutable($this->requiredString($input, 'dateStart'));
                $end = $start->modify("+$remainingSeconds seconds");
                $attendeeIds = isset($input['attendeeIds'])
                    ? $this->stringList($input['attendeeIds'], true)
                    : (array) ($block->get('assignedUsersIds') ?? []);
                $newBlock = $this->entityManager->createEntity(self::BLOCK, [
                    'name' => $block->get('name') . ' — Continued',
                    'status' => 'Planned',
                    'dateStart' => $start->format('Y-m-d H:i:s'),
                    'dateEnd' => $end->format('Y-m-d H:i:s'),
                    'workPackageId' => $package->getId(),
                    'workBlockRunId' => $runId,
                    'templateId' => $block->get('templateId'),
                    'instanceId' => $block->get('instanceId'),
                    'activitiesSnapshot' => array_map(
                        static fn (Entity $item): string => (string) $item->get('nameSnapshot'),
                        $remaining
                    ),
                    'estimatedSeconds' => $remainingSeconds,
                    'sequence' => $block->get('sequence'),
                    'milestoneKind' => $block->get('milestoneKind'),
                    'assignedUsersIds' => $attendeeIds,
                ]);

                foreach ($remaining as $itemRun) {
                    $itemRun->set('scheduledBlockId', $newBlock->getId());
                    $this->entityManager->saveEntity($itemRun);
                }

                $block->setMultiple([
                    'status' => $this->sumBlockElapsed($block->getId()) > 0
                        ? 'Completed'
                        : 'Cancelled',
                    'completedAt' => DateTime::getSystemNowString(),
                ]);
                $this->entityManager->saveEntity($block);

                $warnings = $this->scheduleWarnings($newBlock, $remainingSeconds);

                return [
                    'block' => $this->entityDto($newBlock),
                    'workBlock' => $this->workBlockRunDto($this->get(self::BLOCK_RUN, $runId)),
                    'warnings' => $warnings,
                ];
            }
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function reportIn(array $input): array
    {
        return $this->entityManager->getTransactionManager()->run(
            fn (): array => $this->doReportIn($input)
        );
    }

    /**
     * Timer-first alias for the report-in compatibility operation.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function startTimer(array $input): array
    {
        return $this->entityManager->getTransactionManager()->run(
            fn (): array => $this->doReportIn($input)
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function doReportIn(array $input): array
    {
        $package = $this->get(self::PACKAGE, $this->requiredString($input, 'packageId'));
        $this->target((string) $package->get('targetType'), (string) $package->get('targetId'), true);

        if (!$package->get('scheduledStart')) {
            throw new BadRequest('Scheduled Start is required.');
        }

        $clientActionId = $this->requiredString($input, 'clientActionId');
        $duplicate = $this->entityManager->getRDBRepository(self::SESSION)
            ->where(['clientActionId' => $clientActionId])
            ->findOne();

        if ($duplicate) {
            return $this->sessionDto($duplicate);
        }

        if ($this->activeSession($package->getId())) {
            throw new Conflict('This package already has an active session.');
        }

        $itemRun = isset($input['workItemRunId'])
            ? $this->get(self::ITEM_RUN, $this->requiredString($input, 'workItemRunId'))
            : $this->nextWorkItemRun($package->getId());
        $run = $this->get(self::BLOCK_RUN, (string) $itemRun->get('workBlockRunId'));

        if ($run->get('workPackageId') !== $package->getId()) {
            throw new BadRequest('The selected Work Item does not belong to this target.');
        }

        if (in_array($itemRun->get('status'), ['Completed', 'Cancelled'], true)) {
            throw new Conflict('The selected Work Item is not available for time logging.');
        }

        $block = $this->get(self::BLOCK, (string) $itemRun->get('scheduledBlockId'));
        $attendees = $this->stringList(
            $input['attendeeIds'] ?? [$this->user->getId()]
        );
        $this->assertAttendeesAvailable($attendees);
        $now = DateTime::getSystemNowString();
        $early = $now < (string) $package->get('scheduledStart');
        $session = $this->entityManager->createEntity(self::SESSION, [
            'name' => 'Timer: ' . $itemRun->get('nameSnapshot'),
            'workPackageId' => $package->getId(),
            'workBlockRunId' => $run->getId(),
            'workItemRunId' => $itemRun->getId(),
            'scheduledBlockId' => $block->getId(),
            'status' => 'Active',
            'startedAt' => $now,
            'lastCheckpointAt' => $now,
            'attendeeIds' => $attendees,
            'earlyCheckIn' => $early,
            'clientActionId' => $clientActionId,
        ]);

        $itemRun->set('status', 'In Progress');
        $run->set('status', 'In Progress');
        $block->set('status', 'In Progress');
        $this->entityManager->saveEntity($itemRun);
        $this->entityManager->saveEntity($run);
        $this->entityManager->saveEntity($block);

        $this->syncTargetStatus($package, 'inProgressStatus');

        return $this->sessionDto($session);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function stopTimer(string $sessionId, array $input): array
    {
        return $this->entityManager->getTransactionManager()->run(
            fn (): array => $this->doStopTimer($sessionId, $input)
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function doStopTimer(string $sessionId, array $input): array
    {
        $duplicate = $this->entryByAction($input['clientActionId'] ?? null);
        if ($duplicate) {
            return ['entry' => $this->entityDto($duplicate), 'duplicate' => true];
        }

        $session = $this->activeSessionById($sessionId);
        $package = $this->get(self::PACKAGE, (string) $session->get('workPackageId'));
        $itemRun = $this->get(self::ITEM_RUN, (string) $session->get('workItemRunId'));
        $block = $this->get(self::BLOCK, (string) $session->get('scheduledBlockId'));
        $now = DateTime::getSystemNowString();
        $entry = $this->createEntry(
            $package,
            $block,
            (string) $session->get('lastCheckpointAt'),
            $now,
            (array) $session->get('attendeeIds'),
            'Interactive',
            $input,
            $session,
            $itemRun
        );

        $complete = (bool) ($input['complete'] ?? false);
        $itemRun->set('status', $complete ? 'Completed' : 'In Progress');
        $itemRun->set('completedAt', $complete ? $now : null);
        $session->setMultiple([
            'status' => 'Completed',
            'endedAt' => $now,
            'lastCheckpointAt' => $now,
        ]);
        $this->entityManager->saveEntity($itemRun);
        $this->entityManager->saveEntity($session);
        $this->recomputeWorkItemRun($itemRun);
        $this->recomputeWorkBlockRun((string) $itemRun->get('workBlockRunId'));
        $this->recomputePackage($package->getId());

        return [
            'entry' => $this->entityDto($entry),
            'session' => $this->sessionDto($session),
            'workBlock' => $this->workBlockRunDto(
                $this->get(self::BLOCK_RUN, (string) $itemRun->get('workBlockRunId'))
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function milestone(string $sessionId, array $input): array
    {
        return $this->entityManager->getTransactionManager()->run(
            fn (): array => $this->doMilestone($sessionId, $input)
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function doMilestone(string $sessionId, array $input): array
    {
        $duplicate = $this->entryByAction($input['clientActionId'] ?? null);
        if ($duplicate) {
            return ['entry' => $this->entityDto($duplicate), 'duplicate' => true];
        }
        $session = $this->activeSessionById($sessionId);
        $itemRunId = $session->get('workItemRunId');
        $itemRun = is_string($itemRunId) && $itemRunId !== ''
            ? $this->get(self::ITEM_RUN, $itemRunId)
            : null;
        $block = $itemRun
            ? $this->get(self::BLOCK, (string) $itemRun->get('scheduledBlockId'))
            : $this->get(self::BLOCK, $this->requiredString($input, 'blockId'));
        $now = DateTime::getSystemNowString();
        $entry = $this->createEntry(
            $this->get(self::PACKAGE, (string) $session->get('workPackageId')),
            $block,
            (string) $session->get('lastCheckpointAt'),
            $now,
            (array) $session->get('attendeeIds'),
            'Interactive',
            $input,
            $session,
            $itemRun
        );

        if ($itemRun) {
            $itemRun->setMultiple(['status' => 'Completed', 'completedAt' => $now]);
            $this->entityManager->saveEntity($itemRun);
            $this->recomputeWorkItemRun($itemRun);
            $this->recomputeWorkBlockRun((string) $itemRun->get('workBlockRunId'));
        } else {
            $block->setMultiple(['status' => 'Completed', 'completedAt' => $now]);
            $this->entityManager->saveEntity($block);
        }
        $session->setMultiple(['lastCheckpointAt' => $now, 'revision' => (int) $session->get('revision') + 1]);
        $this->entityManager->saveEntity($session);
        $this->recomputePackage((string) $session->get('workPackageId'));

        return ['entry' => $this->entityDto($entry), 'session' => $this->sessionDto($session)];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function finish(string $sessionId, array $input): array
    {
        return $this->entityManager->getTransactionManager()->run(
            fn (): array => $this->doFinish($sessionId, $input)
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function doFinish(string $sessionId, array $input): array
    {
        $duplicate = $this->entryByAction($input['clientActionId'] ?? null);
        if ($duplicate) {
            return ['entries' => [$this->entityDto($duplicate)], 'duplicate' => true];
        }
        $session = $this->activeSessionById($sessionId);
        $package = $this->get(self::PACKAGE, (string) $session->get('workPackageId'));
        $segments = $input['segments'] ?? [];

        if (!is_array($segments) || $segments === []) {
            $blockId = $this->requiredString($input, 'blockId');
            $segments = [[
                'blockId' => $blockId,
                'start' => $session->get('lastCheckpointAt'),
                'end' => DateTime::getSystemNowString(),
                'workNote' => $input['workNote'] ?? null,
                'userFlagged' => $input['userFlagged'] ?? false,
                'dragReason' => $input['dragReason'] ?? '',
                'clientActionId' => $input['clientActionId'] ?? null,
            ]];
        }

        $ranges = [];
        $sessionStart = (string) $session->get('startedAt');
        $sessionEnd = DateTime::getSystemNowString();
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                throw new BadRequest('Invalid segment.');
            }
            $range = ['start' => $this->requiredString($segment, 'start'), 'end' => $this->requiredString($segment, 'end')];
            if ($range['start'] < $sessionStart || $range['end'] > $sessionEnd) {
                throw new BadRequest('Completion segments must stay within the active session.');
            }
            $ranges[] = $range;
        }

        try {
            TimeMath::assertNoOverlap($ranges);
        } catch (InvalidArgumentException $e) {
            throw new BadRequest($e->getMessage());
        }

        $sortedRanges = $ranges;
        usort($sortedRanges, fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
        $hasGap = false;
        for ($i = 1; $i < count($sortedRanges); $i++) {
            if ($sortedRanges[$i]['start'] > $sortedRanges[$i - 1]['end']) {
                $hasGap = true;
                break;
            }
        }
        if ($hasGap && !($input['acknowledgeGaps'] ?? false)) {
            throw new BadRequest('Visible gaps require explicit acknowledgement.');
        }

        $entries = [];
        $affectedRunIds = [];
        foreach ($segments as $index => $segment) {
            $block = $this->get(self::BLOCK, $this->requiredString($segment, 'blockId'));
            if ($block->get('workPackageId') !== $package->getId()) {
                throw new BadRequest('Block does not belong to the session package.');
            }
            if (!isset($segment['clientActionId']) && isset($input['clientActionId'])) {
                $segment['clientActionId'] = $index === 0
                    ? $input['clientActionId']
                    : $input['clientActionId'] . '-' . $index;
            }
            $sessionItemRunId = $session->get('workItemRunId');
            $itemRun = is_string($sessionItemRunId) && $sessionItemRunId !== ''
                ? $this->get(self::ITEM_RUN, $sessionItemRunId)
                : null;
            if ($itemRun && $itemRun->get('scheduledBlockId') !== $block->getId()) {
                $itemRun = null;
            }
            $itemRun ??= $this->firstWorkItemRunForBlock($block->getId());
            $entries[] = $this->entityDto($this->createEntry(
                $package,
                $block,
                $this->requiredString($segment, 'start'),
                $this->requiredString($segment, 'end'),
                (array) $session->get('attendeeIds'),
                'Interactive',
                $segment,
                $session,
                $itemRun
            ));
            if ($itemRun) {
                $itemRun->setMultiple([
                    'status' => 'Completed',
                    'completedAt' => $this->requiredString($segment, 'end'),
                ]);
                $this->entityManager->saveEntity($itemRun);
                $this->recomputeWorkItemRun($itemRun);
                $affectedRunIds[(string) $itemRun->get('workBlockRunId')] = true;
            } else {
                $block->setMultiple([
                    'status' => 'Completed',
                    'completedAt' => $this->requiredString($segment, 'end'),
                ]);
                $this->entityManager->saveEntity($block);
            }
        }

        $session->setMultiple(['status' => 'Completed', 'endedAt' => DateTime::getSystemNowString()]);
        $this->entityManager->saveEntity($session);
        foreach (array_keys($affectedRunIds) as $runId) {
            $this->recomputeWorkBlockRun($runId);
        }
        $this->recomputePackage($package->getId());

        return ['entries' => $entries, 'session' => $this->sessionDto($session)];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function manualEntry(array $input): array
    {
        return $this->entityManager->getTransactionManager()->run(
            fn (): array => $this->doManualEntry($input)
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function doManualEntry(array $input): array
    {
        $duplicate = $this->entryByAction($input['clientActionId'] ?? null);
        if ($duplicate) {
            return $this->entityDto($duplicate);
        }
        $package = $this->get(self::PACKAGE, $this->requiredString($input, 'packageId'));
        $block = $this->get(self::BLOCK, $this->requiredString($input, 'blockId'));
        $itemRun = isset($input['workItemRunId'])
            ? $this->get(self::ITEM_RUN, $this->requiredString($input, 'workItemRunId'))
            : $this->firstWorkItemRunForBlock($block->getId());
        $this->target((string) $package->get('targetType'), (string) $package->get('targetId'), true);

        if (
            !$package->get('scheduledStart') ||
            $block->get('workPackageId') !== $package->getId() ||
            ($itemRun && $itemRun->get('workBlockRunId') !== $block->get('workBlockRunId'))
        ) {
            throw new BadRequest('A scheduled package and matching block are required.');
        }

        $entry = $this->createEntry(
            $package,
            $block,
            $this->requiredString($input, 'start'),
            $this->requiredString($input, 'end'),
            $this->stringList($input['attendeeIds'] ?? []),
            'Manual',
            $input,
            null,
            $itemRun
        );
        if ($itemRun) {
            $this->recomputeWorkItemRun($itemRun);
            $this->recomputeWorkBlockRun((string) $itemRun->get('workBlockRunId'));
        }
        $this->recomputePackage($package->getId());

        return $this->entityDto($entry);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function capacity(array $input): array
    {
        $this->assertManager();
        $instanceId = $this->requiredString($input, 'instanceId');
        $from = $this->requiredString($input, 'from');
        $to = $this->requiredString($input, 'to');
        TimeMath::calculate($from, $to, 1);

        $blocks = $this->entityManager->getRDBRepository(self::BLOCK)
            ->where([
                'instanceId' => $instanceId,
                'dateStart<' => $to,
                'dateEnd>' => $from,
                'status!=' => 'Cancelled',
            ])
            ->order('dateStart')
            ->find();

        $items = [];
        $allocated = [];

        foreach ($blocks as $block) {
            $package = $this->get(self::PACKAGE, (string) $block->get('workPackageId'));
            try {
                $this->target(
                    (string) $package->get('targetType'),
                    (string) $package->get('targetId'),
                    false
                );
            } catch (Forbidden|NotFound) {
                continue;
            }
            $dto = $this->entityDto($block);
            $dto['userIds'] = (array) ($block->get('assignedUsersIds') ?? []);
            $items[] = $dto;
            foreach ($dto['userIds'] as $userId) {
                $allocated[$userId] = ($allocated[$userId] ?? 0) + (int) $block->get('estimatedSeconds');
            }
        }

        $bookable = [];
        foreach ($allocated as $userId => $seconds) {
            $resource = $this->entityManager->getRDBRepositoryByClass(User::class)->getById($userId);
            if (!$resource) {
                continue;
            }
            $profile = $this->entityManager->getRDBRepository('ElevateRmCapacityProfile')
                ->where(['instanceId' => $instanceId, 'userId' => $userId])
                ->findOne();
            $gross = $profile && $profile->get('weeklyCapacitySeconds')
                ? (int) $profile->get('weeklyCapacitySeconds')
                : (int) round($this->calendarUtilityFactory->createForUser($resource)->getSummedWorkingHours(
                    FieldDateTime::fromString($from),
                    FieldDateTime::fromString($to)
                ) * 3600);
            $reserve = (float) ($profile?->get('reservePercent') ?? 20);
            $bookable[$userId] = (int) round($gross * (1 - $reserve / 100));
        }

        return [
            'items' => $items,
            'allocatedSecondsByUser' => $allocated,
            'bookableSecondsByUser' => $bookable,
            'advice' => $this->capacityAdvice($allocated, $bookable),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function report(array $input): array
    {
        $this->assertManager();
        $where = [];
        if (isset($input['from'])) {
            $where['dateEnd>'] = $input['from'];
        }
        if (isset($input['to'])) {
            $where['dateStart<'] = $input['to'];
        }
        if (isset($input['packageId'])) {
            $where['workPackageId'] = $input['packageId'];
        }

        $entries = $this->entityManager->getRDBRepository(self::ENTRY)->where($where)->order('dateStart')->find();
        $elapsed = 0;
        $labour = 0;
        $items = [];
        $resourceNames = [];

        foreach ($entries as $entry) {
            $package = $this->get(self::PACKAGE, (string) $entry->get('workPackageId'));
            if (($input['instanceId'] ?? null) && $package->get('instanceId') !== $input['instanceId']) {
                continue;
            }
            if (($input['accountId'] ?? null) && $package->get('accountIdSnapshot') !== $input['accountId']) {
                continue;
            }
            if (($input['contactId'] ?? null) && $package->get('contactIdSnapshot') !== $input['contactId']) {
                continue;
            }
            if (($input['userId'] ?? null) && !in_array($input['userId'], (array) $entry->get('attendeeIds'), true)) {
                continue;
            }
            try {
                $this->target(
                    (string) $package->get('targetType'),
                    (string) $package->get('targetId'),
                    false
                );
            } catch (Forbidden|NotFound) {
                continue;
            }

            $includedStart = max(
                strtotime((string) $entry->get('dateStart')),
                isset($input['from']) ? strtotime((string) $input['from']) : PHP_INT_MIN
            );
            $includedEnd = min(
                strtotime((string) $entry->get('dateEnd')),
                isset($input['to']) ? strtotime((string) $input['to']) : PHP_INT_MAX
            );
            $includedElapsed = max(0, $includedEnd - $includedStart);
            $includedLabour = $includedElapsed * count((array) $entry->get('attendeeIds'));
            $elapsed += $includedElapsed;
            $labour += $includedLabour;
            $resourceNames = array_values(array_unique(array_merge(
                $resourceNames,
                (array) ($entry->get('attendeeNames') ?? [])
            )));
            $block = $this->get(self::BLOCK, (string) $entry->get('scheduledBlockId'));
            $itemRunId = $entry->get('workItemRunId');
            $itemRun = is_string($itemRunId) && $itemRunId !== ''
                ? $this->get(self::ITEM_RUN, $itemRunId)
                : null;
            $items[] = array_merge($this->entityDto($entry), [
                'includedElapsedSeconds' => $includedElapsed,
                'includedLabourSeconds' => $includedLabour,
                'targetIdentifier' => $package->get('targetIdentifier'),
                'targetName' => $package->get('targetName'),
                'accountName' => $package->get('accountNameSnapshot'),
                'contactName' => $package->get('contactNameSnapshot'),
                'blockName' => $block->get('name'),
                'workItemName' => $itemRun?->get('nameSnapshot'),
                'workItemDescription' => $itemRun?->get('descriptionSnapshot'),
                'estimatedSeconds' => $block->get('estimatedSeconds'),
                'activities' => $block->get('activitiesSnapshot'),
            ]);
        }

        return [
            'summary' => [
                'entryCount' => count($items),
                'resourceCount' => count($resourceNames),
                'resourceNames' => $resourceNames,
                'elapsedSeconds' => $elapsed,
                'labourSeconds' => $labour,
            ],
            'items' => $items,
            'csv' => $this->toCsv($items),
        ];
    }

    /** @return array<string, mixed> */
    public function billingQueue(string $instanceId): array
    {
        $this->assertBillingManager();
        $items = [];
        foreach ($this->entityManager->getRDBRepository(self::PACKAGE)
            ->where(['instanceId' => $instanceId])
            ->order('plannedStart', 'DESC')
            ->limit(500)
            ->find() as $package) {
            try {
                $this->target(
                    (string) $package->get('targetType'),
                    (string) $package->get('targetId'),
                    false
                );
            } catch (Forbidden|NotFound) {
                continue;
            }
            $items[] = $this->packageDto($package);
        }

        return ['items' => $items];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function billing(string $packageId, string $action, array $input): array
    {
        return $this->entityManager->getTransactionManager()->run(
            fn (): array => $this->doBilling($packageId, $action, $input)
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function doBilling(string $packageId, string $action, array $input): array
    {
        $this->assertBillingManager();
        $package = $this->get(self::PACKAGE, $packageId);
        $this->target(
            (string) $package->get('targetType'),
            (string) $package->get('targetId'),
            in_array($action, ['mark-invoiced', 'reopen'], true)
        );

        if ($action === 'preview' || $action === 'export') {
            return $this->billingData($package);
        }

        if ($action === 'mark-invoiced') {
            if ($package->get('lifecycle') !== Lifecycle::READY) {
                throw new Conflict('Only a ready package can be marked invoiced.');
            }

            $data = $this->billingData($package);
            $version = $this->entityManager->getRDBRepository(self::SNAPSHOT)
                ->where(['workPackageId' => $packageId])
                ->count() + 1;
            $snapshot = $this->entityManager->createEntity(self::SNAPSHOT, [
                'name' => $package->get('name') . " v$version",
                'workPackageId' => $packageId,
                'version' => $version,
                'status' => 'Current',
                'snapshotData' => $data,
                'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                'markedById' => $this->user->getId(),
                'markedAt' => DateTime::getSystemNowString(),
            ]);
            foreach ($this->entriesForPackage($packageId) as $entry) {
                $entry->setMultiple(['billingLocked' => true, 'billingSnapshotId' => $snapshot->getId()]);
                $this->entityManager->saveEntity($entry);
            }
            $package->set('lifecycle', Lifecycle::INVOICED);
            $this->entityManager->saveEntity($package);
            $this->syncTargetStatus($package, 'invoicedTargetStatus');
            return ['snapshot' => $this->entityDto($snapshot)];
        }

        if ($action === 'reopen') {
            foreach ($this->entityManager->getRDBRepository(self::SNAPSHOT)->where(['workPackageId' => $packageId, 'status' => 'Current'])->find() as $snapshot) {
                $snapshot->set('status', 'Superseded');
                $this->entityManager->saveEntity($snapshot);
            }
            foreach ($this->entriesForPackage($packageId) as $entry) {
                $entry->setMultiple(['billingLocked' => false, 'billingSnapshotId' => null]);
                $this->entityManager->saveEntity($entry);
            }
            $package->set('lifecycle', Lifecycle::READY);
            $this->entityManager->saveEntity($package);
            return $this->packageDto($package);
        }

        throw new BadRequest('Unsupported billing action.');
    }

    /** @return array<string, mixed> */
    public function billingExportData(string $packageId): array
    {
        $this->assertBillingManager();
        $package = $this->get(self::PACKAGE, $packageId);
        $this->target(
            (string) $package->get('targetType'),
            (string) $package->get('targetId'),
            false
        );

        return $this->billingData($package);
    }

    public function shouldAutoMarkInvoiced(): bool
    {
        $settings = $this->entityManager->getRDBRepository(self::SETTINGS)->findOne();
        return (bool) $settings?->get('autoMarkInvoicedOnExport');
    }

    public function reconcileTarget(Entity $target): void
    {
        if (str_starts_with($target->getEntityType(), 'ElevateRm')) {
            if ($target->getEntityType() === self::ENTRY && $target->get('workPackageId')) {
                $this->recomputePackage((string) $target->get('workPackageId'));
            }
            return;
        }

        foreach ($this->entityManager->getRDBRepository(self::PACKAGE)
            ->where(['targetType' => $target->getEntityType(), 'targetId' => $target->getId()])
            ->find() as $package) {
            $this->recomputePackage($package->getId());
        }
    }

    public function reconcileAll(): void
    {
        foreach ($this->entityManager->getRDBRepository(self::PACKAGE)->find() as $package) {
            try {
                $this->recomputePackage($package->getId());
            } catch (NotFound) {
                $flags = (array) ($package->get('attentionFlags') ?? []);
                if (!in_array('TargetMissing', $flags, true)) {
                    $flags[] = 'TargetMissing';
                    $package->set('attentionFlags', $flags);
                    $this->entityManager->saveEntity($package);
                }
            }
        }
    }

    /**
     * @param string[] $attendeeIds
     * @param array<string, mixed> $input
     */
    private function createEntry(
        Entity $package,
        Entity $block,
        string $start,
        string $end,
        array $attendeeIds,
        string $mode,
        array $input,
        ?Entity $session = null,
        ?Entity $itemRun = null
    ): Entity {
        if ($attendeeIds === []) {
            throw new BadRequest('At least one attendee is required.');
        }

        try {
            $time = TimeMath::calculate($start, $end, count($attendeeIds));
        } catch (InvalidArgumentException $e) {
            throw new BadRequest($e->getMessage());
        }

        foreach ($this->entityManager->getRDBRepository(self::ENTRY)->where([
            'dateStart<' => $end,
            'dateEnd>' => $start,
        ])->find() as $existing) {
            if (array_intersect($attendeeIds, (array) $existing->get('attendeeIds'))) {
                throw new Conflict('A selected attendee already has an overlapping Time Entry.');
            }
        }

        $actual = ($itemRun
            ? $this->sumItemRunElapsed($itemRun->getId())
            : $this->sumBlockElapsed($block->getId())
        ) + $time['elapsedSeconds'];
        $estimate = (int) ($itemRun?->get('estimatedSeconds') ?? $block->get('estimatedSeconds'));
        $overrun = TimeMath::isOverrun($actual, $estimate);
        $reason = (string) ($input['dragReason'] ?? '');
        $note = trim((string) ($input['workNote'] ?? ''));

        if ($overrun && !in_array($reason, ['InaccurateEstimate', 'Incident', 'Custom'], true)) {
            throw new BadRequest('Reason for Drag is required for this overrun.');
        }
        if ($reason === 'Custom' && $note === '') {
            throw new BadRequest('A Work Note is required for a custom drag reason.');
        }

        $flags = [];
        if ($overrun) {
            $flags[] = 'Overrun';
        }
        if ((bool) ($session?->get('earlyCheckIn') ?? $input['earlyCheckIn'] ?? false)) {
            $flags[] = 'EarlyCheckIn';
        }
        if ((bool) ($input['outsideHours'] ?? false)) {
            $flags[] = 'OutsideHours';
        }

        $entry = $this->entityManager->createEntity(self::ENTRY, [
            'name' => ($itemRun?->get('nameSnapshot') ?? $block->get('name')) . ' — ' . $start,
            'workPackageId' => $package->getId(),
            'scheduledBlockId' => $block->getId(),
            'workBlockRunId' => $itemRun?->get('workBlockRunId') ?? $block->get('workBlockRunId'),
            'workItemRunId' => $itemRun?->getId(),
            'workSessionId' => $session?->getId(),
            'dateStart' => $start,
            'dateEnd' => $end,
            'elapsedSeconds' => $time['elapsedSeconds'],
            'labourSeconds' => $time['labourSeconds'],
            'attendeeIds' => array_values($attendeeIds),
            'attendeeNames' => $this->attendeeNames($attendeeIds),
            'usersIds' => array_values($attendeeIds),
            'entryMode' => $mode,
            'workNote' => $note,
            'userFlagged' => (bool) ($input['userFlagged'] ?? false),
            'flagSources' => $flags,
            'dragReason' => $reason,
            'clientActionId' => $input['clientActionId'] ?? null,
        ]);

        $this->writeSafeStreamNote($package, $entry);

        return $entry;
    }

    private function recomputePackage(string $packageId): void
    {
        $package = $this->get(self::PACKAGE, $packageId);
        $blocks = iterator_to_array($this->entityManager->getRDBRepository(self::BLOCK)
            ->where(['workPackageId' => $packageId, 'status!=' => 'Cancelled'])->find());
        $completedEstimate = 0;
        $totalEstimate = 0;
        $withTime = 0;
        $requiredCount = 0;

        $runs = iterator_to_array($this->entityManager->getRDBRepository(self::BLOCK_RUN)
            ->where(['workPackageId' => $packageId, 'status!=' => 'Cancelled'])
            ->find());

        if ($runs !== []) {
            foreach ($runs as $run) {
                $estimate = (int) $run->get('totalEstimateSeconds');
                $totalEstimate += $estimate;
                if ($run->get('status') === 'Completed') {
                    $completedEstimate += $estimate;
                }
                foreach ($this->entityManager->getRDBRepository(self::ITEM_RUN)
                    ->where(['workBlockRunId' => $run->getId(), 'status!=' => 'Cancelled'])
                    ->find() as $itemRun) {
                    $requiredCount++;
                    if ((int) $itemRun->get('actualElapsedSeconds') > 0) {
                        $withTime++;
                    }
                }
            }
        } else {
            foreach ($blocks as $block) {
                $estimate = (int) $block->get('estimatedSeconds');
                $totalEstimate += $estimate;
                $requiredCount++;
                if ($block->get('status') === 'Completed') {
                    $completedEstimate += $estimate;
                }
                if ($this->sumBlockElapsed($block->getId()) > 0) {
                    $withTime++;
                }
            }
        }

        $instance = $this->get(self::INSTANCE, (string) $package->get('instanceId'));
        $target = $this->target((string) $package->get('targetType'), (string) $package->get('targetId'), false);
        $completedStatuses = Lifecycle::completedTargetStatusList(
            (array) ($instance->get('completedStatusList') ?? []),
            $instance->get('addTimeLogsTargetStatus'),
            $instance->get('readyForBillingTargetStatus'),
            $instance->get('invoicedTargetStatus')
        );
        $targetCompleted = in_array($target->get((string) $instance->get('statusField')), $completedStatuses, true);
        $current = (string) $package->get('lifecycle');
        if ($current === Lifecycle::INVOICED && !$targetCompleted) {
            $flags = (array) ($package->get('attentionFlags') ?? []);
            if (!in_array('TargetReopenedAfterInvoice', $flags, true)) {
                $flags[] = 'TargetReopenedAfterInvoice';
                $package->set('attentionFlags', $flags);
                $this->notifyReopenedAfterInvoice($package);
            }
        }
        $lifecycle = $current === Lifecycle::INVOICED
            ? $current
            : Lifecycle::forCompletion($targetCompleted, (bool) $this->activeSession($packageId), $requiredCount, $withTime);

        $package->setMultiple([
            'totalEstimateSeconds' => $totalEstimate,
            'completionPercent' => $totalEstimate ? round($completedEstimate / $totalEstimate * 100, 2) : 0,
            'lifecycle' => $lifecycle,
            'revision' => (int) $package->get('revision') + 1,
        ]);
        $this->entityManager->saveEntity($package);

        if ($current !== $lifecycle) {
            $this->notifyLifecycle($package, $lifecycle);
        }

        if ($lifecycle === Lifecycle::ADD_TIME) {
            $this->syncTargetStatus($package, 'addTimeLogsTargetStatus');
        } elseif ($lifecycle === Lifecycle::READY) {
            $this->syncTargetStatus($package, 'readyForBillingTargetStatus');
        }
    }

    /** @return Entity[] */
    private function matchingInstances(Entity $target): array
    {
        $items = [];
        foreach ($this->entityManager->getRDBRepository(self::INSTANCE)
            ->where(['targetEntityType' => $target->getEntityType(), 'status' => 'Active'])->find() as $instance) {
            if (Eligibility::matches($target, (array) ($instance->get('eligibilityCriteria') ?? []))) {
                $items[] = $instance;
            }
        }
        return $items;
    }

    /** @return string[] */
    private function defaultWorkBlockIds(Entity $instance): array
    {
        $ids = [];
        foreach ($this->entityManager->getRDBRepository(self::TEMPLATE)
            ->where([
                'instanceId' => $instance->getId(),
                'active' => true,
                'isDefault' => true,
            ])
            ->order('defaultOrder')
            ->find() as $workBlock) {
            $ids[] = $workBlock->getId();
        }

        if ($ids !== []) {
            return $ids;
        }

        return array_values(array_filter(
            (array) ($instance->get('defaultWorkBlockIds') ?? []),
            'is_string'
        ));
    }

    private function target(string $entityType, string $id, bool $edit): Entity
    {
        if (str_starts_with($entityType, 'ElevateRm') || $entityType === 'ElevateResourceManagement') {
            throw new BadRequest('Extension entities cannot be configured as targets.');
        }
        $entity = $this->entityManager->getRDBRepository($entityType)->getById($id);
        if (!$entity) {
            throw new NotFound();
        }
        $allowed = $edit ? $this->acl->checkEntityEdit($entity) : $this->acl->checkEntityRead($entity);
        if (!$allowed) {
            throw new Forbidden();
        }
        return $entity;
    }

    private function get(string $entityType, string $id): Entity
    {
        $entity = $this->entityManager->getRDBRepository($entityType)->getById($id);
        if (!$entity) {
            throw new NotFound();
        }
        return $entity;
    }

    private function activeSession(string $packageId): ?Entity
    {
        return $this->entityManager->getRDBRepository(self::SESSION)
            ->where(['workPackageId' => $packageId, 'status' => 'Active'])->findOne();
    }

    private function activeSessionById(string $id): Entity
    {
        $session = $this->get(self::SESSION, $id);
        if ($session->get('status') !== 'Active') {
            throw new Conflict('The session is no longer active.');
        }
        return $session;
    }

    private function nextWorkItemRun(string $packageId): Entity
    {
        foreach ($this->entityManager->getRDBRepository(self::BLOCK_RUN)
            ->where([
                'workPackageId' => $packageId,
                'status' => ['Planned', 'In Progress'],
            ])
            ->order('sequence')
            ->find() as $run) {
            $item = $this->entityManager->getRDBRepository(self::ITEM_RUN)
                ->where([
                    'workBlockRunId' => $run->getId(),
                    'status' => ['Planned', 'In Progress'],
                ])
                ->order('sequence')
                ->findOne();

            if ($item) {
                return $item;
            }
        }

        throw new BadRequest('No Work Item is available for time logging.');
    }

    private function firstWorkItemRunForBlock(string $blockId): ?Entity
    {
        $block = $this->get(self::BLOCK, $blockId);
        $runId = $block->get('workBlockRunId');

        if (!is_string($runId) || $runId === '') {
            return null;
        }

        return $this->entityManager->getRDBRepository(self::ITEM_RUN)
            ->where([
                'workBlockRunId' => $runId,
                'scheduledBlockId' => $blockId,
                'status!=' => 'Cancelled',
            ])
            ->order('sequence')
            ->findOne();
    }

    /** @param string[] $attendeeIds */
    private function assertAttendeesAvailable(array $attendeeIds): void
    {
        foreach ($this->entityManager->getRDBRepository(self::SESSION)->where(['status' => 'Active'])->find() as $session) {
            if (array_intersect($attendeeIds, (array) $session->get('attendeeIds'))) {
                throw new Conflict('One or more attendees are already checked in elsewhere.');
            }
        }
    }

    /**
     * @param string[] $ids
     * @return string[]
     */
    private function attendeeNames(array $ids): array
    {
        $names = [];
        foreach ($ids as $id) {
            $user = $this->entityManager->getRDBRepositoryByClass(User::class)->getById($id);
            if (!$user || (!$user->isRegular() && !$user->isAdmin())) {
                throw new BadRequest('Only active internal users can attend.');
            }
            $names[] = (string) $user->get('name');
        }
        return $names;
    }

    private function sumBlockElapsed(string $blockId): int
    {
        $sum = 0;
        foreach ($this->entityManager->getRDBRepository(self::ENTRY)->where(['scheduledBlockId' => $blockId])->find() as $entry) {
            $sum += (int) $entry->get('elapsedSeconds');
        }
        return $sum;
    }

    private function sumItemRunElapsed(string $itemRunId): int
    {
        $sum = 0;
        foreach ($this->entityManager->getRDBRepository(self::ENTRY)
            ->where(['workItemRunId' => $itemRunId])
            ->find() as $entry) {
            $sum += (int) $entry->get('elapsedSeconds');
        }

        return $sum;
    }

    private function recomputeWorkItemRun(Entity $itemRun): void
    {
        $elapsed = 0;
        $labour = 0;
        foreach ($this->entityManager->getRDBRepository(self::ENTRY)
            ->where(['workItemRunId' => $itemRun->getId()])
            ->find() as $entry) {
            $elapsed += (int) $entry->get('elapsedSeconds');
            $labour += (int) $entry->get('labourSeconds');
        }

        $itemRun->setMultiple([
            'actualElapsedSeconds' => $elapsed,
            'actualLabourSeconds' => $labour,
        ]);
        $this->entityManager->saveEntity($itemRun);
    }

    private function recomputeWorkBlockRun(string $runId): void
    {
        $run = $this->get(self::BLOCK_RUN, $runId);
        $total = 0;
        $elapsed = 0;
        $labour = 0;
        $hasProgress = false;
        $allComplete = true;
        $progressItems = [];

        foreach ($this->entityManager->getRDBRepository(self::ITEM_RUN)
            ->where(['workBlockRunId' => $runId, 'status!=' => 'Cancelled'])
            ->find() as $itemRun) {
            $estimate = (int) $itemRun->get('estimatedSeconds');
            $total += $estimate;
            $elapsed += (int) $itemRun->get('actualElapsedSeconds');
            $labour += (int) $itemRun->get('actualLabourSeconds');
            $status = (string) $itemRun->get('status');
            $progressItems[] = [
                'estimatedSeconds' => $estimate,
                'status' => $status,
            ];
            if ($status !== 'Completed') {
                $allComplete = false;
            }
            if ($status === 'In Progress' || (int) $itemRun->get('actualElapsedSeconds') > 0) {
                $hasProgress = true;
            }
        }

        $status = $allComplete && $total > 0
            ? 'Completed'
            : ($hasProgress ? 'In Progress' : 'Planned');
        $run->setMultiple([
            'status' => $status,
            'totalEstimateSeconds' => $total,
            'completionPercent' => WorkItemMath::completionPercent($progressItems),
            'actualElapsedSeconds' => $elapsed,
            'actualLabourSeconds' => $labour,
            'revision' => (int) $run->get('revision') + 1,
        ]);
        $this->entityManager->saveEntity($run);

        if ($status === 'Completed') {
            foreach ($this->entityManager->getRDBRepository(self::BLOCK)
                ->where(['workBlockRunId' => $runId, 'status!=' => 'Cancelled'])
                ->find() as $block) {
                $block->setMultiple([
                    'status' => 'Completed',
                    'completedAt' => DateTime::getSystemNowString(),
                ]);
                $this->entityManager->saveEntity($block);
            }
        }
    }

    private function entryByAction(mixed $clientActionId): ?Entity
    {
        if (!is_string($clientActionId) || $clientActionId === '') {
            return null;
        }

        return $this->entityManager->getRDBRepository(self::ENTRY)
            ->where(['clientActionId' => $clientActionId])
            ->findOne();
    }

    /** @return iterable<Entity> */
    private function entriesForPackage(string $packageId): iterable
    {
        return $this->entityManager->getRDBRepository(self::ENTRY)
            ->where(['workPackageId' => $packageId])->order('dateStart')->find();
    }

    /** @return array<string, mixed> */
    private function billingData(Entity $package): array
    {
        $items = [];
        $elapsed = 0;
        $labour = 0;
        foreach ($this->entriesForPackage($package->getId()) as $entry) {
            $block = $this->get(self::BLOCK, (string) $entry->get('scheduledBlockId'));
            $itemRunId = $entry->get('workItemRunId');
            $itemRun = is_string($itemRunId) && $itemRunId !== ''
                ? $this->get(self::ITEM_RUN, $itemRunId)
                : null;
            $items[] = [
                'date' => substr((string) $entry->get('dateStart'), 0, 10),
                'blockName' => $block->get('name'),
                'workItemName' => $itemRun?->get('nameSnapshot'),
                'workItemDescription' => $itemRun?->get('descriptionSnapshot'),
                'activities' => $block->get('activitiesSnapshot') ?? [],
                'start' => $entry->get('dateStart'),
                'end' => $entry->get('dateEnd'),
                'elapsedSeconds' => $entry->get('elapsedSeconds'),
                'labourSeconds' => $entry->get('labourSeconds'),
                'attendeeNames' => $entry->get('attendeeNames') ?? [],
                'workNote' => $entry->get('workNote'),
            ];
            $elapsed += (int) $entry->get('elapsedSeconds');
            $labour += (int) $entry->get('labourSeconds');
        }
        return [
            'ticketIdentifier' => $package->get('targetIdentifier'),
            'ticketName' => $package->get('targetName'),
            'items' => $items,
            'elapsedSeconds' => $elapsed,
            'labourSeconds' => $labour,
            'generatedAt' => DateTime::getSystemNowString(),
        ];
    }

    private function syncTargetStatus(Entity $package, string $mappingField): void
    {
        $instance = $this->get(self::INSTANCE, (string) $package->get('instanceId'));
        $value = $instance->get($mappingField);
        if (!$value) {
            return;
        }
        $target = $this->target((string) $package->get('targetType'), (string) $package->get('targetId'), true);
        $statusField = (string) $instance->get('statusField');
        if ($target->get($statusField) === $value) {
            return;
        }
        $this->recordServiceContainer
            ->get($target->getEntityType())
            ->update($target->getId(), (object) [$statusField => $value]);
    }

    private function notifyLifecycle(Entity $package, string $lifecycle): void
    {
        $settings = $this->entityManager->getRDBRepository(self::SETTINGS)->findOne();
        if (!$settings) {
            return;
        }

        $userId = match ($lifecycle) {
            Lifecycle::ADD_TIME => $settings->get('operationsManagerId'),
            Lifecycle::READY => $settings->get('billingAdministratorId'),
            default => null,
        };

        if (!is_string($userId) || $userId === '') {
            return;
        }

        $message = $lifecycle === Lifecycle::ADD_TIME
            ? 'Closed work requires missing time entries.'
            : 'Closed work is ready for billing.';

        $this->entityManager->createEntity('Notification', [
            'userId' => $userId,
            'type' => 'System',
            'message' => $message,
            'relatedType' => self::PACKAGE,
            'relatedId' => $package->getId(),
            'data' => [
                'entityType' => self::PACKAGE,
                'entityId' => $package->getId(),
                'entityName' => $package->get('name'),
                'lifecycle' => $lifecycle,
            ],
        ]);
    }

    private function notifyReopenedAfterInvoice(Entity $package): void
    {
        $settings = $this->entityManager->getRDBRepository(self::SETTINGS)->findOne();
        if (!$settings) {
            return;
        }

        $ids = array_unique(array_filter([
            $settings->get('operationsManagerId'),
            $settings->get('billingAdministratorId'),
        ], 'is_string'));

        foreach ($ids as $userId) {
            $this->entityManager->createEntity('Notification', [
                'userId' => $userId,
                'type' => 'System',
                'message' => 'An invoiced target was reopened and requires review.',
                'relatedType' => self::PACKAGE,
                'relatedId' => $package->getId(),
                'data' => [
                    'entityType' => self::PACKAGE,
                    'entityId' => $package->getId(),
                    'entityName' => $package->get('name'),
                    'attentionFlag' => 'TargetReopenedAfterInvoice',
                ],
            ]);
        }
    }

    private function writeSafeStreamNote(Entity $package, Entity $entry): void
    {
        $instance = $this->get(self::INSTANCE, (string) $package->get('instanceId'));
        if (!$instance->get('showStreamEntries')) {
            return;
        }

        $this->entityManager->createEntity('Note', [
            'type' => 'Post',
            'parentType' => $package->get('targetType'),
            'parentId' => $package->get('targetId'),
            'post' => sprintf(
                'Time entry added: %d elapsed minutes, %d labour minutes.',
                (int) floor((int) $entry->get('elapsedSeconds') / 60),
                (int) floor((int) $entry->get('labourSeconds') / 60)
            ),
        ]);
    }

    private function assertInternalUser(): void
    {
        if (!$this->user->isRegular() && !$this->user->isAdmin()) {
            throw new Forbidden('Internal users only.');
        }
    }

    private function assertManager(): void
    {
        $this->assertInternalUser();
        if ($this->user->isAdmin()) {
            return;
        }
        $settings = $this->entityManager->getRDBRepository(self::SETTINGS)->findOne();
        if (!$settings || !in_array($this->user->getId(), [$settings->get('operationsManagerId'), $settings->get('billingAdministratorId')], true)) {
            throw new Forbidden();
        }
    }

    private function assertBillingManager(): void
    {
        $this->assertInternalUser();
        if ($this->user->isAdmin()) {
            return;
        }
        $settings = $this->entityManager->getRDBRepository(self::SETTINGS)->findOne();
        if (!$settings || $settings->get('billingAdministratorId') !== $this->user->getId()) {
            throw new Forbidden();
        }
    }

    private function settingsEntity(): Entity
    {
        $settings = $this->entityManager->getRDBRepository(self::SETTINGS)->findOne();

        if ($settings) {
            return $settings;
        }

        if (!$this->user->isAdmin()) {
            throw new NotFound('Resource Management Settings are not initialized.');
        }

        return $this->entityManager->createEntity(self::SETTINGS, [
            'name' => 'Elevate Resource Management',
            'operationsManagerId' => $this->user->getId(),
            'billingAdministratorId' => $this->user->getId(),
            'autoMarkInvoicedOnExport' => false,
            'schemaVersion' => 2,
        ]);
    }

    /** @param array<string, mixed> $input */
    private function assertRevision(Entity $entity, array $input): void
    {
        if (!isset($input['revision']) || (int) $input['revision'] !== (int) $entity->get('revision')) {
            throw new Conflict('The record has changed. Refresh and try again.');
        }
    }

    /** @param array<string, mixed> $input */
    private function requiredString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new BadRequest("$key is required.");
        }
        return $value;
    }

    private function durationSeconds(mixed $value): int
    {
        if (is_int($value) || is_numeric($value)) {
            $seconds = (int) $value;

            if (!Duration::isQuarterHour($seconds)) {
                throw new BadRequest('Estimated time must use 15-minute increments up to 24 hours.');
            }

            return $seconds;
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            throw new BadRequest('Estimated time is required.');
        }

        try {
            return Duration::fromParts(
                (int) ($value['hours'] ?? -1),
                (int) ($value['minutes'] ?? -1)
            );
        } catch (InvalidArgumentException $e) {
            throw new BadRequest($e->getMessage());
        }
    }

    private function optionalDurationSeconds(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->durationSeconds($value);
    }

    /** @return string[] */
    private function stringList(mixed $value, bool $allowEmpty = false): array
    {
        if (!is_array($value)) {
            throw new BadRequest('Expected a list.');
        }
        $result = array_values(array_unique(array_filter($value, 'is_string')));
        if ($result === [] && !$allowEmpty) {
            throw new BadRequest('At least one user is required.');
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function entityDto(Entity $entity): array
    {
        return array_merge(['id' => $entity->getId()], (array) $entity->getValueMap());
    }

    /** @return array<string, mixed> */
    private function packageDto(Entity $package): array
    {
        return $this->entityDto($package);
    }

    /** @return array<string, mixed> */
    private function sessionDto(Entity $session): array
    {
        return array_merge($this->entityDto($session), [
            'attendeeNames' => $this->resourceNames(
                (array) ($session->get('attendeeIds') ?? [])
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function workBlockDefinitionDto(Entity $workBlock): array
    {
        $items = [];
        foreach ($this->entityManager->getRDBRepository(self::BLOCK_ITEM)
            ->where(['workBlockId' => $workBlock->getId()])
            ->order('sequence')
            ->find() as $membership) {
            $workItem = $this->get(self::WORK_ITEM, (string) $membership->get('workItemId'));
            $items[] = [
                'id' => $membership->getId(),
                'workItemId' => $workItem->getId(),
                'name' => $workItem->get('name'),
                'description' => $workItem->get('description'),
                'defaultEstimateSeconds' => $workItem->get('defaultEstimateSeconds'),
                'estimateOverrideSeconds' => $membership->get('estimateOverrideSeconds'),
                'effectiveEstimateSeconds' => $membership->get('effectiveEstimateSeconds'),
                'sequence' => $membership->get('sequence'),
            ];
        }

        return array_merge($this->entityDto($workBlock), ['items' => $items]);
    }

    /** @return array<string, mixed> */
    private function workBlockRunDto(Entity $run): array
    {
        $items = [];
        foreach ($this->entityManager->getRDBRepository(self::ITEM_RUN)
            ->where(['workBlockRunId' => $run->getId(), 'status!=' => 'Cancelled'])
            ->order('sequence')
            ->find() as $item) {
            $items[] = $this->entityDto($item);
        }

        $schedules = [];
        foreach ($this->entityManager->getRDBRepository(self::BLOCK)
            ->where(['workBlockRunId' => $run->getId(), 'status!=' => 'Cancelled'])
            ->order('dateStart')
            ->find() as $block) {
            $dto = $this->entityDto($block);
            $dto['userIds'] = (array) ($block->get('assignedUsersIds') ?? []);
            $dto['userNames'] = $this->resourceNames($dto['userIds']);
            $schedules[] = $dto;
        }

        return array_merge($this->entityDto($run), [
            'items' => $items,
            'schedules' => $schedules,
        ]);
    }

    /**
     * Resolve display names without invalidating historical assignments when a
     * user has since been disabled.
     *
     * @param string[] $ids
     * @return string[]
     */
    private function resourceNames(array $ids): array
    {
        $names = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }
            $user = $this->entityManager->getRDBRepositoryByClass(User::class)->getById($id);
            if ($user) {
                $names[] = (string) $user->get('name');
            }
        }

        return $names;
    }

    /**
     * @param array<string, int> $allocated
     * @param array<string, int> $bookable
     * @return array<int, array<string, mixed>>
     */
    private function capacityAdvice(array $allocated, array $bookable): array
    {
        $advice = [];
        foreach ($allocated as $userId => $seconds) {
            $available = $bookable[$userId] ?? 0;
            $percent = $available > 0 ? round($seconds / $available * 100, 1) : 100;
            if ($percent >= 85) {
                $advice[] = [
                    'type' => $percent > 100 ? 'OverAllocated' : 'NearCapacity',
                    'userId' => $userId,
                    'utilizationPercent' => $percent,
                    'message' => $percent > 100 ? 'Allocation exceeds bookable capacity.' : 'Allocation is nearing bookable capacity.',
                ];
            }
        }
        return $advice;
    }

    /** @return array<int, array{type:string,message:string}> */
    private function scheduleWarnings(Entity $block, int $elapsedSeconds): array
    {
        $warnings = [];
        $userIds = (array) ($block->get('assignedUsersIds') ?? []);

        if ($userIds === []) {
            $warnings[] = ['type' => 'Unassigned', 'message' => 'The Work Block has no assigned resource.'];
        }

        foreach ($userIds as $userId) {
            if (!is_string($userId)) {
                continue;
            }
            $resource = $this->entityManager->getRDBRepositoryByClass(User::class)->getById($userId);
            if ($resource) {
                $hours = $this->calendarUtilityFactory->createForUser($resource)->getSummedWorkingHours(
                    FieldDateTime::fromString((string) $block->get('dateStart')),
                    FieldDateTime::fromString((string) $block->get('dateEnd'))
                );
                if ((int) round($hours * 3600) < $elapsedSeconds) {
                    $warnings[] = ['type' => 'OutsideHours', 'message' => 'The Work Block extends beyond a resource working calendar.'];
                }
            }

            foreach ($this->entityManager->getRDBRepository(self::BLOCK)->where([
                'id!=' => $block->getId(),
                'dateStart<' => $block->get('dateEnd'),
                'dateEnd>' => $block->get('dateStart'),
                'status!=' => 'Cancelled',
            ])->find() as $other) {
                if (in_array($userId, (array) ($other->get('assignedUsersIds') ?? []), true)) {
                    $warnings[] = ['type' => 'Overlap', 'message' => 'A resource is already assigned to an overlapping Work Block.'];
                    break;
                }
            }
        }

        return array_values(array_unique($warnings, SORT_REGULAR));
    }

    /** @param array<int, array<string, mixed>> $items */
    private function toCsv(array $items): string
    {
        $lines = ['ID,Start,End,Elapsed Seconds,Labour Seconds,Attendees,Work Note'];
        foreach ($items as $item) {
            $row = [
                $item['id'] ?? '',
                $item['dateStart'] ?? '',
                $item['dateEnd'] ?? '',
                $item['elapsedSeconds'] ?? 0,
                $item['labourSeconds'] ?? 0,
                implode('; ', (array) ($item['attendeeNames'] ?? [])),
                $item['workNote'] ?? '',
            ];
            $lines[] = implode(',', array_map(fn (mixed $v): string => '"' . str_replace('"', '""', (string) $v) . '"', $row));
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $lines);
    }
}
