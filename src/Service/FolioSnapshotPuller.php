<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use League\Flysystem\FilesystemOperator;
use Survos\DataContracts\Path\DataPaths;
use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Filesystem\Filesystem;

/** Harvest selects the artifact; storage adapters supply bytes without publishing anything. */
final readonly class FolioSnapshotPuller
{
    public function __construct(
        private DataPaths $paths,
        #[Target('folio_dfolio.storage')]
        private ?FilesystemOperator $remote = null,
        #[Target('local_folio_dfolio.storage')]
        private ?FilesystemOperator $local = null,
    ) {
    }

    /** @return array{path:string,source:string,reused:bool} */
    public function pull(FolioCatalogEntry $entry, bool $force = false): array
    {
        $code = $entry->datasetKey;
        DuckDbFolioConnection::validate($code);
        $target = $this->paths->folioFile($code, 'dfolio');
        if (!$force && is_file($target)) {
            $this->pullCompanions($entry, $target, false);

            return ['path' => $target, 'source' => 'disk', 'reused' => true];
        }
        $uri = parse_url($entry->remoteUri ?? '');
        if (false === $uri || ($uri['scheme'] ?? null) !== 's3' || !isset($uri['host'], $uri['path'])
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($uri))
            || in_array('..', explode('/', $uri['path']), true) || !str_ends_with($uri['path'], '.dfolio')) {
            throw new \RuntimeException('Harvest has not published a valid S3 dfolio for '.$code);
        }
        // Both adapters expose a bucket root (or an equivalent local directory).
        $key = ltrim($uri['path'], '/');
        foreach (['local' => $this->local, 'remote' => $this->remote] as $source => $storage) {
            if (null === $storage || !$storage->fileExists($key)) {
                continue;
            }
            $fs = new Filesystem();
            $fs->mkdir(dirname($target));
            $temporary = dirname($target).'/.pull-'.bin2hex(random_bytes(8)).'.dfolio';
            $input = $output = null;
            try {
                $size = $storage->fileSize($key);
                $input = $storage->readStream($key);
                $output = fopen($temporary, 'xb');
                if (false === $output || stream_copy_to_stream($input, $output) !== $size) {
                    throw new \RuntimeException('Downloaded dfolio size does not match the selected storage object.');
                }
                fclose($input);
                fclose($output);
                $input = $output = null;
                if (null !== $entry->checksum && !hash_equals(strtolower($entry->checksum), hash_file('sha256', $temporary))) {
                    if ('local' === $source) {
                        continue;
                    }
                    throw new \RuntimeException('Downloaded dfolio checksum differs from the Harvest publication.');
                }
                $db = (new DuckDbFolioReader())->open($temporary);
                if (1 !== (int) $db->query('SELECT count(*) FROM _folio_build')->fetchColumn()) {
                    throw new \RuntimeException('Downloaded dfolio has no unique build identity.');
                }
                $db = null;
                $fs->rename($temporary, $target, overwrite: true);
            } finally {
                $db = null;
                foreach ([$input, $output] as $stream) {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                $fs->remove($temporary);
            }

            $this->pullCompanions($entry, $target, true);

            return ['path' => $target, 'source' => $source, 'reused' => false];
        }
        throw new \RuntimeException('No dfolio found in local or remote storage: '.$key);
    }

    /**
     * A newspaper dfolio is read with the files published beside it: the rend (block geometry, required) and the
     * word index (optional). Other dfolios have none, so storage is not asked.
     */
    private function pullCompanions(FolioCatalogEntry $entry, string $target, bool $replace): void
    {
        $db = (new DuckDbFolioReader())->open($target);
        $newspaper = (bool) $db->query("SELECT count(*) FROM information_schema.tables WHERE table_name = '_renditions'")->fetchColumn()
            && (bool) $db->query('SELECT count(*) FROM _renditions')->fetchColumn();
        $db = null;
        if (!$newspaper) {
            return;
        }
        $key = ltrim((string) parse_url((string) $entry->remoteUri, PHP_URL_PATH), '/');
        foreach (['drend' => true, 'dwords' => false] as $extension => $required) {
            $remoteKey = substr(DuckDbRenditionReader::rendUri($key), 0, -strlen('drend')).$extension;
            $local = substr(DuckDbRenditionReader::rendUri($target), 0, -strlen('drend')).$extension;
            if (!$replace && is_file($local)) {
                continue;
            }
            $found = false;
            foreach ([$this->local, $this->remote] as $storage) {
                if (null === $storage || !$storage->fileExists($remoteKey)) {
                    continue;
                }
                $temporary = dirname($local).'/.pull-'.bin2hex(random_bytes(8)).'.'.$extension;
                $input = $storage->readStream($remoteKey);
                $output = fopen($temporary, 'xb');
                try {
                    if (false === $output || stream_copy_to_stream($input, $output) !== $storage->fileSize($remoteKey)) {
                        throw new \RuntimeException('Downloaded '.$extension.' size does not match the selected storage object.');
                    }
                    fclose($output);
                    $output = null;
                    (new Filesystem())->rename($temporary, $local, overwrite: true);
                } finally {
                    foreach ([$input, $output] as $stream) {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                    (new Filesystem())->remove($temporary);
                }
                $found = true;
                break;
            }
            if (!$found && $required) {
                throw new \RuntimeException('No rend found beside the published dfolio: '.$remoteKey);
            }
        }
    }
}
