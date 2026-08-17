<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\DataContracts\Util\ImageUrl;
use Survos\DataContracts\Util\ImageUrlVerdict;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Re-validate already-built folios against the same image-URL rules the harvest gates apply,
 * so a bad URL that predates those gates shows up as a queryable backlog instead of as a broken
 * thumbnail a user stumbles over (survos-sites/musdig#40).
 *
 * Reads the folio SQLite directly rather than hydrating Row entities — the largest cores run to
 * ~500k rows and this needs to be cheap enough to run across every folio.
 */
#[AsCommand('folio:image:check', 'Re-validate folio image URLs; report rows whose image is not a renderable image.')]
final class FolioImageCheckCommand extends Command
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly ?HttpClientInterface $http = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('folioCode', InputArgument::OPTIONAL, 'Folio code (provider/dataset), a bare provider, or omit for every folio')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List each offending row id and URL')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max offenders to list per folio', '10')
            ->addOption('documents', null, InputOption::VALUE_NONE, 'Include PDF/document assets (real assets imgproxy cannot rasterise)')
            ->addOption('head', null, InputOption::VALUE_NONE, 'Also HEAD each listed URL to confirm status and content-type (slow)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $listOffenders = (bool) $input->getOption('list');
        $withDocuments = (bool) $input->getOption('documents');
        $withHead = (bool) $input->getOption('head');
        $limit = max(1, (int) $input->getOption('limit'));

        $folioCodes = $this->resolveFolioCodes($input->getArgument('folioCode'));
        if ($folioCodes === []) {
            $io->error('No folios matched.');

            return Command::FAILURE;
        }

        $io->title(sprintf('Image URL check — %d folio(s)', count($folioCodes)));

        $rows = [];
        $totals = ['rows' => 0, 'defects' => 0, 'documents' => 0, 'empty' => 0];

        foreach ($folioCodes as $folioCode) {
            try {
                $conn = $this->folios->context($folioCode)->em->getConnection();
            } catch (\Throwable $e) {
                $io->warning(sprintf('%s: could not open (%s)', $folioCode, $e->getMessage()));
                continue;
            }

            // getThumbnailSource() prefers iiifBase, then largeImageUrl, then thumbnailUrl — mirror
            // that precedence here so we judge the URL the gallery would actually request.
            $sql = <<<'SQL'
                SELECT id,
                       COALESCE(
                           NULLIF(json_extract(dto_data, '$.iiifBase'), ''),
                           NULLIF(json_extract(dto_data, '$.largeImageUrl'), ''),
                           NULLIF(json_extract(dto_data, '$.thumbnailUrl'), '')
                       ) AS src
                FROM item
                SQL;

            $counts = [];
            $offenders = [];
            $rowCount = 0;

            foreach ($conn->iterateAssociative($sql) as $record) {
                ++$rowCount;
                $src = $record['src'];
                $verdict = is_string($src) ? ImageUrl::classify($src) : ImageUrlVerdict::Empty;
                $counts[$verdict->value] = ($counts[$verdict->value] ?? 0) + 1;

                $isOffender = $verdict->isHarvestDefect() || ($withDocuments && $verdict === ImageUrlVerdict::Document);
                if ($isOffender && count($offenders) < $limit) {
                    $offenders[] = [$record['id'], $verdict, (string) $src];
                }
            }

            $defects = $counts[ImageUrlVerdict::NotAnImage->value] ?? 0;
            $documents = $counts[ImageUrlVerdict::Document->value] ?? 0;
            $empty = $counts[ImageUrlVerdict::Empty->value] ?? 0;

            $totals['rows'] += $rowCount;
            $totals['defects'] += $defects;
            $totals['documents'] += $documents;
            $totals['empty'] += $empty;

            if ($defects === 0 && ($documents === 0 || !$withDocuments)) {
                continue;
            }

            $rows[] = [$folioCode, $rowCount, $defects, $documents, $empty];

            if ($listOffenders) {
                $io->section($folioCode);
                foreach ($offenders as [$id, $verdict, $src]) {
                    $io->writeln(sprintf('  <comment>%s</comment>  %s', $verdict->value, $id));
                    $io->writeln(sprintf('    %s', $src));
                    if ($withHead) {
                        $io->writeln('    ' . $this->describeHead($src));
                    }
                }
            }
        }

        if ($rows === []) {
            $io->success(sprintf('No image-URL defects across %d row(s).', $totals['rows']));

            return Command::SUCCESS;
        }

        $io->table(['folio', 'rows', 'not-an-image', 'documents', 'no image'], $rows);
        $io->writeln(sprintf(
            '<info>%d</info> row(s) scanned · <error>%d</error> not-an-image · <comment>%d</comment> document(s) · %d with no image',
            $totals['rows'],
            $totals['defects'],
            $totals['documents'],
            $totals['empty'],
        ));

        if (!$withDocuments && $totals['documents'] > 0) {
            $io->note('Document assets (PDF etc.) counted but not listed — pass --documents to include them.');
        }

        // Non-zero exit only for genuine harvest defects; documents are expected source data.
        return $totals['defects'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<string> */
    private function resolveFolioCodes(?string $argument): array
    {
        $root = $this->folios->rootPath();
        $all = [];
        foreach (glob($root . '/*/*.folio') ?: [] as $path) {
            $provider = basename(dirname($path));
            $dataset = basename($path, '.folio');
            // Skip localized builds (<code>.en.folio) — same rows, translated strings.
            if (str_contains($dataset, '.')) {
                continue;
            }
            $all[] = $provider . '/' . $dataset;
        }
        sort($all);

        if ($argument === null || $argument === '') {
            return $all;
        }
        if (str_contains($argument, '/')) {
            return in_array($argument, $all, true) ? [$argument] : [];
        }

        return array_values(array_filter($all, static fn (string $c): bool => str_starts_with($c, $argument . '/')));
    }

    /** Live confirmation that the URL really is (or isn't) an image, for the --head listing. */
    private function describeHead(string $url): string
    {
        if ($this->http === null) {
            return '(no http client available)';
        }

        try {
            $response = $this->http->request('HEAD', $url, ['timeout' => 10, 'max_redirects' => 3]);
            $status = $response->getStatusCode();
            $type = $response->getHeaders(false)['content-type'][0] ?? '(none)';

            return sprintf('HTTP %d · %s', $status, $type);
        } catch (\Throwable $e) {
            return 'fetch failed: ' . $e->getMessage();
        }
    }
}
