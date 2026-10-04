<?php

declare(strict_types=1);
namespace Survos\FolioBundle\Tests\Service;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Entity\FolioProperty;
use Survos\FolioBundle\Service\FolioPropertyListener;
use Survos\Folio\PropertyStore;

final class FolioPropertyTest extends TestCase
{
    private function em(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        $em->getEventManager()->addEventListener(['postLoad', 'preFlush', 'postFlush'], new FolioPropertyListener());
        return $em;
    }

    public function testLegacyReadsWithoutWritesAndMigratesWithoutDataLoss(): void
    {
        $em = $this->em(); $pdo = $em->getConnection()->getNativeConnection();
        $pdo->exec("CREATE TABLE folio(code TEXT PRIMARY KEY, dataset_key TEXT, fts_content TEXT, label TEXT, content_type TEXT, row_count INTEGER);
            INSERT INTO folio VALUES('loc/1','loc/1','none','Original','newspaper',2900)");
        $before = $pdo->query('SELECT total_changes()')->fetchColumn();
        $folio = $em->find(Folio::class, 'loc/1');
        self::assertSame('Original', $folio->label); self::assertSame(2900, $folio->rowCount);
        self::assertSame($before, $pdo->query('SELECT total_changes()')->fetchColumn());
        $store = new PropertyStore($pdo); $store->migrate();
        $first = $store->read(); $store->migrate(); self::assertEquals($first, $store->read());
        $em->clear(); $folio = $em->find(Folio::class, 'loc/1');
        self::assertSame('Original', $folio->label); self::assertSame('newspaper', $folio->contentType);
        $folio->set('description', 'Correction', 'human', 'editor');
        $folio->set('description', 'Machine', 'meta', 'loc');
        $folio->set('custom.object', (object) ['empty' => (object) [], 'list' => [1, true, null]]);
        $folio->set('label', null, 'human', 'editor');
        $em->flush(); $em->clear();
        $folio = $em->find(Folio::class, 'loc/1');
        self::assertSame('Correction', $folio->description); self::assertNull($folio->label);
        self::assertEquals((object) ['empty' => (object) [], 'list' => [1, true, null]], $folio->get('custom.object'));
        self::assertSame('Original', $pdo->query('SELECT label FROM folio')->fetchColumn());
    }

    public function testNewThinFolioPersistsTypedAndUnknownProperties(): void
    {
        $em = $this->em();
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Folio::class), $em->getClassMetadata(FolioProperty::class)]);
        $folio = new Folio('loc/2'); $folio->label = 'New'; $folio->rowCount = 200; $folio->tags = ['news'];
        $folio->set('custom.flag', false); $em->persist($folio); $em->flush(); $em->clear();
        $loaded = $em->find(Folio::class, 'loc/2');
        self::assertSame('New', $loaded->label); self::assertSame(200, $loaded->rowCount);
        self::assertSame(['news'], $loaded->tags); self::assertFalse($loaded->get('custom.flag'));
        $loaded->replaceProperties('folio.meta', []); $em->flush(); $em->clear();
        self::assertNull($em->find(Folio::class, 'loc/2')->label);
    }
    public function testEmptyPropertyTableFallsBackAndExistingHumanValueWinsConversion(): void
    {
        $em = $this->em(); $pdo = $em->getConnection()->getNativeConnection();
        $pdo->exec("CREATE TABLE folio(code TEXT PRIMARY KEY, label TEXT, row_count INTEGER); INSERT INTO folio VALUES('loc/3','Legacy',12)");
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(FolioProperty::class)]);
        $store = new PropertyStore($pdo);
        self::assertSame('Legacy', $store->values()['label']);
        $store->put('label', \Survos\DataContracts\Metadata\PropertyValue::create(null, 'human', 'editor'));
        $store->migrate();
        self::assertNull($store->values()['label']);
        self::assertSame(12, $store->values()['rowCount']);
        self::assertSame('human', $store->read()['label']->source);
        $pdo->exec("DELETE FROM folio_property WHERE key='rowCount'");
        self::assertArrayNotHasKey('rowCount', $store->values(), 'converted files cannot resurrect a deleted property from stale columns');
    }

    public function testInvalidJsonDoesNotCommitConversionMarker(): void
    {
        $em = $this->em(); $pdo = $em->getConnection()->getNativeConnection();
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(FolioProperty::class)]);
        $pdo->exec("INSERT INTO folio_property VALUES('broken','{','meta','test','2026-10-04T00:00:00Z','{}')");
        try {
            (new PropertyStore($pdo))->migrate();
            self::fail('Invalid JSON should fail loudly');
        } catch (\JsonException) {
            self::assertFalse($pdo->inTransaction());
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM folio_property WHERE key='schemaVersion'")->fetchColumn());
        }
    }

}
