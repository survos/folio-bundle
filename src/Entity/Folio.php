<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\RouteIdentity;
use Survos\FieldBundle\Entity\RouteIdentityTrait;
use Survos\FieldBundle\Entity\RouteParametersInterface;
use Survos\FolioBundle\Repository\FolioRepository;

/**
 * Every folio route takes ONE path param, {folioCode} ("mus/fpus"), not the old
 * {provider}/{dataset} pair -- `provider` was pure noise: always re-derivable from the same
 * single `code` PK, but threaded as a separate variable through every route, controller
 * signature, and link-building call site anyway. #[RouteIdentity(field: 'code', key:
 * 'folioCode')] + RouteIdentityTrait give getRp()/getUniqueIdentifiers()/getClassnamePrefix()
 * for free -- no hand-written methods needed now that this is a single-field-to-single-param
 * mapping (RouteIdentityTrait can't handle the OLD two-param shape, which is why it wasn't used
 * before this route change).
 */
#[ORM\Entity(repositoryClass: FolioRepository::class)]
#[ORM\Table(name: 'folio')]
#[RouteIdentity(field: 'code', key: 'folioCode')]
class Folio implements RouteParametersInterface
{
    use RouteIdentityTrait;

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
}
