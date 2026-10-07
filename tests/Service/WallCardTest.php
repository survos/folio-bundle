<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Vocabulary\{ItemField, MuseumVocab};
use Survos\FolioBundle\Api\{WallCardCollection, WallCardField as Field};
use Survos\FolioBundle\Serializer\WallCardCollectionNormalizer;
use Survos\FolioBundle\Service\{WallCardMapper, WallCardQuery};
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class WallCardTest extends TestCase
{
    public function testDimensionsPreferFrameAndPreserveAspectRatio(): void
    {
        $size = WallCardMapper::size('Image: 80 × 60 cm; Frame: 113 x 92.4 x 7.6 cm', null, null);
        self::assertSame(1130, $size['heightMm']);
        self::assertSame(924, $size['widthMm']);
        self::assertSame('frame', $size['kind']);
        self::assertSame(1543, WallCardMapper::size('Frame: 154,3 × 122,6 × 11,4cm', null, null)['heightMm']);
        self::assertSame(3000, WallCardMapper::size('unparseable', 3000, 4454)['wPx']);
        self::assertNull(WallCardMapper::size('20 x 30 unknown', null, null));
        self::assertNull(WallCardMapper::size(null, 3000, 0));
    }

    public function testSourceLabelsAreLeanAndOptional(): void
    {
        $row = ['local_id' => 'a', 'label' => 'A', 'dto_data' => json_encode([
            ItemField::TITLE => 'Portrait', ItemField::CREATOR => ['Artist', 'Sitter'],
            MuseumVocab::CREDIT => 'Gallery', ItemField::RIGHTS => 'CC0',
        ]), 'extras' => json_encode([MuseumVocab::MEDIUM => 'Oil', MuseumVocab::ACCESSION => 'NPG.1', Field::DIMENSIONS_RAW => 'Frame: 40 x 30 cm', Field::TOMBSTONE => 'Full label', 'raw' => str_repeat('large', 10000)]),
            'card_year' => null, 'card_lat' => null, 'card_lon' => null, 'page_url' => null, 'page_width' => null, 'page_height' => null];
        $card = (new WallCardMapper())->map($row, 'smith/npg');
        self::assertSame('Artist', $card->label['creator']);
        self::assertSame('Sitter', $card->label['subject']);
        self::assertSame('NPG.1', $card->label[MuseumVocab::ACCESSION]);
        self::assertSame('CC0', $card->license);
        self::assertSame([], $card->audio);
        self::assertStringNotContainsString('raw', json_encode($card));
        self::assertLessThan(1000, strlen(json_encode($card)));
    }

    public function testJpegPresetsAreSignedAndAbsentPresetsUseSourceFallback(): void
    {
        $row = ['local_id' => 'a', 'label' => 'A', 'dto_data' => null, 'extras' => null,
            'card_year' => null, 'card_lat' => null, 'card_lon' => null,
            'page_url' => 'https://example.com/image.jpg', 'page_width' => 800, 'page_height' => 600];
        $builder = new \Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder('https://images.test', str_repeat('01', 32), str_repeat('02', 32));
        $card = (new WallCardMapper($builder))->map($row, 'test/one');
        self::assertStringContainsString('/pr:thumb_jpg/', $card->image['thumb']);
        self::assertStringContainsString('/pr:medium_jpg/', $card->image['medium']);
        self::assertStringNotContainsString('/insecure/', $card->image['thumb']);
        $builder = new \Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder('https://images.test', presets: []);
        $card = (new WallCardMapper($builder))->map($row, 'test/one');
        self::assertNull($card->image['thumb']);
        self::assertNull($card->image['medium']);
        self::assertSame($row['page_url'], $card->image['full']);
    }

    public function testFiltersComposeBeforePaginationAndIdsKeepOrder(): void
    {
        $conn = $this->fixture();
        $query = new WallCardQuery();
        $filters = ['type' => 'photograph', 'hasImage' => '1', 'hasSize' => '1', 'year' => ['gte' => '1900', 'lte' => '1910'], ItemField::CITY => 'Dublin', 'tag' => 'portrait', 'q' => 'portrait', 'bbox' => '-10,50,0,60', 'ids' => 'c,a,b', 'itemsPerPage' => '1'];
        $first = $query->fetch($conn, 'test/one:obj', $filters);
        self::assertSame(2, $first['total']);
        self::assertSame('c', $first['rows'][0]['local_id']);
        $next = $query->fetch($conn, 'test/one:obj', $filters + ['page' => '2']);
        self::assertSame('a', $next['rows'][0]['local_id']);
        self::assertSame(0, $query->fetch($conn, 'test/one:obj', ['q' => 'unmatched'])['total']);
        self::assertSame(0, $query->fetch($conn, 'test/one:obj', ['ids' => "a' OR 1=1 --"])['total']);
        $shuffle = ['order' => 'random', 'seed' => '27'];
        self::assertSame($query->fetch($conn, 'test/one:obj', $shuffle), $query->fetch($conn, 'test/one:obj', $shuffle));
        // Missing source year must remain NULL, not become year zero.
        self::assertNull($query->fetch($conn, 'test/one:obj', ['ids' => 'b'])['rows'][0]['card_year']);
    }

    public function testInvalidPaginationIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        (new WallCardQuery())->fetch($this->fixture(), 'test/one:obj', ['itemsPerPage' => '201']);
    }

    public function testHydraLinksPreserveHallAndCore(): void
    {
        $collection = new WallCardCollection([], ['code' => 'test/one'], 3, 1, 2, '/api/slug.en/rows', ['core' => 'obj', 'year' => ['gte' => '1900'], 'ids' => 'c,a,b', 'itemsPerPage' => '2'], '/api/contexts/WallCard', '/api/slug.en/rows');
        $json = (new WallCardCollectionNormalizer())->normalize($collection, 'jsonld');
        self::assertSame('hydra:Collection', $json['@type']);
        parse_str(parse_url($json['hydra:view']['hydra:next'], PHP_URL_QUERY), $query);
        self::assertSame(['gte' => '1900'], $query['year']);
        self::assertSame('c,a,b', $query['ids']);
        self::assertSame('2', $query['page']);
        self::assertSame(3, $json['hydra:totalItems']);
    }

    private function fixture(): \Doctrine\DBAL\Connection
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement('CREATE TABLE item (id TEXT PRIMARY KEY, core_id TEXT, local_id TEXT, label TEXT, dto_type TEXT, dto_data TEXT, extras TEXT)');
        $conn->executeStatement('CREATE TABLE page (id TEXT PRIMARY KEY, row_id TEXT, seq INTEGER, url TEXT, width INTEGER, height INTEGER)');
        $conn->executeStatement('CREATE TABLE item_facet (item_rowid INTEGER, field TEXT, value TEXT)');
        $conn->executeStatement('CREATE VIRTUAL TABLE item_fts USING fts5(body)');
        foreach (['a', 'b', 'c', 'other-core'] as $index => $id) {
            $conn->insert('item', ['id' => $id, 'core_id' => $id === 'other-core' ? 'test/one:per' : 'test/one:obj', 'local_id' => $id, 'label' => $id, 'dto_type' => 'photograph', 'dto_data' => json_encode([ItemField::YEAR => $id === 'b' ? null : 1900 + $index, Field::LATITUDE => 53.3, Field::LONGITUDE => -6.2]), 'extras' => null]);
            $conn->insert('page', ['id' => $id.'#1', 'row_id' => $id, 'seq' => 1, 'url' => 'https://example.com/photo.jpg', 'width' => $id === 'b' ? null : 800, 'height' => $id === 'b' ? null : 600]);
            // A second image must not duplicate the card/count or replace first-page geometry.
            $conn->insert('page', ['id' => $id.'#2', 'row_id' => $id, 'seq' => 2, 'url' => 'https://example.com/second.jpg', 'width' => 1000, 'height' => 2000]);
            foreach ([ItemField::CITY => 'Dublin', Field::TAGS => 'portrait'] as $field => $value) { $conn->insert('item_facet', ['item_rowid' => $index + 1, 'field' => $field, 'value' => $value]); }
            $conn->insert('item_fts', ['rowid' => $index + 1, 'body' => 'A portrait of someone']);
        }
        return $conn;
    }
}
