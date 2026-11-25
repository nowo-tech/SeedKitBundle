<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Testing;

use Nowo\SeedKitBundle\Application\Planner;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Nowo\SeedKitBundle\Testing\SeedProviderContractTestCase;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\ScriptedProvider;

/**
 * A contract case that keeps every optional hook at its default (not discovered: the unit tests drive it directly).
 */
final class DefaultsProbeContract extends SeedProviderContractTestCase
{
    private ?ScriptedProvider $provider = null;

    /**
     * The one provider instance of this case (its rows are the test "database").
     */
    public function provider(): ScriptedProvider
    {
        return $this->provider ??= new ScriptedProvider('demo.items', null, ['platform']);
    }

    /**
     * Exposes the protected export helper.
     */
    public function exportAs(ActorRef $actor): string
    {
        return $this->export($actor);
    }

    /**
     * Exposes the protected read helper.
     */
    public function readYaml(string $yaml): DocumentSet
    {
        return $this->read($yaml);
    }

    /**
     * Exposes the protected planner.
     */
    public function exposedPlanner(): Planner
    {
        return $this->planner();
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
}
