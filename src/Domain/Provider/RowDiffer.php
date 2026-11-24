<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Provider;

use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Plan\FieldChange;
use Nowo\SeedKitBundle\Domain\Plan\FieldValueView;
use Nowo\SeedKitBundle\Domain\Plan\RowChange;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use Nowo\SeedKitBundle\Domain\Support\CanonicalJson;

use function array_key_exists;
use function array_keys;
use function hash;
use function str_replace;

/**
 * Builds the row change of one item from the current values of its row (plain PHP, usable by any provider; the
 * optional {@see AbstractSeedProvider} uses it). Compares top-level properties on their canonical JSON; an absent
 * property keeps the current value unless the schema says `x-seed-omit-means: clear`; read-only properties are never
 * compared. The current fingerprint is the SHA-256 of the canonical current values of the schema's writable
 * properties, so a concurrent change of any of them makes the reviewed plan stale.
 */
final class RowDiffer
{
    /**
     * The change for `$item`, given the current row values (null: the row does not exist).
     *
     * @param array<string, mixed>|null $current
     */
    public static function diff(SeedSchema $schema, SeedItem $item, ?array $current): RowChange
    {
        if ($current === null) {
            $fields = [];
            foreach ($item->data as $property => $value) {
                $fields[] = new FieldChange(self::pointer($property), FieldValueView::absent(), FieldValueView::value($value));
            }

            return RowChange::create($item->key, $item->index, $fields);
        }
        $fields = [];
        foreach (array_keys($schema->properties()) as $property) {
            if ($schema->isReadonly($property)) {
                continue;
            }
            $has = array_key_exists($property, $current) && $current[$property] !== null;
            if ($item->has($property)) {
                $after = $item->data[$property];
                if (!$has || CanonicalJson::encode($current[$property]) !== CanonicalJson::encode($after)) {
                    if ($has || $after !== null) {
                        $fields[] = new FieldChange(self::pointer($property), $has ? FieldValueView::value($current[$property]) : FieldValueView::absent(), FieldValueView::value($after));
                    }
                }
            } elseif ($has && $schema->omitClears($property)) {
                $fields[] = new FieldChange(self::pointer($property), FieldValueView::value($current[$property]), FieldValueView::absent());
            }
        }
        $fingerprint = self::fingerprint($schema, $current);

        return $fields === [] ? RowChange::unchanged($item->key, $item->index, $fingerprint) : RowChange::update($item->key, $item->index, $fields, $fingerprint);
    }

    /**
     * SHA-256 hex of the canonical writable current values.
     *
     * @param array<string, mixed> $current
     */
    public static function fingerprint(SeedSchema $schema, array $current): string
    {
        $kept = [];
        foreach (array_keys($schema->properties()) as $property) {
            if (!$schema->isReadonly($property) && array_key_exists($property, $current) && $current[$property] !== null) {
                $kept[$property] = $current[$property];
            }
        }

        return hash('sha256', $kept === [] ? '{}' : CanonicalJson::encode($kept));
    }

    /**
     * The JSON Pointer of a top-level property.
     */
    public static function pointer(string $property): string
    {
        return '/' . str_replace(['~', '/'], ['~0', '~1'], $property);
    }
}
