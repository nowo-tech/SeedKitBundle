<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Testing;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SecretReferenceValidatorInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Nowo\SeedKitBundle\Testing\SeedProviderContractTestCase;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\InMemoryStore;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\PrefixSecretValidator;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\TagProvider;

/**
 * The contract kit on the tags fixture (secrets, platform scope, no visibility, no escalation).
 */
final class TagProviderContractTest extends SeedProviderContractTestCase
{
    private InMemoryStore $store;

    protected function setUp(): void
    {
        $this->store                                = new InMemoryStore();
        $this->store->rows['demo.tags']['["kept"]'] = ['slug' => 'kept', 'label' => 'Kept', 'apiToken' => 'vault:other-scope', 'createdAt' => '2026-01-01'];
    }

    protected function createProvider(): SeedProviderInterface
    {
        return new TagProvider($this->store);
    }

    protected function scope(): SeedScope
    {
        return SeedScope::platform();
    }

    protected function actor(): ActorRef
    {
        return new ActorRef('alice');
    }

    protected function sampleItems(): array
    {
        return [
            ['slug' => 'News', 'label' => 'News', 'color' => 'red', 'apiToken' => 'vault:ok-1'],
            ['slug' => 'sport', 'label' => 'Sport'],
        ];
    }

    protected function secretValidator(): SecretReferenceValidatorInterface
    {
        return new PrefixSecretValidator();
    }

    protected function knownSecretValues(): array
    {
        return ['vault:other-scope'];
    }
}
