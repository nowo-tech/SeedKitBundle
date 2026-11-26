<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Bridge\Messenger;

use Nowo\SeedKitBundle\Application\SeedRuns;
use Nowo\SeedKitBundle\Domain\Exception\ApplyInterruptedException;
use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Exception\LeaseHeldException;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Throwable;

/**
 * Applies a queued UI run (REQ-MSG-001). Idempotent on redelivery: the run claim lets one worker in, batches up to
 * `batches_done` are skipped, an ended run or a plan hash that is not the run's is a no-op. Failure policy: a lock
 * conflict or a held lease is retried by the transport (`RecoverableMessageHandlingException`, the run is back to
 * `queued` or `partially_applied`); every other SeedKit refusal (access, stale plan, changed rows, provider failure,
 * expired document, `system` actor) is final (`UnrecoverableMessageHandlingException`, recorded on the run and audited);
 * an unexpected error becomes a plain `RuntimeException` (class only) for the transport's retry / failure policy. No
 * thrown exception chains the cause, and logs carry the run id and the error code or class only (ZCE-L4).
 */
#[AsMessageHandler]
final readonly class ApplySeedRunHandler
{
    /**
     * Builds the handler.
     */
    public function __construct(private SeedRuns $runs, private ?LoggerInterface $logger = null)
    {
    }

    /**
     * Handles the message.
     *
     * @throws RecoverableMessageHandlingException
     * @throws UnrecoverableMessageHandlingException
     */
    public function __invoke(ApplySeedRun $message): void
    {
        try {
            $id   = new RunId($message->runId);
            $hash = new PlanHash($message->planHash);
        } catch (InvalidValueException) {
            throw new UnrecoverableMessageHandlingException('Malformed seed run message.');
        }
        // No exception below keeps a previous one: a driver or provider message may quote row values (personal data)
        // and would reach the failure transport (ErrorDetailsStamp) and the worker log (ZCE-L4).
        try {
            $this->runs->applyQueued($id, $hash);
        } catch (LeaseHeldException $e) {
            throw new RecoverableMessageHandlingException('Seed run lease held (CONFLICT_001); retry.', 0, null, $e->retryAfterSeconds * 1000);
        } catch (ApplyInterruptedException $e) {
            if ($e->kind === ApplyInterruptedException::CONFLICT) {
                throw new RecoverableMessageHandlingException('Seed run lock conflict (' . $e->errorCode() . '); retry.', 0, null, ($e->retryAfterSeconds ?? 1) * 1000);
            }
            $this->logger?->warning('Seed run {run} stopped: {code}.', ['run' => $id->value, 'code' => $e->errorCode()]);

            throw new UnrecoverableMessageHandlingException('Seed run stopped (' . $e->errorCode() . ').');
        } catch (SeedKitException $e) {
            $this->logger?->warning('Seed run {run} refused: {code}.', ['run' => $id->value, 'code' => $e->errorCode()]);

            throw new UnrecoverableMessageHandlingException('Seed run refused (' . $e->errorCode() . ').');
        } catch (Throwable $e) {
            // Unexpected (a driver failure around the claim): retried by the transport, class and code only.
            $this->logger?->error('Seed run {run} failed: {exception_class} ({exception_code}).', ['run' => $id->value, 'exception_class' => $e::class, 'exception_code' => (string) $e->getCode()]);

            throw new RuntimeException('Seed run failed unexpectedly (' . $e::class . ').');
        }
    }
}
