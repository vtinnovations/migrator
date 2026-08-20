<?php

/*
 * Contao Migrator
 *
 * Package: vtinnovations/migrator
 * Copyright: V&T Innovations Team
 * Licence: LGPL-3.0-or-later
 * Website: https://www.v-t.one
 */

declare(strict_types=1);

namespace Vtinnovations\Migrator\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('vtinnovations_migrator');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('scratch_dir')
                    ->info('Working directory for jobs, backups and staging. Relative paths resolve against the project dir.')
                    ->defaultValue('%kernel.project_dir%/var/migrator')
                ->end()
                ->floatNode('time_budget')
                    ->info('Seconds a single JobRunner tick is allowed to run before persisting and yielding.')
                    ->defaultValue(20.0)
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
