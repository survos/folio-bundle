<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Dto;

/** Explicit S3 connection settings; credentials are never persisted in a DuckDB file. */
final readonly class DuckDbS3Configuration
{
    public string $host;
    public bool $useSsl;

    public function __construct(
        public string $endpoint,
        #[\SensitiveParameter] private string $accessKey,
        #[\SensitiveParameter] private string $secretKey,
        public string $region,
        #[\SensitiveParameter] private ?string $sessionToken = null,
        public string $urlStyle = 'path',
    ) {
        $url = parse_url($endpoint);
        if (false === $url || !isset($url['scheme'], $url['host'])
            || !in_array($url['scheme'], ['http', 'https'], true)
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || (isset($url['path']) && !in_array($url['path'], ['', '/'], true))) {
            throw new \InvalidArgumentException('Use an HTTP(S) S3 endpoint without credentials, query or path.');
        }
        if ('' === $accessKey || '' === $secretKey || '' === $region
            || !in_array($urlStyle, ['path', 'vhost'], true) || '' === $sessionToken) {
            throw new \InvalidArgumentException('Supply S3 credentials, region and a valid URL style.');
        }
        $this->host = $url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
        $this->useSsl = 'https' === $url['scheme'];
    }

    public function configure(\PDO $db, string $scope): void
    {
        $parameters = [$this->accessKey, $this->secretKey, $this->region, $this->host, $this->urlStyle, $scope];
        $sql = 'CREATE SECRET folio_s3 IN connection (TYPE s3, KEY_ID ?, SECRET ?, REGION ?, ENDPOINT ?, URL_STYLE ?, SCOPE ?, USE_SSL '.($this->useSsl ? 'true' : 'false');
        if (null !== $this->sessionToken) {
            $sql .= ', SESSION_TOKEN ?';
            $parameters[] = $this->sessionToken;
        }
        $db->prepare($sql.')')->execute($parameters);
    }

    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint, 'region' => $this->region, 'urlStyle' => $this->urlStyle];
    }
}
