<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Service\RightsClassifier;

final class RightsClassifierTest extends TestCase
{
    #[DataProvider('statements')]
    public function testClassify(array $statements, ?string $expected): void
    {
        self::assertSame($expected, RightsClassifier::classify(...$statements));
    }

    /** Real values from the production folio_item Rights facet (2026-10-14). */
    public static function statements(): iterable
    {
        yield 'CC0' => [['CC0'], 'CC0'];
        yield 'CC 0, Museum Data Service' => [['CC 0'], 'CC0'];
        yield 'CC BY' => [['CC BY'], 'CC BY'];
        yield 'museum prefix' => [['© Auckland Museum CC BY 4.0'], 'CC BY'];
        yield 'underscore' => [['Copyright, Southeast Iowa Union, CC BY-NC_SA'], 'CC BY-NC-SA'];
        yield 'NC ND' => [['CC BY-NC-ND'], 'CC BY-NC-ND'];
        yield 'licence URL' => [['http://creativecommons.org/licenses/by-nc-sa/4.0/'], 'CC BY-NC-SA'];
        yield 'zero URL' => [['http://creativecommons.org/publicdomain/zero/1.0/'], 'CC0'];
        yield 'mark URL' => [['http://creativecommons.org/publicdomain/mark/1.0/'], 'Public domain'];
        yield 'URI beats prose' => [['No known copyright restrictions.', 'http://rightsstatements.org/vocab/NoC-US/1.0/'], 'Public domain'];
        yield 'NoC-OKLR' => [['http://rightsstatements.org/vocab/NoC-OKLR/1.0/'], 'No known restrictions'];
        yield 'InC' => [['http://rightsstatements.org/vocab/InC-EDU/1.0/'], 'In copyright'];
        yield 'CNE' => [['http://rightsstatements.org/vocab/CNE/1.0/'], 'Not evaluated'];
        yield 'no known restrictions' => [['No known restrictions on publication.'], 'No known restrictions'];
        yield 'not evaluated' => [['Rights status not evaluated.'], 'Not evaluated'];
        yield 'copyrighted' => [['Copyrighted'], 'In copyright'];
        yield 'all rights reserved' => [['All rights reserved'], 'In copyright'];
        yield 'Te Papa RR-F' => [['RR-F'], 'In copyright'];
        yield 'public domain' => [['Public Domain'], 'Public domain'];
        yield 'cultural' => [['Cultural permissions apply'], 'Other'];
        yield 'nothing' => [[null, '', []], null];
    }
}
