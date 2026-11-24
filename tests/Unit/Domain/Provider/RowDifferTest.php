<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Domain\Provider;

use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\FieldChange;
use Nowo\SeedKitBundle\Domain\Provider\RowDiffer;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The default diff (contract 10.2): changed fields only, readonly ignored, omit keeps or clears.
 */
final class RowDifferTest extends TestCase
{
    private static function schema(): SeedSchema
    {
        return new SeedSchema([
            'properties' => [
                'slug'    => ['type' => 'string'],
                'label'   => ['type' => 'string'],
                'color'   => ['type' => 'string', 'x-seed-omit-means' => 'clear'],
                'note'    => ['type' => 'string'],
                'meta'    => ['type' => 'object'],
                'created' => ['type' => 'string', 'x-seed-readonly' => true],
                'a/b~c'   => ['type' => 'string'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function item(array $data): SeedItem
    {
        return new SeedItem(5, new NaturalKey(['k']), $data);
    }

    /**
     * @param list<FieldChange> $fields
     *
     * @return array<string, array{string, mixed, string, mixed}>
     */
    private static function fields(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $out[$field->path] = [$field->before->kind, $field->before->value, $field->after->kind, $field->after->value];
        }

        return $out;
    }

    #[Test]
    public function createListsEverySetField(): void
    {
        $change = RowDiffer::diff(self::schema(), self::item(['slug' => 'k', 'note' => null]), null);
        self::assertSame(ChangeOp::Create, $change->op);
        self::assertSame(5, $change->index);
        self::assertNull($change->currentFingerprint);
        self::assertSame([
            '/slug' => ['absent', null, 'value', 'k'],
            '/note' => ['absent', null, 'value', null],
        ], self::fields($change->fields));
    }

    #[Test]
    public function updateListsChangedFieldsOnly(): void
    {
        $current = ['slug' => 'k', 'label' => 'Old', 'color' => 'red', 'note' => 'n', 'meta' => ['b' => 1, 'a' => 2], 'created' => 'x'];
        $item    = self::item(['slug' => 'k', 'label' => 'New', 'meta' => ['a' => 2, 'b' => 1], 'created' => 'changed', 'a/b~c' => 'v']);
        $change  = RowDiffer::diff(self::schema(), $item, $current);
        self::assertSame(ChangeOp::Update, $change->op);
        self::assertSame([
            '/label'   => ['value', 'Old', 'value', 'New'],
            '/color'   => ['value', 'red', 'absent', null],
            '/a~1b~0c' => ['absent', null, 'value', 'v'],
        ], self::fields($change->fields), 'meta is equal canonically, note is kept when omitted, readonly ignored');
        self::assertSame(RowDiffer::fingerprint(self::schema(), $current), $change->currentFingerprint);
    }

    #[Test]
    public function nullSetsAndClears(): void
    {
        // explicit null over a stored value: a change to null
        $change = RowDiffer::diff(self::schema(), self::item(['slug' => 'k', 'note' => null]), ['slug' => 'k', 'note' => 'n']);
        self::assertSame(['/note' => ['value', 'n', 'value', null]], self::fields($change->fields));

        // explicit null over an absent / null stored value: no change
        $change = RowDiffer::diff(self::schema(), self::item(['slug' => 'k', 'note' => null]), ['slug' => 'k', 'note' => null]);
        self::assertSame(ChangeOp::Unchanged, $change->op);
        self::assertSame([], $change->fields);

        // omitted clear-on-omit property already null: no change
        $change = RowDiffer::diff(self::schema(), self::item(['slug' => 'k']), ['slug' => 'k', 'color' => null]);
        self::assertSame(ChangeOp::Unchanged, $change->op);
    }

    #[Test]
    public function exactTextIsCompared(): void
    {
        $change = RowDiffer::diff(self::schema(), self::item(['slug' => 'k', 'label' => 'bob ']), ['slug' => 'k', 'label' => 'bob']);
        self::assertSame(ChangeOp::Update, $change->op);
        $change = RowDiffer::diff(self::schema(), self::item(['slug' => 'k', 'label' => 1]), ['slug' => 'k', 'label' => '1']);
        self::assertSame(ChangeOp::Update, $change->op);
        $change = RowDiffer::diff(self::schema(), self::item(['slug' => 'k', 'label' => 1.0]), ['slug' => 'k', 'label' => 1]);
        self::assertSame(ChangeOp::Unchanged, $change->op, 'canonical JSON: 1.0 and 1 are the same number');
    }

    #[Test]
    public function fingerprintIgnoresReadonlyNullAndUnknown(): void
    {
        $schema = self::schema();
        self::assertSame(hash('sha256', '{}'), RowDiffer::fingerprint($schema, []));
        self::assertSame(hash('sha256', '{}'), RowDiffer::fingerprint($schema, ['created' => 'x', 'note' => null, 'other' => 1]));
        self::assertSame(hash('sha256', '{"label":"L","slug":"k"}'), RowDiffer::fingerprint($schema, ['slug' => 'k', 'label' => 'L', 'created' => 'x']));
    }

    #[Test]
    public function pointerEscapes(): void
    {
        self::assertSame('/a~1b~0c', RowDiffer::pointer('a/b~c'));
        self::assertSame('/~01', RowDiffer::pointer('~1'));
        self::assertSame('/', RowDiffer::pointer(''));
    }
}
