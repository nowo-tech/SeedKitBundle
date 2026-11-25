<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Console;

use Closure;
use LogicException;
use Nowo\SeedKitBundle\Application\RunMaintenance;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\SeedKitBundle\Domain\Exception\ApplyInterruptedException;
use Nowo\SeedKitBundle\Domain\Exception\DocumentInvalidException;
use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;
use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Exception\LeaseHeldException;
use Nowo\SeedKitBundle\Domain\Exception\ScopeNotFoundException;
use Nowo\SeedKitBundle\Domain\Exception\SecretValueRefusedException;
use Nowo\SeedKitBundle\Domain\Exception\StalePlanException;
use Nowo\SeedKitBundle\Domain\Exception\UnknownProviderException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Plan\RowError;
use Nowo\SeedKitBundle\Domain\Port\SeedActorResolverInterface;
use Nowo\SeedKitBundle\Infrastructure\Reader\DocumentReader;
use Nowo\SeedKitBundle\Infrastructure\Reader\ReaderLimits;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\CommandSupport;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\ConsolePaths;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\RunsPurgeCommand;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\ConflictingRunStore;
use Nowo\SeedKitBundle\Tests\Support\Stdin;
use Nowo\TransactionContract\ConcurrencyConflict;
use Nowo\TransactionContract\TransientFailureKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Throwable;

use function fclose;
use function file_put_contents;
use function fopen;
use function is_file;
use function restore_error_handler;
use function rewind;
use function set_error_handler;
use function stream_get_contents;
use function stream_get_meta_data;
use function sys_get_temp_dir;
use function unlink;

/**
 * The console helper without a kernel: actor rules, stdin, files that cannot be opened, translation fallbacks and the
 * mapping of every failure to its exit code (contract section 12.4).
 */
