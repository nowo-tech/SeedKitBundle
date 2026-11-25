<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Testing;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Nowo\SeedKitBundle\Testing\SeedProviderContractTestCase;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\ScriptedProvider;

/**
 * A contract case over a provider that breaks the rules the kit checks: it accepts an "escalating" item and shows
 * every row to the restricted actor (keys to hide left at the default, so nothing is checked).
 */
final class LaxProbeContract extends SeedProviderContractTestCase
{
    private ?ScriptedProvider $provider = null;

    /**
     * The one provider instance of this case (its rows are the test "database").
     */
    public function provider(): ScriptedProvider
    {
        return $this->provider ??= new ScriptedProvider('demo.items', null, ['platform']);
    }

    protected function createProvider(): SeedProviderInterface
    {
        return $this->provider();
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
        return [['code' => 'a', 'label' => 'A']];
    }

    protected function escalatingItem(): array
    {
        return ['code' => 'admin', 'label' => 'grants everything'];
    }

    protected function restrictedActor(): ActorRef
    {
        return new ActorRef('bob');
    }
}
