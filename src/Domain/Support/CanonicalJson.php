<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Support;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;

use function array_is_list;
use function array_keys;
use function implode;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function mb_convert_encoding;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strcmp;
use function substr;
use function usort;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_LINE_TERMINATORS;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Canonical JSON (contract section 9, RFC 8785 rules): object keys sorted by UTF-16 code units, no whitespace,
 * minimal string escaping, integers as-is, floats in the shortest round-trip form (`1`, `0.5`, `1e+25`; NaN / INF
 * refused), `null` kept. A PHP list is a JSON array, any other array a JSON object (an empty array is `[]`).
 * Used for natural keys, fingerprints, the plan hash and the batch digests; `docs/PLAN-HASH.md` holds the vectors.
 */
final class CanonicalJson
{
    /** Flags of the string encoder. */
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    /**
     * Encodes a JSON-compatible value canonically.
     *
     * @throws InvalidValueException for a non-finite float or a value that is not JSON-compatible
     */
    public static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return self::float($value);
        }
        if (is_string($value)) {
            return json_encode($value, self::FLAGS);
        }
        if (!is_array($value)) {
            throw new InvalidValueException('A value is not JSON-compatible.');
        }
        if (array_is_list($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = self::encode($item);
            }

            return '[' . implode(',', $parts) . ']';
        }
        $keys = [];
        foreach (array_keys($value) as $key) {
            $keys[] = (string) $key;
        }
        usort($keys, static fn (string $a, string $b): int => strcmp(self::utf16($a), self::utf16($b)));
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = json_encode($key, self::FLAGS) . ':' . self::encode($value[$key]);
        }

        return '{' . implode(',', $parts) . '}';
    }

    /**
     * The shortest round-trip form of a finite float, ECMAScript style.
     *
     * @throws InvalidValueException for NaN or an infinity
     */
    private static function float(float $value): string
    {
        if (!is_finite($value)) {
            throw new InvalidValueException('Non-finite numbers are not allowed.');
        }
        $text = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $text = str_replace(['E', '.0e'], ['e', 'e'], $text);
        if (!str_contains($text, 'e') && str_ends_with($text, '.0')) {
            $text = substr($text, 0, -2);
        }

        return $text === '-0' ? '0' : $text;
    }

    /**
     * The UTF-16BE bytes of a key (sort order of RFC 8785).
     */
    private static function utf16(string $key): string
    {
        return mb_convert_encoding($key, 'UTF-16BE', 'UTF-8');
    }
}
