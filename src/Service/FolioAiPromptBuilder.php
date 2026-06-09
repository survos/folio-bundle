<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\IiifBundle\Service\IiifUrl;

final class FolioAiPromptBuilder
{
    public const IMAGE_ENRICH = 'image_enrich';
    public const DENSE_SUMMARY = 'dense_summary';

    /** @param array<string,mixed> $row */
    public function requestLine(array $row, string $task, string $model, string $source, string $imageDetail = 'low'): array
    {
        $dtoData = $this->decode($row['dto_data'] ?? null);
        $extras = $this->decode($row['extras'] ?? null);
        $imageUrl = $this->imageUrl($dtoData);

        return [
            'custom_id' => (string) $row['id'],
            'method' => 'POST',
            'url' => '/v1/chat/completions',
            'body' => [
                'model' => $model,
                'temperature' => 0.2,
                'max_tokens' => $task === self::IMAGE_ENRICH ? 1000 : 500,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt($task)],
                    ['role' => 'user', 'content' => $this->userContent($row, $dtoData, $extras, $task, $imageUrl, $imageDetail)],
                ],
            ],
            'metadata' => [
                'source' => $source,
                'task' => $task,
                'dtoType' => $row['dto_type'] ?? null,
                'localId' => $row['local_id'] ?? null,
            ],
        ];
    }

    /** @param array<string,mixed>|null $dtoData */
    public function imageUrl(?array $dtoData): ?string
    {
        if ($dtoData === null) {
            return null;
        }

        foreach (['iiifBase', 'largeImageUrl', 'thumbnailUrl', 'imageUrl'] as $field) {
            $value = $dtoData[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return $field === 'iiifBase' ? IiifUrl::imageUrl($value) : $value;
            }
        }

        return null;
    }

    private function systemPrompt(string $task): string
    {
        return match ($task) {
            self::IMAGE_ENRICH => 'You enrich museum photograph records. Return only valid JSON. Prefer concise, factual claims grounded in the image and supplied metadata. Use null or empty arrays when evidence is insufficient.',
            self::DENSE_SUMMARY => 'You summarize museum collection records. Return only valid JSON. Use the supplied metadata only and do not infer visual details.',
            default => throw new \InvalidArgumentException(sprintf('Unsupported folio AI task "%s".', $task)),
        };
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $dtoData
     * @param array<string,mixed>|null $extras
     * @return string|array<int,array<string,mixed>>
     */
    private function userContent(array $row, ?array $dtoData, ?array $extras, string $task, ?string $imageUrl, string $imageDetail): string|array
    {
        $payload = [
            'id' => $row['id'] ?? null,
            'localId' => $row['local_id'] ?? null,
            'label' => $row['label'] ?? null,
            'dtoType' => $row['dto_type'] ?? null,
            'dtoData' => $dtoData,
            'extras' => $extras,
        ];

        $schema = $task === self::IMAGE_ENRICH
            ? 'Return JSON with keys: title, description, keywords, denseSummary, people, places, organizations, dateText, confidence, claims. Claims is optional and may contain objects with predicate, value, confidence, basis.'
            : 'Return JSON with keys: denseSummary, keywords, subjects, confidence, claims. Claims is optional and may contain objects with predicate, value, confidence, basis.';
        $text = $schema . "\nRecord:\n" . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($task !== self::IMAGE_ENRICH) {
            return $text;
        }
        if ($imageUrl === null) {
            throw new \RuntimeException(sprintf('Row "%s" has no image URL for image enrichment.', (string) ($row['id'] ?? '')));
        }

        return [
            ['type' => 'text', 'text' => $text],
            ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => $imageDetail]],
        ];
    }

    /** @return array<string,mixed>|null */
    private function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
