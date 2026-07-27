<?php

namespace Espo\Modules\ElevateResourceManagement\Hooks\ElevateRmWorkBlockItem;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

final class RecomputeWorkBlock implements AfterSave, AfterRemove
{
    public function __construct(private EntityManager $entityManager) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        $this->recompute((string) $entity->get('workBlockId'));
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        $this->recompute((string) $entity->get('workBlockId'));
    }

    private function recompute(string $workBlockId): void
    {
        if ($workBlockId === '') {
            return;
        }

        $workBlock = $this->entityManager
            ->getRDBRepository('ElevateRmWorkBlockTemplate')
            ->getById($workBlockId);

        if (!$workBlock) {
            return;
        }

        $total = 0;
        foreach ($this->entityManager->getRDBRepository('ElevateRmWorkBlockItem')
            ->where(['workBlockId' => $workBlockId])
            ->find() as $item) {
            $total += (int) $item->get('effectiveEstimateSeconds');
        }

        if ((int) $workBlock->get('estimatedSeconds') === $total) {
            return;
        }

        $workBlock->set('estimatedSeconds', $total);
        $this->entityManager->saveEntity($workBlock);
    }
}
