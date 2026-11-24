<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Model;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;

use function preg_match;
use function strlen;

/**
 * Exact identifier text (REQ-SEC-006, contract section 5.1): 1..max bytes of valid UTF-8, no control or format
 * characters (ZWSP, BOM, bidi controls), no leading or trailing whitespace (NBSP included). Never trimmed, folded or
 * normalised: values are compared byte for byte.
 *
 * @internal
 */
final class ExactText
{
    /**
     * Whether `$value` is acceptable exact text.
     */
    public static function isValid(string $value, int $maxBytes): bool
    {
        $length = strlen($value);
        if ($length === 0 || $length > $maxBytes || preg_match('//u', $value) !== 1) {
            return false;
        }
        if (preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $value) === 1) {
            return false;
        }

        return preg_match('/\A[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]|[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]\z/u', $value) !== 1;
    }

    /**
     * Asserts exact text.
     *
     * @param string $rule static text of the exception
     *
     * @throws InvalidValueException
     */
    public static function assert(string $value, int $maxBytes, string $rule): string
    {
        if (!self::isValid($value, $maxBytes)) {
            throw new InvalidValueException($rule);
        }

        return $value;
    }
}
