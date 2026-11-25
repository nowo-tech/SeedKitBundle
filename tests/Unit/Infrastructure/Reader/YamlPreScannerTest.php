<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Infrastructure\Reader;

use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;
use Nowo\SeedKitBundle\Infrastructure\Reader\YamlPreScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The YAML pre-scanner (contract 13.3, F4): refused tokens where a node starts, everything else is text.
 */
final class YamlPreScannerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1?: int}>
     */
    public static function safeTexts(): iterable
    {
        yield 'empty' => [''];
        yield 'blank lines' => ["\n   \n\t\n"];
        yield 'plain scalars with inner indicators' => ["a: b&c*d!e%f<<g\n"];
        yield 'double quoted with an escaped quote' => ["a: \"x\\\"*y\"\n"];
        yield 'single quoted with a doubled quote' => ["a: 'x''&y'\n"];
        yield 'quoted scalar over several lines' => ["a: 'first\n*second\n&third'\nb: \"one\n  !two\"\n"];
        yield 'quoted keys' => ["'*k': 1\n\"&k\": 2\n"];
        yield 'comment at the start' => ["# *a &b !c\n"];
        yield 'comment after a value' => ["a: x # *alias\n"];
        yield 'hash inside a plain scalar' => ["a: x#*y\n"];
        yield 'literal block scalar' => ["a: |\n  *x\n\n  &y\nb: 1\n"];
        yield 'folded block scalar with a keep indicator' => ["- >+\n    !x\n- y\n"];
        yield 'block scalar at the end' => ["a: |-\n  *x"];
        yield 'sequence entries' => ["- a\n-\n  - b\n- c\n"];
        yield 'document markers' => ["---\na: 1\n...\n--- \nb: 2\n--- c\n"];
        yield 'not a document marker' => ["----x: 1\n"];
        yield 'flow collections over lines' => ["a: [\n  x,\n  { k: v },\n  ]\nb: {\n  c: d\n}\n"];
        yield 'flow with empty values' => ["a: {b:, c: d}\nx: [y:]\nz: {q:}\n"];
        yield 'closing bracket at a line start outside a flow' => ["]\n"];
        yield 'colon inside a flow plain scalar' => ["a: [b:c]\n"];
        yield 'block indicator inside a flow' => ["a: [|, >]\n"];
        yield 'not a merge key' => ["<<x: 1\n"];
        yield 'percent inside a flow at column 0' => ["a: [\n%x]\n"];
        yield 'percent after indentation' => ["a:\n  %x\n"];
        yield 'carriage returns' => ["a: 1\rb: 2\r\nc: 3\n"];
        yield 'plain keys starting with a colon or a question mark' => [":|p: x\n:\"p: y\n?x: 1\n:>q: 2\n"];
        yield 'plain flow entry starting with a colon' => ["a: [:b, c]\n"];
        yield 'legitimate look-alikes' => ["u: {link: http://x.test/a?b=1#c, at: 10:30}\nj: {\"k\":\"v\",\"n\":[1,2]}\nq: 'it''s: fine'\n"
            . "p: first line\n  second line\ns: \"multi\n  line\"\nh: Hello! # note\nw: Wow!!\n- [a-b, c]\n"];
        yield 'quoted sibling key after a plain value' => ["- a: x\n  \"b\": y\n"];
        yield 'flow collections followed by separators' => ["k: [a, [b], {c: [d]}]\nl: {m: [n]}\n[o]: p\nq: [r]\n"];
        yield 'nested sequences and maps' => ["- - a\n  - b\n  - c: 1\n    d: 2\n"];
        yield 'quoted values and keys in a row' => ["- \"a\"\n- 'b'\nk: \"v\"\nl: 'w'\n- k: \"v\"\n  \"l\": 'w'\n"];
        yield 'block scalar of a sequence item key' => ["- k: |\n    *text !x \"\n  n: 1\n"];
        yield 'block scalar as a sequence item' => ["- >\n  *text\n- b\n"];
        yield 'block scalar headers' => ["a: |-\n  *x\nb: >+ # keep\n  !y\nc: |\n  &z\n"];
        yield 'quoted value on the next line after a key' => ["a:\n  \"b\"\n"];
        yield 'plain value after a block scalar' => ["a: |\n  x\nb: \"y\"\n"];
        yield 'json-style flow mapping' => ["a: [{\"k\":\"v\",\"n\":1,'s':[1,2]}]\n"];
        yield 'quoted key with spaces before the colon' => ["a: {\"k\" : v}\n"];
        yield 'url in a flow mapping' => ["a: {u: http://x/a&b*c}\n"];
        yield 'quoted block value followed by a colon' => ["a: \"x\":y\n"];
        yield 'nesting at the depth limit' => ["a:\n  - [[x]]\n", 5];
        yield 'compact entries within the estimate' => ["- - - x\n", 4];
        yield 'deeper lines after a shallower one' => ["a:\n  b:\n    c: 1\nd:\n  e: 1\n", 3];
        yield 'comment lines do not nest' => ["a:\n  b: 1\n      # deep comment\n", 2];
    }

    #[Test]
    #[DataProvider('safeTexts')]
    public function acceptsSafeTexts(string $text, ?int $maxDepth = null): void
    {
        $this->expectNotToPerformAssertions();

        YamlPreScanner::assertSafe($text, $maxDepth);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function unsafeTexts(): iterable
    {
        yield 'local tag' => ["a: !x 1\n", 'yaml_tag', 1];
        yield 'global tag' => ["a: !!str 1\n", 'yaml_tag', 1];
        yield 'tag on a sequence entry' => ["-\n- !php/object x\n", 'yaml_tag', 2];
        yield 'tag on a key' => ["!k a: 1\n", 'yaml_tag', 1];
        yield 'anchor' => ["a: &x 1\n", 'yaml_anchor', 1];
        yield 'alias' => ["a: 1\nb: *x\n", 'yaml_alias', 2];
        yield 'alias in a flow sequence' => ["a: [1, *x]\n", 'yaml_alias', 1];
        yield 'alias in a flow mapping value' => ["a: {k: *x}\n", 'yaml_alias', 1];
        yield 'alias after an empty flow value' => ["a: {k:, l: *x}\n", 'yaml_alias', 1];
        yield 'alias after a closing flow on the next line' => ["a: [\n  b\n] \nc: *x\n", 'yaml_alias', 4];
        yield 'bracket inside a flow plain scalar' => ["a: [b[*x]]\n", 'yaml_ambiguous', 1];
        yield 'tab after a colon' => ["a:\t&x 1\n", 'yaml_ambiguous', 1];
        yield 'tab in indentation' => ["a:\n\t b: 1\n", 'yaml_ambiguous', 2];
        yield 'tab inside a plain scalar' => ["a: b\tc\n", 'yaml_ambiguous', 1];
        yield 'merge key' => ["<<: {a: 1}\n", 'yaml_merge_key', 1];
        yield 'merge key with a tab' => ["- <<\t: x\n", 'yaml_merge_key', 1];
        yield 'directive' => ["%TAG ! tag:x\n", 'yaml_directive', 1];
        yield 'directive after a document' => ["a: 1\n...\n%YAML 1.1\n", 'yaml_directive', 3];
        yield 'after the block scalar' => ["a: |\n  x\n*y: 1\n", 'yaml_alias', 3];
        yield 'after a document marker' => ["--- &a\n", 'yaml_anchor', 1];
        yield 'after a closed quote on a continuation line' => ["a: 'x\ny' \n- *z\n", 'yaml_alias', 3];
        yield 'continued plain scalar' => ["a: b\n  !c\n", 'yaml_tag', 2];
        yield 'after a closing bracket at a line start' => ["a: [\n] \n*b: 1\n", 'yaml_alias', 3];
        // ZBK-v M1-b: a `:` / `?` glued to `|`, `>` or a quote is a plain key to Symfony, not an indicator.
        yield 'tag after a plain key starting with :|' => ["a:\n  :|p: !!binary aGVsbG8=\n", 'yaml_tag', 2];
        yield 'tag after a plain key starting with :>' => ["- :>p: !!int 1\n", 'yaml_tag', 1];
        yield 'alias after a plain key starting with :"' => [":\"p: *a\n", 'yaml_alias', 1];
        yield 'alias after a plain key starting with ?"' => ["?\"a\\\"x: *a\n", 'yaml_alias', 1];
        yield 'explicit key' => ["? a\n: b\n", 'yaml_explicit_key', 1];
        yield 'explicit key with a quote' => ["? \"a\\\"x: *a\n", 'yaml_explicit_key', 1];
        yield 'explicit key with a block indicator' => ["? |  \\: *a\n", 'yaml_explicit_key', 1];
        yield 'explicit value line' => ["a: 1\n: *a\n", 'yaml_explicit_key', 2];
        yield 'explicit key in a flow mapping' => ["a: {? b: c}\n", 'yaml_explicit_key', 1];
        yield 'alias after a colon and a quote in a flow sequence' => ["a: [:\"p, *x\"]\n", 'yaml_alias', 1];
        yield 'alias after a quoted key, two colons and a quote' => ["a: {\"t\"::\"p, *x\"}\n", 'yaml_alias', 1];
        yield 'tag after a colon run in block' => ["\"k\"::!!str 1\n", 'yaml_tag', 1];
        // ZBK-v2: where Symfony could read the text otherwise, refuse (fail closed).
        yield 'hash after a tab' => ["a\t# x: *zz\n", 'yaml_ambiguous', 1];
        yield 'hash after a tab hiding a quote' => ["n: x\t# y: '\nm: !!binary aGVsbG8=\n# '\n", 'yaml_ambiguous', 1];
        yield 'tab comment line' => ["\t# x: '\nm: !!str 1\n", 'yaml_ambiguous', 1];
        yield 'quote opening a plain continuation line' => ["c: x\n  \"y\nd: !!binary aGVsbG8=\ne: '\"'\n", 'yaml_ambiguous', 2];
        yield 'single quote opening a plain continuation line' => ["- k: x\n    'y\n", 'yaml_ambiguous', 2];
        yield 'bracket opening a plain continuation line' => ["c: x\n  [y\n", 'yaml_ambiguous', 2];
        yield 'block indicator opening a plain continuation line' => ["c:\n  x\n   |\n", 'yaml_ambiguous', 3];
        yield 'dash inside a flow is plain text' => ["[- -   ',!x ]\n", 'yaml_tag', 1];
        yield 'tag after a space inside text' => ["a: b !!str 1\n", 'yaml_tag', 1];
        yield 'tag after a comma inside a flow plain scalar' => ["a: [b,!x]\n", 'yaml_tag', 1];
        yield 'block scalar of a sequence item key does not own the sibling line' => ["- b:x- %<<: >\n  :\":>,!t :: !!float 7\n", 'yaml_tag', 2];
        yield 'closing bracket outside a flow is plain text' => ["- }\n    [\"- x\n- !!binary QUJD\n", 'yaml_ambiguous', 2];
        yield 'quote on a line under a quoted value' => ["-\n  'a'  # c\n  '%\n- !!float 7\n", 'yaml_ambiguous', 3];
        yield 'quote under a quoted value of a key' => ["k: \"v\"\n  \"w\n", 'yaml_ambiguous', 2];
        yield 'quote after a dash on a continuation line' => ["}?x? : - - \n  - \"\n  !t \n*zz\n", 'yaml_ambiguous', 2];
        yield 'quote after a key on a continuation line' => ["a: b\n  c: \"d\n", 'yaml_ambiguous', 2];
        yield 'alias in a nested sequence item' => ["- - k\n  - *zz\n", 'yaml_alias', 2];
        yield 'alias under a compact item' => ["- - - -  ,:>a::{\n      - *zz:\"!x\n", 'yaml_alias', 2];
        yield 'value of a compact item under its first dash' => ["- - -\n    k\n    '{*zz\n", 'yaml_ambiguous', 3];
        yield 'token right after a closed flow' => ["[[]*zz]\n", 'yaml_ambiguous', 1];
        yield 'tag right after a closed flow' => ["[[]!t ::]\n", 'yaml_ambiguous', 1];
        yield 'token after a closed flow on the next line' => ["[\n  []\n  *zz]\n", 'yaml_ambiguous', 3];
        yield 'text after a closed block flow' => ["k: [a] b\n", 'yaml_ambiguous', 1];
        yield 'block scalar after a document marker' => ["--- > #::\n  !!float 7?a{\n", 'yaml_ambiguous', 1];
        yield 'block scalar at the root' => ["|\n  *x\n", 'yaml_ambiguous', 1];
        yield 'quote never closed' => ["a: '':\":x? ---\nb: 1\n", 'yaml_ambiguous', 3];
        yield 'text after a quoted scalar that spanned lines' => ["k:\n  'a\n  b' :\"\n*zz\n", 'yaml_ambiguous', 3];
        yield 'block scalar header with text' => ["a: >- x\n  y\n", 'yaml_ambiguous', 1];
        yield 'block scalar of a nested sequence item' => ["- - |\n  - *zz\n", 'yaml_alias', 2];
        yield 'block scalar indentation indicator' => ["a: |2\n   x\n", 'yaml_ambiguous', 1];
        yield 'dash after a key is plain text' => ["b: - - ':>[\n  x: !!float 7\n", 'yaml_tag', 2];
        yield 'quote continuing a plain value of a key on the line above' => ["b: x\n  'y\n", 'yaml_ambiguous', 2];
        yield 'quote continuing a value that started on its own line' => ["k:\n    ?'a\n    '\n  ''!x\n", 'yaml_ambiguous', 3];
        yield 'quote continuing a sequence item at the item column' => ["- x\n  \"y\n", 'yaml_ambiguous', 2];
        yield 'quote continuing a multi-line plain scalar' => ["k: a\n  b\n  \"c\n", 'yaml_ambiguous', 3];
        yield 'quote continuing a plain scalar after a comment line' => ["a:\n  x\n  # note\n  \"y\n", 'yaml_ambiguous', 4];
        yield 'quoted scalar over lines of a flow' => ["[\"a #b\n  *zz\"]\n", 'yaml_ambiguous', 1];
        yield 'quote after a colon glued inside a flow plain scalar' => ["[#:>{'!!str 8- [,\n!!float 7:>]\n", 'yaml_ambiguous', 1];
        yield 'quote after a glued colon in a flow' => ["a: [b:'c, *x']\n", 'yaml_ambiguous', 1];
        yield 'tag after a dash inside text' => ["a: b-!x\n", 'yaml_tag', 1];
        yield 'anchor after a quoted flow key and a bare colon' => ["a: [{\"k\":&x [1]}, {\"k\":*x}]\n", 'yaml_anchor', 1];
        yield 'alias after a quoted flow key and a bare colon' => ["a: {\"k\":*x}\n", 'yaml_alias', 1];
        yield 'tag after a quoted flow key and a bare colon' => ["a: {\"k\":!!binary YQ==}\n", 'yaml_tag', 1];
        yield 'tag after a single-quoted flow key' => ["a: {'k':!!str 1}\n", 'yaml_tag', 1];
        yield 'alias after a doubled quote in a single-quoted key' => ["a: {'it''s':*x}\n", 'yaml_alias', 1];
        yield 'alias after an escaped quote in a double-quoted key' => ["a: {\"a\\\"b\":*x}\n", 'yaml_alias', 1];
        yield 'tab after a quoted key' => ["a: {\"k\"\t:*x}\n", 'yaml_ambiguous', 1];
        yield 'quoted key over two lines of a flow' => ["a: {\"k\n  l\":*x}\n", 'yaml_ambiguous', 1];
        yield 'alias after a run of colons' => ["a: {k: :*x}\n", 'yaml_alias', 1];
        yield 'anchor after a colon inside a flow plain scalar' => ["a: [b:&x c]\n", 'yaml_anchor', 1];
        yield 'alias after a quoted block key and a bare colon' => ["\"k\":*x\n", 'yaml_alias', 1];
        yield 'alias after a quoted block key, a space and a colon' => ["- 'k' :*x\n", 'yaml_alias', 1];
    }

    #[Test]
    #[DataProvider('unsafeTexts')]
    public function refusesUnsafeTokensWithTheirLine(string $text, string $reason, int $line): void
    {
        try {
            YamlPreScanner::assertSafe($text);
            self::fail('Expected ' . $reason . '.');
        } catch (DocumentReadException $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($line, $e->lineNumber);
        }
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function tooDeepTexts(): iterable
    {
        yield 'flow brackets' => ["a: [[[x]]]\n", 3, 1];
        yield 'flow mappings' => ["a: {b: {c: {d: 1}}}\n", 3, 1];
        yield 'flow over lines' => ["a: [\n  [\n    [\n      [x]]]]\n", 3, 3];
        yield 'compact sequence entries' => ["- - - - x\n", 3, 1];
        yield 'block indentation' => ["a:\n  b:\n    c:\n      d: 1\n", 3, 4];
        yield 'block indentation then flow' => ["a:\n  b: [[x]]\n", 3, 2];
    }

    #[Test]
    #[DataProvider('tooDeepTexts')]
    public function refusesNestingDeeperThanTheLimit(string $text, int $maxDepth, int $line): void
    {
        try {
            YamlPreScanner::assertSafe($text, $maxDepth);
            self::fail('Expected too_deep.');
        } catch (DocumentReadException $e) {
            self::assertSame('too_deep', $e->reason);
            self::assertSame($line, $e->lineNumber);
        }
    }

    #[Test]
    public function aDocumentMarkerResetsTheIndentation(): void
    {
        $this->expectNotToPerformAssertions();

        YamlPreScanner::assertSafe("a:\n  b:\n    c: 1\n--- [[x]]\n", 3);
    }
}
