<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/** Lossless separation of newspaper coordinates from text and stable anchors. */
final class NewspaperGeometry
{
    private const array KEYS = ['bbox', 'box', 'polygon', 'points', 'baseline', 'region', 'xywh',
        'x', 'y', 'w', 'h', 'width', 'height', 'left', 'top', 'right', 'bottom',
        'columnCenters', 'columnWidth', 'mastheadRegion'];

    /** @return array{0:array,1:list<array{path:array,value:mixed}>} */
    public static function split(array $value): array
    {
        $patches = [];
        $walk = static function (array $data, array $path = []) use (&$walk, &$patches): array {
            foreach ($data as $key => $child) {
                $next = [...$path, $key];
                if (is_string($key) && in_array($key, self::KEYS, true)) {
                    $patches[] = ['path' => $next, 'value' => $child];
                    unset($data[$key]);
                } elseif (is_array($child)) {
                    $data[$key] = $walk($child, $next);
                }
            }

            return $data;
        };

        return [$walk($value), $patches];
    }

    public static function restore(array $value, array $patches): array
    {
        foreach ($patches as $patch) {
            $target = &$value;
            $path = $patch['path'];
            $last = array_pop($path);
            foreach ($path as $key) {
                if (!array_key_exists($key, $target) || !is_array($target[$key])) {
                    throw new \UnexpectedValueException('Rend geometry does not match the folio structure.');
                }
                $target = &$target[$key];
            }
            if (array_key_exists($last, $target)) {
                throw new \UnexpectedValueException('Geometry is duplicated in the folio and rend.');
            }
            $target[$last] = $patch['value'];
            unset($target);
        }

        return $value;
    }

    /** Split SQL rows, preserving nulls and all non-geometry values. */
    public static function splitRow(string $table, array $row): array
    {
        $patches = [];
        if ('page' === $table) {
            foreach (['layout', 'width', 'height'] as $field) {
                if (array_key_exists($field, $row) && null !== $row[$field]) {
                    $patches[$field] = $row[$field];
                    $row[$field] = null;
                }
            }
        }
        foreach ('item' === $table ? ['dto_data', 'extras', 'raw'] : ['ledger', 'dialogue'] as $field) {
            if (!isset($row[$field])) {
                continue;
            }
            $data = json_decode($row[$field], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                continue;
            }
            [$plain, $removed] = self::split($data);
            if ([] !== $removed) {
                $patches[$field] = $removed;
                $row[$field] = json_encode($plain, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return [$row, $patches];
    }

    public static function restoreRow(string $table, array $row, array $patches): array
    {
        foreach ($patches as $field => $value) {
            if ('page' === $table && in_array($field, ['layout', 'width', 'height'], true)) {
                if (($row[$field] ?? null) !== null) {
                    throw new \UnexpectedValueException('Duplicate page geometry.');
                }
                $row[$field] = $value;
            } else {
                $row[$field] = json_encode(self::restore(json_decode($row[$field], true, flags: JSON_THROW_ON_ERROR), $value), JSON_THROW_ON_ERROR);
            }
        }

        return $row;
    }
}
