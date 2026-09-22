<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Set\FolioSetResolver;
use Symfony\Component\Console\Attribute\{Argument, AsCommand, Option};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Resolve every folio set (survos_folio.folio_sets) from its criteria and record the membership.
 * Idempotent, and meant to run as a composer auto-script so every install and deploy reconciles.
 *
 * Membership is derived state, written to var/folio-sets/<code>.json and rebuilt every run. If a
 * set cannot be resolved (the registry is unavailable), the last recorded membership is kept and
 * the command warns rather than failing the install. See docs/folio-sets.md.
 */
#[AsCommand('folio:sets:sync', 'Resolve folio sets from their criteria and record their membership')]
final class FolioSetsSyncCommand
{
    public function __construct(private readonly FolioSetResolver $resolver, private readonly Filesystem $fs) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Only this set')] ?string $code = null,
        #[Option('Resolve and report, but do not record')] bool $dryRun = false,
    ): int {
        $sets = $this->resolver->sets();
        if ($sets === []) {
            $io->note('No folio sets are configured (survos_folio.folio_sets).');

            return Command::SUCCESS;
        }
        if ($code !== null && !$this->resolver->has($code)) {
            $io->error(sprintf('No folio set "%s". Defined: %s.', $code, implode(', ', array_keys($sets))));

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($code !== null ? [$code => $sets[$code]] : $sets as $setCode => $set) {
            $previous = $this->resolver->recorded($setCode);
            $before = array_column($previous['members'] ?? [], 'datasetKey');
            try {
                $members = $this->resolver->resolve($setCode);
            } catch (\Throwable $e) {
                $io->warning(sprintf('%s: could not resolve (%s). %s', $setCode, $e->getMessage(),
                    $previous !== null ? 'Keeping the last recorded membership.' : 'Nothing recorded yet.'));
                $rows[] = [$setCode, $set['label'] ?? '', count($before), '—', '—'];
                continue;
            }
            $after = array_column($members, 'datasetKey');
            if (!$dryRun) {
                $this->fs->dumpFile($this->resolver->membershipFile($setCode), json_encode([
                    'code' => $setCode,
                    'label' => $set['label'],
                    'criteria' => $set['criteria'],
                    'resolvedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'members' => $members,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
            $rows[] = [$setCode, $set['label'] ?? '', count($members),
                implode(', ', array_diff($after, $before)) ?: '—', implode(', ', array_diff($before, $after)) ?: '—'];
        }

        $io->table(['set', 'label', 'members', 'joined', 'left'], $rows);
        if ($dryRun) {
            $io->note('Dry run: membership not recorded.');
        }

        return Command::SUCCESS;
    }
}
