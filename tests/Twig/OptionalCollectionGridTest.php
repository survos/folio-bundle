<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

final class OptionalCollectionGridTest extends TestCase
{
    public function testCollectionRendersInstallationWarningWithoutGrid(): void
    {
        // No Grid component extension or partial exists in this reader application.
        $html = $this->renderCollection([]);

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('composer require survos/grid-bundle', $html);
        self::assertStringContainsString('name="q"', $html);
    }

    public function testEnabledGridLoadsTheCollectionTable(): void
    {
        $html = $this->renderCollection(['SurvosGridBundle' => 'Survos\\Grid\\SurvosGridBundle']);

        self::assertStringContainsString('collection-grid-rendered', $html);
        self::assertStringNotContainsString('composer require survos/grid-bundle', $html);
    }

    private function renderCollection(array $bundles): string
    {
        $templates = [
            'base.html.twig' => '{% block body %}{% endblock %}',
            'collection.html.twig' => file_get_contents(__DIR__.'/../../templates/folio/collection.html.twig'),
        ];
        if ($bundles !== []) {
            $templates['@SurvosFolioBundle/folio/_collection_grid.html.twig'] = 'collection-grid-rendered';
        }
        $twig = new Environment(new ArrayLoader($templates), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('folio_base_template', fn () => 'base.html.twig'));
        if ($bundles !== []) {
            $twig->addExtension(new \Survos\Grid\Twig\TwigExtension());
        }
        $twig->addFunction(new TwigFunction('path', fn () => '/f'));
        $twig->addFunction(new TwigFunction('ux_pagination', fn (mixed ...$args) => '', ['is_variadic' => true]));

        return $twig->render('collection.html.twig', [
            'app' => ['request' => Request::create('/f'), 'bundles' => $bundles],
            'query' => '',
            'total' => 0,
            'pagination' => ['totalPages' => 1],
        ]);
    }
}
