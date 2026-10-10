<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Publisher;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Transport for Harvest's publisher endpoints (/api/publishers/{publisher}/{environment}/…).
 *
 * Authenticates with the publisher's own token, not the dataset read token. Holds no state: the
 * generation, pending receipts and retry policy live in {@see PublisherState} and
 * {@see PublisherRegistrar}. Every HTTP failure surfaces as a Symfony HttpClient exception except
 * 409 on receipts, which is the {@see StaleGenerationException} the registrar acts on.
 */
final readonly class PublisherApiClient
{
    public const MAX_BATCH = 500;

    private const IDENTIFIER = '/^[a-z0-9][a-z0-9_-]{0,63}$/D';

    public function __construct(
        private HttpClientInterface $http,
        private string $server,
        private string $token,
        private string $publisher,
        private string $environment,
    ) {}

    /** Register, or start a new generation and drop this publisher/environment's receipts. */
    public function reset(PublisherSelection $selection, ?string $label = null, ?string $baseUrl = null): PublisherRegistration
    {
        $body = array_filter(['label' => $label, 'baseUrl' => $baseUrl], static fn (?string $value): bool => $value !== null);
        return PublisherRegistration::fromArray($this->request('POST', '/reset', $body + ['selection' => $selection->toArray()])->toArray());
    }

    /** Null when this publisher/environment never registered. */
    public function registration(): ?PublisherRegistration
    {
        $response = $this->request('GET', '');
        return $response->getStatusCode() === 404 ? null : PublisherRegistration::fromArray($response->toArray());
    }

    /** Selection is intent only: generation and receipts are untouched. */
    public function updateSelection(PublisherSelection $selection): PublisherRegistration
    {
        return PublisherRegistration::fromArray($this->request('PUT', '/selection', ['selection' => $selection->toArray()])->toArray());
    }

    /**
     * One bulk call (published and withdrawn receipts alike); the registrar does the batching.
     *
     * @param list<PublicationReceipt> $receipts
     *
     * @return list<ReceiptStatus> one per receipt, in order
     */
    public function receipts(int $generation, array $receipts): array
    {
        if (count($receipts) > self::MAX_BATCH) {
            throw new \LengthException(sprintf('At most %d receipts per call.', self::MAX_BATCH));
        }
        $response = $this->request('POST', '/receipts', [
            'generation' => $generation,
            'receipts' => array_map(static fn (PublicationReceipt $r): array => $r->toArray(), $receipts),
        ]);
        if ($response->getStatusCode() === 409) {
            throw new StaleGenerationException($generation);
        }
        $results = $response->toArray()['results'] ?? null;
        if (!is_array($results) || count($results) !== count($receipts)) {
            throw new \UnexpectedValueException('Receipt response does not answer every receipt.');
        }
        return array_map(static fn (array $result): ReceiptStatus => ReceiptStatus::tryFrom((string) ($result['status'] ?? ''))
            ?? throw new \UnexpectedValueException(sprintf('Unrecognised receipt status "%s".', $result['status'] ?? '')), array_values($results));
    }

    private function request(string $method, string $suffix, ?array $json = null): ResponseInterface
    {
        if ($this->token === '' || !preg_match('#^https?://[^/]+#', $this->server)) {
            throw new \LogicException('Configure the dataset API server and the publisher token.');
        }
        foreach ([$this->publisher, $this->environment] as $segment) {
            if (!preg_match(self::IDENTIFIER, $segment)) {
                throw new \LogicException(sprintf('Publisher and environment must match %s, got "%s".', self::IDENTIFIER, $segment));
            }
        }
        $options = [
            'auth_bearer' => $this->token, 'timeout' => 10, 'max_duration' => 30, 'max_redirects' => 0,
            'headers' => ['Accept' => 'application/json'],
        ];
        if ($json !== null) { $options['json'] = $json; }
        return $this->http->request($method, sprintf('%s/api/publishers/%s/%s%s', rtrim($this->server, '/'), $this->publisher, $this->environment, $suffix), $options);
    }
}
