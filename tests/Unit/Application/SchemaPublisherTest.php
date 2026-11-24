<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Application;

use Nowo\SeedKitBundle\Application\SchemaPublisher;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use Nowo\SeedKitBundle\Infrastructure\Schema\OpisSchemaValidator;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\ScriptedProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\InMemoryStore;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\TagProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The published document schema (contract 7.1).
 */
final class SchemaPublisherTest extends TestCase
{
    #[Test]
    public function wrapsTheItemSchema(): void
    {
        $tags   = new TagProvider(new InMemoryStore());
        $schema = (new SchemaPublisher(50))->documentSchema($tags);
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        self::assertSame('urn:nowo-seed:demo.tags:1', $schema['$id']);
        self::assertSame('Tags (seed document, version 1)', $schema['title']);
        self::assertSame(['provider', 'version', 'scope', 'items'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        $properties = $schema['properties'];
        self::assertIsArray($properties);
        self::assertSame(['const' => 'demo.tags'], $properties['provider']);
        self::assertSame(['const' => 1], $properties['version']);
        self::assertSame(['enum' => ['platform', 'organization']], $properties['scope']);
        self::assertSame(['type' => 'array', 'maxItems' => 50, 'items' => ['$ref' => '#/$defs/item']], $properties['items']);
        self::assertSame(['item' => $tags->schema()->item], $schema['$defs']);
    }

    #[Test]
    public function hoistsTheItemDefinitions(): void
    {
        $provider = new ScriptedProvider('demo.items', [
            'type'       => 'object',
            'x-seed-key' => ['code'],
            'properties' => ['code' => ['$ref' => '#/$defs/code']],
            '$defs'      => ['code' => ['type' => 'string', 'maxLength' => 3]],
        ], ['platform']);
        $schema = (new SchemaPublisher())->documentSchema($provider);
        self::assertSame('demo.items (seed document, version 1)', $schema['title']);
        $defs = $schema['$defs'];
        self::assertIsArray($defs);
        self::assertSame(['item', 'code'], array_keys($defs));
        self::assertSame(['type' => 'string', 'maxLength' => 3], $defs['code']);
        self::assertIsArray($defs['item']);
        self::assertArrayNotHasKey('$defs', $defs['item']);
        $properties = $schema['properties'];
        self::assertIsArray($properties);
        self::assertIsArray($properties['items']);
        self::assertSame(10000, $properties['items']['maxItems']);

        // the hoisted references resolve: a document validates against the published schema
        $validator = new OpisSchemaValidator();
        $document  = ['provider' => 'demo.items', 'version' => 1, 'scope' => 'platform', 'items' => [['code' => 'abc']]];
        self::assertSame([], $validator->validate(new SeedSchema($schema), $document, 10));
        $document['items'][0]['code'] = 'abcd';
        self::assertNotSame([], $validator->validate(new SeedSchema($schema), $document, 10));
    }
}
