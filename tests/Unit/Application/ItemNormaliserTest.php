<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Application;

use Nowo\SeedKitBundle\Application\ItemNormaliser;
use Nowo\SeedKitBundle\Application\SeedKitSettings;
use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;
use Nowo\SeedKitBundle\Domain\Plan\RowError;
use Nowo\SeedKitBundle\Domain\Port\SchemaValidatorInterface;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\Harness;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\PrefixSecretValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

use const INF;
use const NAN;

/**
 * Raw item → SeedItem or row errors (contract 7, 8, 13.2, 13.3 structure budget).
 */
final class ItemNormaliserTest extends TestCase
{
    private function schema(): SeedSchema
    {
        return new SeedSchema([
            'type'                 => 'object',
            'required'             => ['email'],
            'additionalProperties' => false,
            'x-seed-key'           => ['email', 'n'],
            'x-seed-key-normalise' => ['email' => 'lower', 'n' => 'lower', 'name' => 'nfc', 'other' => 'upper'],
            'properties'           => [
                'email'   => ['type' => 'string', 'maxLength' => 64],
                'n'       => ['type' => 'integer'],
                'name'    => ['type' => 'string'],
                'other'   => ['type' => 'string'],
                'price'   => ['type' => 'number', 'x-seed-float' => true],
                'tags'    => ['type' => 'array'],
                'token'   => ['type' => ['string', 'null'], 'x-seed-secret' => true],
                'created' => ['type' => 'string', 'x-seed-readonly' => true],
            ],
        ]);
    }

    private function normaliser(?SeedKitSettings $settings = null, bool $withSecrets = true): ItemNormaliser
    {
        return Harness::normaliser($settings ?? Harness::settings(), $withSecrets ? new PrefixSecretValidator() : null);
    }

    /**
     * @param list<RowError>|SeedItem $result
     *
     * @return list<string>
     */
    private function rules(array|SeedItem $result): array
    {
        self::assertIsArray($result);

        return array_map(static fn (RowError $e): string => $e->rule . '@' . $e->path, $result);
    }

    #[Test]
    public function normalisesAValidItem(): void
    {
        $item = $this->normaliser()->normalise($this->schema(), 4, [
            'email'   => 'Bob@Example.COM',
            'n'       => 3,
            'name'    => "Jose\u{0301}",
            'other'   => 'Keep',
            'price'   => 9.5,
            'token'   => 'vault:ok-1',
            'created' => 'ignored',
        ], Harness::context());
        self::assertInstanceOf(SeedItem::class, $item);
        self::assertSame(4, $item->index);
        self::assertSame('["bob@example.com",3]', $item->key->canonical);
        self::assertSame("Jos\u{00E9}", $item->data['name'], 'nfc normaliser');
        self::assertSame('Keep', $item->data['other'], 'unknown normaliser leaves the value');
        self::assertSame(9.5, $item->data['price']);
        self::assertSame('vault:ok-1', $item->data['token']);
        self::assertFalse($item->has('created'), 'read-only dropped on import');
    }

