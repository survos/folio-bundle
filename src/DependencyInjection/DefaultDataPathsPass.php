<?php

declare(strict_types=1);

namespace Survos\FolioBundle\DependencyInjection;

use Survos\DataContracts\Path\DataPaths;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers DataPaths from survos_folio.data_dir when nothing else did.
 *
 * It runs after every extension has been merged, so an application's own DataPaths (Harvest's,
 * from dataset-bundle, with its capture root and other paths) is seen and never overwritten.
 * Checking in loadExtension() cannot do that: each extension loads into an isolated builder.
 */
final class DefaultDataPathsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(DataPaths::class) || $container->hasAlias(DataPaths::class)
            || !$container->hasParameter('survos_folio.data_dir')) {
            return;
        }
        $container->register(DataPaths::class, DataPaths::class)
            ->setArgument('$dataDir', '%survos_folio.data_dir%')
            ->setPublic(true);
    }
}
