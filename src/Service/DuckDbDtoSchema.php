<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\NullableType;

/** Stable projection from declarations, never from observed field values. */
final class DuckDbDtoSchema
{
    /** @return array<string,string|array> JSON transform structure */
    public function structure(string $class): array
    {
        $doc = new PhpDocExtractor();
        $shape = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->isVirtual() || 'unmapped' === $property->name) {
                continue;
            }
            $type = $property->getType();
            if (!$type instanceof \ReflectionNamedType) {
                throw new \LogicException('Declare an unambiguous export type for '.$class.'::$'.$property->name);
            }
            $sql = match ($type->getName()) {
                'string' => 'VARCHAR', 'int' => 'BIGINT', 'float' => 'DOUBLE', 'bool' => 'BOOLEAN',
                // A PHP array without an element contract is deliberately not inferred.
                'array', 'mixed' => 'JSON',
                default => throw new \LogicException('Unsupported DTO type '.$type.' for '.$class.'::$'.$property->name),
            };
            if ('array' === $type->getName()) {
                $documented = $doc->getType($property->getDeclaringClass()->name, $property->name);
                if ($documented instanceof NullableType) {
                    $documented = $documented->getWrappedType();
                }
                if ($documented instanceof CollectionType && $documented->isList()) {
                    $element = match ((string) $documented->getCollectionValueType()) {
                        'string' => 'VARCHAR', 'int' => 'BIGINT', 'float' => 'DOUBLE', 'bool' => 'BOOLEAN',
                        default => null,
                    };
                    if (null !== $element) {
                        $sql = [$element];
                    }
                }
            }
            $shape[$property->name] = $sql;
        }

        return $shape;
    }
}
