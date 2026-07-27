<?php

namespace Espo\Modules\ElevateResourceManagement\Hooks\ElevateRmWorkBlockItem;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\ElevateResourceManagement\Domain\Duration;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

final class ValidateCompositionItem implements BeforeSave
{
    public function __construct(private EntityManager $entityManager) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $override = $entity->get('estimateOverrideSeconds');

        if (
            $override !== null &&
            ($entity->isNew() || $entity->isAttributeChanged('estimateOverrideSeconds')) &&
            !Duration::isQuarterHour((int) $override)
        ) {
            throw new BadRequest('Estimated time overrides must use 15-minute increments.');
        }

        $workItemId = $entity->get('workItemId');
        $workItem = is_string($workItemId)
            ? $this->entityManager->getRDBRepository('ElevateRmWorkItem')->getById($workItemId)
            : null;

        if (!$workItem) {
            throw new BadRequest('A valid Work Item is required.');
        }

        $effective = $override !== null
            ? (int) $override
            : (int) $workItem->get('defaultEstimateSeconds');

        $entity->set('effectiveEstimateSeconds', $effective);
        $entity->set('name', sprintf(
            '%s #%d',
            (string) $workItem->get('name'),
            (int) $entity->get('sequence') + 1
        ));
    }
}
