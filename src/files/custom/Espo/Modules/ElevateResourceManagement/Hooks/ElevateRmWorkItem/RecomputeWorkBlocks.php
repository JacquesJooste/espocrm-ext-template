<?php

namespace Espo\Modules\ElevateResourceManagement\Hooks\ElevateRmWorkItem;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

final class RecomputeWorkBlocks implements AfterSave
{
    public function __construct(private EntityManager $entityManager) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isAttributeChanged('defaultEstimateSeconds')) {
            return;
        }

        foreach ($this->entityManager->getRDBRepository('ElevateRmWorkBlockItem')
            ->where([
                'workItemId' => $entity->getId(),
                'estimateOverrideSeconds' => null,
            ])
            ->find() as $membership) {
            $membership->set('effectiveEstimateSeconds', (int) $entity->get('defaultEstimateSeconds'));
            $this->entityManager->saveEntity($membership);
        }
    }
}
