<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Application;

use Nowo\SeedKitBundle\Application\SchemaLinter;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\ScriptedProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\ArticleProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\InMemoryStore;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\TagProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Schema lint (contract 7.2, 7.3): every rule.
 */
final class SchemaLinterTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $properties
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function schema(array $properties = [], array $extra = []): array
    {
        return $extra + [
            'type'                 => 'object',
            'required'             => ['code'],
            'additionalProperties' => false,
            'x-seed-key'           => ['code'],
            'properties'           => ['code' => ['type' => 'string', 'maxLength' => 10, 'description' => 'Code']] + $properties,
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $dependsOn
     *
     * @return array{errors: list<string>, warnings: list<string>}
     */
    private static function lint(array $schema, array $dependsOn = []): array
    {
        return (new SchemaLinter())->lint(new ScriptedProvider('demo.items', $schema, ['platform'], $dependsOn));
    }

    #[Test]
    public function theFixturesAreClean(): void
    {
        $store = new InMemoryStore();
        self::assertSame(['errors' => [], 'warnings' => []], (new SchemaLinter())->lint(new TagProvider($store)));
        self::assertSame(['errors' => [], 'warnings' => []], (new SchemaLinter())->lint(new ArticleProvider($store)));
        self::assertSame(['errors' => [], 'warnings' => []], self::lint(self::schema()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>, list<string>}>
     */
    public static function errors(): iterable
    {
        yield 'not an object' => [self::schema([], ['type' => 'array']), [], ['The item schema must have "type": "object".']];
        yield 'remote ref' => [self::schema(['a' => ['$ref' => 'https://x/y', 'description' => 'd']]), [], ['#/properties/a: only local "$ref" (#/...) is allowed.']];
        yield 'non-string ref' => [self::schema(['a' => ['$ref' => 5, 'description' => 'd']]), [], ['#/properties/a: only local "$ref" (#/...) is allowed.']];
        yield 'dynamic ref' => [self::schema(['a' => ['$dynamicRef' => '#x', 'description' => 'd']]), [], ['#/properties/a: "$dynamicRef" is not allowed.']];
        yield 'vocabulary' => [self::schema([], ['$vocabulary' => ['x' => true]]), [], ['#: "$vocabulary" is not allowed.']];
        yield 'recursive ref' => [self::schema([], ['$recursiveRef' => '#']), [], ['#: "$recursiveRef" is not allowed.']];
        yield 'regex format' => [self::schema(['a' => ['type' => 'string', 'format' => 'regex', 'description' => 'd']]), [], ['#/properties/a: the "regex" format is disabled; use "pattern".']];
        yield 'open object' => [self::schema(['a' => ['type' => 'object', 'description' => 'd']]), [], ['#/properties/a: objects need "additionalProperties": false.']];
        yield 'open nullable object' => [self::schema(['a' => ['type' => ['object', 'null'], 'description' => 'd']]), [], ['#/properties/a: objects need "additionalProperties": false.']];
        yield 'open object in list' => [self::schema(['a' => ['anyOf' => [['type' => 'object'], ['type' => 'integer']], 'description' => 'd']]), [], ['#/properties/a/anyOf/0: objects need "additionalProperties": false.']];
        yield 'no key' => [self::schema([], ['x-seed-key' => []]), [], ['x-seed-key must list 1 to 4 properties.']];
        yield 'five keys' => [self::schema(['a' => ['type' => 'integer', 'description' => 'd']], ['x-seed-key' => ['code', 'a', 'a', 'a', 'a'], 'required' => ['code', 'a']]), [], ['x-seed-key must list 1 to 4 properties.']];
        yield 'key missing' => [self::schema([], ['x-seed-key' => ['nope']]), [], ['Key property "nope" must be a required string or integer property.']];
        yield 'key optional' => [self::schema([], ['required' => []]), [], ['Key property "code" must be a required string or integer property.']];
        yield 'key boolean' => [self::schema(['f' => ['type' => 'boolean', 'description' => 'd']], ['x-seed-key' => ['f'], 'required' => ['f']]), [], ['Key property "f" must be a required string or integer property.']];
        yield 'key secret' => [self::schema(['s' => ['type' => 'string', 'maxLength' => 5, 'x-seed-secret' => true, 'description' => 'd']], ['x-seed-key' => ['s'], 'required' => ['s']]), [], ['Key property "s" cannot be secret or read-only.']];
        yield 'key readonly' => [self::schema(['r' => ['type' => 'integer', 'x-seed-readonly' => true, 'description' => 'd']], ['x-seed-key' => ['r'], 'required' => ['r']]), [], ['Key property "r" cannot be secret or read-only.']];
        yield 'normaliser not key' => [self::schema(['n' => ['type' => 'string', 'maxLength' => 5, 'description' => 'd']], ['x-seed-key-normalise' => ['n' => 'lower']]), [], ['x-seed-key-normalise of "n" must be "lower" or "nfc" (ext-intl) on a key property.']];
        yield 'normaliser mode' => [self::schema([], ['x-seed-key-normalise' => ['code' => 'upper']]), [], ['x-seed-key-normalise of "code" must be "lower" or "nfc" (ext-intl) on a key property.']];
        yield 'ref missing property' => [self::schema([], ['x-seed-ref' => ['tag' => 'demo.tags']]), ['demo.tags'], ['x-seed-ref of "tag" must point at a provider listed in dependsOn().']];
        yield 'ref not a dependency' => [self::schema(['tag' => ['type' => 'string', 'maxLength' => 5, 'description' => 'd']], ['x-seed-ref' => ['tag' => 'demo.tags']]), [], ['x-seed-ref of "tag" must point at a provider listed in dependsOn().']];
        yield 'secret-looking name' => [self::schema(['apiKey' => ['type' => 'string', 'maxLength' => 5, 'description' => 'd'], 'Password' => ['type' => 'string', 'maxLength' => 5, 'description' => 'd']]), [], ['Property "apiKey" looks like a secret: declare "x-seed-secret": true or false.', 'Property "Password" looks like a secret: declare "x-seed-secret": true or false.']];
        yield 'omit means' => [self::schema(['o' => ['type' => 'integer', 'x-seed-omit-means' => 'drop', 'description' => 'd']]), [], ['x-seed-omit-means of "o" must be "keep" or "clear".']];
        yield 'integer-like name' => [self::schema(['10' => ['type' => 'integer', 'description' => 'd'], '-1' => ['type' => 'integer', 'description' => 'd']]), [], ['Property "10" has an integer-like name (PHP arrays turn it into an int key); rename it.', 'Property "-1" has an integer-like name (PHP arrays turn it into an int key); rename it.']];
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $dependsOn
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('errors')]
    public function reportsErrors(array $schema, array $dependsOn, array $expected): void
    {
        self::assertSame($expected, self::lint($schema, $dependsOn)['errors']);
    }

    #[Test]
    public function acceptsWhatTheRulesAllow(): void
    {
        $schema = self::schema([
            'tag'   => ['type' => 'string', 'maxLength' => 5, 'description' => 'd'],
            'token' => ['type' => 'string', 'maxLength' => 5, 'x-seed-secret' => false, 'description' => 'd'],
            'color' => ['type' => 'string', 'enum' => ['a'], 'x-seed-omit-means' => 'clear', 'description' => 'd'],
            'local' => ['$ref' => '#/$defs/x', 'description' => 'd'],
            'n'     => ['type' => 'integer', 'description' => 'd'],
            'obj'   => ['type' => 'object', 'additionalProperties' => false, 'properties' => [], 'description' => 'd'],
            '01'    => ['type' => 'integer', 'description' => 'leading zero is text'],
        ], [
            'x-seed-key'           => ['code', 'n'],
            'required'             => ['code', 'n'],
            'x-seed-key-normalise' => ['code' => 'lower', 'n' => 'nfc'],
            'x-seed-ref'           => ['tag' => 'demo.tags'],
            'examples'             => [['type' => 'object']],
            '$defs'                => ['x' => ['type' => 'string', 'const' => 'k']],
        ]);
        self::assertSame([], self::lint($schema, ['demo.tags'])['errors']);
    }

    #[Test]
    public function warnsAboutMissingDescriptionsAndUnboundedStrings(): void
    {
        $result = self::lint(self::schema([
            'free' => ['type' => 'string', 'description' => ''],
            'list' => ['type' => 'array', 'items' => ['type' => ['string', 'null']], 'description' => 'd'],
            'date' => ['type' => 'string', 'format' => 'date'],
        ]));
        self::assertSame([], $result['errors']);
        self::assertSame([
            '#/properties/free: string without "maxLength" (the reader cap applies).',
            '#/properties/list/items: string without "maxLength" (the reader cap applies).',
            'Property "free" has no description (an AI editing the file reads it).',
            'Property "date" has no description (an AI editing the file reads it).',
        ], $result['warnings']);
    }
}
