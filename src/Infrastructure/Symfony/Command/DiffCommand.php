<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Command;

use Nowo\SeedKitBundle\Application\PruneOptions;
use Nowo\SeedKitBundle\Application\SeedOperations;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\TransactionContract\ConcurrencyConflict;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_numeric;

/**
 * `nowo:seed-kit:diff <file|->` (alias `seed:diff`): the dry run. Validates the whole document and prints the plan
 * (counts, changed rows and fields, plan hash); nothing is written. Exit 0 (or 9 with `--exit-code` when there are
 * changes), 2 invalid, 4 denied / scope not found.
 */
#[AsCommand(name: 'nowo:seed-kit:diff', description: 'Dry run: validate a seed file and show what apply would change')]
final class DiffCommand extends Command
{
    /**
     * Builds the command.
     */
    public function __construct(private readonly SeedOperations $operations, private readonly CommandSupport $support)
    {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Seed file (.yaml, .json, .jsonl) or - for stdin', '-')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Target scope: kind or kind:id', 'platform')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Also plan deleting the rows the file omits')
            ->addOption('allow-mass-prune', null, InputOption::VALUE_NONE, 'Accept a prune above prune.max_ratio')
            ->addOption('allow-empty-prune', null, InputOption::VALUE_NONE, 'Accept a prune with an empty document')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text')
            ->addOption('max-rows', null, InputOption::VALUE_REQUIRED, 'Changed rows shown per provider', '50')
            ->addOption('exit-code', null, InputOption::VALUE_NONE, 'Exit 9 when the plan has changes')
            ->addOption('allow-any-path', null, InputOption::VALUE_NONE, 'Allow a file outside console.allowed_dirs')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who plans (audit); system for fresh installs');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = CommandSupport::errorStyle($input, $output);
        try {
            $set  = $this->support->read($input, 'file');
            $plan = $this->operations->diff(
                $set,
                CommandSupport::scope($input),
                $this->support->actor($input),
                Channel::Console,
                new PruneOptions((bool) $input->getOption('prune'), (bool) $input->getOption('allow-mass-prune'), (bool) $input->getOption('allow-empty-prune')),
            );
        } catch (SeedKitException|ConcurrencyConflict $e) {
            return $this->support->fail($io, $e);
        }
        $max = $input->getOption('max-rows');
        $max = is_numeric($max) ? (int) $max : 50;
        if ($input->getOption('format') === 'json') {
            PlanPrinter::json($output, $plan, $max);
        } else {
            PlanPrinter::text($output, $plan, $max);
        }

        return $input->getOption('exit-code') && $plan->changes() > 0 ? CommandSupport::EXIT_CHANGES : Command::SUCCESS;
    }
}
