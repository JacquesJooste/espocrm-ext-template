<?php

use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\ORM\EntityManager;

/**
 * Called when the extension is installed. Here you can write config parameter or create default records.
 */
class AfterInstall
{
    public function run(Container $container): void
    {
        $em = $container->getByClass(EntityManager::class);
        $configWriter = $container->getByClass(InjectableFactory::class)->create(ConfigWriter::class);
        $config = $container->getByClass(Config::class);

        $provenance = [];

        foreach ([
            'tabList' => 'ElevateResourceManagement',
            'calendarEntityList' => 'ElevateRmScheduledBlock',
            'busyRangesEntityList' => 'ElevateRmScheduledBlock',
        ] as $parameter => $value) {
            $list = (array) ($config->get($parameter) ?? []);

            if (!in_array($value, $list, true)) {
                $list[] = $value;
                $configWriter->set($parameter, $list);
                $provenance[$parameter] = true;
            } else {
                $provenance[$parameter] = false;
            }
        }

        $configWriter->save();

        $settings = $em->getRDBRepository('ElevateRmSettings')->findOne();

        if (!$settings) {
            $admin = $em->getRDBRepository('User')
                ->where(['isAdmin' => true, 'isActive' => true])
                ->order('createdAt')
                ->findOne();

            if ($admin) {
                $em->createEntity('ElevateRmSettings', [
                    'name' => 'Elevate Resource Management',
                    'operationsManagerId' => $admin->getId(),
                    'billingAdministratorId' => $admin->getId(),
                    'autoMarkInvoicedOnExport' => false,
                    'installProvenance' => $provenance,
                    'schemaVersion' => 1,
                ]);
            }
        } else {
            $settings->set('installProvenance', $provenance);
            $em->saveEntity($settings);
        }
    }
}

