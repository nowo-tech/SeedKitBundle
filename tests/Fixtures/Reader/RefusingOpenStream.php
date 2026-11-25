<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Reader;

/**
 * A stream wrapper that refuses every open: registered in place of `php://` so the reader's private temp buffer
 * cannot be created.
 */
final class RefusingOpenStream
{
    /** @var resource|null */
    public $context;

    /**
     * Refuses the open.
     */
    public function stream_open(): bool
    {
        return false;
    }
}
