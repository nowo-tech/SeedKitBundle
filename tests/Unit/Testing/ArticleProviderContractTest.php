<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Testing;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Nowo\SeedKitBundle\Testing\SeedProviderContractTestCase;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\ArticleProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\InMemoryStore;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\TagProvider;

/**
 * The contract kit on the articles fixture (dependency, escalation refusal, row-level visibility).
 */
final class ArticleProviderContractTest extends SeedProviderContractTestCase
{
    private InMemoryStore $store;

    protected function setUp(): void
    {
        $this->store                                        = new InMemoryStore();
        $this->store->rows['demo.tags']['["news"]']         = ['slug' => 'news', 'label' => 'News'];
        $this->store->rows['demo.articles']['["secret-1"]'] = ['code' => 'secret-1', 'title' => 'Hidden', 'owner' => 'alice', 'level' => 0];
    }

    protected function createProvider(): SeedProviderInterface
    {
        return new ArticleProvider($this->store);
    }

    protected function dependencies(): array
    {
        return [new TagProvider($this->store)];
    }

    protected function scope(): SeedScope
    {
        return new SeedScope(new ScopeKind('organization'), new ScopeId('org-1'));
    }

    protected function actor(): ActorRef
    {
        return new ActorRef('bob');
    }

    protected function sampleItems(): array
    {
        return [['code' => 'a-1', 'title' => 'First', 'tag' => 'news', 'price' => 9.5, 'level' => 1, 'owner' => 'bob']];
    }

    protected function escalatingItem(): array
    {
        return ['code' => 'a-2', 'title' => 'Admin', 'level' => 5, 'owner' => 'bob'];
    }

    protected function restrictedActor(): ActorRef
    {
        return new ActorRef('bob');
    }

    protected function keysHiddenFromRestrictedActor(): array
    {
        return [new NaturalKey(['secret-1'])];
    }
}
