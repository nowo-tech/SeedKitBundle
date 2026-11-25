<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Support;

use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Port\IdGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Run ids as UUID v7 (time-ordered, so the run history pages by id).
 */
final readonly class UuidV7IdGenerator implements IdGeneratorInterface
{
    /**
     * {@inheritDoc}
     */
    public function runId(): RunId
    {
        return new RunId(Uuid::v7()->toRfc4122());
    }
}
