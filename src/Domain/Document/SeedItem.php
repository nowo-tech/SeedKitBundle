<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Document;

use Nowo\SeedKitBundle\Domain\Model\NaturalKey;

use function array_key_exists;

/**
 * One normalised, validated item: its 0-based position in the document, its natural key and its JSON-compatible data
 * (null, bool, int, string, list, map; floats only for `x-seed-float` properties). Read-only properties are already
 * removed and key normalisers applied. Secret properties hold `vault:` reference strings.
 */
final readonly class SeedItem
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(public int $index, public NaturalKey $key, public array $data)
    {
    }

    /**
     * Whether the item sets the property (an absent optional property keeps the current value).
     */
    public function has(string $property): bool
    {
        return array_key_exists($property, $this->data);
    }
}
