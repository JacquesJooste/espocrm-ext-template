<?php

namespace Espo\Modules\ElevateResourceManagement\Hooks\ElevateRmInstance;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;

final class ProtectHistory implements BeforeRemove
{
    public function __construct(private EntityManager $entityManager) {}

    public function beforeRemove(Entity $entity, RemoveOptions $options): void
    {
        if ($this->entityManager->getRDBRepository('ElevateRmWorkPackage')
            ->where(['instanceId' => $entity->getId()])
            ->count() > 0) {
            throw new Conflict('Instances with planning or time history must be archived, not deleted.');
        }
    }
}
