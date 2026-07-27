<?php

namespace Espo\Modules\ElevateResourceManagement\Hooks\ElevateRmSettings;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RemoveOptions;

final class ProtectSingleton implements BeforeRemove
{
    public function beforeRemove(Entity $entity, RemoveOptions $options): void
    {
        throw new Forbidden('Resource Management Settings cannot be deleted.');
    }
}
