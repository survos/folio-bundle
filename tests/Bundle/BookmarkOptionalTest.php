<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Survos\BookmarkBundle\Service\BookmarkManager;
use Survos\FolioBundle\Service\FolioService;
use Survos\FolioBundle\SurvosFolioBundle;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class BookmarkOptionalTest extends TestCase
{
    public function testReaderDoesNotInstallOrRegisterBookmarkServices(): void
    {
        // A runtime requirement lets Flex enable BookmarkBundle in every reader,
        // which cannot boot without an app-specific owner entity configuration.
        $manifest = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('survos/bookmark-bundle', $manifest['require']);
        self::assertArrayHasKey('survos/bookmark-bundle', $manifest['suggest']);

        $builder = $this->load();
        self::assertTrue($builder->hasDefinition(FolioService::class));
        self::assertFalse($builder->hasDefinition(BookmarkManager::class));
        self::assertFalse($builder->hasDefinition(\Survos\FolioBundle\Bookmark\Service\BookmarkManager::class));
    }

    public function testConfiguredBookmarksRegisterTheirManagers(): void
    {
        $builder = $this->load([
            'bookmark_class' => 'App\\Entity\\Bookmark',
            'folder_class' => 'App\\Entity\\Folder',
        ]);
        self::assertTrue($builder->hasDefinition(BookmarkManager::class));
        self::assertSame('App\\Entity\\Bookmark', $builder->getDefinition(BookmarkManager::class)->getArgument('$bookmarkClass'));
        self::assertTrue($builder->hasDefinition(\Survos\FolioBundle\Bookmark\Service\BookmarkManager::class));
    }

    private function load(array $config = []): ContainerBuilder
    {
        $bundle = new SurvosFolioBundle();
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.project_dir', sys_get_temp_dir());
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.debug', false);
        $instanceof = [];
        $container = new ContainerConfigurator($builder, new PhpFileLoader($builder, new FileLocator()), $instanceof, __DIR__, __FILE__);
        $configuration = $bundle->getContainerExtension()->getConfiguration([], $builder);
        $bundle->loadExtension((new Processor())->processConfiguration($configuration, [$config]), $container, $builder);

        return $builder;
    }
}
