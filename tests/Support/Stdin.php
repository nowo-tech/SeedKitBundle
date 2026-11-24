<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Support;

use Closure;
use RuntimeException;

use function defined;
use function fclose;
use function fopen;
use function fstat;
use function ftruncate;
use function fwrite;
use function is_array;
use function is_resource;
use function rewind;
use function sys_get_temp_dir;

use const STDIN;

/**
 * Runs code with the process standard input (`php://stdin`, file descriptor 0) reading a given text. The first call
 * closes STDIN, so a temporary file opened right after takes descriptor 0 (the lowest free one); the file stays open
 * for the rest of the process and each call rewrites and rewinds it (`php://stdin` is a duplicate of descriptor 0 and
 * shares its offset). Throws when descriptor 0 could not be taken (the caller skips the test).
 */
final class Stdin
{
    /** @var resource|null the file on descriptor 0 (test process only, never a worker) */
    private static $handle; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public static function with(string $content, Closure $work): mixed
    {
        $handle = self::handle();
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $content);
        rewind($handle);

        return $work();
    }

    /**
     * @return resource
     */
    private static function handle()
    {
        if (is_resource(self::$handle)) {
            return self::$handle;
        }
        if (defined('STDIN') && is_resource(STDIN)) {
            fclose(STDIN);
        }
        $handle = fopen(sys_get_temp_dir() . '/nowo-seed-kit-stdin', 'w+');
        $probe  = fopen('php://stdin', 'r');
        $same   = is_resource($handle) && is_resource($probe) && is_array($a = fstat($handle)) && is_array($b = fstat($probe)) && $a['ino'] === $b['ino'];
        if (is_resource($probe)) {
            fclose($probe);
        }
        if (!$same || !is_resource($handle)) {
            throw new RuntimeException('Standard input could not be redirected.');
        }

        return self::$handle = $handle; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
    }
}
