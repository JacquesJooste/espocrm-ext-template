<?php

namespace Espo\Modules\ElevateResourceManagement\Hooks\ElevateRmInstance;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

final class SynchronizeDefaultWorkBlocks implements AfterSave
{
    public function __construct(private EntityManager $entityManager) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isAttributeChanged('defaultWorkBlockIds')) {
            return;
        }

        $selected = array_values(array_filter(
            (array) ($entity->get('defaultWorkBlockIds') ?? []),
            'is_string'
        ));
        $positions = array_flip($selected);

        foreach ($this->entityManager->getRDBRepository('ElevateRmWorkBlockTemplate')
            ->where(['instanceId' => $entity->getId()])
            ->find() as $workBlock) {
            $isDefault = isset($positions[$workBlock->getId()]);
            $order = $isDefault ? (int) $positions[$workBlock->getId()] : 0;
            if (
                (bool) $workBlock->get('isDefault') === $isDefault &&
                (int) $workBlock->get('defaultOrder') === $order
            ) {
                continue;
            }
            $workBlock->setMultiple([
                'isDefault' => $isDefault,
                'defaultOrder' => $order,
            ]);
            $this->entityManager->saveEntity($workBlock);
        }
    }
}
