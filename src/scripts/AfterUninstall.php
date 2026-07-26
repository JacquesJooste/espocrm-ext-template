<?php

use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\ORM\EntityManager;

/**
 * Called when the extension is uninstalled.
 */
class AfterUninstall
{
    public function run(Container $container): void
    {
        $em = $container->getByClass(EntityManager::class);
        $config = $container->getByClass(Config::class);
        $writer = $container->getByClass(InjectableFactory::class)->create(ConfigWriter::class);
        $settings = $em->getRDBRepository('ElevateRmSettings')->findOne();
        $provenance = $settings ? (array) ($settings->get('installProvenance') ?? []) : [];

        foreach ([
            'tabList' => 'ElevateResourceManagement',
            'calendarEntityList' => 'ElevateRmScheduledBlock',
            'busyRangesEntityList' => 'ElevateRmScheduledBlock',
        ] as $parameter => $value) {
            if (!($provenance[$parameter] ?? false)) {
                continue;
            }

            $list = array_values(array_filter(
                (array) ($config->get($parameter) ?? []),
                fn (mixed $item): bool => $item !== $value
            ));
            $writer->set($parameter, $list);
        }

        $writer->save();
    }
}

