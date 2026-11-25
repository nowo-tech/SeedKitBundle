<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Support;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;

use function abs;
use function array_is_list;
use function array_keys;
use function explode;
use function implode;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function mb_convert_encoding;
use function rtrim;
use function sprintf;
use function str_repeat;
use function str_replace;
use function strcmp;
use function strlen;
use function substr;
use function usort;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_LINE_TERMINATORS;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Canonical JSON (contract section 9, RFC 8785 rules): object keys sorted by UTF-16 code units, no whitespace,
 * minimal string escaping, integers as-is, floats as ECMAScript writes them (`1`, `0.5`, `0.000001`, `1e+25`; NaN /
 * INF refused), `null` kept. A PHP list is a JSON array, any other array a JSON object (an empty array is `[]`).
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
     * A finite float as ECMAScript `Number::toString` writes it (RFC 8785): the shortest digits that read back as the
     * same float (the nearest such decimal; computed with `sprintf`, so no `precision` / `serialize_precision` ini
     * changes the hash), in fixed notation for 1e-7 < |x| < 1e21 and in exponent notation otherwise (`0.000001`,
     * `100000000000000000000`, `1e+21`, `1e-7`).
     *
     * @throws InvalidValueException for NaN or an infinity
     */
    private static function float(float $value): string
    {
        if (!is_finite($value)) {
            throw new InvalidValueException('Non-finite numbers are not allowed.');
        }
        if ($value === 0.0) {
            return '0';
        }
        $abs     = abs($value);
        $integer = 0;
        $power   = 0;
        for ($digits = 1; $digits <= 17; ++$digits) {
            // The correctly rounded decimal of this many digits: integer x 10^power.
            [$mantissa, $exponent] = explode('e', sprintf('%.' . ($digits - 1) . 'e', $abs)) + [1 => '0'];
            $integer               = (int) str_replace('.', '', $mantissa);
            $power                 = (int) $exponent - $digits + 1;
            $read                  = (float) ($integer . 'e' . $power);
            if ($read === $abs) {
                break;
            }
            // Just above a power of two the gap below is half the gap above: the next decimal up may read back.
            if ($read < $abs && (float) (($integer + 1) . 'e' . $power) === $abs) {
                ++$integer;

                break;
            }
        }
        $all         = (string) $integer;
        $significant = rtrim($all, '0');
        $count       = strlen($significant);
        $point       = $power + strlen($all);
        $sign        = $value < 0 ? '-' : '';
        if ($count <= $point && $point <= 21) {
            return $sign . $significant . str_repeat('0', $point - $count);
        }
        if ($point > 0 && $point <= 21) {
            return $sign . substr($significant, 0, $point) . '.' . substr($significant, $point);
        }
        if ($point > -6 && $point <= 0) {
            return $sign . '0.' . str_repeat('0', -$point) . $significant;
        }
        $shift = $point - 1;

        return $sign . $significant[0] . ($count > 1 ? '.' . substr($significant, 1) : '') . 'e' . ($shift < 0 ? '-' : '+') . abs($shift);
    }

    /**
     * The UTF-16BE bytes of a key (sort order of RFC 8785).
     */
    private static function utf16(string $key): string
    {
        return mb_convert_encoding($key, 'UTF-16BE', 'UTF-8');
    }
}
