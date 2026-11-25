<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Domain\Plan;

use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\AppliedBatch;
use Nowo\SeedKitBundle\Domain\Plan\BatchPlan;
use Nowo\SeedKitBundle\Domain\Plan\BatchResult;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\DocumentPlan;
use Nowo\SeedKitBundle\Domain\Plan\FieldChange;
use Nowo\SeedKitBundle\Domain\Plan\FieldValueView;
use Nowo\SeedKitBundle\Domain\Plan\NaturalKeySet;
use Nowo\SeedKitBundle\Domain\Plan\PendingKeys;
use Nowo\SeedKitBundle\Domain\Plan\PlanOptions;
use Nowo\SeedKitBundle\Domain\Plan\RowChange;
use Nowo\SeedKitBundle\Domain\Plan\RowError;
use Nowo\SeedKitBundle\Domain\Plan\SeedPlan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Contract 5.3: plan value objects and their counters.
 */
final class PlanModelTest extends TestCase
{
    #[Test]
    public function fieldValueViews(): void
    {
        $value = FieldValueView::value(['a' => 1]);
        self::assertSame(FieldValueView::VALUE, $value->kind);
        self::assertSame(['a' => 1], $value->value);

        $null = FieldValueView::value(null);
        self::assertSame('value', $null->kind);
        self::assertNull($null->value);

        self::assertSame('hidden', FieldValueView::hidden()->kind);
        self::assertNull(FieldValueView::hidden()->value);
        self::assertSame('absent', FieldValueView::absent()->kind);

        $change = new FieldChange('/a', FieldValueView::absent(), $value);
        self::assertSame('/a', $change->path);
        self::assertSame('absent', $change->before->kind);
        self::assertSame($value, $change->after);
    }

    #[Test]
    public function rowChangeFactories(): void
    {
        $key   = new NaturalKey(['k']);
        $field = new FieldChange('/x', FieldValueView::absent(), FieldValueView::value(1));

        $create = RowChange::create($key, 3, [$field]);
        self::assertSame(ChangeOp::Create, $create->op);
        self::assertSame(3, $create->index);
        self::assertSame([$field], $create->fields);
        self::assertNull($create->currentFingerprint);
        self::assertNull($create->desiredFingerprint);
        self::assertTrue($create->writes());
        self::assertSame([], RowChange::create($key, 0)->fields);

        $update = RowChange::update($key, 1, [$field], 'fp');
        self::assertSame(ChangeOp::Update, $update->op);
        self::assertSame('fp', $update->currentFingerprint);
        self::assertTrue($update->writes());

        $unchanged = RowChange::unchanged($key, 2, 'fp2');
        self::assertSame(ChangeOp::Unchanged, $unchanged->op);
        self::assertSame([], $unchanged->fields);
        self::assertSame('fp2', $unchanged->currentFingerprint);
        self::assertFalse($unchanged->writes());

        $delete = RowChange::delete($key, 'fp3');
        self::assertSame(ChangeOp::Delete, $delete->op);
        self::assertNull($delete->index);
        self::assertTrue($delete->writes());

        $withDesired = $update->withDesiredFingerprint('want');
        self::assertSame('want', $withDesired->desiredFingerprint);
        self::assertSame('fp', $withDesired->currentFingerprint);
        self::assertSame([$field], $withDesired->fields);
        self::assertNull($update->desiredFingerprint);

        $withFields = $withDesired->withFields([]);
        self::assertSame([], $withFields->fields);
        self::assertSame('want', $withFields->desiredFingerprint);
        self::assertSame(ChangeOp::Update, $withFields->op);
        self::assertSame($key, $withFields->key);
        self::assertSame(1, $withFields->index);
    }

    #[Test]
    public function rowErrorForProvider(): void
    {
        $key   = new NaturalKey(['k']);
        $error = new RowError(4, $key, '/p', 'type', 'seed_kit.row.type', ['expected' => 'string']);
        self::assertNull($error->provider);
        $scoped = $error->forProvider('demo.tags');
        self::assertSame('demo.tags', $scoped->provider);
        self::assertSame(4, $scoped->index);
        self::assertSame($key, $scoped->key);
        self::assertSame('/p', $scoped->path);
        self::assertSame('type', $scoped->rule);
        self::assertSame('seed_kit.row.type', $scoped->messageKey);
        self::assertSame(['expected' => 'string'], $scoped->messageParams);
    }

