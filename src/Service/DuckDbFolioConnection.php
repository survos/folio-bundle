<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\SearchBundle\Adapter\DuckDb\DuckDbConnectionProviderInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class DuckDbFolioConnection implements DuckDbConnectionProviderInterface
{
    public function __construct(private DuckDbFolioLocatorInterface $locator, private DuckDbFolioReader $reader,
        private bool $enabled = false)
    {
    }

    public function name(): string
    {
        return 'folio';
    }

    public function connect(array $options = []): \PDO
    {
        if (!$this->enabled) {
            throw new NotFoundHttpException('DuckDB folio browsing is disabled.');
        }
        $key = $options['folioCode'];
        self::validate($key);

        return $this->reader->open($this->locator->locate($key));
    }

    public static function validate(string $key): void
    {
        if (!preg_match('{^[a-zA-Z0-9_-][a-zA-Z0-9_.-]*/[a-zA-Z0-9_-][a-zA-Z0-9_.-]*$}', $key)) {
            throw new NotFoundHttpException('Invalid folio identity.');
        }
    }
}
