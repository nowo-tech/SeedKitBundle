<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Command;

use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\SeedKitBundle\Domain\Exception\ApplyInterruptedException;
use Nowo\SeedKitBundle\Domain\Exception\DocumentInvalidException;
use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Exception\LeaseHeldException;
use Nowo\SeedKitBundle\Domain\Exception\ScopeNotFoundException;
use Nowo\SeedKitBundle\Domain\Exception\SecretValueRefusedException;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Exception\StalePlanException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\RowError;
use Nowo\SeedKitBundle\Domain\Port\SeedActorResolverInterface;
use Nowo\SeedKitBundle\Infrastructure\Reader\DocumentReader;
use Nowo\SeedKitBundle\Infrastructure\Reader\ReaderLimits;
use Nowo\TransactionContract\ConcurrencyConflict;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function fclose;
use function fopen;
use function is_resource;
use function is_string;
use function json_encode;
use function sprintf;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Shared console helpers (contract section 12.4): the actor (`--actor`, required by writing commands unless
 * `console.require_actor: false`; `--actor=system` only here, QB-SK-5), the scope option, confined file arguments
 * ({@see ConsolePaths}), the reader, and the mapping of failures to messages and exit codes: 1 unexpected / provider
 * failure, 2 invalid input, 3 stale plan, 4 denied or scope not found, 5 lock conflict (`CONFLICT_001`), 6 partially
 * applied, 7 declined, 9 changes found (`diff --exit-code`). Messages are static and translated; values never appear.
 */
