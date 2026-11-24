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
     * @return iterable<string, array{string}>
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
        yield 'sequence entries' => ["- a\n-\n  - b\n-\tc\n"];
        yield 'complex key' => ["? a\n: b\n"];
        yield 'document markers' => ["---\na: 1\n...\n--- \nb: 2\n---\tc\n"];
        yield 'not a document marker' => ["----x: 1\n"];
        yield 'flow collections over lines' => ["a: [\n  x,\n  { k: v },\n  ]\nb: {\n  c: d\n}\n"];
        yield 'flow with empty values' => ["a: {b:, c: d}\nx: [y:]\nz: {q:}\n"];
        yield 'nested flow without spaces' => ["a: [b[c], d]\n"];
        yield 'closing bracket at a line start outside a flow' => ["]\n"];
        yield 'colon inside a flow plain scalar' => ["a: [b:c]\n"];
        yield 'block indicator inside a flow' => ["a: [|, >]\n"];
        yield 'not a merge key' => ["<<x: 1\n"];
        yield 'percent inside a flow at column 0' => ["a: [\n%x]\n"];
        yield 'percent after indentation' => ["a:\n  %x\n"];
        yield 'carriage returns' => ["a: 1\rb: 2\r\nc: 3\n"];
    }

    #[Test]
    #[DataProvider('safeTexts')]
    public function acceptsSafeTexts(string $text): void
    {
        $this->expectNotToPerformAssertions();

        YamlPreScanner::assertSafe($text);
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
        yield 'alias in a nested flow without spaces' => ["a: [b[*x]]\n", 'yaml_alias', 1];
        yield 'anchor after a tab' => ["a:\t&x 1\n", 'yaml_anchor', 1];
        yield 'merge key' => ["<<: {a: 1}\n", 'yaml_merge_key', 1];
        yield 'merge key with a tab' => ["- <<\t: x\n", 'yaml_merge_key', 1];
        yield 'directive' => ["%TAG ! tag:x\n", 'yaml_directive', 1];
        yield 'directive after a document' => ["a: 1\n...\n%YAML 1.1\n", 'yaml_directive', 3];
        yield 'after the block scalar' => ["a: |\n  x\n*y: 1\n", 'yaml_alias', 3];
        yield 'after a document marker' => ["--- &a\n", 'yaml_anchor', 1];
        yield 'after a closed quote on a continuation line' => ["a: 'x\ny' \n- *z\n", 'yaml_alias', 3];
        yield 'continued plain scalar' => ["a: b\n  !c\n", 'yaml_tag', 2];
        yield 'after a closing bracket at a line start' => ["a: [\n] \n*b: 1\n", 'yaml_alias', 3];
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
}
