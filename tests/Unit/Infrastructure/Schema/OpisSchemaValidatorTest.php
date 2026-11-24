<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Infrastructure\Schema;

use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use Nowo\SeedKitBundle\Infrastructure\Schema\OpisSchemaValidator;
use Opis\JsonSchema\Exceptions\UnresolvedReferenceException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function count;
use function ini_get;
use function ini_set;
use function str_repeat;

/**
 * The default schema validator (Q-SK-2): leaf errors as JSON Pointer + keyword, caps, the compiled-schema memo, the
 * scoped backtrack limit and no reference resolution outside the schema.
 */
final class OpisSchemaValidatorTest extends TestCase
{
    #[Test]
    public function acceptsAValidItem(): void
    {
        self::assertSame([], (new OpisSchemaValidator())->validate(self::schema(), ['name' => 'abc', 'email' => 'a@b', 'tags' => ['ok']], 200));
    }

    #[Test]
    public function reportsEveryLeafErrorWithItsEscapedJsonPointer(): void
    {
        $violations = (new OpisSchemaValidator())->validate(
            self::schema(),
            ['name' => 'abcd', 'a/b' => 'x', 't~x' => 'y', 'tags' => ['ok', 'BAD'], 'nested' => ['deep' => 's'], 'extra' => 1],
            200,
        );

        self::assertSame([
            ['path' => '', 'rule' => 'required'],
            ['path' => '/name', 'rule' => 'maxLength'],
            ['path' => '/a~1b', 'rule' => 'type'],
            ['path' => '/t~0x', 'rule' => 'type'],
            ['path' => '/tags/1', 'rule' => 'pattern'],
            ['path' => '/nested/deep', 'rule' => 'type'],
            ['path' => '', 'rule' => 'additionalProperties'],
        ], $violations);
    }

