<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Database;

use Closure;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Nowo\SeedKitBundle\Infrastructure\Dbal\ConnectionOnlyRegistry;
use Nowo\SeedKitBundle\Infrastructure\Dbal\DbalWriteRunner;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use Nowo\TransactionContract\ConcurrencyConflict;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

/**
 * The batch write unit with the real transaction-doctrine WriteUnit and runner on the bundle's connection: commit,
 * rollback, replay after a lock conflict with the attempt number, the conflict after the last attempt, and one nested
 * attempt (a savepoint) inside a host transaction.
 */
final class DbalWriteRunnerTest extends DatabaseTestCase
{
    private function runner(int $attempts = 3): DbalWriteRunner
    {
        return new DbalWriteRunner(
            new WriteUnit(new ConnectionOnlyRegistry($this->connection), new WriteUnitPolicy($attempts), null, $this->clock),
            DoctrineTransactionRunner::forConnection($this->connection, 1),
        );
    }

    private function notes(): int
    {
        return TestDatabase::int($this->connection->fetchOne('SELECT COUNT(*) FROM host_note'));
    }

    private function deadlock(): DeadlockException
    {
        $driver = new class('Deadlock found', 1213) extends RuntimeException implements DriverException {
            public function getSQLState(): string
            {
                return '40001';
            }
        };

        return new DeadlockException($driver, null);
    }

    /**
     * The failure of a call.
     *
     * @param Closure(): mixed $call
     */
    private static function failure(Closure $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $e) {
            return $e;
        }
        self::fail('no failure');
    }

    #[Test]
    public function aUnitCommitsOrRollsBack(): void
    {
        self::assertSame('done', $this->runner()->write(function (int $attempt): string {
            self::assertSame(1, $attempt);
            self::assertTrue($this->connection->isTransactionActive());
            $this->connection->insert('host_note', ['scope_key' => 'platform', 'code' => 'a', 'body' => 'A']);

            return 'done';
        }));
        self::assertSame(1, $this->notes());
        self::assertFalse($this->connection->isTransactionActive());

        $failure = self::failure(fn (): mixed => $this->runner()->write(function (): void {
            $this->connection->insert('host_note', ['scope_key' => 'platform', 'code' => 'b', 'body' => 'B']);

            throw new RuntimeException('provider failed');
        }));
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('provider failed', $failure->getMessage());
        self::assertSame(1, $this->notes());
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    #[Test]
    public function aLockConflictIsReplayedWithTheAttemptNumber(): void
    {
        $attempts = [];
        $result   = $this->runner()->write(function (int $attempt) use (&$attempts): int {
            $attempts[] = $attempt;
            $this->connection->insert('host_note', ['scope_key' => 'platform', 'code' => 'c' . $attempt, 'body' => 'C']);
            if ($attempt < 3) {
                throw $this->deadlock();
            }

            return $attempt;
        });

        self::assertSame(3, $result);
        self::assertSame([1, 2, 3], $attempts);
        self::assertSame(['c3'], $this->connection->fetchFirstColumn('SELECT code FROM host_note'), 'the failed attempts were rolled back');

        $conflict = self::failure(fn (): mixed => $this->runner(2)->write(fn (): never => throw $this->deadlock()));
        self::assertInstanceOf(ConcurrencyConflict::class, $conflict);
        self::assertSame(2, $conflict->attempts);
        self::assertFalse($conflict->committed);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    #[Test]
    public function insideAHostTransactionTheUnitRunsOnceAsASavepoint(): void
    {
        $this->connection->beginTransaction();
        $this->runner()->write(function (int $attempt): void {
            self::assertSame(1, $attempt);
            self::assertSame(2, $this->connection->getTransactionNestingLevel());
            $this->connection->insert('host_note', ['scope_key' => 'platform', 'code' => 'd', 'body' => 'D']);
        });
        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, $this->notes());
        $this->connection->rollBack();

        self::assertSame(0, $this->notes(), 'the host rolled back its transaction, the unit with it');
    }
}
