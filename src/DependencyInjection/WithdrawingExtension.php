<?php

declare(strict_types=1);

namespace App\Withdrawing\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Loads Withdrawing service wiring into a consuming or standalone Symfony container.
 */
final class WithdrawingExtension extends Extension
{
    public function getAlias(): string
    {
        return 'withdrawing';
    }

    /**
     * Register the component service configuration in the supplied container builder.
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configDirectory = __DIR__.'/../../config';
        $loader = new YamlFileLoader($container, new FileLocator($configDirectory));
        $loader->load('services.yaml');
    }
}
