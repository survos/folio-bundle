<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Publisher\PublisherApiClient;
use Survos\FolioBundle\Publisher\PublisherRegistrar;
use Survos\FolioBundle\Publisher\PublisherSelection;
use Survos\FolioBundle\SurvosFolioBundle;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/** survos_folio.publisher is opt-in; when on, the registrar is wired from config alone. */
final class PublisherWiringTest extends TestCase
{
    public function testOffByDefault(): void
    {
        $builder = $this->build([]);
        self::assertFalse($builder->hasDefinition(PublisherRegistrar::class));
    }

    public function testWiredFromConfig(): void
    {
        $builder = $this->build([
            'dataset_api' => ['server' => 'https://harvest.example', 'token' => 'read'],
            'publisher' => ['code' => 'ink', 'environment' => 'local', 'token' => 'ink-secret', 'label' => 'Ink',
                'base_url' => 'https://ink.wip', 'selection' => ['mode' => 'tags', 'tags' => ['newspaper']]],
        ]);

        $api = $builder->getDefinition(PublisherApiClient::class);
        self::assertSame(['$server' => 'https://harvest.example', '$token' => 'ink-secret', '$publisher' => 'ink', '$environment' => 'local'], $api->getArguments());

        $selection = $builder->getDefinition(PublisherRegistrar::class)->getArgument('$selection');
        self::assertInstanceOf(Definition::class, $selection);
        self::assertSame(['mode' => 'tags', 'tags' => ['newspaper']], PublisherSelection::fromArray($selection->getArgument(0))->toArray());
    }

    public function testRequiresTheDatasetApi(): void
    {
        $this->expectException(\LogicException::class);
        $this->build(['publisher' => ['code' => 'ink']]);
    }

    private function build(array $config): ContainerBuilder
    {
        $bundle = new SurvosFolioBundle();
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.project_dir', sys_get_temp_dir());
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.debug', false);
        $instanceof = [];
        $configurator = new ContainerConfigurator($builder, new PhpFileLoader($builder, new FileLocator()), $instanceof, __DIR__, __FILE__);
        $configuration = $bundle->getContainerExtension()?->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);
        $bundle->loadExtension((new Processor())->processConfiguration($configuration, [$config]), $configurator, $builder);

        return $builder;
    }
}
