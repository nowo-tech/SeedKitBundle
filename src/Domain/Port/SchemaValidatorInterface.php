<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;

/**
 * Validates one normalised item against its provider schema (default: opis/json-schema, draft 2020-12, local `$ref`
 * only, no remote loader, Q-SK-2).
 */
interface SchemaValidatorInterface
{
    /**
     * The violations: JSON Pointer and rule keyword, never the value.
     *
     * @param array<string, mixed> $item
     *
     * @return list<array{path: string, rule: string}>
     */
    public function validate(SeedSchema $schema, array $item, int $maxErrors): array;
}
