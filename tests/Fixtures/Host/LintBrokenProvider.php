<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Host;

use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Plan\AppliedBatch;
use Nowo\SeedKitBundle\Domain\Plan\BatchResult;
use Nowo\SeedKitBundle\Domain\Plan\PlanOptions;
use Nowo\SeedKitBundle\Domain\Provider\AbstractSeedProvider;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;

/**
 * A provider whose schema fails the lint (no `x-seed-key`, a token-like property without `x-seed-secret`).
 */
final class LintBrokenProvider extends AbstractSeedProvider
{
    public function name(): ProviderName
    {
        return new ProviderName('host.broken');
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
        return new SeedSchema([
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => ['token' => ['type' => 'string', 'maxLength' => 10, 'description' => 'Token']],
        ]);
    }

    public function exportCurrent(SeedContext $context): iterable
    {
        return [];
    }

    public function applyBatch(SeedContext $context, AppliedBatch $batch): BatchResult
    {
        return new BatchResult();
    }

    protected function currentRows(SeedContext $context, array $keys, PlanOptions $options): array
    {
        return [];
    }
}
