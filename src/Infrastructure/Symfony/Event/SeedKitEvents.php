<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Event;

/**
 * Event names of the bundle (the domain events are dispatched by class name).
 */
final class SeedKitEvents
{
    /** {@see SeedAccessCheckEvent}: grant or deny an export, apply or prune (house access pattern, REQ-UI-002). */
    public const ACCESS_CHECK = 'nowo_seed_kit.access_check';
}
