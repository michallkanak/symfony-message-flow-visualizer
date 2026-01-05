<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Bundle configuration definition.
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('message_flow_visualizer');

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('enabled')
                    ->defaultValue('%env(bool:MESSAGE_FLOW_ENABLED)%')
                    ->info('Enable or disable message flow tracking')
                ->end()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('type')
                            ->values(['filesystem', 'doctrine', 'redis', 'memory'])
                            ->defaultValue('filesystem')
                            ->info('Storage backend for flow data')
                        ->end()
                        ->scalarNode('path')
                            ->defaultValue('%kernel.project_dir%/var/message_flow')
                            ->info('Path for filesystem storage')
                        ->end()
                        ->scalarNode('connection')
                            ->defaultValue('default')
                            ->info('Connection name for doctrine/redis storage')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('sampling')
                    ->addDefaultsIfNotSet()
                    ->info('Sampling configuration - when enabled, only a percentage of flows are tracked')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                            ->info('Enable sampling mode')
                        ->end()
                        ->floatNode('rate')
                            ->defaultValue(0.01)
                            ->min(0.0)
                            ->max(1.0)
                            ->info('Sampling rate (0.01 = 1% of flows)')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('cleanup')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('retention_days')
                            ->defaultValue(7)
                            ->min(1)
                            ->info('Number of days to keep flow data')
                        ->end()
                    ->end()
                ->end()
                ->integerNode('slow_threshold_ms')
                    ->defaultValue(500)
                    ->min(1)
                    ->info('Threshold in milliseconds to mark a flow as slow')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
