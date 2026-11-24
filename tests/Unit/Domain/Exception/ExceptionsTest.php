<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Domain\Exception;

use Closure;
use Nowo\SeedKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\SeedKitBundle\Domain\Exception\ApplyInterruptedException;
use Nowo\SeedKitBundle\Domain\Exception\DocumentInvalidException;
use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;
use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Exception\LeaseHeldException;
use Nowo\SeedKitBundle\Domain\Exception\ProviderContractException;
use Nowo\SeedKitBundle\Domain\Exception\PruneRefusedException;
use Nowo\SeedKitBundle\Domain\Exception\ScopeMismatchException;
use Nowo\SeedKitBundle\Domain\Exception\ScopeNotFoundException;
use Nowo\SeedKitBundle\Domain\Exception\SecretValueRefusedException;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Exception\StalePlanException;
use Nowo\SeedKitBundle\Domain\Exception\UnknownProviderException;
use Nowo\SeedKitBundle\Domain\Plan\RowError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Contract 5.5: codes, static messages (no value, key or secret) and translation keys.
 */
final class ExceptionsTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(): SeedKitException, string, string}>
     */
    public static function staticExceptions(): iterable
    {
        yield 'access' => [static fn (): SeedKitException => new AccessDeniedException(), 'ACCESS_002', AccessDeniedException::MESSAGE];
        yield 'contract' => [static fn (): SeedKitException => new ProviderContractException(), 'SEED_011', ProviderContractException::MESSAGE];
        yield 'mismatch' => [static fn (): SeedKitException => new ScopeMismatchException(), 'SEED_007', ScopeMismatchException::MESSAGE];
        yield 'not found' => [static fn (): SeedKitException => new ScopeNotFoundException(), 'ACCESS_001', ScopeNotFoundException::MESSAGE];
        yield 'stale' => [static fn (): SeedKitException => new StalePlanException(), 'SEED_005', StalePlanException::MESSAGE];
        yield 'unknown' => [static fn (): SeedKitException => new UnknownProviderException(), 'SEED_001', UnknownProviderException::MESSAGE];
        yield 'secret' => [static fn (): SeedKitException => new SecretValueRefusedException(), 'SEED_004', SecretValueRefusedException::MESSAGE];
        yield 'lease' => [static fn (): SeedKitException => new LeaseHeldException(30), 'CONFLICT_001', 'Another seed run is applying the same provider in this scope. Retry later.'];
    }

    /**
     * @param Closure(): SeedKitException $factory
     */
    #[Test]
    #[DataProvider('staticExceptions')]
    public function staticCodesAndMessages(Closure $factory, string $code, string $message): void
    {
        $exception = $factory();
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame($code, $exception->errorCode());
        self::assertSame($message, $exception->getMessage());
        self::assertSame('seed_kit.error.' . $code, $exception->messageKey());
    }

    #[Test]
    public function leaseHeldCarriesRetryAfter(): void
    {
        self::assertSame(30, (new LeaseHeldException(30))->retryAfterSeconds);
    }

    #[Test]
    public function secretRefusalCarriesErrors(): void
    {
        $error     = new RowError(0, null, '/token', 'secret_plaintext', 'seed_kit.row.secret_plaintext');
        $exception = new SecretValueRefusedException([$error], true);
        self::assertSame([$error], $exception->errors);
        self::assertTrue($exception->truncated);
        self::assertSame([], (new SecretValueRefusedException())->errors);
        self::assertFalse((new SecretValueRefusedException())->truncated);
    }

    #[Test]
    public function documentRead(): void
    {
        $withLine = new DocumentReadException('yaml_tag', 12);
        self::assertSame('SEED_002', $withLine->errorCode());
        self::assertSame('seed_kit.read.yaml_tag', $withLine->messageKey());
        self::assertSame('The seed document cannot be read (yaml_tag, line 12).', $withLine->getMessage());
        self::assertSame(12, $withLine->lineNumber);
        self::assertSame('yaml_tag', $withLine->reason);

        $noLine = new DocumentReadException('too_large');
        self::assertSame('The seed document cannot be read (too_large).', $noLine->getMessage());
        self::assertNull($noLine->lineNumber);
    }

    #[Test]
    public function documentInvalid(): void
    {
        $error = new RowError(1, null, '', 'required', 'seed_kit.row.required');
        $exact = new DocumentInvalidException([$error, $error]);
        self::assertSame('SEED_003', $exact->errorCode());
        self::assertSame('The seed document is invalid (2 error(s)); nothing was written.', $exact->getMessage());
        self::assertFalse($exact->truncated);
        self::assertCount(2, $exact->errors);

        $truncated = new DocumentInvalidException([$error], true);
        self::assertSame('The seed document is invalid (1+ error(s)); nothing was written.', $truncated->getMessage());
    }

    #[Test]
    public function invalidValue(): void
    {
        $exception = new InvalidValueException('Invalid thing.');
        self::assertSame('Invalid thing.', $exception->getMessage());
        self::assertSame('SEED_002', $exception->errorCode());
        self::assertSame('seed_kit.invalid_value', $exception->messageKey());
    }

    #[Test]
    public function pruneRefused(): void
    {
        $mass = new PruneRefusedException(PruneRefusedException::MASS, 8, 10);
        self::assertSame('SEED_012', $mass->errorCode());
        self::assertSame('The prune would delete 8 of 10 rows; confirm a mass prune explicitly.', $mass->getMessage());
        self::assertSame(8, $mass->deletions);
        self::assertSame(10, $mass->current);
        self::assertSame('mass', $mass->guard);

        $empty = new PruneRefusedException(PruneRefusedException::EMPTY, 3, 3);
        self::assertSame('The document has no item and prune is on; confirm an empty prune explicitly.', $empty->getMessage());
    }

    #[Test]
    public function applyInterruptedRowChanged(): void
    {
        $first = new ApplyInterruptedException(ApplyInterruptedException::ROW_CHANGED, 0, 3, 'run');
        self::assertSame('SEED_006', $first->errorCode());
        self::assertFalse($first->isPartial());
        self::assertSame('A row changed while the plan was applied; 0 of 3 batch(es) were applied and stay. Apply the document again.', $first->getMessage());
        self::assertSame('run', $first->runId);
        self::assertNull($first->retryAfterSeconds);

        $partial = new ApplyInterruptedException(ApplyInterruptedException::ROW_CHANGED, 2, 3);
        self::assertTrue($partial->isPartial());
        self::assertSame('SEED_006', $partial->errorCode());
    }

    #[Test]
    public function applyInterruptedProviderFailed(): void
    {
        $cause     = new RuntimeException('boom');
        $exception = new ApplyInterruptedException(ApplyInterruptedException::PROVIDER_FAILED, 1, 2, null, null, $cause);
        self::assertSame('SEED_010', $exception->errorCode());
        self::assertSame($cause, $exception->getPrevious());
        self::assertStringStartsWith('The provider failed to apply a batch; 1 of 2', $exception->getMessage());
        self::assertStringNotContainsString('boom', $exception->getMessage());
    }

    #[Test]
    public function applyInterruptedConflict(): void
    {
        $none = new ApplyInterruptedException(ApplyInterruptedException::CONFLICT, 0, 2, 'r', 5);
        self::assertSame('CONFLICT_001', $none->errorCode());
        self::assertSame(5, $none->retryAfterSeconds);
        self::assertStringStartsWith('The database kept refusing the write because of a lock conflict; 0 of 2', $none->getMessage());
        self::assertSame('seed_kit.error.CONFLICT_001', $none->messageKey());

        $partial = new ApplyInterruptedException(ApplyInterruptedException::CONFLICT, 1, 2);
        self::assertSame('CONFLICT_002', $partial->errorCode());
    }
}
