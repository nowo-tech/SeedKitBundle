<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Application;

use DateTimeImmutable;
use Nowo\SeedKitBundle\Application\RunMaintenance;
use Nowo\SeedKitBundle\Application\SeedKitSettings;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\SeedKitBundle\Testing\Support\InMemoryRunStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function sprintf;

/**
 * Run history: list, purge (dry run and real), forget an actor; plus the settings helpers.
 */
final class RunMaintenanceTest extends TestCase
{
    private function record(int $n, string $created, RunStatus $status, ?string $actor = 'alice'): RunRecord
    {
        return new RunRecord(
            new RunId(sprintf('00000000-0000-7000-8000-%012d', $n)),
            SeedScope::platform(),
            $actor !== null ? new ActorRef($actor) : null,
            Channel::Console,
            $status,
            ['demo.tags' => 1],
            false,
            null,
            new PlanHash('sk1:' . str_repeat('a', 64)),
            [],
            0,
            [],
            null,
            new DateTimeImmutable($created),
        );
    }

    #[Test]
    public function maintainsTheHistory(): void
    {
        $store = new InMemoryRunStore();
        $store->create($this->record(1, '2024-01-01', RunStatus::Applied));
        $store->create($this->record(2, '2024-01-02', RunStatus::Running, 'bob'));
        $store->create($this->record(3, '2026-11-01', RunStatus::Failed));
        $maintenance = new RunMaintenance($store, new MockClock(new DateTimeImmutable('2026-11-24T00:00:00Z')), 30);

        self::assertSame('2026-10-25', $maintenance->defaultBefore()->format('Y-m-d'));
        self::assertSame('2025-11-24', (new RunMaintenance($store, new MockClock(new DateTimeImmutable('2026-11-24T00:00:00Z'))))->defaultBefore()->format('Y-m-d'));

        $list = $maintenance->list(null, null, 2);
        self::assertSame(['00000000-0000-7000-8000-000000000003', '00000000-0000-7000-8000-000000000002'], array_map(static fn (RunRecord $r): string => $r->id->value, $list));
        self::assertCount(1, $maintenance->list(SeedScope::platform(), RunStatus::Applied, 10));
        self::assertCount(1, $maintenance->list(null, null, 10, new RunId('00000000-0000-7000-8000-000000000002')));

        $before = $maintenance->defaultBefore();
        self::assertSame(1, $maintenance->purge($before, true), 'only finished runs');
        self::assertSame(3, $store->count(), 'a dry run deletes nothing');
        self::assertSame(1, $maintenance->purge($before, false));
        self::assertSame(2, $store->count());

        self::assertSame(1, $maintenance->forgetActor(new ActorRef('alice')));
        self::assertNull($store->find(new RunId('00000000-0000-7000-8000-000000000003'))?->actor);
        self::assertSame('bob', $store->find(new RunId('00000000-0000-7000-8000-000000000002'))?->actor?->value);
        self::assertSame(0, $maintenance->forgetActor(new ActorRef('alice')));
    }

    #[Test]
    public function settingsKnowTheKinds(): void
    {
        $settings = new SeedKitSettings(['platform' => false, 'organization' => true]);
        self::assertTrue($settings->knowsKind(new ScopeKind('platform')));
        self::assertFalse($settings->knowsKind(new ScopeKind('project')));
        self::assertFalse($settings->requiresId(new ScopeKind('platform')));
        self::assertTrue($settings->requiresId(new ScopeKind('organization')));
        self::assertTrue($settings->requiresId(new ScopeKind('project')), 'unknown kinds need an id');

        $defaults = new SeedKitSettings();
        self::assertSame(['platform' => false], $defaults->scopeKinds);
        self::assertSame([500, 300, 200, 200, 0.5, 'seed-kit', 16, 10000, 65536, 256], [$defaults->batchSize, $defaults->leaseSeconds, $defaults->maxErrors, $defaults->previewRows, $defaults->pruneMaxRatio, $defaults->generator, $defaults->maxDepth, $defaults->maxNodes, $defaults->maxStringBytes, $defaults->maxProperties]);
    }
}