final class CommandSupportTest extends TestCase
{
    private static function support(?ActorRef $current = null, bool $requireActor = true, ?Translator $translator = null): CommandSupport
    {
        $resolver = new class($current) implements SeedActorResolverInterface {
            public function __construct(private readonly ?ActorRef $actor)
            {
            }

            public function current(): ?ActorRef
            {
                return $this->actor;
            }
        };

        return new CommandSupport($resolver, new DocumentReader(), new ReaderLimits(), new ConsolePaths([sys_get_temp_dir()]), $requireActor, $translator);
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function input(array $values): ArrayInput
    {
        return new ArrayInput($values, new InputDefinition([
            new InputArgument('file', InputArgument::OPTIONAL, '', '-'),
            new InputOption('actor', null, InputOption::VALUE_REQUIRED),
            new InputOption('scope', null, InputOption::VALUE_REQUIRED),
            new InputOption('output', null, InputOption::VALUE_REQUIRED),
            new InputOption('force', null, InputOption::VALUE_NONE),
            new InputOption('allow-any-path', null, InputOption::VALUE_NONE),
        ]));
    }

    /**
     * Runs code with PHP warnings silenced (a file that cannot be opened warns before the helper refuses it).
     *
     * @param Closure(): mixed $call
     */
    private function quietly(Closure $call): void
    {
        set_error_handler(static fn (): bool => true); // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        try {
            $call();
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function theActorComesFromTheOptionTheResolverOrTheSystem(): void
    {
        self::assertSame('ops', self::support(new ActorRef('alice'))->actor(self::input(['--actor' => 'ops']))->value);
        self::assertSame('alice', self::support(new ActorRef('alice'))->actor(self::input(['--actor' => '']))->value);
        self::assertTrue(self::support(null, false)->actor(self::input([]))->isSystem());

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('This command needs --actor=<who>');
        self::support()->actor(self::input([]));
    }

    #[Test]
    public function theScopeAndArgumentsHaveDefaults(): void
    {
        self::assertSame('platform', CommandSupport::scope(self::input([]))->key());
        self::assertSame('platform', CommandSupport::scope(self::input(['--scope' => '']))->key());
        self::assertSame('organization:acme', CommandSupport::scope(self::input(['--scope' => 'organization:acme']))->key());
        self::assertSame('-', CommandSupport::argument(self::input([]), 'file'));
        self::assertSame('', CommandSupport::argument(new ArrayInput([], new InputDefinition([new InputArgument('file', InputArgument::IS_ARRAY)])), 'file'));
        self::assertSame('{"a":"é/"}', CommandSupport::display(['a' => 'é/']));
    }

    #[Test]
    public function aDashReadsStandardInput(): void
    {
        $document = "provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - {slug: news, label: News}\n";
        try {
            $set = Stdin::with($document, static fn (): DocumentSet => self::support()->read(self::input(['file' => '-']), 'file'));
        } catch (RuntimeException $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::assertCount(1, $set->documents);
        self::assertSame('host.tags', $set->documents[0]->header->provider->value);
    }

    #[Test]
    public function aFileThatCannotBeOpenedIsRefused(): void
    {
        // sysfs: a write-only attribute is a regular file that even root cannot open for reading.
        $unreadable = '/sys/bus/platform/uevent';
        if (!is_file($unreadable)) {
            self::markTestSkipped('No write-only sysfs attribute here.');
        }
        $this->quietly(static function () use ($unreadable): void {
            try {
                self::support()->read(self::input(['file' => $unreadable, '--allow-any-path' => true]), 'file');
                self::fail('opened');
            } catch (InvalidValueException $e) {
                self::assertSame('The file cannot be opened.', $e->getMessage());
            }
        });
    }

    #[Test]
    public function anOutputThatCannotBeOpenedIsRefused(): void
    {
        // sysfs is mounted read-only: the directory exists, no file can be created in it.
        $this->quietly(static function (): void {
            try {
                self::support()->output(self::input(['--output' => '/sys/bus/platform/seed.yaml', '--allow-any-path' => true]));
                self::fail('opened');
            } catch (InvalidValueException $e) {
                self::assertSame('The output cannot be opened.', $e->getMessage());
            }
        });

        $file   = sys_get_temp_dir() . '/seed-support-output.yaml';
        $stream = self::support()->output(self::input(['--output' => $file, '--force' => true]));
        file_put_contents($file, 'x');
        self::assertIsResource($stream);
        unlink($file);
    }

    #[Test]
    public function withoutForceTheOutputIsCreatedExclusively(): void
    {
        $file = sys_get_temp_dir() . '/seed-support-exclusive.yaml';
        if (is_file($file)) {
            unlink($file);
        }
        $stream = self::support()->output(self::input(['--output' => $file, '--allow-any-path' => true]));
        self::assertIsResource($stream);
        self::assertSame('x', stream_get_meta_data($stream)['mode'], 'exclusive create: a link planted after the check is not followed (I4)');
        fclose($stream);
        unlink($file);
    }

    #[Test]
    public function messagesAreTranslatedOrFallBack(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['seed_kit.known' => 'Known %n%'], 'en', CommandSupport::DOMAIN);

        self::assertSame('fallback', self::support()->trans('seed_kit.known', 'fallback'));
        self::assertSame('Known 3', self::support(null, true, $translator)->trans('seed_kit.known', 'fallback', ['%n%' => 3]));
        self::assertSame('fallback', self::support(null, true, $translator)->trans('seed_kit.unknown', 'fallback'));
    }

    #[Test]
    public function messagesGoToStandardErrorWhenThereIsOne(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $console = new ConsoleOutput();
        $console->setErrorOutput(new StreamOutput($stream));
        CommandSupport::errorStyle(new ArrayInput([]), $console)->writeln('to stderr');
        rewind($stream);
        self::assertSame("to stderr\n", stream_get_contents($stream));

        $buffered = new BufferedOutput();
        CommandSupport::errorStyle(new ArrayInput([]), $buffered)->writeln('to the only output');
        self::assertSame("to the only output\n", $buffered->fetch());
    }

    /**
     * @return iterable<string, array{Throwable, int, list<string>}>
     */
    public static function failures(): iterable
    {
        $error = new RowError(3, new NaturalKey(['news']), '/color', 'enum', 'seed_kit.row.enum', [], 'host.tags');
        $root  = new RowError(0, null, '', 'required', 'seed_kit.row.none');
        $lock  = new ConcurrencyConflict(3, TransientFailureKind::LockWaitTimeout, 2, new RuntimeException('wait'));

        yield 'lock conflict' => [$lock, CommandSupport::EXIT_CONFLICT, ['[CONFLICT_001]']];
        yield 'invalid document' => [new DocumentInvalidException([$error, $root], true), CommandSupport::EXIT_INVALID, ['[SEED_003]', 'host.tags #3 ["news"] /color [enum]', '#0 - / [required] required', 'more errors']];
        yield 'secret refused' => [new SecretValueRefusedException([$error]), CommandSupport::EXIT_INVALID, ['[SEED_004]', '/color']];
        yield 'unreadable' => [new DocumentReadException('yaml_alias', 4), CommandSupport::EXIT_INVALID, ['[SEED_002]', 'YAML aliases are not allowed.']];
        yield 'unknown provider' => [new UnknownProviderException(), CommandSupport::EXIT_INVALID, ['[SEED_001]']];
        yield 'lease held' => [new LeaseHeldException(42), CommandSupport::EXIT_CONFLICT, ['Retry after 42 second(s).']];
        yield 'stale' => [new StalePlanException(), CommandSupport::EXIT_STALE, ['[SEED_005]']];
        yield 'denied' => [new AccessDeniedException(), CommandSupport::EXIT_DENIED, ['[ACCESS_002]']];
        yield 'scope not found' => [new ScopeNotFoundException(), CommandSupport::EXIT_DENIED, ['[ACCESS_001]']];
        yield 'partial' => [new ApplyInterruptedException(ApplyInterruptedException::ROW_CHANGED, 1, 3), CommandSupport::EXIT_PARTIAL, ['[SEED_006]']];
        yield 'row changed first' => [new ApplyInterruptedException(ApplyInterruptedException::ROW_CHANGED, 0, 3), CommandSupport::EXIT_STALE, ['[SEED_006]']];
        yield 'conflict first' => [new ApplyInterruptedException(ApplyInterruptedException::CONFLICT, 0, 3), CommandSupport::EXIT_CONFLICT, ['[CONFLICT_001]']];
        yield 'conflict later' => [new ApplyInterruptedException(ApplyInterruptedException::CONFLICT, 2, 3), CommandSupport::EXIT_PARTIAL, ['[CONFLICT_002]']];
        yield 'provider failed' => [new ApplyInterruptedException(ApplyInterruptedException::PROVIDER_FAILED, 0, 3), CommandSupport::EXIT_FAILURE, ['[SEED_010]']];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('failures')]
    public function failuresMapToExitCodes(Throwable $failure, int $code, array $expected): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['seed_kit.read.yaml_alias' => 'YAML aliases are not allowed.'], 'en', CommandSupport::DOMAIN);
        $output = new BufferedOutput();

        self::assertSame($code, self::support(null, true, $translator)->fail(new SymfonyStyle(new ArrayInput([]), $output), $failure));
        $text = $output->fetch();
        foreach ($expected as $part) {
            self::assertStringContainsString($part, $text);
        }
    }

    #[Test]
    public function otherFailuresAreRethrown(): void
    {
        $this->expectException(LogicException::class);

        self::support()->fail(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()), new LogicException('bug'));
    }

    #[Test]
    public function aPurgeThatMeetsALockConflictExitsOne(): void
    {
        $tester = new CommandTester(new RunsPurgeCommand(new RunMaintenance(new ConflictingRunStore(), new MockClock('2026-11-24 10:00:00')), self::support()));

        self::assertSame(CommandSupport::EXIT_FAILURE, $tester->execute([]));
        self::assertStringContainsString('ERROR', $tester->getDisplay());
    }
}
