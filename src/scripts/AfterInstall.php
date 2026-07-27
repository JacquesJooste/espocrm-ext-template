<?php

use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\ElevateResourceManagement\Domain\LegacyMigration;

/**
 * Called when the extension is installed. Here you can write config parameter or create default records.
 */
class AfterInstall
{
    public function run(Container $container): void
    {
        $em = $container->getByClass(EntityManager::class);
        $configWriter = $container->getByClass(InjectableFactory::class)->create(ConfigWriter::class);
        $config = $container->getByClass(Config::class);

        $provenance = [];

        foreach ([
            'tabList' => 'ElevateResourceManagement',
            'calendarEntityList' => 'ElevateRmScheduledBlock',
            'busyRangesEntityList' => 'ElevateRmScheduledBlock',
        ] as $parameter => $value) {
            $list = (array) ($config->get($parameter) ?? []);

            if (!in_array($value, $list, true)) {
                $list[] = $value;
                $configWriter->set($parameter, $list);
                $provenance[$parameter] = true;
            } else {
                $provenance[$parameter] = false;
            }
        }

        $configWriter->save();

        $settings = $em->getRDBRepository('ElevateRmSettings')->findOne();

        if (!$settings) {
            $admin = $em->getRDBRepository('User')
                ->where(['isAdmin' => true, 'isActive' => true])
                ->order('createdAt')
                ->findOne();

            if ($admin) {
                $em->createEntity('ElevateRmSettings', [
                    'name' => 'Elevate Resource Management',
                    'operationsManagerId' => $admin->getId(),
                    'billingAdministratorId' => $admin->getId(),
                    'autoMarkInvoicedOnExport' => false,
                    'installProvenance' => $provenance,
                    'schemaVersion' => 1,
                ]);
            }
        } else {
            $settings->set('installProvenance', $provenance);
            $em->saveEntity($settings);
        }

        foreach ($em->getRDBRepository('ElevateRmScheduledBlock')
            ->where(['createdAt' => null])
            ->find() as $scheduledBlock) {
            $scheduledBlock->set(
                'createdAt',
                $scheduledBlock->get('dateStart') ?: date('Y-m-d H:i:s')
            );
            $scheduledBlock->set(
                'modifiedAt',
                $scheduledBlock->get('dateStart') ?: date('Y-m-d H:i:s')
            );
            $em->saveEntity($scheduledBlock);
        }

        $settings = $em->getRDBRepository('ElevateRmSettings')->findOne();

        if ($settings && (int) $settings->get('schemaVersion') < 2) {
            $this->migrateToSchemaVersion2($em, $settings);
        }
    }

