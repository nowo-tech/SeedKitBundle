<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Domain;

use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Contract 5.4: enums and their helpers.
 */
final class EnumsTest extends TestCase
{
    #[Test]
    public function changeOpLetters(): void
    {
        self::assertSame('C', ChangeOp::Create->letter());
        self::assertSame('U', ChangeOp::Update->letter());
        self::assertSame('N', ChangeOp::Unchanged->letter());
        self::assertSame('D', ChangeOp::Delete->letter());
        self::assertSame(['create', 'update', 'unchanged', 'delete'], array_map(static fn (ChangeOp $op): string => $op->value, ChangeOp::cases()));
    }

    #[Test]
    public function documentFormats(): void
    {
        self::assertSame('yaml', DocumentFormat::Yaml->extension());
        self::assertSame('json', DocumentFormat::Json->extension());
        self::assertSame('jsonl', DocumentFormat::JsonLines->extension());
        self::assertSame('application/yaml', DocumentFormat::Yaml->mediaType());
        self::assertSame('application/json', DocumentFormat::Json->mediaType());
        self::assertSame('application/jsonl', DocumentFormat::JsonLines->mediaType());
    }

    #[Test]
    public function exportModesAndChannels(): void
    {
        self::assertSame(ExportMode::Empty, ExportMode::from('empty'));
        self::assertSame(ExportMode::Current, ExportMode::from('current'));
        self::assertSame(['http', 'console', 'messenger'], array_map(static fn (Channel $c): string => $c->value, Channel::cases()));
    }

    #[Test]
    public function runStatusFinality(): void
    {
        $final = [];
        foreach (RunStatus::cases() as $status) {
            if ($status->isFinal()) {
                $final[] = $status->value;
            }
        }
        self::assertSame(['applied', 'failed', 'discarded', 'expired'], $final);
        self::assertFalse(RunStatus::PartiallyApplied->isFinal());
    }

    #[Test]
    public function seedActionAttributes(): void
    {
        self::assertSame('SEED_EXPORT', SeedAction::Export->attribute());
        self::assertSame('SEED_APPLY', SeedAction::Apply->attribute());
        self::assertSame('SEED_PRUNE', SeedAction::Prune->attribute());
        self::assertSame(SeedAction::Prune, SeedAction::fromAttribute('SEED_PRUNE'));
        self::assertSame(SeedAction::Export, SeedAction::fromAttribute('SEED_EXPORT'));
        self::assertNull(SeedAction::fromAttribute('seed_export'));
        self::assertNull(SeedAction::fromAttribute('ROLE_ADMIN'));
    }
}
