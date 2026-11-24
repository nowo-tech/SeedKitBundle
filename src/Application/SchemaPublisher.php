<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;

use function is_array;

/**
 * The document schema a provider publishes (contract section 7.1): the item schema wrapped in the envelope (provider,
 * version, scope kinds, meta, items); the item's `$defs` move to the document's `$defs` (`item` is reserved). An AI or an editor validates a whole file against it. Stateless.
 */
final readonly class SchemaPublisher
{
    /**
     * Builds the publisher.
     */
    public function __construct(private int $maxItems = 10000)
    {
    }

    /**
     * The document schema.
     *
     * @return array<string, mixed>
     */
    public function documentSchema(SeedProviderInterface $provider): array
    {
        $name   = $provider->name()->value;
        $schema = $provider->schema();
        $item   = $schema->item;
        $defs   = is_array($item['$defs'] ?? null) ? $item['$defs'] : [];
        unset($item['$defs']);
        $kinds = [];
        foreach ($provider->scopes() as $kind) {
            $kinds[] = $kind->value;
        }

        return [
            '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
            '$id'                  => 'urn:nowo-seed:' . $name . ':' . $provider->version(),
            'title'                => $schema->title($name) . ' (seed document, version ' . $provider->version() . ')',
            'type'                 => 'object',
            'required'             => ['provider', 'version', 'scope', 'items'],
            'additionalProperties' => false,
            'properties'           => [
                '$schema'  => ['type' => 'string', 'maxLength' => 255],
                'provider' => ['const' => $name],
                'version'  => ['const' => $provider->version()],
                'scope'    => ['enum' => $kinds],
                'meta'     => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'exportedAt'   => ['type' => 'string', 'format' => 'date-time'],
                    'exportedFrom' => ['type' => 'string', 'maxLength' => 128],
                    'generator'    => ['type' => 'string', 'maxLength' => 64],
                ]],
                'items' => ['type' => 'array', 'maxItems' => $this->maxItems, 'items' => ['$ref' => '#/$defs/item']],
            ],
            // The item's own $defs are hoisted, so its local "#/$defs/..." references resolve in the document too.
            '$defs' => ['item' => $item] + $defs,
        ];
    }
}
