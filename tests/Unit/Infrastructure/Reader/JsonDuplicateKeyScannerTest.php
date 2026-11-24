<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Infrastructure\Reader;

use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;
use Nowo\SeedKitBundle\Infrastructure\Reader\JsonDuplicateKeyScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The JSON duplicate-key scan (PHP keeps the last key silently, contract 13.3).
 */
final class JsonDuplicateKeyScannerTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function uniqueJson(): iterable
    {
        yield 'scalar' => ['"a"'];
        yield 'empty object' => ['{}'];
        yield 'same key in sibling objects' => ['[{"a":1},{"a":2}]'];
        yield 'same key at different depths' => ['{"a":{"a":{"a":1}}}'];
        yield 'values equal to keys' => ['{"a":"a","b":"a","c":["a","a"]}'];
        yield 'escaped quote in a key' => ['{"a\"":1,"a":2}'];
        yield 'json text inside a string' => ['{"a":"{\"a\":1,\"a\":2}","b":1}'];
        yield 'key after a nested object' => ['{"a":{"b":1},"b":2}'];
        yield 'key after an array of objects' => ['{"a":[{"x":1}],"x":2}'];
        yield 'escaped backslash before a closing quote' => ['{"a\\\\":1,"a":2}'];
    }

    #[Test]
    #[DataProvider('uniqueJson')]
    public function acceptsJsonWithoutRepeatedKeys(string $json): void
    {
        $this->expectNotToPerformAssertions();

        JsonDuplicateKeyScanner::assertUnique($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function duplicateJson(): iterable
    {
        yield 'top level' => ['{"a":1,"a":2}'];
        yield 'nested' => ['{"x":{"a":1,"b":2,"a":3}}'];
        yield 'in a list' => ['[1,{"a":1,"a":2}]'];
        yield 'after a nested object' => ['{"a":{"a":1},"a":2}'];
        yield 'unicode escape spelling' => ['{"a":1,"a":2}'];
        yield 'empty keys' => ['{"":1,"":2}'];
        yield 'with whitespace' => ["{ \"a\" : 1 ,\n \"a\" : 2 }"];
    }

    #[Test]
    #[DataProvider('duplicateJson')]
    public function refusesRepeatedKeys(string $json): void
    {
        try {
            JsonDuplicateKeyScanner::assertUnique($json);
            self::fail('Expected duplicate_key.');
        } catch (DocumentReadException $e) {
            self::assertSame('duplicate_key', $e->reason);
            self::assertNull($e->lineNumber);
        }
    }
}
