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
use Espo\Modules\ElevateResourceManagement\Domain\Lifecycle;
use Espo\Modules\ElevateResourceManagement\Domain\TimeMath;
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

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private CalendarUtilityFactory $calendarUtilityFactory,
        private ServiceContainer $recordServiceContainer,
    ) {}

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

        if ($package) {
            foreach ($this->entityManager->getRDBRepository(self::BLOCK)
                ->where(['workPackageId' => $package->getId(), 'status!=' => 'Cancelled'])
                ->order('sequence')
                ->find() as $block) {
                $blocks[] = $this->entityDto($block);
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
            'actions' => [
                'plan' => !$package && count($instances) > 0,
                'reportIn' => (bool) $package && (bool) $package->get('scheduledStart') && !$session,
                'milestone' => (bool) $session,
                'finish' => (bool) $session,
                'manualEntry' => (bool) $package && (bool) $package->get('scheduledStart'),
            ],
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

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createPackage(array $input): array
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

        $templateIds = $input['templateIds'] ?? $instance->get('defaultWorkBlockIds') ?? [];
        $attendeeIds = $this->stringList($input['attendeeIds'] ?? []);

        if (!is_array($templateIds) || $templateIds === []) {
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

            $seconds = (int) $template->get('estimatedSeconds');
            $end = $start->modify("+$seconds seconds");
            $this->entityManager->createEntity(self::BLOCK, [
                'name' => $template->get('name'),
                'status' => 'Planned',
                'dateStart' => $start->format('Y-m-d H:i:s'),
                'dateEnd' => $end->format('Y-m-d H:i:s'),
                'workPackageId' => $package->getId(),
                'templateId' => $template->getId(),
                'instanceId' => $instance->getId(),
                'activitiesSnapshot' => $template->get('activities') ?? [],
                'estimatedSeconds' => $seconds,
                'sequence' => $sequence++,
                'milestoneKind' => $template->get('milestoneKind') ?? 'Normal',
                'usersIds' => $attendeeIds,
            ]);
            $estimateTotal += $seconds;
            $start = $end;
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
            $block->set('usersIds', $this->stringList($input['attendeeIds'], true));
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
    public function reportIn(array $input): array
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

        $attendees = $this->stringList($input['attendeeIds'] ?? []);
        $this->assertAttendeesAvailable($attendees);
        $now = DateTime::getSystemNowString();
        $early = $now < (string) $package->get('scheduledStart');
        $session = $this->entityManager->createEntity(self::SESSION, [
            'name' => 'Session: ' . $package->get('name'),
            'workPackageId' => $package->getId(),
            'status' => 'Active',
            'startedAt' => $now,
            'lastCheckpointAt' => $now,
            'attendeeIds' => $attendees,
            'earlyCheckIn' => $early,
            'clientActionId' => $clientActionId,
        ]);

        $next = $this->entityManager->getRDBRepository(self::BLOCK)
            ->where(['workPackageId' => $package->getId(), 'status!=' => 'Completed'])
            ->order('sequence')
            ->findOne();

        if ($next) {
            $next->set('status', 'In Progress');
            $this->entityManager->saveEntity($next);
        }

        $this->syncTargetStatus($package, 'inProgressStatus');

        return $this->sessionDto($session);
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
        $block = $this->get(self::BLOCK, $this->requiredString($input, 'blockId'));
        $now = DateTime::getSystemNowString();
        $entry = $this->createEntry(
            $this->get(self::PACKAGE, (string) $session->get('workPackageId')),
            $block,
            (string) $session->get('lastCheckpointAt'),
            $now,
            (array) $session->get('attendeeIds'),
            'Interactive',
            $input,
            $session
        );

        $block->setMultiple(['status' => 'Completed', 'completedAt' => $now]);
        $session->setMultiple(['lastCheckpointAt' => $now, 'revision' => (int) $session->get('revision') + 1]);
        $this->entityManager->saveEntity($block);
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
            $entries[] = $this->entityDto($this->createEntry(
                $package,
                $block,
                $this->requiredString($segment, 'start'),
                $this->requiredString($segment, 'end'),
                (array) $session->get('attendeeIds'),
                'Interactive',
                $segment,
                $session
            ));
            $block->setMultiple(['status' => 'Completed', 'completedAt' => $this->requiredString($segment, 'end')]);
            $this->entityManager->saveEntity($block);
        }

        $session->setMultiple(['status' => 'Completed', 'endedAt' => DateTime::getSystemNowString()]);
        $this->entityManager->saveEntity($session);
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
        $this->target((string) $package->get('targetType'), (string) $package->get('targetId'), true);

        if (!$package->get('scheduledStart') || $block->get('workPackageId') !== $package->getId()) {
            throw new BadRequest('A scheduled package and matching block are required.');
        }

        $entry = $this->createEntry(
            $package,
            $block,
            $this->requiredString($input, 'start'),
            $this->requiredString($input, 'end'),
            $this->stringList($input['attendeeIds'] ?? []),
            'Manual',
            $input
        );
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
            $dto = $this->entityDto($block);
            $dto['userIds'] = (array) ($block->get('usersIds') ?? []);
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
            $items[] = array_merge($this->entityDto($entry), [
                'includedElapsedSeconds' => $includedElapsed,
                'includedLabourSeconds' => $includedLabour,
                'targetIdentifier' => $package->get('targetIdentifier'),
                'targetName' => $package->get('targetName'),
                'accountName' => $package->get('accountNameSnapshot'),
                'contactName' => $package->get('contactNameSnapshot'),
                'blockName' => $block->get('name'),
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

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function billing(string $packageId, string $action, array $input): array
    {
        $this->assertBillingManager();
        $package = $this->get(self::PACKAGE, $packageId);

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
        return $this->billingData($this->get(self::PACKAGE, $packageId));
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
        ?Entity $session = null
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

        $actual = $this->sumBlockElapsed($block->getId()) + $time['elapsedSeconds'];
        $overrun = TimeMath::isOverrun($actual, (int) $block->get('estimatedSeconds'));
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
            'name' => $block->get('name') . ' — ' . $start,
            'workPackageId' => $package->getId(),
            'scheduledBlockId' => $block->getId(),
            'workSessionId' => $session?->getId(),
            'dateStart' => $start,
            'dateEnd' => $end,
            'elapsedSeconds' => $time['elapsedSeconds'],
            'labourSeconds' => $time['labourSeconds'],
            'attendeeIds' => array_values($attendeeIds),
            'attendeeNames' => $this->attendeeNames($attendeeIds),
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

        foreach ($blocks as $block) {
            $estimate = (int) $block->get('estimatedSeconds');
            $totalEstimate += $estimate;
            if ($block->get('status') === 'Completed') {
                $completedEstimate += $estimate;
            }
            if ($this->sumBlockElapsed($block->getId()) > 0) {
                $withTime++;
            }
        }

        $instance = $this->get(self::INSTANCE, (string) $package->get('instanceId'));
        $target = $this->target((string) $package->get('targetType'), (string) $package->get('targetId'), false);
        $completedStatuses = (array) ($instance->get('completedStatusList') ?? []);
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
            : Lifecycle::forCompletion($targetCompleted, (bool) $this->activeSession($packageId), count($blocks), $withTime);

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
            $items[] = [
                'date' => substr((string) $entry->get('dateStart'), 0, 10),
                'blockName' => $block->get('name'),
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
        return $this->entityDto($session);
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
        $userIds = (array) ($block->get('usersIds') ?? []);

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
                if (in_array($userId, (array) ($other->get('usersIds') ?? []), true)) {
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
