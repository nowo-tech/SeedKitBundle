<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Bridge\AuditKit;

use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorderInterface;
use Nowo\SeedKitBundle\Bridge\AuditKit\AuditKitSeedAuditSink;
use Nowo\SeedKitBundle\Domain\Audit\SeedAuditRecord;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function is_array;

/**
 * The AuditKit bridge (contract 12.3): `seed_kit.<action>`, no actor / subject entity, flat context, scope key.
 */
final class AuditKitSeedAuditSinkTest extends TestCase
{
    #[Test]
    public function recordsAndFlushesOneActionLogRow(): void
    {
        $record   = new SeedAuditRecord(SeedAuditRecord::PRUNE, new ActorRef('user-7'), SeedScope::parse('project:p:1'), ['a.one', 'a.two'], ['delete' => 3], Channel::Console, 'SEED_009', 'sha256:x', 'plan', 'run');
        $recorder = $this->createMock(ActionLogRecorderInterface::class);
        $recorder->expects(self::never())->method('record');
        $recorder->expects(self::once())->method('recordAndFlush')
            ->with('seed_kit.prune', null, null, $record->toArray(), 'project:p:1')
            ->willReturn($this->createStub(AbstractActionLog::class));

        (new AuditKitSeedAuditSink($recorder))->record($record);
    }

    #[Test]
    public function usesThePlatformScopeKey(): void
    {
        $record   = new SeedAuditRecord(SeedAuditRecord::EXPORT, ActorRef::system(), SeedScope::platform(), ['a.one'], ['rows' => 1], Channel::Http, SeedAuditRecord::OUTCOME_SUCCESS);
        $recorder = $this->createMock(ActionLogRecorderInterface::class);
        $recorder->expects(self::once())->method('recordAndFlush')
            ->with('seed_kit.export', null, null, self::callback(static fn (mixed $context): bool => is_array($context) && $context['actor'] === 'system' && $context['scope'] === 'platform' && $context['document_hash'] === null), 'platform')
            ->willReturn($this->createStub(AbstractActionLog::class));

        (new AuditKitSeedAuditSink($recorder))->record($record);
    }
}
