<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Reader;

use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;

use function array_pop;
use function count;
use function in_array;
use function preg_match;
use function preg_split;
use function str_starts_with;
use function strlen;
use function strspn;
use function trim;

use const PHP_INT_MAX;

/**
 * Refuses the YAML features a seed document never needs before the parser sees the text (contract section 13.3, F4):
 * tags (`!`, `!!`: objects, constants, binary), anchors (`&`) and aliases (`*`: billion laughs), merge keys (`<<`) and
 * directives (`%`), and explicit keys (`? k` / `: v`, unsupported by Symfony Yaml). A token is checked where a node
 * starts (line start after indentation, after `- `, `: `, `[`, `{`, `,`, after any `:` inside a flow collection, after
 * a `:` that follows a closing quote whatever comes next, as in `{"k":v}` or `'k':v`, and after a run of `:`); a `:` or
 * `?` glued to the next character starts a plain scalar (`:|p`, `:"p` are keys, not indicators). Quoted scalars, block
 * scalars, comments and the inside of plain scalars are skipped, so `a&b` or `"*"` are fine. A plain scalar continued on
 * a new line that starts with one of these characters is refused too (fail closed).
 *
 * When a depth is given, the nesting is bounded before the parser recurses (`too_deep`): flow brackets, the block
 * indentation levels and the compact `- ` indicators of a line are counted together (an upper estimate).
 */
final class YamlPreScanner
{
    /**
     * Asserts the text is free of the refused features.
     *
     * @param int|null $maxDepth refuse a nesting deeper than this (`too_deep`); null = no bound
     *
     * @throws DocumentReadException
     */
    public static function assertSafe(string $text, ?int $maxDepth = null): void
    {
        $lines   = preg_split("/\r\n|\n|\r/", $text);
        $lines   = $lines === false ? [] : $lines;
        $count   = count($lines);
        $quote   = null;
        $flow    = 0;
        $indents = [];
        $limit   = $maxDepth ?? PHP_INT_MAX;
        for ($i = 0; $i < $count; ++$i) {
            $line   = $lines[$i];
            $length = strlen($line);
            $pos    = 0;
            $start  = $quote === null;
            if ($quote === null) {
                $pos = strspn($line, " \t");
                if ($pos === $length) {
                    continue;
                }
                if ($pos === 0 && $flow === 0 && $line[0] === '%') {
                    throw new DocumentReadException('yaml_directive', $i + 1);
                }
                if ($pos === 0 && $flow === 0 && (str_starts_with($line, '---') || str_starts_with($line, '...')) && ($length === 3 || $line[3] === ' ' || $line[3] === "\t")) {
                    $pos     = 3;
                    $indents = [];
                } elseif ($flow === 0 && $line[$pos] !== '#') {
                    while ($indents !== [] && $indents[count($indents) - 1] >= $pos) {
                        array_pop($indents);
                    }
                    $indents[] = $pos;
                }
            }
            $compact    = 0;
            $afterQuote = false;
            if (count($indents) + $flow > $limit) {
                throw new DocumentReadException('too_deep', $i + 1);
            }
            while ($pos < $length) {
                $char = $line[$pos];
                if ($quote === '"') {
                    if ($char === '\\') {
                        $pos += 2;

                        continue;
                    }
                    if ($char === '"') {
                        $quote      = null;
                        $afterQuote = true;
                    }
                    ++$pos;

                    continue;
                }
                if ($quote === "'") {
                    if ($char === "'") {
                        if (($line[$pos + 1] ?? '') === "'") {
                            $pos += 2;

                            continue;
                        }
                        $quote      = null;
                        $afterQuote = true;
                    }
                    ++$pos;

                    continue;
                }
                if ($char === ' ' || $char === "\t") {
                    ++$pos;

                    continue;
                }
                // Only `#` at column 0 or after a space opens a comment for Symfony; after a tab it is text.
                if ($char === '#' && ($pos === 0 || $line[$pos - 1] === ' ')) {
                    break;
                }
                if ($start) {
                    if ($char === '!') {
                        throw new DocumentReadException('yaml_tag', $i + 1);
                    }
                    if ($char === '&') {
                        throw new DocumentReadException('yaml_anchor', $i + 1);
                    }
                    if ($char === '*') {
                        throw new DocumentReadException('yaml_alias', $i + 1);
                    }
                    if ($char === '<' && preg_match('/\G<<[ \t]*:/', $line, $match, 0, $pos) === 1) {
                        throw new DocumentReadException('yaml_merge_key', $i + 1);
                    }
                    if ($char === "'" || $char === '"') {
                        $quote = $char;
                        $start = false;
                        ++$pos;

                        continue;
                    }
                    if (($char === '|' || $char === '>') && $flow === 0) {
                        $indent = strspn($line, " \t");
                        while ($i + 1 < $count && (trim($lines[$i + 1]) === '' || strspn($lines[$i + 1], " \t") > $indent)) {
                            ++$i;
                        }

                        continue 2;
                    }
                    $next = $line[$pos + 1] ?? '';
                    if (($char === '?' || $char === ':') && in_array($next, ['', ' ', "\t"], true)) {
                        // Explicit keys (`? k` / `: v`): Symfony Yaml does not support them, seeds never need them.
                        if ($char === '?' || $flow === 0) {
                            throw new DocumentReadException('yaml_explicit_key', $i + 1);
                        }
                        ++$pos;

                        continue;
                    }
                    if ($char === ':' && in_array($next, [':', '!', '&', '*'], true)) {
                        // A run of `:` (flow values skip them) or a token glued to it: keep checking the next node.
                        ++$pos;

                        continue;
                    }
                    if ($char === '-' && in_array($next, ['', ' ', "\t"], true)) {
                        if (count($indents) + $flow + ++$compact > $limit) {
                            throw new DocumentReadException('too_deep', $i + 1);
                        }
                        ++$pos;

                        continue;
                    }
                    if ($char === '[' || $char === '{') {
                        self::enterFlow($flow, count($indents) + $compact, $limit, $i);
                        ++$pos;

                        continue;
                    }
                    if ($char === ']' || $char === '}') {
                        $flow  = $flow > 0 ? $flow - 1 : 0;
                        $start = false;
                        ++$pos;

                        continue;
                    }
                    if ($char === ',' && $flow > 0) {
                        ++$pos;

                        continue;
                    }
                    // Anything else starts a plain scalar, including `:x` / `?x` (`:|p` and `:"p` are plain keys).
                    $start = false;
                    ++$pos;

                    continue;
                }
                $next = $line[$pos + 1] ?? ' ';
                if ($char === ':' && ($afterQuote || $flow > 0 || $next === ' ' || $next === "\t")) {
                    $start = true;
                } elseif ($flow > 0 && $char === ',') {
                    $start = true;
                } elseif ($flow > 0 && ($char === ']' || $char === '}')) {
                    --$flow;
                } elseif ($flow > 0 && ($char === '[' || $char === '{')) {
                    self::enterFlow($flow, count($indents) + $compact, $limit, $i);
                    $start = true;
                }
                $afterQuote = false;
                ++$pos;
            }
        }
    }

    /**
     * Opens one more flow collection, refusing a nesting deeper than the limit.
     *
     * @throws DocumentReadException
     */
    private static function enterFlow(int &$flow, int $block, int $limit, int $line): void
    {
        if ($block + ++$flow > $limit) {
            throw new DocumentReadException('too_deep', $line + 1);
        }
    }
}