    #[Test]
    public function neverReportsTheValue(): void
    {
        $violations = (new OpisSchemaValidator())->validate(self::schema(), ['name' => 'secret-value-123'], 200);

        self::assertStringNotContainsString('secret-value-123', (string) json_encode($violations));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function caps(): iterable
    {
        yield 'one' => [1, 1];
        yield 'two' => [2, 2];
        yield 'more than the errors' => [50, 7];
    }

    #[Test]
    #[DataProvider('caps')]
    public function capsTheErrors(int $max, int $expected): void
    {
        $violations = (new OpisSchemaValidator())->validate(
            self::schema(),
            ['name' => 'abcd', 'a/b' => 'x', 't~x' => 'y', 'tags' => ['ok', 'BAD'], 'nested' => ['deep' => 's'], 'extra' => 1],
            $max,
        );

        self::assertCount($expected, $violations);
    }

    #[Test]
    public function capsTheLeavesOfOneCombinedError(): void
    {
        $schema = new SeedSchema(['type' => 'object', 'properties' => ['p' => ['anyOf' => [['type' => 'string'], ['type' => 'integer'], ['type' => 'boolean']]]]]);
        $item   = ['p' => [1]];

        self::assertSame([['path' => '/p', 'rule' => 'type']], (new OpisSchemaValidator())->validate($schema, $item, 1));
        self::assertSame(
            [['path' => '/p', 'rule' => 'type'], ['path' => '/p', 'rule' => 'type'], ['path' => '/p', 'rule' => 'type']],
            (new OpisSchemaValidator())->validate($schema, $item, 10),
        );
    }

    #[Test]
    public function reportsARootErrorWithAnEmptyPointer(): void
    {
        self::assertSame([['path' => '', 'rule' => 'type']], (new OpisSchemaValidator())->validate(new SeedSchema(['type' => 'string']), [], 5));
    }

    #[Test]
    public function validatesFormatsAndKeepsJsonStringsUndecoded(): void
    {
        $validator = new OpisSchemaValidator();

        self::assertSame([['path' => '/p', 'rule' => 'format']], $validator->validate(new SeedSchema(['properties' => ['p' => ['type' => 'string', 'format' => 'email']]]), ['p' => 'x'], 5));
        self::assertSame([['path' => '/p', 'rule' => 'type']], $validator->validate(new SeedSchema(['properties' => ['p' => ['type' => 'object']]]), ['p' => '{"q":1}'], 5));
    }

    #[Test]
    public function resolvesLocalReferences(): void
    {
        $schema = new SeedSchema(['$defs' => ['n' => ['type' => 'integer']], 'type' => 'object', 'properties' => ['p' => ['$ref' => '#/$defs/n']]]);

        self::assertSame([['path' => '/p', 'rule' => 'type']], (new OpisSchemaValidator())->validate($schema, ['p' => 'x'], 5));
        self::assertSame([], (new OpisSchemaValidator())->validate($schema, ['p' => 1], 5));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outsideReferences(): iterable
    {
        yield 'https' => ['https://example.com/schema.json'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'relative file' => ['other.json'];
        yield 'missing local definition' => ['#/$defs/missing'];
    }

    #[Test]
    #[DataProvider('outsideReferences')]
    public function neverResolvesReferencesOutsideTheSchema(string $ref): void
    {
        $this->expectException(UnresolvedReferenceException::class);

        (new OpisSchemaValidator())->validate(new SeedSchema(['type' => 'object', 'properties' => ['p' => ['$ref' => $ref]]]), ['p' => 1], 5);
    }

    #[Test]
    public function restoresTheBacktrackLimitAfterAPattern(): void
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '777777');

        try {
            $schema = new SeedSchema(['type' => 'object', 'properties' => ['p' => ['type' => 'string', 'pattern' => '^(a+)+$']]]);

            $hostile = (new OpisSchemaValidator())->validate($schema, ['p' => str_repeat('a', 40) . 'b'], 5);

            self::assertSame([['path' => '/p', 'rule' => 'pattern']], $hostile);
            self::assertSame('777777', ini_get('pcre.backtrack_limit'));
            self::assertSame([], (new OpisSchemaValidator())->validate($schema, ['p' => 'aaa'], 5));
            self::assertSame('777777', ini_get('pcre.backtrack_limit'));
        } finally {
            ini_set('pcre.backtrack_limit', $previous === false ? '1000000' : $previous);
        }
    }

    #[Test]
    public function compilesEachSchemaOnceAndForgetsThemOnReset(): void
    {
        $validator = new OpisSchemaValidator();
        $schema    = self::schema();

        $validator->validate($schema, ['name' => 'a'], 5);
        $validator->validate(new SeedSchema(self::schema()->item), ['name' => 'b'], 5);
        self::assertSame(1, self::compiledCount($validator));
        $validator->validate(new SeedSchema(['type' => 'object']), [], 5);
        self::assertSame(2, self::compiledCount($validator));

        $validator->reset();

        self::assertSame(0, self::compiledCount($validator));
        self::assertSame([['path' => '/name', 'rule' => 'maxLength']], $validator->validate($schema, ['name' => 'abcd', 'email' => 'a@b'], 5));
        self::assertSame(1, self::compiledCount($validator));
    }

    #[Test]
    public function evictsTheMemoWhenItIsFull(): void
    {
        $validator = new OpisSchemaValidator();
        for ($i = 0; $i < OpisSchemaValidator::MAX_SCHEMAS; ++$i) {
            $validator->validate(new SeedSchema(['type' => 'object', 'maxProperties' => $i + 1]), [], 5);
        }
        self::assertSame(OpisSchemaValidator::MAX_SCHEMAS, self::compiledCount($validator));

        $violations = $validator->validate(new SeedSchema(['type' => 'object', 'maxProperties' => 0]), ['a' => 1], 5);

        self::assertSame(1, self::compiledCount($validator));
        self::assertSame([['path' => '', 'rule' => 'maxProperties']], $violations);
    }

    private static function schema(): SeedSchema
    {
        return new SeedSchema([
            'type'       => 'object',
            'required'   => ['name', 'email'],
            'properties' => [
                'name'   => ['type' => 'string', 'maxLength' => 3],
                'email'  => ['type' => 'string'],
                'a/b'    => ['type' => 'integer'],
                't~x'    => ['type' => 'integer'],
                'tags'   => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[a-z]+$']],
                'nested' => ['type' => 'object', 'properties' => ['deep' => ['type' => 'integer']]],
            ],
            'additionalProperties' => false,
        ]);
    }

    private static function compiledCount(OpisSchemaValidator $validator): int
    {
        $compiled = (new ReflectionProperty(OpisSchemaValidator::class, 'compiled'))->getValue($validator);
        self::assertIsArray($compiled);

        return count($compiled);
    }
}
