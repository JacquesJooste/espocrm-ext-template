<?php

namespace Espo\Modules\ElevateResourceManagement\Hooks\ElevateRmTimeEntry;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

final class ProtectBillingLock implements BeforeSave, BeforeRemove
{
    public function __construct(
        private EntityManager $entityManager,
        private User $user,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->isNew() || !$entity->getFetched('billingLocked')) {
            return;
        }

        if ($entity->get('billingLocked')) {
            throw new Conflict('Invoiced Time Entries are locked. Reopen billing first.');
        }

        if (!$this->isBillingManager()) {
            throw new Forbidden('Only the Billing Administrator can reopen billing.');
        }
    }

    public function beforeRemove(Entity $entity, RemoveOptions $options): void
    {
        if ($entity->get('billingLocked')) {
            throw new Conflict('Invoiced Time Entries are locked. Reopen billing first.');
        }
    }

    private function isBillingManager(): bool
    {
        if ($this->user->isAdmin()) {
            return true;
        }

        $settings = $this->entityManager->getRDBRepository('ElevateRmSettings')->findOne();

        return $settings && $settings->get('billingAdministratorId') === $this->user->getId();
    }
}
