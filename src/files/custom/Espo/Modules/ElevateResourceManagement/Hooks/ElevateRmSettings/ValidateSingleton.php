<?php

namespace Espo\Modules\ElevateResourceManagement\Hooks\ElevateRmSettings;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

final class ValidateSingleton implements BeforeSave
{
    public function __construct(private EntityManager $entityManager) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->isNew() && $this->entityManager->getRDBRepository('ElevateRmSettings')->count() > 0) {
            throw new Conflict('Only one Elevate Resource Management settings record is allowed.');
        }

        foreach (['operationsManagerId', 'billingAdministratorId'] as $field) {
            $id = $entity->get($field);
            $user = is_string($id)
                ? $this->entityManager->getRDBRepositoryByClass(User::class)->getById($id)
                : null;

            if (!$user || !$user->get('isActive') || (!$user->isRegular() && !$user->isAdmin())) {
                throw new BadRequest('Configured managers must be active internal users.');
            }
        }

        $entity->set('name', 'Elevate Resource Management');
    }
}
