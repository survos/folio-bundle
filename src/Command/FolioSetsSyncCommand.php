<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Event\FolioSetSyncedEvent;
use Survos\FolioBundle\Set\FolioSetResolver;
use Survos\FolioBundle\Set\FolioSiteRegistry;
use Symfony\Component\Console\Attribute\{Argument, AsCommand, Option};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Resolve every folio set (survos_folio.folio_sets), settle its files, record its membership and
 * hand it to the app. Idempotent; runs as a composer auto-script so every install reconciles.
 *
 * Files and the app database obey different rules (docs/folio-sets.md, "Sync"):
 *   - files: plain sync only reports; --pull fetches missing folios; --force re-pulls every member.
 *     local_passthrough overrides both — the data dir belongs to the app that builds the folios
 *     (production reads zm's /platform), so nothing here writes a folio or that app's registry.
 *   - app database: FolioSetSyncedEvent fires for every set, passthrough or not, so the app
 *     upserts what is on disk and hides what is gone.
 *
 * If a set cannot be resolved (the hub is down and nothing is cached), its last recorded
 * membership is kept and the command warns rather than failing the install or the deploy.
 */
#[AsCommand('folio:sets:sync', 'Resolve folio sets, settle their files, and hand membership to the app', aliases: ['folio:sites:sync'])]
final class FolioSetsSyncCommand
{
    public function __construct(
        private readonly FolioSetResolver $resolver,
        private readonly FolioSiteRegistry $sites,
        private readonly FolioPullCommand $pull,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly Filesystem $fs,
        #[Autowire('%survos_folio.local_passthrough%')]
        private readonly bool $localPassthrough = false,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Only this set')] ?string $code = null,
        #[Option('Pull members that are not on disk')] bool $pull = false,
        #[Option('Re-pull and re-inflate every member, even those on disk (implies --pull)')] bool $force = false,
        #[Option('Resolve and report; record nothing, pull nothing, notify nothing')] bool $dryRun = false,
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
        $pull = $pull || $force;
        $io->text(sprintf('Resolving against the %s.', $this->resolver->source() === 'catalog' ? 'hub catalog' : 'local dataset registry'));

        $rows = [];
        $settled = []; // datasetKey → true once pulled (or refused) this run; a folio in two sets is handled once
        foreach ($code !== null ? [$code => $sets[$code]] : $sets as $setCode => $set) {
            $previous = $this->resolver->recorded($setCode);
            $before = array_column($previous['members'] ?? [], 'datasetKey');
            try {
                $members = $this->resolver->resolve($setCode);
            } catch (\Throwable $e) {
                $io->warning(sprintf('%s: could not resolve (%s). %s', $setCode, $e->getMessage(),
                    $previous !== null ? 'Keeping the last recorded membership.' : 'Nothing recorded yet.'));
                $rows[] = [$setCode, count($before), '—', '—', '—'];
                continue;
            }

            if ($pull && !$dryRun) {
                foreach ($members as $i => $member) {
                    $key = $member['datasetKey'];
                    if (isset($settled[$key]) || ($member['available'] && !$force)) {
                        continue;
                    }
                    $settled[$key] = true;
                    $members[$i] = $this->settle($io, $member, $force);
                }
            }

            $after = array_column($members, 'datasetKey');
            $missing = array_column(array_filter($members, static fn (array $m): bool => !$m['available']), 'datasetKey');
            if (!$dryRun) {
                $this->fs->dumpFile($this->resolver->membershipFile($setCode), json_encode([
                    'code' => $setCode,
                    'label' => $set['label'],
                    'criteria' => $set['criteria'],
                    'resolvedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'members' => $members,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $this->dispatcher->dispatch(new FolioSetSyncedEvent($setCode, $set, $members));
            }
            $rows[] = [
                $setCode.(count($members) === 1 ? ' (single)' : ''),
                sprintf('%d (%d on disk)', count($members), count($members) - count($missing)),
                implode(', ', array_diff($after, $before)) ?: '—',
                implode(', ', array_diff($before, $after)) ?: '—',
                implode(', ', $missing) ?: '—',
            ];
        }

        $io->table(['set', 'members', 'joined', 'left', 'not on disk'], $rows);
        if ($this->sites->all() !== []) {
            $io->table(['site', 'hosts', 'sets', 'folios'], array_map(
                fn (string $site): array => [
                    $site.($this->sites->isSingle($site) ? ' (single)' : ''),
                    implode(', ', $this->sites->get($site)['hosts']),
                    implode(', ', $this->sites->get($site)['sets']),
                    count($this->sites->members($site)),
                ],
                array_keys($this->sites->all()),
            ));
        }
        if ($dryRun) {
            $io->note('Dry run: nothing recorded, pulled or dispatched.');
        } elseif (!$pull && array_filter($rows, static fn (array $r): bool => $r[4] !== '—') !== []) {
            $io->note('Members not on disk are listed but not fetched. Run with --pull to fetch them.');
        }

        return Command::SUCCESS;
    }

    /** Pull one member if this app may write its data dir and the hub publishes it; say why not otherwise. */
    private function settle(SymfonyStyle $io, array $member, bool $force): array
    {
        $key = $member['datasetKey'];
        if ($this->localPassthrough) {
            $io->warning(sprintf('%s: not %s — local_passthrough is on, so this app does not write the folio data dir (the builder owns it).',
                $key, $member['available'] ? 're-pulled' : 'pulled'));

            return $member;
        }
        if (!$member['inCatalog'] || ($member['downloadUrl'] ?? null) === null) {
            $io->warning(sprintf('%s: not published by the hub catalog, so it cannot be pulled.', $key));

            return $member;
        }

        try {
            $exit = $this->pull->__invoke($io, dataset: $key, force: $force);
            if ($exit !== Command::SUCCESS) {
                $io->warning(sprintf('%s: folio:pull exited with %d.', $key, $exit));
            }
        } catch (\Throwable $e) {
            // Often only a translated variant (mus/fortepan.en) is missing upstream while the
            // folio itself arrived, so what counts is the disk, not the exception.
            $io->warning(sprintf('%s: pull failed (%s).', $key, $e->getMessage()));
        }
        $member['local'] = $this->resolver->localPath($key);
        $member['available'] = $member['local'] !== null;

        return $member;
    }
}
