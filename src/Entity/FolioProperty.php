<?php

declare(strict_types=1);
namespace Survos\FolioBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\DataContracts\Metadata\PropertyValue;

#[ORM\Entity]
#[ORM\Table(name: 'folio_property')]
final class FolioProperty
{
    #[ORM\Id]
    #[ORM\Column(name: '`key`', length: 180)]
    public string $key;
    #[ORM\Column(type: 'text')]
    public string $value;
    #[ORM\Column(length: 16)]
    public string $source;
    #[ORM\Column(length: 180)]
    public string $owner;
    #[ORM\Column(length: 40)]
    public string $updatedAt;
    #[ORM\Column(type: 'text')]
    public string $provenance;

    public function __construct(string $key, PropertyValue $property)
    {
        $this->key = $key;
        $this->assign($property);
    }

    public function assign(PropertyValue $property): void
    {
        $this->value = json_encode($property->value, JSON_THROW_ON_ERROR);
        $this->source = $property->source;
        $this->owner = $property->owner;
        $this->updatedAt = $property->updatedAt;
        $this->provenance = json_encode($property->provenance, JSON_THROW_ON_ERROR);
    }
}
