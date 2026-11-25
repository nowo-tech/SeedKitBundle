<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Run;

/**
 * Where a call comes from. The system actor and `--allow-any-path` exist only on the console channel.
 */
enum Channel: string
{
    case Http      = 'http';
    case Console   = 'console';
    case Messenger = 'messenger';
}
