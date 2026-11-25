<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Infrastructure\Support;

use Nowo\SeedKitBundle\Domain\Audit\SeedAuditRecord;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Infrastructure\Support\PsrLogAuditSink;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

use function is_array;

/**
 * The default audit sink (contract 12.3): one record at the configured level with the flat context.
 */
final class PsrLogAuditSinkTest extends TestCase
{
    #[Test]
    public function logsAtNoticeByDefaultWithTheFlatContext(): void
    {
        $record = $this->record();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('log')->with(LogLevel::NOTICE, 'Seed {action} by {actor} in {scope}: {outcome}.', [
            'action'        => 'apply',
            'actor'         => 'user-7',
            'scope'         => 'organization:org-1',
            'providers'     => ['crm.contacts'],
            'counts'        => ['create' => 2],
            'channel'       => 'http',
            'outcome'       => 'success',
            'document_hash' => 'sha256:abc',
            'plan_hash'     => 'plan',
            'run_id'        => 'run',
        ]);

        (new PsrLogAuditSink($logger))->record($record);
    }

    #[Test]
    public function logsAtTheConfiguredLevel(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('log')->with(LogLevel::INFO, self::anything(), self::callback(static fn (mixed $context): bool => is_array($context) && $context['action'] === 'export' && $context['run_id'] === null));

        (new PsrLogAuditSink($logger, LogLevel::INFO))->record(new SeedAuditRecord(SeedAuditRecord::EXPORT, ActorRef::system(), SeedScope::platform(), ['a.one'], ['rows' => 1], Channel::Console, SeedAuditRecord::OUTCOME_SUCCESS));
    }

    #[Test]
    public function doesNothingWithoutALogger(): void
    {
        $this->expectNotToPerformAssertions();

        (new PsrLogAuditSink(null))->record($this->record());
    }

    private function record(): SeedAuditRecord
    {
        return new SeedAuditRecord(
            SeedAuditRecord::APPLY,
            new ActorRef('user-7'),
            SeedScope::parse('organization:org-1'),
            ['crm.contacts'],
            ['create' => 2],
            Channel::Http,
            SeedAuditRecord::OUTCOME_SUCCESS,
            'sha256:abc',
            'plan',
            'run',
        );
    }
}
