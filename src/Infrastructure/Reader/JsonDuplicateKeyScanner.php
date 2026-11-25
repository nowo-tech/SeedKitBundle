<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Reader;

use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;

use function array_pop;
use function count;
use function is_string;
use function json_decode;
use function strlen;
use function substr;

/**
 * Refuses JSON objects with a repeated key (PHP keeps the last one silently, contract section 13.3). Runs on text that
 * already decoded without error, so it only tracks strings, objects and arrays.
 */
final class JsonDuplicateKeyScanner
{
    /**
     * Asserts no object repeats a key.
     *
     * @throws DocumentReadException
     */
    public static function assertUnique(string $json): void
    {
        /** @var list<array{object: bool, keys: array<string, true>, expectKey: bool}> $stack */
        $stack  = [];
        $length = strlen($json);
        for ($pos = 0; $pos < $length; ++$pos) {
            $char = $json[$pos];
            if ($char === '{' || $char === '[') {
                $stack[] = ['object' => $char === '{', 'keys' => [], 'expectKey' => $char === '{'];
            } elseif ($char === '}' || $char === ']') {
                array_pop($stack);
            } elseif ($char === ',' || $char === ':') {
                $top = count($stack) - 1;
                if ($top >= 0 && $stack[$top]['object']) {
                    $stack[$top]['expectKey'] = $char === ',';
                }
            } elseif ($char === '"') {
                $begin = $pos;
                for (++$pos; $pos < $length && $json[$pos] !== '"'; ++$pos) {
                    if ($json[$pos] === '\\') {
                        ++$pos;
                    }
                }
                $top = count($stack) - 1;
                if ($top >= 0 && $stack[$top]['object'] && $stack[$top]['expectKey']) {
                    $key = json_decode(substr($json, $begin, $pos - $begin + 1));
                    $key = is_string($key) ? $key : '';
                    if (isset($stack[$top]['keys'][$key])) {
                        throw new DocumentReadException('duplicate_key');
                    }
                    $stack[$top]['keys'][$key] = true;
                }
            }
        }
    }
}
