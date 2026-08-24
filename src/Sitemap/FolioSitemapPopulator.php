<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Sitemap;

use Presta\SitemapBundle\Event\SitemapPopulateEvent;
use Presta\SitemapBundle\Sitemap\Url\UrlConcrete;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Feeds folio item pages into presta's sitemap dumper, one section per folio.
 *
 * Why a section per folio: a folio is the unit that gets rebuilt, so it is also the unit that
 * needs regenerating. `dump()` with a section rewrites exactly that section while reloading the
 * index and preserving every other entry, so republishing one folio does not re-walk 664 SQLite
 * databases.
 *
 * The URLs are streamed, never collected: iterateAssociative() yields row by row from SQLite and
 * presta's DumpingUrlset fwrite()s each <url> straight to a temp file, so memory is flat
 * regardless of folio size. Do not "collect then add" here -- that is the one change that would
 * turn this back into a memory problem.
 */
#[AsEventListener(event: SitemapPopulateEvent::class)]
final class FolioSitemapPopulator
{
    /**
     * Sitemap sections are filenames (sitemap.<section>.xml), and folio codes contain a slash
     * ("curatescape/baltimore"). Encode rather than strip, so the mapping stays reversible.
     */
    public const SECTION_PREFIX = 'folio-';

    /**
     * Stand-in for the row id while the router builds one URL per (core, dtoType) group. Chosen
     * to survive routing untouched -- no slash, nothing the generator would encode.
     */
    private const ID_PLACEHOLDER = '__FOLIO_ROW_ID__';

    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioSitemapRegistry $registry,
    ) {
    }

    public function __invoke(SitemapPopulateEvent $event): void
    {
        $section = $event->getSection();

        // A null section means "dump everything" -- and it is genuinely one pass: presta populates
        // every section from this single event and writes the index once at the end. Looping
        // dump() per folio instead would re-parse and rewrite the whole index 664 times.
        $codes = $section === null
            ? $this->registry->publishedFolioCodes()
            : array_filter([self::folioCodeFromSection($section)]);

        foreach ($codes as $folioCode) {
            $this->populateFolio($event, $folioCode);
        }
    }

    public static function sectionForFolio(string $folioCode): string
    {
        return self::SECTION_PREFIX . str_replace('/', '--', $folioCode);
    }

    public static function folioCodeFromSection(string $section): ?string
    {
        if (!str_starts_with($section, self::SECTION_PREFIX)) {
            return null;
        }

        return str_replace('--', '/', substr($section, \strlen(self::SECTION_PREFIX)));
    }

    private function populateFolio(SitemapPopulateEvent $event, string $folioCode): void
    {
        $urls = $event->getUrlContainer();
        $router = $event->getUrlGenerator();
        $section = self::sectionForFolio($folioCode);

        // Folio build time, applied to every row in the folio. Deliberately coarse for now: a
        // per-row updated_at would be a better <lastmod>, especially once AI enrichment starts
        // rewriting individual rows, but it is a folio SCHEMA change and folios are mid-rebuild.
        // Deferred rather than mixed in; see docs/sitemaps.md.
        $folioLastmod = $this->registry->lastModified($folioCode);

        $em = $this->folios->switch($folioCode);
        $connection = $em->getConnection();

        // Three small columns only. Note what is NOT selected: dto_data is a CLOB, and pulling it
        // per row to mine an image URL would mean 1.35M reads and json_decode()s -- a different
        // order of cost from this query.
        $rows = $connection->executeQuery(
            'SELECT local_id, dto_type, core_id
               FROM item
              WHERE local_id IS NOT NULL AND dto_type IS NOT NULL
              ORDER BY core_id, dto_type',
        )->iterateAssociative();

        // The router costs ~6.5us per call -- 8.8s across 1.35M rows, four times what everything
        // else in the dump costs put together. But the URL shape is identical for every row in a
        // (core, dtoType) group, so the router only has to run once per GROUP: generate one URL
        // with a placeholder id, then substitute. Measured at 0.47us/url, 14x faster, and routing
        // stays the single source of truth for the URL shape rather than being duplicated as a
        // sprintf() somewhere. The ORDER BY is what makes the groups contiguous.
        $groupKey = null;
        $template = '';

        foreach ($rows as $row) {
            $coreId = (string) $row['core_id'];
            $coreCode = str_contains($coreId, ':') ? substr($coreId, strrpos($coreId, ':') + 1) : $coreId;
            if ($coreCode === '') {
                continue;
            }

            $dtoType = (string) $row['dto_type'];
            $key = $coreCode . "\0" . $dtoType;

            if ($key !== $groupKey) {
                $groupKey = $key;
                $template = $router->generate('survos_folio_row_show', [
                    'folioCode' => $folioCode,
                    'coreCode' => $coreCode,
                    'dtoType' => $dtoType,
                    'localId' => self::ID_PLACEHOLDER,
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }

            $urls->addUrl(
                new UrlConcrete(
                    str_replace(self::ID_PLACEHOLDER, rawurlencode((string) $row['local_id']), $template),
                    $folioLastmod,
                ),
                $section,
            );
        }
    }

}
