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
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Applies a queued UI run (REQ-MSG-001). Idempotent on redelivery: the run claim lets one worker in, batches up to
 * `batches_done` are skipped, an ended run or a plan hash that is not the run's is a no-op. Failure policy: a lock
 * conflict or a held lease is retried by the transport (`RecoverableMessageHandlingException`, the run is back to
 * `queued` or `partially_applied`); every other SeedKit refusal (access, stale plan, changed rows, provider failure,
 * expired document, `system` actor) is final (`UnrecoverableMessageHandlingException`, recorded on the run and audited);
 * an unexpected error is rethrown for the transport's retry / failure policy. Logs carry the run id and the error code
 * only.
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
        } catch (InvalidValueException $e) {
            throw new UnrecoverableMessageHandlingException('Malformed seed run message.', 0, $e);
        }
        try {
            $this->runs->applyQueued($id, $hash);
        } catch (LeaseHeldException $e) {
            throw new RecoverableMessageHandlingException('Seed run lease held; retry.', 0, $e, $e->retryAfterSeconds * 1000);
        } catch (ApplyInterruptedException $e) {
            if ($e->kind === ApplyInterruptedException::CONFLICT) {
                throw new RecoverableMessageHandlingException('Seed run lock conflict; retry.', 0, $e, ($e->retryAfterSeconds ?? 1) * 1000);
            }
            $this->logger?->warning('Seed run {run} stopped: {code}.', ['run' => $id->value, 'code' => $e->errorCode()]);

            throw new UnrecoverableMessageHandlingException('Seed run stopped.', 0, $e);
        } catch (SeedKitException $e) {
            $this->logger?->warning('Seed run {run} refused: {code}.', ['run' => $id->value, 'code' => $e->errorCode()]);

            throw new UnrecoverableMessageHandlingException('Seed run refused.', 0, $e);
        }
    }
}
