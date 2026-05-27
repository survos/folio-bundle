<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Entity\{Core,Link,Row,TermSet};
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('folio:info', 'Show counts for a folio database.')]
final class FolioInfoCommand extends Command
{
    public function __construct(private readonly FolioService $folios) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('folioCode', InputArgument::REQUIRED); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ctx = $this->folios->context((string) $input->getArgument('folioCode'));
        $repo = fn (string $class) => $ctx->em->getRepository($class)->count([]);
        (new SymfonyStyle($input, $output))->table(['Metric', 'Count'], [
            ['cores', $repo(Core::class)], ['rows', $repo(Row::class)], ['term sets', $repo(TermSet::class)], ['links', $repo(Link::class)],
        ]);
        return Command::SUCCESS;
    }
}
