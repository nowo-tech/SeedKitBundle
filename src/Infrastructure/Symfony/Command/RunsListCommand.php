<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Command;

use Nowo\SeedKitBundle\Application\RunMaintenance;
use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function array_keys;
use function implode;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function sprintf;

/**
 * `nowo:seed-kit:runs:list` (alias `seed:runs:list`): the newest runs (id, scope, actor, status, providers, counts,
 * batches, error code).
 */
#[AsCommand(name: 'nowo:seed-kit:runs:list', description: 'List the seed runs (history)')]
final class RunsListCommand extends Command
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
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Only runs of this scope (kind or kind:id)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Only runs with this status')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows (1-500)', '20');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = CommandSupport::errorStyle($input, $output);
        try {
            $status = $input->getOption('status');
            $status = is_string($status) && $status !== '' ? RunStatus::tryFrom($status) ?? throw new InvalidValueException('Unknown --status.') : null;
            $scope  = is_string($input->getOption('scope')) ? CommandSupport::scope($input) : null;
            $limit  = $input->getOption('limit');
            $runs   = $this->runs->list($scope, $status, is_numeric($limit) ? max(1, min(500, (int) $limit)) : 20);
        } catch (SeedKitException $e) {
            return $this->support->fail($io, $e);
        }
        $rows = [];
        foreach ($runs as $run) {
            $counts = [];
            foreach ($run->counts as $op => $count) {
                $counts[] = sprintf('%s %d', $op, $count);
            }
            $rows[] = [$run->id->value, $run->scope->key(), $run->actor !== null ? $run->actor->value : '-', $run->status->value, implode(', ', array_keys($run->providers)), implode(', ', $counts), $run->batchesDone, $run->errorCode ?? '', $run->createdAt->format('Y-m-d H:i:s')];
        }
        $io->table(['Run', 'Scope', 'Actor', 'Status', 'Providers', 'Written', 'Batches', 'Error', 'Created (UTC)'], $rows);

        return Command::SUCCESS;
    }
}
