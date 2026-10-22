<?php

namespace Wexample\SymfonyDataSync\Helper;

use DateTimeInterface;
use Wexample\SymfonyDataSync\Class\FieldMapping;

/**
 * How mapped values are compared and fingerprinted, the same way on both
 * sides and in every run.
 */
final class SyncValueHelper
{
    public static function same(mixed $a, mixed $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    /**
     * @param FieldMapping[] $mappings
     * @param array<string, mixed> $fields
     */
    public static function hash(array $mappings, array $fields, bool $remote): string
    {
        $values = [];
        foreach ($mappings as $mapping) {
            $values[$mapping->localField] = self::canonical($fields[$remote ? $mapping->remoteField : $mapping->localField] ?? null);
        }

        ksort($values);

        return sha1(json_encode($values, JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return json_encode(is_scalar($value) ? (string) $value : $value, JSON_THROW_ON_ERROR);
    }
}