    private function migrateToSchemaVersion2(EntityManager $em, Entity $settings): void
    {
        if ($em->getRDBRepository('ElevateRmWorkSession')
            ->where(['status' => 'Active'])
            ->count() > 0) {
            throw new \RuntimeException(
                'Stop all active Elevate Resource Management timers before upgrading.'
            );
        }

        $skipHooks = [SaveOption::SKIP_HOOKS => true];

        foreach ($em->getRDBRepository('ElevateRmWorkBlockTemplate')->find() as $workBlock) {
            $membership = $em->getRDBRepository('ElevateRmWorkBlockItem')
                ->where(['workBlockId' => $workBlock->getId()])
                ->order('sequence')
                ->findOne();

            if (!$membership) {
                $activities = array_values(array_filter(
                    (array) ($workBlock->get('activities') ?? []),
                    'is_string'
                ));
                $workItem = $em->getRDBRepository('ElevateRmWorkItem')
                    ->where(['legacyWorkBlockId' => $workBlock->getId()])
                    ->findOne();
                if (!$workItem) {
                    $workItem = $em->createEntity(
                        'ElevateRmWorkItem',
                        LegacyMigration::workItem(
                            $workBlock->getId(),
                            (string) $workBlock->get('name'),
                            $activities,
                            (int) $workBlock->get('estimatedSeconds'),
                            (bool) $workBlock->get('active')
                        ),
                        $skipHooks
                    );
                }
                $membership = $em->createEntity('ElevateRmWorkBlockItem', [
                    'name' => $workItem->get('name') . ' #1',
                    'workBlockId' => $workBlock->getId(),
                    'workItemId' => $workItem->getId(),
                    'sequence' => 0,
                    'estimateOverrideSeconds' => null,
                    'effectiveEstimateSeconds' => max(60, (int) $workBlock->get('estimatedSeconds')),
                ], $skipHooks);
            }
        }

        foreach ($em->getRDBRepository('ElevateRmInstance')->find() as $instance) {
            foreach (array_values((array) ($instance->get('defaultWorkBlockIds') ?? [])) as $order => $id) {
                if (!is_string($id)) {
                    continue;
                }
                $workBlock = $em->getRDBRepository('ElevateRmWorkBlockTemplate')->getById($id);
                if (!$workBlock || $workBlock->get('instanceId') !== $instance->getId()) {
                    continue;
                }
                $workBlock->setMultiple(['isDefault' => true, 'defaultOrder' => $order]);
                $em->saveEntity($workBlock, $skipHooks);
            }
        }

        foreach ($em->getRDBRepository('ElevateRmScheduledBlock')->find() as $scheduledBlock) {
            $run = null;
            $runId = $scheduledBlock->get('workBlockRunId');
            if (is_string($runId) && $runId !== '') {
                $run = $em->getRDBRepository('ElevateRmWorkBlockRun')->getById($runId);
            }
            if (!$run) {
                $run = $em->getRDBRepository('ElevateRmWorkBlockRun')
                    ->where(['legacyScheduledBlockId' => $scheduledBlock->getId()])
                    ->findOne();
            }

            if (!$run) {
                $run = $em->createEntity('ElevateRmWorkBlockRun', [
                    ...LegacyMigration::workBlockRun(
                        $scheduledBlock->getId(),
                        (string) $scheduledBlock->get('name'),
                        (string) $scheduledBlock->get('status'),
                        (string) ($scheduledBlock->get('milestoneKind') ?? ''),
                        (int) ($scheduledBlock->get('sequence') ?? 0),
                        (int) $scheduledBlock->get('estimatedSeconds')
                    ),
                    'workPackageId' => $scheduledBlock->get('workPackageId'),
                    'definitionId' => $scheduledBlock->get('templateId'),
                ], $skipHooks);
            }
            if ($scheduledBlock->get('workBlockRunId') !== $run->getId()) {
                $scheduledBlock->set('workBlockRunId', $run->getId());
                $em->saveEntity($scheduledBlock, $skipHooks);
            }

            $itemRun = $em->getRDBRepository('ElevateRmWorkItemRun')
                ->where(['workBlockRunId' => $run->getId()])
                ->order('sequence')
                ->findOne();

            if (!$itemRun) {
                $membership = null;
                $templateId = $scheduledBlock->get('templateId');
                if (is_string($templateId) && $templateId !== '') {
                    $membership = $em->getRDBRepository('ElevateRmWorkBlockItem')
                        ->where(['workBlockId' => $templateId])
                        ->order('sequence')
                        ->findOne();
                }
                $sourceId = $membership?->get('workItemId');
                $source = is_string($sourceId)
                    ? $em->getRDBRepository('ElevateRmWorkItem')->getById($sourceId)
                    : null;
                $snapshotName = (string) (
                    $source?->get('name') ?: $scheduledBlock->get('name')
                );
                $snapshotDescription = (string) (
                    $source?->get('description') ?:
                    implode("\n", (array) ($scheduledBlock->get('activitiesSnapshot') ?? []))
                );
                $itemRun = $em->createEntity('ElevateRmWorkItemRun', [
                    ...LegacyMigration::workItemRun(
                        $snapshotName,
                        $snapshotDescription,
                        (int) $scheduledBlock->get('estimatedSeconds'),
                        (string) $scheduledBlock->get('status'),
                        is_string($scheduledBlock->get('completedAt'))
                            ? $scheduledBlock->get('completedAt')
                            : null
                    ),
                    'workBlockRunId' => $run->getId(),
                    'sourceWorkItemId' => $source?->getId(),
                    'scheduledBlockId' => $scheduledBlock->getId(),
                ], $skipHooks);
            }

            $elapsed = 0;
            $labour = 0;
            foreach ($em->getRDBRepository('ElevateRmTimeEntry')
                ->where(['scheduledBlockId' => $scheduledBlock->getId()])
                ->find() as $entry) {
                $entry->setMultiple(LegacyMigration::timeEntryLinks(
                    $run->getId(),
                    $itemRun->getId(),
                    (array) ($entry->get('attendeeIds') ?? [])
                ));
                $em->saveEntity($entry, $skipHooks);
                $elapsed += (int) $entry->get('elapsedSeconds');
                $labour += (int) $entry->get('labourSeconds');
            }

            $itemRun->setMultiple([
                'actualElapsedSeconds' => $elapsed,
                'actualLabourSeconds' => $labour,
            ]);
            $em->saveEntity($itemRun, $skipHooks);
            $run->setMultiple([
                'actualElapsedSeconds' => $elapsed,
                'actualLabourSeconds' => $labour,
            ]);
            $em->saveEntity($run, $skipHooks);
        }

        $settings->set('schemaVersion', 2);
        $em->saveEntity($settings, $skipHooks);
    }
}

