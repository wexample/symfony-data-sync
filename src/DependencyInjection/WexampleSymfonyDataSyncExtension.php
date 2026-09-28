<?php

namespace Wexample\SymfonyDataSync\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Wexample\SymfonyDataSync\Service\DoctrineLocalStore;
use Wexample\SymfonyDataSync\Service\SyncDefinitionRegistry;
use Wexample\SymfonyHelpers\DependencyInjection\AbstractWexampleSymfonyExtension;

class WexampleSymfonyDataSyncExtension extends AbstractWexampleSymfonyExtension
{
    public function load(
        array $configs,
        ContainerBuilder $container
    ): void {
        $this->loadConfig(
            __DIR__,
            $container
        );

        $config = $this->processConfiguration(new Configuration(), $configs);

        // Adapters and local stores are app services named in the configuration:
        // the registry reaches them through a locator, built lazily per definition.
        $services = [DoctrineLocalStore::class => new Reference(DoctrineLocalStore::class)];
        foreach ($config['definitions'] as $definition) {
            $services[$definition['adapter']] = new Reference($definition['adapter']);

            if ($definition['local_store']) {
                $services[$definition['local_store']] = new Reference($definition['local_store']);
            }
        }

        $container->getDefinition(SyncDefinitionRegistry::class)
            ->setArgument('$definitions', $config['definitions'])
            ->setArgument('$services', ServiceLocatorTagPass::register($container, $services));
    }
}
