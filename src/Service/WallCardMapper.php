<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Util\ImageUrl;
use Survos\DataContracts\Vocabulary\{ItemField, MediaPreset, MuseumVocab};
use Survos\FolioBundle\Api\{WallCard, WallCardField as Field};
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;

final class WallCardMapper
{
    public function __construct(private readonly ?ImgproxyUrlBuilder $imgproxy = null) {}

    /** Museum measurements are H × W × D; Dimensions owns parsing and integer millimetres. */
    public static function size(?string $dimensions, ?int $width, ?int $height): ?array
    {
        if ($dimensions !== null) {
            $segments = (new \Survos\DimensionsBundle\Parser\DimensionParser())->parseLabeledDimensions($dimensions, order: 'hwd');
            $preferred = array_unique(array_merge(['frame', 'framed', 'stretcher', 'sight', 'image', 'sheet'], array_keys($segments)));
            foreach ($preferred as $kind) {
                if (!isset($segments[$kind])) { continue; }
                $size = $segments[$kind];
                if ($size->widthMm > 0 && $size->heightMm > 0) {
                    return ['widthMm' => $size->widthMm, 'heightMm' => $size->heightMm, 'depthMm' => $size->depthMm, 'kind' => $kind, 'wPx' => $width, 'hPx' => $height];
                }
            }
        }
        return $width > 0 && $height > 0 ? ['widthMm' => null, 'heightMm' => null, 'depthMm' => null, 'kind' => 'image', 'wPx' => $width, 'hPx' => $height] : null;
    }

    /**
     * Audio guide stops, lean: no inline transcripts (they run to kilobytes). transcriptUrl is the
     * stop's own folio row (core=sound), whose full representation carries the transcript.
     *
     * @return list<array{url:string, durationSec:?int, title:?string, tour:?string, tours:?list<array<string,mixed>>, transcriptUrl:?string}>
     */
    public static function audio(mixed $audio, string $folioCode): array
    {
        $stops = [];
        foreach (is_array($audio) ? $audio : [] as $stop) {
            if (!is_array($stop) || !is_string($stop['url'] ?? null) || $stop['url'] === '') { continue; }
            $soundId = is_scalar($stop['soundId'] ?? null) ? (string) $stop['soundId'] : null;
            $stops[] = [
                'url' => $stop['url'],
                'durationSec' => is_numeric($stop['durationSec'] ?? null) ? (int) $stop['durationSec'] : null,
                'title' => is_string($stop['title'] ?? null) ? $stop['title'] : null,
                'tour' => is_string($stop['tour'] ?? null) ? $stop['tour'] : null,
                'tours' => is_array($stop['tours'] ?? null) ? array_values($stop['tours']) : null,
                'transcriptUrl' => $soundId === null || !isset($stop['transcript']) ? null
                    : '/api/'.$folioCode.'/rows/'.rawurlencode($soundId).'?core=sound',
            ];
        }
        return $stops;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        $value = is_string($value) ? [$value] : (is_array($value) ? $value : []);
        return array_values(array_unique(array_filter(array_map(
            static fn ($v) => is_scalar($v) ? trim((string) $v) : '', $value,
        ), static fn (string $v) => $v !== '')));
    }

    public static function renderable(?string $url): bool
    {
        return $url !== null && ImageUrl::classify($url)->isRenderable();
    }

    /** @param array<string,mixed> $row selected item and first page columns */
    public function map(array $row, string $folioCode): WallCard
    {
        // Both JSON columns are nullable by schema; individual label fields are optional.
        $dto = $row['dto_data'] === null ? [] : json_decode($row['dto_data'], true, flags: JSON_THROW_ON_ERROR);
        $extras = $row['extras'] === null ? [] : json_decode($row['extras'], true, flags: JSON_THROW_ON_ERROR);
        $fields = $dto + $extras;
        $text = static function (string $key) use ($fields): ?string {
            $value = $fields[$key] ?? null;
            return is_scalar($value) ? (string) $value : null;
        };
        $creators = $fields[ItemField::CREATOR] ?? [];
        $creator = $creators === [] ? null : implode('; ', $folioCode === 'smith/npg' ? array_slice($creators, 0, 1) : $creators);
        $subject = $folioCode === 'smith/npg' && count($creators) > 1 ? implode('; ', array_slice($creators, 1)) : null;
        $dimensions = $text(Field::DIMENSIONS_RAW);
        $title = $text(ItemField::TITLE) ?? $text(Field::SOURCE_CAPTION) ?? $row['label'];
        $year = $row['card_year'] === null ? null : (int) $row['card_year'];
        $place = implode(', ', array_filter([$text(ItemField::CITY), $text(ItemField::STATE), $text(ItemField::COUNTRY)], static fn ($v) => $v !== null && $v !== ''));
        $place = $place !== '' ? $place : ($text(Field::PLACE_OF_ORIGIN) ?? '');
        $license = $text(ItemField::LICENSE) ?? $text(Field::RIGHTS_URI) ?? $text(ItemField::RIGHTS);
        $image = null;
        if (self::renderable($row['page_url'])) {
            if ($this->imgproxy === null) { throw new \LogicException('WallCard imagery requires a configured signed imgproxy service.'); }
            $thumb = $this->imgproxy->resizePreset($row['page_url'], 'tiny');
            $medium = $this->imgproxy->resizePreset($row['page_url'], MediaPreset::THUMB);
            if (str_contains($thumb, '/insecure/') || str_contains($medium, '/insecure/')) { throw new \LogicException('WallCard imagery requires imgproxy signing key and salt.'); }
            $full = $row['page_url'];
            // thumbhash/color: paint these the instant the card arrives, then swap in thumb/medium.
            $image = ['thumb' => $thumb, 'medium' => $medium, 'full' => $full, 'thumbhash' => $row['page_thumb_hash'] ?? null, 'color' => $row['page_color'] ?? null];
        }
        return new WallCard(
            id: $row['local_id'], title: $title,
            label: [
                'creator' => $creator, ItemField::TITLE => $title, 'subject' => $subject,
                ItemField::DATE => $text(ItemField::DATE) ?? ($year === null ? null : (string) $year),
                'place' => $place === '' ? null : $place,
                'medium' => $text(MuseumVocab::MEDIUM), MuseumVocab::DIMENSIONS => $dimensions,
                MuseumVocab::CREDIT => $text(MuseumVocab::CREDIT) ?? $text(Field::CREDIT_LINE),
                MuseumVocab::ACCESSION => $text(MuseumVocab::ACCESSION), Field::TOMBSTONE => $text(Field::TOMBSTONE),
                Field::SOURCE_CAPTION => $text(Field::SOURCE_CAPTION), Field::DONOR => $text(Field::DONOR),
            ],
            year: $year,
            geo: $row['card_lat'] !== null && $row['card_lon'] !== null ? ['lat' => (float) $row['card_lat'], 'lon' => (float) $row['card_lon']] : null,
            size: self::size($dimensions, $row['page_width'] === null ? null : (int) $row['page_width'], $row['page_height'] === null ? null : (int) $row['page_height']),
            image: $image, audio: self::audio($fields[Field::AUDIO] ?? null, $folioCode),
            tags: self::strings($fields[Field::TAGS] ?? null), subjects: self::strings($fields[ItemField::SUBJECTS] ?? null),
            sourceUrl: $text(ItemField::CITATION_URL) ?? $text(Field::SOURCE_URL) ?? $text(ItemField::URL), license: $license,
        );
    }
}
