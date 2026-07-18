<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Entity\RouteParametersInterface;
use Survos\FolioBundle\Repository\FolioRepository;

/**
 * Implements RouteParametersInterface by hand rather than via field-bundle's own
 * RouteIdentityTrait/#[RouteIdentity] shortcut -- that trait's getRp() only ever emits ONE
 * {key: value} pair from ONE declared field, but every folio route
 * (survos_folio_show/search/map/...) takes TWO path params, {provider} and {dataset}, both
 * derived by splitting the single `code` PK ("mus/fpus" -> provider "mus", dataset "fpus").
 * Existing callers building ['provider' => ..., 'dataset' => ...] by hand (openfoto's
 * HomeController, TenantController, TenantMenu, ...) can call $folio->getRp() instead.
 */
#[ORM\Entity(repositoryClass: FolioRepository::class)]
#[ORM\Table(name: 'folio')]
class Folio implements RouteParametersInterface
{
    #[ORM\Id]
    #[ORM\Column(length: 120, options: ['comment' => 'Unique folio identifier, matches dataset key'])]
    public string $code;

    #[ORM\Column(length: 255, nullable: true, options: ['comment' => 'Human-readable display name'])]
    public ?string $label = null;

    #[ORM\Column(length: 180, nullable: true, options: ['comment' => 'Dataset key from data-bundle (e.g. mus/cleveland)'])]
    public ?string $datasetKey = null;

    #[ORM\Column(options: ['default' => 0, 'comment' => 'Total rows across all cores'])]
    public int $rowCount = 0;

    /** @var Collection<int, LinkType> */
    #[ORM\OneToMany(targetEntity: LinkType::class, mappedBy: 'folio', fetch: 'EXTRA_LAZY')]
    public Collection $linkTypes;

    public function __construct(string $code)
    {
        $this->code = $code;
        $this->linkTypes = new ArrayCollection();
    }

    /** @return array{provider: string, dataset: string} */
    public function getUniqueIdentifiers(): array
    {
        return $this->getRp();
    }

    /** @return array<string, mixed> */
    public function getRp(?array $addlParams = []): array
    {
        [$provider, $dataset] = array_pad(explode('/', $this->code, 2), 2, $this->code);

        return array_merge(['provider' => $provider, 'dataset' => $dataset], $addlParams ?? []);
    }

    public static function getClassnamePrefix(?string $class = null): string
    {
        return 'folio';
    }
}
