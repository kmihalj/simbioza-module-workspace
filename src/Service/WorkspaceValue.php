<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use function is_array;
use function is_numeric;
use function is_scalar;

final class WorkspaceValue
{
    /**
     * EN: Normalizes an arbitrary value to text without PHP warnings.
     *
     * HR: Normalizira proizvoljnu vrijednost na tekst bez PHP upozorenja.
     */
    public static function string(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * EN: Normalizes a numeric value to an integer.
     *
     * HR: Normalizira numeričku vrijednost na cijeli broj.
     */
    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * EN: Retains only string keys from an arbitrary array.
     *
     * HR: Zadržava samo string ključeve jednog proizvoljnog polja.
     *
     * @return array<string, mixed>
     */
    public static function stringKeyArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * EN: Normalizes an ORM result into a list of string-key rows.
     *
     * HR: Normalizira ORM rezultat u listu string-key redaka.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            $normalized = self::stringKeyArray($row);
            if ($normalized !== []) {
                $rows[] = $normalized;
            }
        }

        return $rows;
    }

    /**
     * EN: Retains string keys and positive integer values from a map.
     *
     * HR: Zadržava string ključeve i pozitivne cjelobrojne vrijednosti mape.
     *
     * @return array<string, int>
     */
    public static function intMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $number = self::int($item);
            if ($number > 0) {
                $result[$key] = $number;
            }
        }

        return $result;
    }

    /**
     * EN: Retains only string keys and scalar text values from a map.
     *
     * HR: Zadržava samo string ključeve i skalarne tekstualne vrijednosti mape.
     *
     * @return array<string, string>
     */
    public static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && is_scalar($item)) {
                $result[$key] = (string)$item;
            }
        }

        return $result;
    }
}
