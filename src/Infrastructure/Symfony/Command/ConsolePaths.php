<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Command;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;

use function basename;
use function dirname;
use function file_exists;
use function is_dir;
use function is_file;
use function is_link;
use function preg_match;
use function realpath;
use function rtrim;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;

/**
 * Console file arguments (contract section 13.4): no stream wrappers (`phar:`, `php:`, `data:`, `glob:`, any
 * `scheme://`), symlinks resolved, the real path must be a regular file (no directory, FIFO or device) inside
 * `console.allowed_dirs` unless `--allow-any-path`; outputs go into an allowed directory, never through a symlink and
 * never over an existing file without `--force`.
 */
final readonly class ConsolePaths
{
    /**
     * @param list<string> $allowedDirs
     */
    public function __construct(private array $allowedDirs)
    {
    }

    /**
     * The real path of an input file.
     *
     * @throws InvalidValueException
     */
    public function input(string $path, bool $allowAnyPath): string
    {
        $this->refuseWrapper($path);
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new InvalidValueException('The file does not exist or is not a regular file.');
        }
        if (!$allowAnyPath && !$this->allowed($real)) {
            throw new InvalidValueException('The file is outside console.allowed_dirs (use --allow-any-path).');
        }

        return $real;
    }

    /**
     * The path of an output file.
     *
     * @throws InvalidValueException
     */
    public function output(string $path, bool $allowAnyPath, bool $force): string
    {
        $this->refuseWrapper($path);
        $dir = realpath(dirname($path));
        if ($dir === false || !is_dir($dir)) {
            throw new InvalidValueException('The output directory does not exist.');
        }
        $target = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($path);
        if (!$allowAnyPath && !$this->allowed($target)) {
            throw new InvalidValueException('The output is outside console.allowed_dirs (use --allow-any-path).');
        }
        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new InvalidValueException('The output must be a regular file, not a link.');
        }
        if (file_exists($target) && !$force) {
            throw new InvalidValueException('The output file exists (use --force to overwrite).');
        }

        return $target;
    }

    /**
     * Refuses stream wrappers and URL-like arguments.
     *
     * @throws InvalidValueException
     */
    private function refuseWrapper(string $path): void
    {
        if (preg_match('/\A[A-Za-z][A-Za-z0-9+.\-]+:/', $path) === 1) {
            throw new InvalidValueException('Stream wrappers and URLs are not accepted as files.');
        }
    }

    /**
     * Whether a real path lies inside an allowed directory.
     */
    private function allowed(string $real): bool
    {
        foreach ($this->allowedDirs as $dir) {
            $base = realpath($dir);
            if ($base !== false && str_starts_with($real, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
