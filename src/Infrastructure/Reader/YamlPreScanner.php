<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Reader;

use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;

use function count;
use function preg_match;
use function preg_split;
use function str_starts_with;
use function strlen;
use function strspn;
use function trim;

/**
 * Refuses the YAML features a seed document never needs before the parser sees the text (contract section 13.3, F4):
 * tags (`!`, `!!`: objects, constants, binary), anchors (`&`) and aliases (`*`: billion laughs), merge keys (`<<`) and
 * directives (`%`). A token is checked where a node starts (line start after indentation, after `- `, `? `, `: `, `[`,
 * `{`, `,`); quoted scalars, block scalars, comments and the inside of plain scalars are skipped, so `a&b` or `"*"` are
 * fine. A plain scalar continued on a new line that starts with one of these characters is refused too (fail closed).
 */
final class YamlPreScanner
{
    /**
     * Asserts the text is free of the refused features.
     *
     * @throws DocumentReadException
     */
    public static function assertSafe(string $text): void
    {
        $lines = preg_split("/\r\n|\n|\r/", $text);
        $lines = $lines === false ? [] : $lines;
        $count = count($lines);
        $quote = null;
        $flow  = 0;
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
                    $pos = 3;
                }
            }
            while ($pos < $length) {
                $char = $line[$pos];
                if ($quote === '"') {
                    if ($char === '\\') {
                        $pos += 2;

                        continue;
                    }
                    if ($char === '"') {
                        $quote = null;
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
                        $quote = null;
                    }
                    ++$pos;

                    continue;
                }
                if ($char === ' ' || $char === "\t") {
                    ++$pos;

                    continue;
                }
                if ($char === '#' && ($pos === 0 || $line[$pos - 1] === ' ' || $line[$pos - 1] === "\t")) {
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
                    if (($char === '-' || $char === '?') && ($pos + 1 === $length || $line[$pos + 1] === ' ' || $line[$pos + 1] === "\t")) {
                        ++$pos;

                        continue;
                    }
                    if ($char === '[' || $char === '{') {
                        ++$flow;
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
                    $start = false;
                    ++$pos;

                    continue;
                }
                $next = $line[$pos + 1] ?? ' ';
                if ($char === ':' && ($next === ' ' || $next === "\t" || ($flow > 0 && ($next === ',' || $next === ']' || $next === '}')))) {
                    $start = true;
                } elseif ($flow > 0 && $char === ',') {
                    $start = true;
                } elseif ($flow > 0 && ($char === ']' || $char === '}')) {
                    --$flow;
                } elseif ($flow > 0 && ($char === '[' || $char === '{')) {
                    ++$flow;
                    $start = true;
                }
                ++$pos;
            }
        }
    }
}
