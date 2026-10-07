<?php

declare(strict_types=1);

namespace Survos\FolioBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Survos\DataContracts\Vocabulary\{ItemField, MuseumVocab};
use Survos\FolioBundle\Api\WallCardCollection;
use Survos\FolioBundle\Entity\{Core, Folio};
use Survos\FolioBundle\Service\{FolioService, WallCardMapper, WallCardQuery};
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class WallCardProvider implements ProviderInterface
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly RequestStack $requests,
        private readonly WallCardMapper $mapper,
        private readonly WallCardQuery $query,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): WallCardCollection
    {
        $request = $this->requests->getCurrentRequest() ?? throw new \LogicException('WallCard needs an HTTP request.');
        // The priority-16 folio listener resolves public slugs/locales and switches SQLite before
        // API Platform's read provider. Use its resolved attribute, not the original URI variables.
        $code = $request->attributes->get('folioCode');
        if (!is_string($code)) { throw new \LogicException('Missing resolved folioCode.'); }
        $request->attributes->set('_wallcard_response', true);
        $ctx = $this->folios->context($code);
        $coreCode = $request->query->get('core', 'obj');
        $core = $ctx->em->find(Core::class, Core::id($code, $coreCode));
        $folio = $ctx->em->find(Folio::class, $code);
        if ($core === null || $folio === null) { throw new NotFoundHttpException('Unknown folio or core.'); }
        $result = $this->query->fetch($ctx->em->getConnection(), $core->id, $request->query->all());
        $cards = array_map(fn (array $row) => $this->mapper->map($row, $code), $result['rows']);
        $request->attributes->set('_wallcard_response', true);
        return new WallCardCollection(
            cards: $cards,
            folio: ['code' => $code, 'title' => $folio->label, ItemField::LICENSE => $folio->get(ItemField::LICENSE), MuseumVocab::CREDIT => $folio->get(MuseumVocab::CREDIT)],
            total: $result['total'], page: $result['page'], itemsPerPage: $result['limit'],
            path: $request->getBaseUrl().$request->getPathInfo(), query: $request->query->all(),
            contextUrl: $this->urls->generate('api_jsonld_context', ['shortName' => 'WallCard']),
            itemPath: $request->getBaseUrl().$request->getPathInfo(),
        );
    }
}
