<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Nowo\SeedKitBundle\Domain\Exception\ProviderContractException;
use Nowo\SeedKitBundle\Domain\Exception\UnknownProviderException;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Psr\Container\ContainerInterface;

use function array_flip;
use function in_array;
use function usort;

/**
 * The registered providers (lazy, through a service locator) and their dependency order, fixed when the container was
 * compiled (Kahn's algorithm, ties by name byte order; cycles fail the build). Holds configuration only.
 */
final readonly class ProviderRegistry
{
    /** @var array<string, int> */
    private array $rank;

    /**
     * @param ContainerInterface $providers provider name → service
     * @param list<string> $order every provider name in dependency order
     */
    public function __construct(private ContainerInterface $providers, private array $order)
    {
        $this->rank = array_flip($order);
    }

    /**
     * Every provider name, in dependency order.
     *
     * @return list<ProviderName>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->order as $name) {
            $names[] = new ProviderName($name);
        }

        return $names;
    }

    /**
     * Whether the provider is registered.
     */
    public function has(ProviderName $name): bool
    {
        return isset($this->rank[$name->value]);
    }

    /**
     * The provider.
     *
     * @throws UnknownProviderException
     * @throws ProviderContractException when the service is not a provider or its name() differs from its tag
     */
    public function get(ProviderName $name): SeedProviderInterface
    {
        if (!$this->has($name)) {
            throw new UnknownProviderException();
        }
        $provider = $this->providers->get($name->value);
        if (!$provider instanceof SeedProviderInterface || $provider->name()->value !== $name->value) {
            throw new ProviderContractException();
        }

        return $provider;
    }

    /**
     * The provider when it is available in the scope kind.
     *
     * @throws UnknownProviderException
     */
    public function getForKind(ProviderName $name, ScopeKind $kind): SeedProviderInterface
    {
        $provider = $this->get($name);
        if (!in_array($kind->value, $this->kinds($provider), true)) {
            throw new UnknownProviderException();
        }

        return $provider;
    }

    /**
     * The providers available in a kind (every provider for null), in dependency order.
     *
     * @return list<SeedProviderInterface>
     */
    public function all(?ScopeKind $kind = null): array
    {
        $providers = [];
        foreach ($this->names() as $name) {
            $provider = $this->get($name);
            if (!$kind instanceof ScopeKind || in_array($kind->value, $this->kinds($provider), true)) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * Sorts names by dependency order.
     *
     * @param list<ProviderName> $names
     *
     * @return list<ProviderName>
     */
    public function sort(array $names): array
    {
        usort($names, fn (ProviderName $a, ProviderName $b): int => ($this->rank[$a->value] ?? 0) <=> ($this->rank[$b->value] ?? 0));

        return $names;
    }

    /**
     * The kind names of a provider.
     *
     * @return list<string>
     */
    private function kinds(SeedProviderInterface $provider): array
    {
        $kinds = [];
        foreach ($provider->scopes() as $kind) {
            $kinds[] = $kind->value;
        }

        return $kinds;
    }
}
