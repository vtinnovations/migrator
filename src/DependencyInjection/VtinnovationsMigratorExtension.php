<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class VtinnovationsMigratorExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('vtinnovations_migrator.scratch_dir', $config['scratch_dir']);
        $container->setParameter('vtinnovations_migrator.time_budget', $config['time_budget']);

        // Product identity — fixed code constants, never operator-config (so no request/config can
        // repoint the licensing destination or forge product identity).
        $container->setParameter('vtinnovations_migrator.project', 'Migrator');
        $container->setParameter('vtinnovations_migrator.project_slug', 'migrator');
        $container->setParameter('vtinnovations_migrator.product_id', 'vt-migrator');

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'vtinnovations_migrator';
    }
}
