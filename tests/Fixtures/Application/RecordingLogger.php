<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Application;

use Psr\Log\AbstractLogger;
use Stringable;

use function is_string;

/**
 * Keeps every log record as [level, message, context].
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [is_string($level) ? $level : '?', (string) $message, $context];
    }
}
