<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Presta\SitemapBundle\Service\DumperInterface;
use Survos\FolioBundle\Sitemap\FolioSitemapPopulator;
use Survos\FolioBundle\Sitemap\FolioSitemapRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouterInterface;

/**
 * Writes static sitemap files, one section per folio, plus the sitemap index.
 *
 * Lives in src/Command/, which the kit base auto-registers -- which is also why
 * presta/sitemap-bundle is a hard `require` rather than a `suggest`: an auto-scanned class
 * cannot have an optional dependency, since autowiring reflects its constructor whether or not
 * the package is installed. presta adds only ext-simplexml on top of what this bundle already
 * requires, so that is a cheap trade for not hand-wiring the registration.
 *
 * These are STATIC files on purpose. presta also ships a dynamic /sitemap.xml route, and using it
 * would mean regenerating URLs from 664 SQLite databases on every bot request -- the same shape of
 * expensive-unauthenticated-page that took the origin down in survos-sites/zm#22. Dumped files are
 * served by Caddy as bytes, with no PHP in the request path at all.
 *
 * On format: the sitemaps protocol accepts XML, RSS/Atom or plain text, and JSON is not an
 * accepted format for any search engine -- so XML is a consumer constraint, not a style choice.
 * It is also the only one of the three that carries the image extension, which is the metadata
 * that actually earns its keep for an image archive. (<changefreq> and <priority> are omitted
 * deliberately: Google documents that it ignores both.)
 */
final class FolioSitemapCommand
{
    public function __construct(
        private readonly DumperInterface $dumper,
        private readonly FolioSitemapRegistry $registry,
        private readonly RouterInterface $router,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    #[AsCommand('folio:sitemap', 'Dump static sitemaps for folio item pages, one section per folio')]
    public function __invoke(
        SymfonyStyle $io,
        #[Option('Folio code to dump, e.g. mus/fortepan. Omit with --all for every published folio.')]
        ?string $folio = null,
        #[Option('Dump every published folio. Walks all folios, so it is never the implicit default.')]
        bool $all = false,
        #[Option('Absolute base URL for generated links, e.g. https://museado.org')]
        ?string $host = null,
        #[Option('Target directory for the XML files, relative to public/')]
        string $target = '',
        #[Option('Write .xml.gz alongside; bots accept gzip and these compress ~10:1')]
        bool $gzip = true,
    ): int {
        if ($folio === null && !$all) {
            $io->error('Pass --folio=<code> or --all.');

            return Command::INVALID;
        }

        $host ??= $this->defaultHost();
        if ($host === null) {
            $io->error('No host given and none could be derived; pass --host=https://example.org.');

            return Command::INVALID;
        }

        // Must happen before either branch: the index's entries come from $host, but each <loc>
        // inside a urlset comes from the ROUTER's context. Applying this only on the single-folio
        // path is how --all would quietly emit DEFAULT_URI item links under a --host index.
        $this->applyRouterContext($host);

        // --all is ONE dump() with a null section, not a loop. presta re-parses and rewrites the
        // whole index on every dump() call, so looping per folio would do that 664 times; a null
        // section lets the populator emit every folio's URLs from a single event and write the
        // index once at the end.
        if ($folio === null) {
            $filenames = $this->dumper->dump($this->targetDir($target), $this->hostForTarget($host, $target), null, ['gzip' => $gzip]);

            if ($filenames === false) {
                $io->warning('No published folios produced any URLs.');

                return Command::SUCCESS;
            }

            $io->success(sprintf('%d file(s) written to %s', \count($filenames), $this->targetDir($target)));

            return Command::SUCCESS;
        }

        $filenames = $this->dumpFolio($folio, $host, $target, $gzip);

        if ($filenames === []) {
            $io->warning(sprintf('%s produced no URLs (empty or missing folio?)', $folio));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d file(s) written to %s', \count($filenames), $this->targetDir($target)));

        return Command::SUCCESS;
    }

    /**
     * Regenerate one folio's sitemap section. Shared by the command and by folio:publish, so
     * "publishing regenerates the sitemap" and "dump this folio" cannot drift apart.
     *
     * @return list<string> filenames written; empty when the folio produced no URLs
     */
    public function dumpFolio(string $folioCode, ?string $host = null, string $target = '', bool $gzip = true): array
    {
        $host ??= $this->defaultHost()
            ?? throw new \RuntimeException('No sitemap host given and none could be derived from the router context.');

        // The <loc> inside each urlset comes from the ROUTER's request context, while the index's
        // entries come from $host. Left alone those two disagree -- a host of museado.org still
        // produced zm.wip item URLs, because the CLI router context is whatever DEFAULT_URI says.
        // Drive both from the one value so they cannot diverge. Idempotent, so it is safe that
        // __invoke() has usually already done this.
        $this->applyRouterContext($host);

        // One dump() call for this folio's section. presta reloads the existing index and re-adds
        // every OTHER section before writing, so this is genuinely incremental -- republishing one
        // folio does not invalidate the rest.
        $filenames = $this->dumper->dump(
            $this->targetDir($target),
            $this->hostForTarget($host, $target),
            FolioSitemapPopulator::sectionForFolio($folioCode),
            ['gzip' => $gzip],
        );

        return $filenames === false ? [] : array_values($filenames);
    }

    private function targetDir(string $target): string
    {
        return rtrim($this->projectDir . '/public/' . trim($target, '/'), '/');
    }

    /**
     * presta builds each index entry's <loc> as $host . '/' . $filename, with no knowledge of a
     * subdirectory, so a target dir has to be folded into the host or every index entry 404s.
     */
    private function hostForTarget(string $host, string $target): string
    {
        $target = trim($target, '/');

        return $target === '' ? $host : $host . '/' . $target;
    }

    /**
     * Sitemaps must carry absolute URLs, and a CLI run has no request to infer the host from.
     * The router's own context (framework.router.default_uri, i.e. DEFAULT_URI) is what
     * `bin/console` already uses for absolute URL generation, so reuse it rather than inventing a
     * second place to configure the public hostname.
     */
    private function defaultHost(): ?string
    {
        $context = $this->router->getContext();
        $host = $context->getHost();
        if ($host === '') {
            return null;
        }

        $scheme = $context->getScheme() ?: 'https';
        $port = match (true) {
            $scheme === 'http' && $context->getHttpPort() !== 80 => ':' . $context->getHttpPort(),
            $scheme === 'https' && $context->getHttpsPort() !== 443 => ':' . $context->getHttpsPort(),
            default => '',
        };

        return $scheme . '://' . $host . $port;
    }

    private function applyRouterContext(string $host): void
    {
        $parts = parse_url($host);
        $context = $this->router->getContext();

        if (!empty($parts['scheme'])) {
            $context->setScheme($parts['scheme']);
        }
        if (!empty($parts['host'])) {
            $context->setHost($parts['host']);
        }
        if (!empty($parts['path'])) {
            $context->setBaseUrl(rtrim($parts['path'], '/'));
        }
    }
}
