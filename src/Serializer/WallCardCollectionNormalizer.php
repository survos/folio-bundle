<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Serializer;

use Survos\FolioBundle\Api\WallCardCollection;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class WallCardCollectionNormalizer implements NormalizerInterface
{
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof WallCardCollection;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [WallCardCollection::class => true];
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $url = static fn (array $query): string => $data->path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $pageUrl = static fn (int $page): string => $url(array_replace($data->query, ['page' => $page]));
        $last = max(1, (int) ceil($data->total / $data->itemsPerPage));
        $view = ['@id' => $pageUrl($data->page), '@type' => 'hydra:PartialCollectionView', 'hydra:first' => $pageUrl(1), 'hydra:last' => $pageUrl($last)];
        if ($data->page > 1) { $view['hydra:previous'] = $pageUrl($data->page - 1); }
        if ($data->page < $last) { $view['hydra:next'] = $pageUrl($data->page + 1); }
        $members = [];
        foreach ($data->cards as $card) {
            $iri = $data->itemPath.'/'.rawurlencode($card->id);
            if (isset($data->query['core']) && $data->query['core'] !== 'obj') {
                $iri .= '?'.http_build_query(['core' => $data->query['core']]);
            }
            $members[] = ['@id' => $iri, '@type' => 'WallCard'] + get_object_vars($card);
        }
        return [
            '@context' => $data->contextUrl,
            '@id' => $url($data->query),
            '@type' => 'hydra:Collection',
            'hydra:totalItems' => $data->total,
            'folio' => $data->folio,
            'hydra:member' => $members,
            'hydra:view' => $view,
        ];
    }
}
