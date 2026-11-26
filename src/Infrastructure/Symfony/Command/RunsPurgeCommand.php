<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Command;

use DateTimeImmutable;
use Exception;
use Nowo\SeedKitBundle\Application\RunMaintenance;
use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\TransactionContract\ConcurrencyConflict;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function sprintf;

/**
 * `nowo:seed-kit:runs:purge` (alias `seed:runs:purge`): deletes finished runs older than `--before` (default: now minus
 * `runs.history_days`), in batches; `--dry-run` only counts. Exit 0, 1 on a lock conflict, 2 for a bad date.
 */
#[AsCommand(name: 'nowo:seed-kit:runs:purge', description: 'Expire stored run documents past their TTL and delete finished seed runs older than the history window')]
final class RunsPurgeCommand extends Command
{
    /**
     * Builds the command.
     */
    public function __construct(private readonly RunMaintenance $runs, private readonly CommandSupport $support)
    {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this
            ->addOption('before', null, InputOption::VALUE_REQUIRED, 'Delete runs created before this date (UTC, e.g. 2026-01-01)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = CommandSupport::errorStyle($input, $output);
        try {
            $before = $input->getOption('before');
            try {
                $limit = is_string($before) && $before !== '' ? new DateTimeImmutable($before . ' UTC') : $this->runs->defaultBefore();
            } catch (Exception) {
                throw new InvalidValueException('--before is not a date.');
            }
            $dryRun  = (bool) $input->getOption('dry-run');
            $expired = $dryRun ? 0 : $this->runs->expire();
            $count   = $this->runs->purge($limit, $dryRun);
        } catch (SeedKitException $e) {
            return $this->support->fail($io, $e);
        } catch (ConcurrencyConflict $e) {
            $io->error($e->getMessage());

            return CommandSupport::EXIT_FAILURE;
        }
        $io->success($dryRun ? sprintf('%d run(s) would be deleted.', $count) : sprintf('%d run(s) deleted, %d stored document(s) expired.', $count, $expired));

        return Command::SUCCESS;
    }
}
