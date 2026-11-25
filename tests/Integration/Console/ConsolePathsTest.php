<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Console;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\ConsolePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function function_exists;
use function mkdir;
use function posix_mkfifo;
use function realpath;
use function symlink;
use function sys_get_temp_dir;

/**
 * Console file arguments (contract section 13.4): allowed directories, stream wrappers, symlinks, regular files only,
 * outputs never over an existing file without --force nor through a link.
 */
final class ConsolePathsTest extends TestCase
{
    private string $root;

    private string $allowed;

    private string $outside;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nowo-seed-kit-paths';
        (new Filesystem())->remove($this->root);
        $this->allowed = $this->root . '/allowed';
        $this->outside = $this->root . '/outside';
        mkdir($this->allowed, 0o777, true);
        mkdir($this->outside, 0o777, true);
        file_put_contents($this->allowed . '/tags.yaml', "provider: x\n");
        file_put_contents($this->outside . '/secret.yaml', "provider: x\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    private function paths(): ConsolePaths
    {
        return new ConsolePaths([$this->root . '/missing', $this->allowed . '/']);
    }

    private function refused(string $message, callable $call): void
    {
        try {
            $call();
            self::fail('Not refused: ' . $message);
        } catch (InvalidValueException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    #[Test]
    public function anInputInsideTheAllowedDirectoryResolves(): void
    {
        self::assertSame(realpath($this->allowed . '/tags.yaml'), $this->paths()->input($this->allowed . '/../allowed/tags.yaml', false));
        self::assertSame(realpath($this->outside . '/secret.yaml'), $this->paths()->input($this->outside . '/secret.yaml', true), '--allow-any-path');
    }

    #[Test]
    public function inputsOutsideOrNotRegularAreRefused(): void
    {
        $paths = $this->paths();
        $this->refused('outside console.allowed_dirs', fn (): string => $paths->input($this->outside . '/secret.yaml', false));
        $this->refused('outside console.allowed_dirs', fn (): string => $paths->input($this->allowed . '/../outside/secret.yaml', false));
        $this->refused('does not exist or is not a regular file', fn (): string => $paths->input($this->allowed . '/missing.yaml', false));
        $this->refused('does not exist or is not a regular file', fn (): string => $paths->input($this->allowed, true));

        symlink($this->outside . '/secret.yaml', $this->allowed . '/escape.yaml');
        $this->refused('outside console.allowed_dirs', fn (): string => $paths->input($this->allowed . '/escape.yaml', false));
        self::assertSame(realpath($this->outside . '/secret.yaml'), $paths->input($this->allowed . '/escape.yaml', true));

        if (function_exists('posix_mkfifo')) {
            posix_mkfifo($this->allowed . '/fifo.yaml', 0o600);
            $this->refused('not a regular file', fn (): string => $paths->input($this->allowed . '/fifo.yaml', true));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrappers(): iterable
    {
        yield 'phar' => ['phar:///tmp/a.phar/x.yaml'];
        yield 'php' => ['php://filter/resource=/etc/passwd'];
        yield 'data' => ['data:text/plain,provider'];
        yield 'glob' => ['glob:///tmp/*'];
        yield 'url' => ['https://example.com/x.yaml'];
        yield 'compress' => ['compress.zlib:///tmp/x.gz'];
    }

    #[Test]
    #[DataProvider('wrappers')]
    public function streamWrappersAreRefused(string $path): void
    {
        $this->refused('Stream wrappers and URLs are not accepted', fn (): string => $this->paths()->input($path, true));
        $this->refused('Stream wrappers and URLs are not accepted', fn (): string => $this->paths()->output($path, true, true));
    }

    #[Test]
    public function outputsStayInsideAndNeverOverwriteSilently(): void
    {
        $paths = $this->paths();

        self::assertSame(realpath($this->allowed) . '/new.yaml', $paths->output($this->allowed . '/new.yaml', false, false));
        self::assertSame(realpath($this->allowed) . '/tags.yaml', $paths->output($this->allowed . '/tags.yaml', false, true), '--force');
        self::assertSame(realpath($this->outside) . '/new.yaml', $paths->output($this->outside . '/new.yaml', true, false));
        $this->refused('The output file exists (use --force', fn (): string => $paths->output($this->allowed . '/tags.yaml', false, false));
        $this->refused('The output is outside console.allowed_dirs', fn (): string => $paths->output($this->outside . '/new.yaml', false, false));
        $this->refused('The output directory does not exist', fn (): string => $paths->output($this->allowed . '/nope/new.yaml', false, false));
        $this->refused('The output must be a regular file, not a link', fn (): string => $paths->output($this->root . '/allowed', true, true));

        symlink($this->outside . '/secret.yaml', $this->allowed . '/link.yaml');
        $this->refused('The output must be a regular file, not a link', fn (): string => $paths->output($this->allowed . '/link.yaml', false, true));
        symlink($this->outside, $this->allowed . '/dirlink');
        $this->refused('The output is outside console.allowed_dirs', fn (): string => $paths->output($this->allowed . '/dirlink/new.yaml', false, false));
    }
}
