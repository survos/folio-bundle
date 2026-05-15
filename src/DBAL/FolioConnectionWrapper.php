<?php

declare(strict_types=1);

namespace Survos\FolioBundle\DBAL;

use Doctrine\DBAL\{Configuration,Connection,Driver};

final class FolioConnectionWrapper extends Connection
{
    public string $currentPath;

    public function __construct(array $params, Driver $driver, ?Configuration $config = null)
    {
        parent::__construct($params, $driver, $config);
        $this->currentPath = (string) ($params['path'] ?? $params['dbname'] ?? '');
    }

    public function selectDatabase(string $path): void
    {
        if ($this->currentPath === $path) {
            return;
        }
        if ($this->isConnected()) {
            $this->close();
        }
        $params = $this->getParams();
        unset($params['url'], $params['dbname']);
        $params['driver'] ??= 'pdo_sqlite';
        $params['path'] = $this->currentPath = $path;
        parent::__construct($params, $this->getDriver(), $this->_config);
    }
}
