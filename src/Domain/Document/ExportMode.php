<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Document;

/**
 * Export modes: an empty commented template, or the current rows the actor may read (round-trip stable).
 */
enum ExportMode: string
{
    case Empty   = 'empty';
    case Current = 'current';
}
