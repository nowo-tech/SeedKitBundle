<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Reader;

/**
 * A stream wrapper whose reads fail (`fread()` returns false) while it never reports the end: the reader's
 * "unreadable input" path.
 */
final class FailingReadStream
{
    /** The scheme the tests register. */
    public const SCHEME = 'seedkit-failing';

    /** @var resource|null */
    public $context;

    /**
     * Opens the stream.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    /**
     * Fails every read.
     */
    public function stream_read(int $count): false
    {
        return false;
    }

    /**
     * Never at the end.
     */
    public function stream_eof(): bool
    {
        return false;
    }
}
