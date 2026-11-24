<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Domain\Schema;

use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The item schema accessors and the `x-seed-*` keywords (contract section 7).
 */
final class SeedSchemaTest extends TestCase
{
    private static function schema(): SeedSchema
    {
        return new SeedSchema([
            'title'                => 'Contacts',
            'required'             => ['email', 7, 'name'],
            'x-seed-key'           => ['email', false],
            'x-seed-key-normalise' => ['email' => 'lower', 'name' => 3],
            'x-seed-ref'           => ['company' => 'crm.companies', 'bad' => ['x']],
            'properties'           => [
                'email'   => ['type' => 'string', 'x-seed-personal-data' => true, 'description' => 'E-mail'],
                'name'    => ['type' => 'string', 'description' => 7],
                'token'   => ['type' => 'string', 'x-seed-secret' => true, 'x-seed-omit-means' => 'clear'],
                'price'   => ['type' => 'number', 'x-seed-float' => true],
                'note'    => ['type' => 'string', 'x-seed-omit-means' => 'clear'],
                'created' => ['type' => 'string', 'x-seed-readonly' => true],
                'company' => ['type' => 'string', 'x-seed-secret' => 'yes'],
                'broken'  => 'not an array',
                3         => [0 => 'int key'],
            ],
        ]);
    }

    #[Test]
    public function readsTheKeywords(): void
    {
        $schema = self::schema();
        self::assertSame('Contacts', $schema->title('fallback'));
        self::assertSame(['email', 'name', 'token', 'price', 'note', 'created', 'company', 'broken', '3'], array_map('strval', array_keys($schema->properties())));
        self::assertSame([], $schema->properties()['broken']);
        self::assertSame(['0' => 'int key'], array_values($schema->properties())[8]);
        self::assertSame(['email', 'name'], $schema->required());
        self::assertSame(['email'], $schema->keyProperties());
        self::assertSame(['email' => 'lower'], $schema->keyNormalisers());
        self::assertSame(['company' => 'crm.companies'], $schema->references());

        self::assertTrue($schema->isSecret('token'));
        self::assertFalse($schema->isSecret('company'));
        self::assertFalse($schema->isSecret('missing'));
        self::assertTrue($schema->isPersonalData('email'));
        self::assertTrue($schema->isReadonly('created'));
        self::assertTrue($schema->allowsFloat('price'));
        self::assertFalse($schema->allowsFloat('name'));
        self::assertTrue($schema->omitClears('note'));
        self::assertFalse($schema->omitClears('token'), 'secrets always keep');
        self::assertFalse($schema->omitClears('name'));

        self::assertSame(['token'], $schema->secretProperties());
        self::assertSame(['email'], $schema->personalDataProperties());
        self::assertSame(['created'], $schema->readonlyProperties());
        self::assertTrue($schema->isSensitive());

        self::assertSame('E-mail', $schema->description('email'));
        self::assertSame('', $schema->description('name'));
        self::assertSame('', $schema->description('missing'));
        self::assertTrue($schema->isRequired('email'));
        self::assertFalse($schema->isRequired('note'));
    }

    #[Test]
    public function toleratesAMalformedSchema(): void
    {
        $schema = new SeedSchema([
            'title'                => '',
            'properties'           => 'nope',
            'required'             => 'email',
            'x-seed-key'           => null,
            'x-seed-key-normalise' => 'lower',
            'x-seed-ref'           => 'x',
        ]);
        self::assertSame('fallback', $schema->title('fallback'));
        self::assertSame('fallback', (new SeedSchema(['title' => 5]))->title('fallback'));
        self::assertSame([], $schema->properties());
        self::assertSame([], $schema->required());
        self::assertSame([], $schema->keyProperties());
        self::assertSame([], $schema->keyNormalisers());
        self::assertSame([], $schema->references());
        self::assertFalse($schema->isSensitive());
    }

    #[Test]
    public function sensitiveWithSecretsOnly(): void
    {
        self::assertTrue((new SeedSchema(['properties' => ['t' => ['x-seed-secret' => true]]]))->isSensitive());
        self::assertFalse((new SeedSchema(['properties' => ['t' => ['type' => 'string']]]))->isSensitive());
    }

    #[Test]
    public function fingerprintIsCanonical(): void
    {
        $a = new SeedSchema(['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]);
        $b = new SeedSchema(['properties' => ['a' => ['type' => 'string']], 'type' => 'object']);
        self::assertSame($a->fingerprint(), $b->fingerprint());
        self::assertSame(hash('sha256', '{"properties":{"a":{"type":"string"}},"type":"object"}'), $a->fingerprint());
        self::assertNotSame($a->fingerprint(), (new SeedSchema(['type' => 'object']))->fingerprint());
    }
}
