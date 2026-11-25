<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Reader;

use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;

use function array_pop;
use function count;
use function in_array;
use function preg_match;
use function preg_split;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strspn;
use function substr;
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
 * a new line that starts with one of these characters is refused too. Where Symfony could read the text differently
 * from this scanner, it refuses (`yaml_ambiguous`) instead of guessing: a tab outside quoted and block scalars, a
 * quoted, flow or block scalar opening on a line that may continue a value (deeper than the key or first `-` owning
 * it; its tokens are still checked as nodes), text right after a closed flow collection without a separator, a
 * bracket or a quote right after a `:` glued inside a flow plain scalar, a quoted scalar over several lines of a flow
 * collection and a block-scalar indentation indicator. `- ` after a key or inside a flow, and `]` / `}` outside a
 * flow, are plain text, as for Symfony. A `!` after a space, a tab or `[ { , : ?` is refused
 * anywhere outside quoted scalars and comments, so no desync can carry a tag (`Hello!` stays fine).
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
        // Column of the key or `-` owning the value the previous lines ended in (a deeper line continues it).
        $plain = null;
        // Column of the key (or `-`) whose value starts on a later line (`k:` alone), else null.
        $pending = null;
        // Line where the open quoted scalar started, and the column of the key or `-` owning it.
        $opened      = 0;
        $openedOwner = null;
        // A flow collection just closed: only `,`, `]`, `}` or `:` may follow it.
        $closed = false;
        // The previous line ended inside a plain scalar of a flow collection (Symfony folds the next line into it).
        $flowPlain = false;
        for ($i = 0; $i < $count; ++$i) {
            $line      = $lines[$i];
            $length    = strlen($line);
            $pos       = 0;
            $start     = $quote === null;
            $continued = false;
            if ($quote === null) {
                $pos = strspn($line, " \t");
                if ($pos === $length) {
                    continue;
                }
                if (str_contains(substr($line, 0, $pos), "\t")) {
                    throw new DocumentReadException('yaml_ambiguous', $i + 1);
                }
                $continued = $plain !== null && $flow === 0 && $pos > $plain;
                if ($continued && in_array($line[$pos], ['"', "'", '[', '{', '|', '>'], true)) {
                    // Symfony reads this line as more text of the plain scalar above, not as a new quoted, flow or
                    // block node: refuse rather than guess (fail closed).
                    throw new DocumentReadException('yaml_ambiguous', $i + 1);
                }
                if ($flowPlain && in_array($line[$pos], ['"', "'", '[', '{', '|', '>'], true)) {
                    // `[x` then `"y, …"`: for Symfony the quote is more text of `x`; refuse rather than guess.
                    throw new DocumentReadException('yaml_ambiguous', $i + 1);
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
            $node       = null;
            $key        = null;
            $firstDash  = null;
            $lastDash   = null;
            $guess      = false;
            $tail       = false;
            $closed     = $closed && $flow > 0;
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
                        $tail       = $opened !== $i;
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
                        $tail       = $opened !== $i;
                    }
                    ++$pos;

                    continue;
                }
                if ($char === "\t") {
                    // Symfony treats a tab as a separator in some places and as text in others: spaces only.
                    throw new DocumentReadException('yaml_ambiguous', $i + 1);
                }
                if ($char === ' ') {
                    ++$pos;

                    continue;
                }
                if ($char === '#' && ($pos === 0 || $line[$pos - 1] === ' ')) {
                    break;
                }
                if ($tail) {
                    // Text after a quoted scalar that spanned lines: Symfony folds those lines first; refuse.
                    throw new DocumentReadException('yaml_ambiguous', $i + 1);
                }
                if ($closed) {
                    if (!in_array($char, [',', ']', '}', ':'], true)) {
                        // Symfony reads on after a closed collection without a separator; refuse.
                        throw new DocumentReadException('yaml_ambiguous', $i + 1);
                    }
                    $closed = false;
                }

                if ($start) {
                    if ($continued && in_array($char, ['"', "'", '[', '{', '|', '>'], true)) {
                        // A line Symfony may read as more text of the value above: its tokens are still checked as
                        // nodes, but a quoted, flow or block scalar is never opened on it (fail closed).
                        throw new DocumentReadException('yaml_ambiguous', $i + 1);
                    }
                    if ($guess && in_array($char, ['"', "'", '[', '{'], true)) {
                        // A node the scanner only assumes (after a `:` glued inside a flow plain scalar): Symfony may
                        // read this as more plain text, so a quote or a collection here is refused (fail closed).
                        throw new DocumentReadException('yaml_ambiguous', $i + 1);
                    }
                    $guess = false;
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
                        $node ??= $pos;
                        $quote       = $char;
                        $opened      = $i;
                        $openedOwner = $key ?? $firstDash ?? $pending ?? $node;
                        $start       = false;
                        ++$pos;

                        continue;
                    }
                    if (($char === '|' || $char === '>') && $flow === 0) {
                        if ($node === null && $lastDash === null) {
                            // A block scalar must be the value of a key or a `-` (not after `---` or at the root).
                            throw new DocumentReadException('yaml_ambiguous', $i + 1);
                        }
                        if (preg_match('/\G[|>][+-]?(?: +(?:#.*)?)?\z/', $line, $match, 0, $pos) !== 1) {
                            // Only `|` / `>` with an optional chomping sign and a comment is a header both parsers agree
                            // on (an indentation digit or other text changes which lines belong to the scalar).
                            throw new DocumentReadException('yaml_ambiguous', $i + 1);
                        }
                        // The scalar holds the lines deeper than the key or `-` that owns it (`- k: |` → deeper than
                        // `k`, `- - |` → deeper than the second `-`).
                        $indent = $node ?? $lastDash ?? strspn($line, " \t");
                        while ($i + 1 < $count && (trim($lines[$i + 1]) === '' || strspn($lines[$i + 1], " \t") > $indent)) {
                            ++$i;
                        }
                        $plain   = null;
                        $pending = null;

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
                    // Inside a flow collection `- ` is plain text for Symfony, not a sequence entry.
                    // After a key on the same line (`k: - x`) a dash is plain text too.
                    if ($char === '-' && $flow === 0 && $node === null && in_array($next, ['', ' ', "\t"], true)) {
                        if (count($indents) + $flow + ++$compact > $limit) {
                            throw new DocumentReadException('too_deep', $i + 1);
                        }
                        $firstDash ??= $pos;
                        $lastDash = $pos;
                        ++$pos;

                        continue;
                    }
                    if ($char === '[' || $char === '{') {
                        self::enterFlow($flow, count($indents) + $compact, $limit, $i);
                        ++$pos;

                        continue;
                    }
                    if (($char === ']' || $char === '}') && $flow > 0) {
                        --$flow;
                        $start  = false;
                        $closed = true;
                        ++$pos;

                        continue;
                    }
                    if ($char === ',' && $flow > 0) {
                        ++$pos;

                        continue;
                    }
                    // Anything else starts a plain scalar, including `:x` / `?x` (`:|p` and `:"p` are plain keys).
                    $node ??= $pos;
                    $start = false;
                    ++$pos;

                    continue;
                }
                if ($char === '!' && ($pos === 0 || in_array($line[$pos - 1], [' ', "\t", '[', '{', ',', ':', '?'], true))) {
                    // A tag-like token inside text: refused whatever the scanner thinks the node is (fail closed).
                    throw new DocumentReadException('yaml_tag', $i + 1);
                }
                $next = $line[$pos + 1] ?? ' ';
                if ($char === ':' && ($afterQuote || $next === ' ' || ($flow > 0 && in_array($next, [',', ']', '}'], true)))) {
                    $start = true;
                    if ($flow === 0) {
                        $key = $node ?? $key;
                    }
                } elseif ($char === ':' && $flow > 0) {
                    // `a:b` inside a flow: a plain scalar for Symfony, a value only in `{k:v}` style; check as a node.
                    $start = true;
                    $guess = true;
                } elseif ($flow > 0 && $char === ',') {
                    $start = true;
                } elseif ($flow > 0 && ($char === ']' || $char === '}')) {
                    --$flow;
                    $closed = true;
                } elseif ($flow > 0 && ($char === '[' || $char === '{')) {
                    // A bracket inside a flow plain scalar is text for Symfony, a collection for a naive scanner.
                    throw new DocumentReadException('yaml_ambiguous', $i + 1);
                }
                $afterQuote = false;
                ++$pos;
            }
            if ($quote !== null && $flow > 0) {
                // A quoted scalar over several lines of a flow collection: Symfony joins and cleans these lines first.
                throw new DocumentReadException('yaml_ambiguous', $i + 1);
            }
            if ($quote === null && $node === null && $firstDash === null && $start) {
                // Nothing but a comment on this line: the open plain scalar or pending key carries on.
                continue;
            }
            $flowPlain = $flow > 0 && $quote === null && !$start && !$afterQuote && !$closed;
            if ($continued) {
                // More text of the plain scalar above: the owner of the scalar does not change.
                $pending = null;
            } elseif ($quote === null && $flow === 0 && !$start) {
                // The line ends inside a value (plain, quoted or a closed flow): a deeper line belongs to it.
                // The owner is the key, else the first `-` of the line (the smaller column: fail closed).
                $plain   = $key ?? $firstDash ?? $pending ?? $node ?? $openedOwner;
                $pending = null;
            } else {
                $plain   = null;
                $pending = $quote === null && $flow === 0 && $start ? $key ?? $firstDash : null;
            }
        }
        if ($quote !== null) {
            // A quote never closed: Symfony reads that text another way (or fails); refuse.
            throw new DocumentReadException('yaml_ambiguous', $count);
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
