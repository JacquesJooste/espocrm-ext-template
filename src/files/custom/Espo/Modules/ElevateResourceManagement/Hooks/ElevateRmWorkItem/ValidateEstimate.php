<?php

namespace Espo\Modules\ElevateResourceManagement\Hooks\ElevateRmWorkItem;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\ElevateResourceManagement\Domain\Duration;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

final class ValidateEstimate implements BeforeSave
{
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew() && !$entity->isAttributeChanged('defaultEstimateSeconds')) {
            return;
        }

        if (!Duration::isQuarterHour((int) $entity->get('defaultEstimateSeconds'))) {
            throw new BadRequest('Estimated time must be between 15 minutes and 24 hours in 15-minute increments.');
        }
    }
}