final readonly class CommandSupport
{
    /** Unexpected failure, or a provider failure before any commit. */
    public const EXIT_FAILURE = 1;

    /** Invalid document, option or argument. */
    public const EXIT_INVALID = 2;

    /** Stale plan (`--expect-hash`, or a row changed before any commit). */
    public const EXIT_STALE = 3;

    /** Access denied or scope not found. */
    public const EXIT_DENIED = 4;

    /** Lock conflict, nothing committed (`CONFLICT_001`). */
    public const EXIT_CONFLICT = 5;

    /** Partially applied (`CONFLICT_002`, or a failure after a commit). */
    public const EXIT_PARTIAL = 6;

    /** Declined at the prompt, or non-interactive without `--yes`. */
    public const EXIT_DECLINED = 7;

    /** `diff --exit-code`: the plan has changes. */
    public const EXIT_CHANGES = 9;

    /** Translation domain. */
    public const DOMAIN = 'NowoSeedKitBundle';

    /**
     * Builds the helper.
     */
    public function __construct(
        private SeedActorResolverInterface $actors,
        private DocumentReader $reader,
        private ReaderLimits $limits,
        private ConsolePaths $paths,
        private bool $requireActor = true,
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * The actor of the command.
     *
     * @throws InvalidValueException when a writing command has no actor
     */
    public function actor(InputInterface $input): ActorRef
    {
        $option = $input->getOption('actor');
        if (is_string($option) && $option !== '') {
            return new ActorRef($option);
        }
        $actor = $this->actors->current();
        if ($actor instanceof ActorRef) {
            return $actor;
        }
        if ($this->requireActor) {
            throw new InvalidValueException('This command needs --actor=<who> (audit; --actor=system for fresh installs).');
        }

        return ActorRef::system();
    }

    /**
     * The `--scope` option (`kind` or `kind:id`, default `platform`).
     *
     * @throws InvalidValueException
     */
    public static function scope(InputInterface $input): SeedScope
    {
        $scope = $input->getOption('scope');

        return SeedScope::parse(is_string($scope) && $scope !== '' ? $scope : 'platform');
    }

    /**
     * A string argument ('' when absent).
     */
    public static function argument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? $value : '';
    }

    /**
     * Reads the document argument (`-` = stdin).
     *
     * @throws SeedKitException
     */
    public function read(InputInterface $input, string $argument): DocumentSet
    {
        $path = $input->getArgument($argument);
        $path = is_string($path) ? $path : '-';
        if ($path === '-') {
            $stream = fopen('php://stdin', 'r');
            $format = null;
        } else {
            $real   = $this->paths->input($path, (bool) $input->getOption('allow-any-path'));
            $stream = fopen($real, 'r');
            $format = DocumentReader::formatOfName($real);
        }
        if (!is_resource($stream)) {
            throw new InvalidValueException('The file cannot be opened.');
        }
        try {
            return $this->reader->read($stream, $format, $this->limits);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Opens the `--output` target (`-` = stdout).
     *
     * @throws InvalidValueException
     *
     * @return resource
     */
    public function output(InputInterface $input)
    {
        $path  = $input->getOption('output');
        $path  = is_string($path) && $path !== '' ? $path : '-';
        $force = (bool) $input->getOption('force');
        $real  = $path === '-' ? 'php://stdout' : $this->paths->output($path, (bool) $input->getOption('allow-any-path'), $force);
        // Without --force the file is created exclusively (`x`): a file or link planted after the path check makes the
        // open fail instead of being followed or overwritten.
        $stream = fopen($real, $path === '-' || $force ? 'w' : 'x');
        if (!is_resource($stream)) {
            throw new InvalidValueException('The output cannot be opened.');
        }

        return $stream;
    }

    /**
     * The style for messages (stderr, so stdout carries data).
     */
    public static function errorStyle(InputInterface $input, OutputInterface $output): SymfonyStyle
    {
        return new SymfonyStyle($input, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
    }

    /**
     * A translated message, or the fallback.
     *
     * @param array<string, scalar> $parameters
     */
    public function trans(string $key, string $fallback, array $parameters = []): string
    {
        if (!$this->translator instanceof TranslatorInterface) {
            return $fallback;
        }
        $message = $this->translator->trans($key, $parameters, self::DOMAIN);

        return $message === $key ? $fallback : $message;
    }

    /**
     * Prints a failure (static, translated) and returns its exit code; rethrows what is not a SeedKit failure.
     *
     * @throws Throwable
     */
    public function fail(SymfonyStyle $io, Throwable $e): int
    {
        if ($e instanceof ConcurrencyConflict) {
            $io->error(sprintf('[CONFLICT_001] %s', $e->getMessage()));

            return self::EXIT_CONFLICT;
        }
        if (!$e instanceof SeedKitException) {
            throw $e;
        }
        $io->error(sprintf('[%s] %s', $e->errorCode(), $this->trans($e->messageKey(), $e->getMessage())));
        if ($e instanceof DocumentInvalidException || $e instanceof SecretValueRefusedException) {
            $this->rowErrors($io, $e->errors, $e->truncated);
        }
        if ($e instanceof LeaseHeldException) {
            $io->writeln(sprintf('Retry after %d second(s).', $e->retryAfterSeconds));
        }

        return match (true) {
            $e instanceof ApplyInterruptedException => $this->interruptedCode($e),
            $e instanceof LeaseHeldException        => self::EXIT_CONFLICT,
            $e instanceof StalePlanException        => self::EXIT_STALE,
            $e instanceof AccessDeniedException,
            $e instanceof ScopeNotFoundException => self::EXIT_DENIED,
            default                              => self::EXIT_INVALID,
        };
    }

    /**
     * Prints row errors (index, natural key, pointer, rule, message; never the value).
     *
     * @param list<RowError> $errors
     */
    public function rowErrors(SymfonyStyle $io, array $errors, bool $truncated): void
    {
        foreach ($errors as $error) {
            $io->writeln(sprintf(
                '  %s #%d %s %s [%s] %s',
                $error->provider ?? '',
                $error->index,
                $error->key !== null ? $error->key->canonical : '-',
                $error->path === '' ? '/' : $error->path,
                $error->rule,
                $this->trans($error->messageKey, $error->rule, $error->messageParams),
            ));
        }
        if ($truncated) {
            $io->writeln('  … more errors (validation.max_errors).');
        }
    }

    /**
     * A JSON value for display.
     */
    public static function display(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The exit code of an interrupted apply.
     */
    private function interruptedCode(ApplyInterruptedException $e): int
    {
        if ($e->isPartial()) {
            return self::EXIT_PARTIAL;
        }

        return match ($e->kind) {
            ApplyInterruptedException::ROW_CHANGED => self::EXIT_STALE,
            ApplyInterruptedException::CONFLICT    => self::EXIT_CONFLICT,
            default                                => self::EXIT_FAILURE,
        };
    }
}
