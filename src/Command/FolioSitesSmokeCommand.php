<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\FolioBundle\Service\FolioService;
use Survos\FolioBundle\Set\FolioSiteRegistry;
use Symfony\Component\Console\Attribute\{Argument, AsCommand, Option};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request every site's home, every member folio's home, and one row per folio, through this app's
 * own kernel — no web server, so it runs the same on a laptop, in CI and on production right after
 * deploy. Fails on any response that is not 2xx after following same-site redirects.
 *
 * In-process is deliberate: 2026-09-28's voxstory 500 (a folio this app could not write) and its
 * cleveland "Item not found" both reproduced exactly this way while every status check was green.
 */
#[AsCommand('folio:sites:smoke', 'Request each site, its folios and one row per folio; fail on anything but 2xx')]
final class FolioSitesSmokeCommand
{
    private const int MAX_REDIRECTS = 4;

    public function __construct(
        private readonly FolioSiteRegistry $sites,
        private readonly FolioService $folios,
        private readonly HttpKernelInterface $kernel,
        private readonly UrlGeneratorInterface $urls,
        // Each request gets a clean slate, as under PHP-FPM: without it one failed request's
        // folio state leaks into the next and smoke reports 404s no visitor would see.
        #[Autowire(service: 'services_resetter')]
        private readonly ResetInterface $resetter,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Only this site')] ?string $site = null,
        #[Option('Use the first host containing this (e.g. ".wip"); default: each site\'s first host')] ?string $host = null,
        #[Option('Locale for folio routes')] string $locale = 'en',
        #[Option('Log failures at critical level, for alerting')] bool $notify = false,
    ): int {
        $sites = $site !== null ? [$site => $this->sites->get($site)] : $this->sites->all();
        if ($sites === []) {
            $io->note('No folio sites are configured (survos_folio.sites).');

            return Command::SUCCESS;
        }

        $rows = [];
        $failures = [];
        foreach ($sites as $code => $config) {
            $siteHost = $this->pickHost($config['hosts'], $host);
            foreach ($this->paths($code, $locale) as [$what, $path]) {
                [$status, $final] = $this->request($siteHost, $path);
                $ok = $status >= 200 && $status < 300;
                $rows[] = [$code, $what, $path.($final !== $path ? ' → '.$final : ''), $ok ? $status : sprintf('<error>%d</error>', $status)];
                if (!$ok) {
                    $failures[] = sprintf('%d https://%s%s (%s, %s)', $status, $siteHost, $path, $code, $what);
                }
            }
        }

        $io->table(['site', 'page', 'path', 'status'], $rows);
        if ($failures === []) {
            $io->success(sprintf('%d page(s), all 2xx.', count($rows)));

            return Command::SUCCESS;
        }
        foreach ($failures as $failure) {
            $notify ? $this->logger?->critical('folio:sites:smoke failure: '.$failure) : null;
        }
        $io->error(sprintf("%d of %d page(s) failed:\n%s", count($failures), count($rows), implode("\n", $failures)));

        return Command::FAILURE;
    }

    /** @return list<array{string, string}> [label, path] */
    private function paths(string $site, string $locale): array
    {
        $paths = [['home', '/']];
        foreach ($this->sites->members($site) as $key => $member) {
            if (!($member['available'] ?? false)) {
                // Listed but not on disk: a site that links it will fail, and that is the finding.
                $paths[] = [$key.' (not on disk)', $this->urls->generate('survos_folio_show', ['folioCode' => $key, '_locale' => $locale])];
                continue;
            }
            $paths[] = [$key, $this->urls->generate('survos_folio_show', ['folioCode' => $key, '_locale' => $locale])];
            if (($localId = $this->firstRow($key)) !== null) {
                $paths[] = [$key.' row', $this->urls->generate('survos_folio_row_shortcut', ['folioCode' => $key, 'localId' => $localId, '_locale' => $locale])];
            }
        }

        return $paths;
    }

    private function firstRow(string $datasetKey): ?string
    {
        try {
            $value = $this->folios->context($datasetKey)->em->getConnection()
                ->fetchOne('SELECT local_id FROM item ORDER BY rowid LIMIT 1');
        } catch (\Throwable) {
            return null; // the folio home request reports why the folio cannot be opened
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array{int, string} status, and the path it ended on after same-host redirects */
    private function request(string $host, string $path): array
    {
        for ($hop = 0; ; ++$hop) {
            try {
                $response = $this->kernel->handle(Request::create('https://'.$host.$path), HttpKernelInterface::MAIN_REQUEST, true);
            } catch (\Throwable) {
                return [500, $path];
            } finally {
                $this->resetter->reset();
            }
            $status = $response->getStatusCode();
            $location = $response->headers->get('Location');
            if (!$response->isRedirection() || $location === null || $hop >= self::MAX_REDIRECTS) {
                return [$status, $path];
            }
            $target = parse_url($location);
            if (isset($target['host']) && strcasecmp($target['host'], $host) !== 0) {
                return [$status, $path]; // leaving the site: the redirect itself is the answer
            }
            $path = ($target['path'] ?? '/').(isset($target['query']) ? '?'.$target['query'] : '');
        }
    }

    /** @param list<string> $hosts */
    private function pickHost(array $hosts, ?string $contains): string
    {
        if ($contains !== null) {
            foreach ($hosts as $h) {
                if (str_contains($h, $contains)) {
                    return $h;
                }
            }
        }

        return $hosts[0];
    }
}
