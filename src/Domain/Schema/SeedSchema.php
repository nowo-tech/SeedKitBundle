<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Schema;

use Nowo\SeedKitBundle\Domain\Support\CanonicalJson;

use function array_keys;
use function array_values;
use function hash;
use function in_array;
use function is_array;
use function is_string;

/**
 * The JSON Schema of ONE item of a provider (draft 2020-12 subset + `x-seed-*` keywords, contract section 7). The
 * bundle wraps it in the document schema it publishes, validates items against it and reads the keywords:
 *
 * - item: `x-seed-key` (natural key properties), `x-seed-key-normalise` (`lower` / `nfc`), `x-seed-ref`
 *   (property → provider name, must be in `dependsOn`);
 * - property: `x-seed-secret`, `x-seed-personal-data`, `x-seed-readonly`, `x-seed-float`, `x-seed-omit-means`
 *   (`keep` default, `clear`).
 *
 * The constructor accepts any array; the application's `SchemaLinter` reports what is wrong (the
 * warmer, the `lint` command and the contract test kit run it). Immutable and stateless.
 */
final readonly class SeedSchema
{
    /** @var array<string, mixed> */
    public array $item;

    /**
     * @param array<string, mixed> $item the item schema
     */
    public function __construct(array $item)
    {
        $this->item = $item;
    }

    /**
     * The `title` of the item schema, or the fallback.
     */
    public function title(string $fallback): string
    {
        return is_string($this->item['title'] ?? null) && $this->item['title'] !== '' ? $this->item['title'] : $fallback;
    }

    /**
     * The top-level properties, in schema order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function properties(): array
    {
        $properties = [];
        if (is_array($this->item['properties'] ?? null)) {
            foreach ($this->item['properties'] as $name => $definition) {
                $properties[(string) $name] = is_array($definition) ? $this->stringKeys($definition) : [];
            }
        }

        return $properties;
    }

    /**
     * The required top-level properties.
     *
     * @return list<string>
     */
    public function required(): array
    {
        return $this->stringList($this->item['required'] ?? null);
    }

    /**
     * The natural key properties (`x-seed-key`), in key order.
     *
     * @return list<string>
     */
    public function keyProperties(): array
    {
        return $this->stringList($this->item['x-seed-key'] ?? null);
    }

    /**
     * The key normalisers (`x-seed-key-normalise`): property → `lower` | `nfc`.
     *
     * @return array<string, string>
     */
    public function keyNormalisers(): array
    {
        $normalisers = [];
        if (is_array($this->item['x-seed-key-normalise'] ?? null)) {
            foreach ($this->item['x-seed-key-normalise'] as $property => $mode) {
                if (is_string($mode)) {
                    $normalisers[(string) $property] = $mode;
                }
            }
        }

        return $normalisers;
    }

    /**
     * The references to other providers (`x-seed-ref`): property → provider name.
     *
     * @return array<string, string>
     */
    public function references(): array
    {
        $references = [];
        if (is_array($this->item['x-seed-ref'] ?? null)) {
            foreach ($this->item['x-seed-ref'] as $property => $provider) {
                if (is_string($provider)) {
                    $references[(string) $property] = $provider;
                }
            }
        }

        return $references;
    }

    /**
     * Whether the property is a secret (`x-seed-secret: true`).
     */
    public function isSecret(string $property): bool
    {
        return ($this->properties()[$property]['x-seed-secret'] ?? false) === true;
    }

    /**
     * Whether the property holds personal data (`x-seed-personal-data: true`).
     */
    public function isPersonalData(string $property): bool
    {
        return ($this->properties()[$property]['x-seed-personal-data'] ?? false) === true;
    }

    /**
     * Whether the property is exported for context only and ignored on import (`x-seed-readonly: true`).
     */
    public function isReadonly(string $property): bool
    {
        return ($this->properties()[$property]['x-seed-readonly'] ?? false) === true;
    }

    /**
     * Whether the property may hold floats (`x-seed-float: true`).
     */
    public function allowsFloat(string $property): bool
    {
        return ($this->properties()[$property]['x-seed-float'] ?? false) === true;
    }

    /**
     * Whether an absent property clears the stored value (`x-seed-omit-means: clear`); secrets always keep.
     */
    public function omitClears(string $property): bool
    {
        return !$this->isSecret($property) && ($this->properties()[$property]['x-seed-omit-means'] ?? 'keep') === 'clear';
    }

    /**
     * The secret properties.
     *
     * @return list<string>
     */
    public function secretProperties(): array
    {
        return $this->filter(fn (string $property): bool => $this->isSecret($property));
    }

    /**
     * The personal-data properties.
     *
     * @return list<string>
     */
    public function personalDataProperties(): array
    {
        return $this->filter(fn (string $property): bool => $this->isPersonalData($property));
    }

    /**
     * The read-only properties.
     *
     * @return list<string>
     */
    public function readonlyProperties(): array
    {
        return $this->filter(fn (string $property): bool => $this->isReadonly($property));
    }

    /**
     * Whether an export of this provider is sensitive (personal data or secrets: audited, Q-SK-11).
     */
    public function isSensitive(): bool
    {
        return $this->secretProperties() !== [] || $this->personalDataProperties() !== [];
    }

    /**
     * The property description, or ''.
     */
    public function description(string $property): string
    {
        $description = $this->properties()[$property]['description'] ?? null;

        return is_string($description) ? $description : '';
    }

    /**
     * Whether the property is required.
     */
    public function isRequired(string $property): bool
    {
        return in_array($property, $this->required(), true);
    }

    /**
     * SHA-256 hex of the canonical item schema: part of the plan hash, so a provider upgrade between the dry run and
     * the apply makes the plan stale.
     */
    public function fingerprint(): string
    {
        return hash('sha256', CanonicalJson::encode($this->item));
    }

    /**
     * The properties a predicate accepts, in schema order.
     *
     * @param callable(string): bool $accept
     *
     * @return list<string>
     */
    private function filter(callable $accept): array
    {
        $kept = [];
        foreach (array_keys($this->properties()) as $property) {
            $property = (string) $property;
            if ($accept($property)) {
                $kept[] = $property;
            }
        }

        return $kept;
    }

    /**
     * The strings of a list.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        $strings = [];
        foreach (is_array($value) ? array_values($value) : [] as $entry) {
            if (is_string($entry)) {
                $strings[] = $entry;
            }
        }

        return $strings;
    }

    /**
     * The array with string keys.
     *
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private function stringKeys(array $value): array
    {
        $out = [];
        foreach ($value as $key => $entry) {
            $out[(string) $key] = $entry;
        }

        return $out;
    }
}
