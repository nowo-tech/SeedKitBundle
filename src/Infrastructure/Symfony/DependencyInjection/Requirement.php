<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * A build-time requirement of an enabled option (an extension or an optional package).
 */
final class Requirement
{
    /**
     * Fails the container build with the message when the requirement is not met.
     *
     * @throws InvalidArgumentException
     */
    public static function check(bool $met, string $message): void
    {
        if (!$met) {
            throw new InvalidArgumentException($message);
        }
    }
}
