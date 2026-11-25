<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Domain\Provider;

use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\NaturalKeySet;
use Nowo\SeedKitBundle\Domain\Plan\PlanOptions;
use Nowo\SeedKitBundle\Domain\Plan\RowError;
use Nowo\SeedKitBundle\Domain\Provider\RowDiffer;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\ArticleProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\InMemoryStore;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\MinimalProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\TagProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The provider base class: defaults, examples for the empty template and the batched plan.
 */
final class AbstractSeedProviderTest extends TestCase
{
    private static function context(): SeedContext
    {
        return new SeedContext(SeedScope::platform(), new ActorRef('u'), Channel::Console);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function provider(array $schema): MinimalProvider
    {
        return new MinimalProvider($schema);
    }

    #[Test]
    public function defaults(): void
    {
        $provider = $this->provider(['properties' => ['code' => ['type' => 'string']]]);
        self::assertSame([], $provider->dependsOn());
        self::assertSame([], $provider->exportEmpty(self::context()));
        self::assertSame([], iterator_to_array((static fn () => yield from $provider->planDeletions(self::context(), new NaturalKeySet(), new PlanOptions()))()));
        $provider->afterBatch(self::context());
    }

    #[Test]
    public function exportEmptyKeepsOnlyObjectExamples(): void
    {
        $provider = $this->provider(['examples' => [['code' => 'a', 5 => 'x'], ['list'], 'scalar', []]]);
        self::assertSame([['code' => 'a', '5' => 'x']], $provider->exportEmpty(self::context()));
        self::assertSame([], $this->provider(['examples' => 'nope'])->exportEmpty(self::context()));
    }

    #[Test]
    public function planBatchDiffsAgainstCurrentRowsWithOneLookup(): void
    {
        $schema   = ['properties' => ['code' => ['type' => 'string'], 'label' => ['type' => 'string']]];
        $provider = $this->provider($schema);
        $items    = [
            new SeedItem(0, new NaturalKey(['a']), ['code' => 'a', 'label' => 'A']),
            new SeedItem(1, new NaturalKey(['b']), ['code' => 'b', 'label' => 'New']),
        ];
        $plan = $provider->planBatch(self::context(), $items, new PlanOptions());
        self::assertSame([['["a"]', '["b"]']], $provider->requested);
        self::assertSame([ChangeOp::Create, ChangeOp::Update], [$plan->changes[0]->op, $plan->changes[1]->op]);
        self::assertSame([], $plan->errors);
        self::assertSame(
            RowDiffer::fingerprint(new SeedSchema($schema), ['code' => 'b', 'label' => 'Old']),
            $provider->exposedFingerprint(['code' => 'b', 'label' => 'Old']),
        );
    }

    #[Test]
    public function planBatchCollectsValidationErrors(): void
    {
        $store    = new InMemoryStore();
        $provider = new TagProvider($store);
        $plan     = $provider->planBatch(self::context(), [new SeedItem(0, new NaturalKey(['a']), ['slug' => 'a', 'label' => 'A'])], new PlanOptions(true));
        self::assertSame(1, $store->lockedPlans);
        self::assertSame(ChangeOp::Create, $plan->changes[0]->op);

        $bob     = new SeedContext(SeedScope::parse('organization:o'), new ActorRef('bob'), Channel::Console);
        $article = new ArticleProvider($store);
        $plan    = $article->planBatch($bob, [
            new SeedItem(0, new NaturalKey(['a']), ['code' => 'a', 'title' => 'A', 'level' => 5]),
            new SeedItem(1, new NaturalKey(['b']), ['code' => 'b', 'title' => 'B', 'tag' => 'missing']),
        ], new PlanOptions());
        self::assertSame([[0, '/level', 'escalation'], [1, '/tag', 'reference']], array_map(
            static fn (RowError $error): array => [$error->index, $error->path, $error->rule],
            $plan->errors,
        ));
        self::assertCount(2, $plan->changes);
    }
}
