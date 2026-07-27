<?php

use Espo\Core\Container;
use Espo\ORM\EntityManager;

class BeforeInstall
{
    public function run(Container $container): void
    {
        $entityManager = $container->getByClass(EntityManager::class);

        if (!$entityManager->getDefs()->hasEntity('ElevateRmWorkSession')) {
            return;
        }

        $activeCount = $entityManager->getRDBRepository('ElevateRmWorkSession')
            ->where(['status' => 'Active'])
            ->count();

        if ($activeCount > 0) {
            throw new \RuntimeException(
                'Stop all active Elevate Resource Management timers before upgrading.'
            );
        }
    }
}
