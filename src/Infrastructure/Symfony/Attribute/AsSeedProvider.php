<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Attribute;

use Attribute;

/**
 * Registers a seed provider (autoconfiguration): the tag `nowo_seed_kit.provider` with its name and dependencies, so the
 * container can check names, dependencies and cycles and fix the order when it is compiled. The provider's `name()` and
 * `dependsOn()` must return the same values: the name is checked at runtime, the dependency order comes from the
 * attribute (keep both lists equal; the contract test kit and the lint use `dependsOn()` for references).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsSeedProvider
{
    /**
     * @param string $name the provider name (`crm.contacts`)
     * @param list<string> $dependsOn providers applied before this one
     */
    public function __construct(public string $name, public array $dependsOn = [])
    {
    }
}
