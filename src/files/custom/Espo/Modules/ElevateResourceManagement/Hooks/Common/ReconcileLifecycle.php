<?php

namespace Espo\Modules\ElevateResourceManagement\Hooks\Common;

use Espo\Core\Hook\Hook\LateAfterSave;
use Espo\Modules\ElevateResourceManagement\Service\ApplicationService;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

final class ReconcileLifecycle implements LateAfterSave
{
    public static int $order = 100;

    /** @var string[] */
    private const EXCLUDED_ENTITY_TYPES = [
        'Email',
        'EmailAddress',
        'EmailAccount',
        'InboundEmail',
        'EmailFilter',
        'EmailFolder',
    ];

    public function __construct(private ApplicationService $service) {}

    public function lateAfterSave(Entity $entity, SaveOptions $options): void
    {
        if (in_array($entity->getEntityType(), [
            ...self::EXCLUDED_ENTITY_TYPES,
            'ElevateRmSettings',
            'ElevateRmInstance',
            'ElevateRmBillingSnapshot',
            'ElevateRmWorkPackage',
        ], true)) {
            return;
        }

        $this->service->reconcileTarget($entity);
    }
}
