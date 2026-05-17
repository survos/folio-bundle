<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Console\Input\DatasetArg;
use Survos\FolioBundle\Entity\{Core,Row};
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\Console\Attribute\{AsCommand,MapInput,Option};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('folio:browse', 'Browse folio cores or rows from the CLI.')]
final class FolioBrowseCommand
{
    public function __construct(private readonly FolioService $folios) {}

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] DatasetArg $input,
        #[Option('Core name')] ?string $core = null,
        #[Option('Rows to show')] int $limit = 25,
    ): int {
        $ctx = $this->folios->context($input->folioCode());

        $io->text(sprintf('Path: %s', $ctx->path));

        if (!$core) {
            $cores = $ctx->em->getRepository(Core::class)->findBy([], ['code' => 'ASC']);
            $io->table(
                ['core', 'label', 'rows'],
                array_map(fn (Core $c) => [$c->code, $c->label ?? '—', $c->rowCount], $cores),
            );
            return Command::SUCCESS;
        }

        $coreEntity = $ctx->em->find(Core::class, Core::id($input->folioCode(), $core))
            ?? throw new \RuntimeException(sprintf('Core "%s" not found in %s.', $core, $input->folioCode()));

        $rows = $ctx->em->getRepository(Row::class)
            ->findBy(['core' => $coreEntity], ['localId' => 'ASC'], max(1, $limit));

        $io->table(
            ['id', 'label', 'dto'],
            array_map(fn (Row $r) => [$r->localId, $r->label ?? '—', $r->dtoType ?? '—'], $rows),
        );

        return Command::SUCCESS;
    }
}
