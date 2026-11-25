<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Provider;

use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Plan\AppliedBatch;
use Nowo\SeedKitBundle\Domain\Plan\BatchResult;
use Nowo\SeedKitBundle\Domain\Plan\PlanOptions;
use Nowo\SeedKitBundle\Domain\Provider\AbstractSeedProvider;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;

use function array_map;

/**
 * Fixture provider `demo.min` keeping every AbstractSeedProvider default; one stored row `b`.
 */
final class MinimalProvider extends AbstractSeedProvider
{
    /** @var list<list<string>> canonical keys asked per currentRows() call */
    public array $requested = [];

    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(private readonly array $schema)
    {
    }

    public function name(): ProviderName
    {
        return new ProviderName('demo.min');
    }

    public function version(): int
    {
        return 1;
    }

    public function scopes(): array
    {
        return [new ScopeKind('platform')];
    }

    public function schema(): SeedSchema
    {
        return new SeedSchema($this->schema);
    }

    public function exportCurrent(SeedContext $context): iterable
    {
        return [];
    }

    public function applyBatch(SeedContext $context, AppliedBatch $batch): BatchResult
    {
        return new BatchResult();
    }

    /**
     * Exposes the protected fingerprint helper.
     *
     * @param array<string, mixed> $current
     */
    public function exposedFingerprint(array $current): string
    {
        return $this->fingerprint($current);
    }

    protected function currentRows(SeedContext $context, array $keys, PlanOptions $options): array
    {
        $this->requested[] = array_map(static fn (NaturalKey $key): string => $key->canonical, $keys);

        return ['["b"]' => ['code' => 'b', 'label' => 'Old']];
    }
}
