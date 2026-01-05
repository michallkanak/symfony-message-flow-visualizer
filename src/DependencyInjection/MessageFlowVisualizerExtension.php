<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\DependencyInjection;

use MichalKanak\MessageFlowVisualizerBundle\Storage\DoctrineStorage;
use MichalKanak\MessageFlowVisualizerBundle\Storage\FilesystemStorage;
use MichalKanak\MessageFlowVisualizerBundle\Storage\InMemoryStorage;
use MichalKanak\MessageFlowVisualizerBundle\Storage\RedisStorage;
use MichalKanak\MessageFlowVisualizerBundle\Storage\StorageInterface;
use Predis\Client as PredisClient;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * DI Extension for Message Flow Visualizer Bundle.
 */
class MessageFlowVisualizerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Set parameters
        $container->setParameter('message_flow_visualizer.enabled', $config['enabled']);
        $container->setParameter('message_flow_visualizer.storage.type', $config['storage']['type']);
        $container->setParameter('message_flow_visualizer.storage.path', $config['storage']['path']);
        $container->setParameter('message_flow_visualizer.storage.connection', $config['storage']['connection']);
        $container->setParameter('message_flow_visualizer.sampling.enabled', $config['sampling']['enabled']);
        $container->setParameter('message_flow_visualizer.sampling.rate', $config['sampling']['rate']);
        $container->setParameter('message_flow_visualizer.cleanup.retention_days', $config['cleanup']['retention_days']);
        $container->setParameter('message_flow_visualizer.slow_threshold_ms', $config['slow_threshold_ms']);

        // Load services
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.yaml');

        // Configure storage based on type
        $this->configureStorage($container, $config['storage']);
    }

    /**
     * @param array{type: string, path: string, connection: string} $storageConfig
     */
    private function configureStorage(ContainerBuilder $container, array $storageConfig): void
    {
        $storageType = $storageConfig['type'];

        // Register Redis services only when Redis storage is selected
        if ('redis' === $storageType) {
            // Register Predis client
            $redisClientDefinition = new Definition(PredisClient::class);
            $redisClientDefinition->addArgument('%message_flow_visualizer.storage.connection%');
            $container->setDefinition('message_flow_visualizer.redis_client', $redisClientDefinition);

            // Register RedisStorage
            $redisStorageDefinition = new Definition(RedisStorage::class);
            $redisStorageDefinition->addArgument(new Reference('message_flow_visualizer.redis_client'));
            $container->setDefinition(RedisStorage::class, $redisStorageDefinition);
        }

        $storageClass = match ($storageType) {
            'filesystem' => FilesystemStorage::class,
            'doctrine' => DoctrineStorage::class,
            'redis' => RedisStorage::class,
            'memory' => InMemoryStorage::class,
            default => FilesystemStorage::class,
        };

        $container->setAlias(StorageInterface::class, $storageClass);
    }

    public function getAlias(): string
    {
        return 'message_flow_visualizer';
    }
}
