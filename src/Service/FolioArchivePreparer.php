<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

final readonly class FolioArchivePreparer
{
    public function __construct(
        private FolioSchemaSnapshotter $snapshotter,
        private FolioViewBuilder $viewBuilder,
        private FolioDocsBuilder $docsBuilder,
    ) {
    }

    /** @param array<string,mixed> $metadata */
    public function prepare(string $dbFile, array $metadata = []): array
    {
        $schema = $this->snapshotter->snapshot($dbFile);
        $views = $this->viewBuilder->rebuild($dbFile);
        $docs = $this->docsBuilder->rebuild($dbFile, $metadata + ['schema' => $schema, 'views' => $views]);

        return $schema + $views + $docs;
    }
}