    #[Test]
    public function naturalKeySet(): void
    {
        $set = new NaturalKeySet();
        self::assertCount(0, $set);
        self::assertTrue($set->add(new NaturalKey(['a'])));
        self::assertFalse($set->add(new NaturalKey(['a'])));
        self::assertTrue($set->add(new NaturalKey(['A'])));
        self::assertTrue($set->has(new NaturalKey(['a'])));
        self::assertFalse($set->has(new NaturalKey(['b'])));
        self::assertCount(2, $set);
    }

    #[Test]
    public function pendingKeysArePerProvider(): void
    {
        $pending = new PendingKeys();
        $tags    = new ProviderName('demo.tags');
        $key     = new NaturalKey(['news']);
        self::assertFalse($pending->has($tags, $key));
        $pending->add($tags, $key);
        $pending->add($tags, new NaturalKey(['other']));
        self::assertTrue($pending->has($tags, $key));
        self::assertFalse($pending->has(new ProviderName('demo.articles'), $key));
        self::assertFalse($pending->has($tags, new NaturalKey(['News'])));
    }

    #[Test]
    public function batchValueObjects(): void
    {
        $key    = new NaturalKey(['a']);
        $change = RowChange::create($key, 0);
        $item   = new SeedItem(0, $key, ['a' => 1]);
        $batch  = new AppliedBatch(2, [$change], [$key->canonical => $item]);
        self::assertSame(2, $batch->number);
        self::assertSame($item, $batch->item($change));
        self::assertNull($batch->item(RowChange::delete(new NaturalKey(['b']), 'fp')));
        self::assertSame([], (new AppliedBatch(1, []))->items);

        $plan = new BatchPlan([$change]);
        self::assertSame([], $plan->errors);
        self::assertSame([$change], $plan->changes);

        $result = new BatchResult();
        self::assertSame([0, 0, 0], [$result->created, $result->updated, $result->deleted]);
        $result = new BatchResult(1, 2, 3);
        self::assertSame([1, 2, 3], [$result->created, $result->updated, $result->deleted]);

        self::assertFalse((new PlanOptions())->lockForUpdate);
        self::assertTrue((new PlanOptions(true))->lockForUpdate);
    }

    #[Test]
    public function documentAndSeedPlanCounters(): void
    {
        $first = new DocumentPlan(
            new ProviderName('demo.tags'),
            1,
            'schema',
            ['create' => 2, 'update' => 1, 'unchanged' => 5, 'delete' => 1],
            [],
            ['d1', 'd2'],
            ['d3'],
            [true, false, true],
        );
        self::assertSame(2, $first->count(ChangeOp::Create));
        self::assertSame(5, $first->count(ChangeOp::Unchanged));
        self::assertSame(4, $first->changes());
        self::assertSame(3, $first->batches());
        self::assertSame(2, $first->writingBatches());

        $second = new DocumentPlan(new ProviderName('demo.articles'), 1, 'schema', ['update' => 3], [], ['d4'], []);
        self::assertSame(0, $second->count(ChangeOp::Create));
        self::assertSame(3, $second->changes());
        self::assertSame(0, $second->writingBatches());
        self::assertSame(1, $second->batches());

        $plan = new SeedPlan(SeedScope::platform(), true, [$first, $second], new PlanHash('sk1:' . str_repeat('a', 64)), [SeedPlan::WARNING_EXPORTED_FROM]);
        self::assertSame(7, $plan->changes());
        self::assertSame(4, $plan->batches());
        self::assertSame(2, $plan->writingBatches());
        self::assertSame(4, $plan->count(ChangeOp::Update));
        self::assertSame(1, $plan->count(ChangeOp::Delete));
        self::assertTrue($plan->prune);
        self::assertSame(['exported_from_differs'], $plan->warnings);

        $empty = new SeedPlan(SeedScope::platform(), false, [], new PlanHash('sk1:' . str_repeat('a', 64)));
        self::assertSame(0, $empty->changes());
        self::assertSame(0, $empty->batches());
        self::assertSame(0, $empty->writingBatches());
        self::assertSame(0, $empty->count(ChangeOp::Create));
        self::assertSame([], $empty->warnings);
    }
}
