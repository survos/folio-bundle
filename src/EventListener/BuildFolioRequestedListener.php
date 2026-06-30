<?php

declare(strict_types=1);

namespace Survos\FolioBundle\EventListener;

use Survos\DatasetBundle\Event\BuildFolioRequestedEvent;
use Survos\FolioBundle\Command\FolioBuildCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Builds a dataset's folio inline when a stage command asks for it (e.g. `dataset:normalize --folio`)
 * by invoking {@see FolioBuildCommand} as a plain method with the command's own $io. So the full
 * folio:build path runs (ingest → inflate working folio → register artifact) AND its output — rows,
 * indexes, and the browse link on the folio server — appears, instead of a silent build.
 *
 * Decoupled via {@see BuildFolioRequestedEvent} because dataset-bundle cannot depend on folio-bundle
 * (folio→dataset is the dependency direction); an app without folio-bundle simply has no listener.
 */
#[AsEventListener]
final class BuildFolioRequestedListener
{
    public function __construct(
        private readonly FolioBuildCommand $folioBuild,
        /**
         * When true (publishing/prod hosts), inline workflow builds also write the compressed
         * .folio.gz archive and register the FOLIO_ARCHIVE artifact, so the dataset is immediately
         * pullable. Off locally — the .gz is slow and unused for browsing. Bound via
         * survos_folio.build_archive (typically an env var).
         */
        private readonly bool $buildArchive = false,
    ) {
    }

    public function __invoke(BuildFolioRequestedEvent $event): void
    {
        // Write under the dispatching command's own style; fall back to a silent style when there's
        // no console (dispatched outside a command).
        $io = $event->io ?? new SymfonyStyle(new ArrayInput([]), new NullOutput());

        ($this->folioBuild)($io, dataset: $event->datasetKey, core: $event->core, gz: $this->buildArchive, force: $event->force);
    }
}