    #[Test]
    public function aNullSecretIsDropped(): void
    {
        $item = $this->normaliser()->normalise($this->schema(), 0, ['email' => 'a@b', 'n' => 1, 'token' => null], Harness::context());
        self::assertInstanceOf(SeedItem::class, $item);
        self::assertFalse($item->has('token'), 'absent secret keeps the stored value');
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function invalidItems(): iterable
    {
        yield 'scalar' => ['x', ['type@']];
        yield 'list' => [[1, 2], ['type@']];
        yield 'float elsewhere' => [['email' => 'a', 'n' => 1.5], ['float_not_allowed@/n']];
        yield 'nested float' => [['email' => 'a', 'n' => 1, 'tags' => [1, [2.5]]], ['float_not_allowed@/tags']];
        yield 'NaN price' => [['email' => 'a', 'n' => 1, 'price' => NAN], ['float_not_allowed@/price']];
        yield 'INF price' => [['email' => 'a', 'n' => 1, 'price' => INF], ['float_not_allowed@/price']];
        yield 'schema' => [['email' => 'a', 'n' => 'x'], ['type@/n']];
        yield 'missing key' => [['email' => 'a'], ['key_invalid@']];
        yield 'inexact key' => [['email' => 'a ', 'n' => 1], ['key_invalid@']];
        yield 'plaintext' => [['email' => 'a', 'n' => 1, 'token' => 'hunter2'], ['secret_plaintext@/token']];
        yield 'not a string' => [['email' => 'a', 'n' => 1, 'token' => 5], ['type@/token', 'secret_plaintext@/token']];
        yield 'refused ref' => [['email' => 'a', 'n' => 1, 'token' => 'vault:nope'], ['secret_refused@/token']];
    }

    /**
     * @param list<string> $rules
     */
    #[Test]
    #[DataProvider('invalidItems')]
    public function reportsRowErrors(mixed $raw, array $rules): void
    {
        $result = $this->normaliser()->normalise($this->schema(), 2, $raw, Harness::context());
        self::assertSame($rules, $this->rules($result));
        self::assertIsArray($result);
        foreach ($result as $error) {
            self::assertSame(2, $error->index);
            self::assertSame([], $error->messageParams);
        }
    }

    #[Test]
    public function emptyObjectIsAnObject(): void
    {
        $result = $this->normaliser()->normalise($this->schema(), 0, [], Harness::context());
        // `{}` decodes to [] and reaches the validator as an empty JSON array: refused as a type error (an item
        // always needs its required key properties anyway)
        self::assertSame(['type@'], $this->rules($result));
        self::assertIsArray($result);
        self::assertSame('seed_kit.row.schema_type', $result[0]->messageKey);
    }

    #[Test]
    public function schemaErrorsCarryTheKey(): void
    {
        $result = $this->normaliser()->normalise($this->schema(), 0, ['email' => 'A', 'n' => 1, 'bogus' => 1], Harness::context());
        self::assertIsArray($result);
        self::assertSame('["a",1]', $result[0]->key?->canonical);
    }

    #[Test]
    public function withoutAValidatorEveryReferenceIsRefused(): void
    {
        $result = $this->normaliser(withSecrets: false)->normalise($this->schema(), 0, ['email' => 'a', 'n' => 1, 'token' => 'vault:ok-1'], Harness::context());
        self::assertSame(['secret_refused@/token'], $this->rules($result));
    }

    #[Test]
    public function integerKeysOfTheItemAreText(): void
    {
        $schema = new SeedSchema(['type' => 'object', 'x-seed-key' => ['email'], 'properties' => ['email' => ['type' => 'string']]]);
        $stub   = new class implements SchemaValidatorInterface {
            public function validate(SeedSchema $schema, array $item, int $maxErrors): array
            {
                return [];
            }
        };
        $item = (new ItemNormaliser($stub, Harness::settings()))->normalise($schema, 0, ['email' => 'a', 7 => 'x'], Harness::context());
        self::assertInstanceOf(SeedItem::class, $item);
        self::assertSame(['email' => 'a', '7' => 'x'], $item->data);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function budgetBreaks(): iterable
    {
        yield 'nodes' => [['email' => 'a', 'tags' => range(1, 20)], 'too_many_nodes'];
        yield 'string' => [['email' => str_repeat('x', 33)], 'string_too_long'];
        yield 'key string' => [['email' => 'a', str_repeat('k', 33) => 1], 'string_too_long'];
        yield 'deep' => [['email' => 'a', 'tags' => [[[[1]]]]], 'too_deep'];
        yield 'properties' => [['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5, 'f' => 6], 'too_many_properties'];
        yield 'object' => [['email' => new stdClass()], 'unsupported_value'];
    }

    #[Test]
    #[DataProvider('budgetBreaks')]
    public function enforcesTheStructureBudget(mixed $raw, string $reason): void
    {
        $settings = new SeedKitSettings(maxDepth: 4, maxNodes: 15, maxStringBytes: 32, maxProperties: 5);
        try {
            $this->normaliser($settings)->normalise($this->schema(), 0, $raw, Harness::context());
            self::fail('expected');
        } catch (DocumentReadException $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame('seed_kit.read.' . $reason, $e->messageKey());
        }
    }

    #[Test]
    public function listsMayBeLongerThanThePropertyCap(): void
    {
        $settings = new SeedKitSettings(maxProperties: 2);
        $result   = $this->normaliser($settings)->normalise($this->schema(), 0, ['email' => 'a', 'n' => 1], Harness::context());
        self::assertInstanceOf(SeedItem::class, $result);
        $result = $this->normaliser($settings)->normalise(new SeedSchema(['type' => 'object', 'x-seed-key' => ['e'], 'properties' => ['e' => ['type' => 'string'], 't' => ['type' => 'array']]]), 0, ['e' => 'a', 't' => [1, 2, 3, 4]], Harness::context());
        self::assertInstanceOf(SeedItem::class, $result);
    }
}
