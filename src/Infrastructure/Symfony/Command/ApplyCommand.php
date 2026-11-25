<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Command;

use Nowo\SeedKitBundle\Application\PruneOptions;
use Nowo\SeedKitBundle\Application\SeedOperations;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Exception\StalePlanException;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\TransactionContract\ConcurrencyConflict;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function sprintf;

/**
 * `nowo:seed-kit:apply <file|->` (alias `seed:apply`): plans (same as `diff`), shows the summary and the plan hash,
 * asks (or `--yes`), then applies in batches through the providers; the apply re-plans and refuses a stale plan.
 * `--expect-hash` binds the apply to a reviewed dry run. Exit codes: 0 applied or nothing to do, 2 invalid, 3 stale,
 * 4 denied, 5 lock conflict (nothing committed), 6 partially applied (re-apply converges), 7 declined / no TTY without
 * `--yes`, 1 unexpected.
 */
#[AsCommand(name: 'nowo:seed-kit:apply', description: 'Apply a seed file (dry run first, then batched writes through the providers)')]
final class ApplyCommand extends Command
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
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Delete the rows of the scope the file omits (needs SEED_PRUNE)')
            ->addOption('allow-mass-prune', null, InputOption::VALUE_NONE, 'Accept a prune above prune.max_ratio')
            ->addOption('allow-empty-prune', null, InputOption::VALUE_NONE, 'Accept a prune with an empty document')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Apply without asking (CI)')
            ->addOption('expect-hash', null, InputOption::VALUE_REQUIRED, 'Refuse unless the plan hash is this one (sk1:...)')
            ->addOption('allow-any-path', null, InputOption::VALUE_NONE, 'Allow a file outside console.allowed_dirs')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who applies (audit); system for fresh installs');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = CommandSupport::errorStyle($input, $output);
        try {
            $expected = $input->getOption('expect-hash');
            $expected = is_string($expected) && $expected !== '' ? new PlanHash($expected) : null;
            $set      = $this->support->read($input, 'file');
            $scope    = CommandSupport::scope($input);
            $actor    = $this->support->actor($input);
            $prune    = new PruneOptions((bool) $input->getOption('prune'), (bool) $input->getOption('allow-mass-prune'), (bool) $input->getOption('allow-empty-prune'));
            $plan     = $this->operations->diff($set, $scope, $actor, Channel::Console, $prune);
            PlanPrinter::text($io, $plan, 20);
            if ($expected instanceof PlanHash && !$plan->hash->equals($expected)) {
                throw new StalePlanException();
            }
            if ($plan->changes() === 0) {
                $io->success('Nothing to do: the data already matches the file.');

                return Command::SUCCESS;
            }
            if (!$input->getOption('yes')) {
                if (!$input->isInteractive()) {
                    $io->error('Not applied: pass --yes to apply without a prompt.');

                    return CommandSupport::EXIT_DECLINED;
                }
                if (!$io->confirm(sprintf('Apply %d change(s)?', $plan->changes()), false)) {
                    $io->warning('Not applied.');

                    return CommandSupport::EXIT_DECLINED;
                }
            }
            $result = $this->operations->apply($set, $scope, $actor, Channel::Console, $prune, $plan->hash);
        } catch (SeedKitException|ConcurrencyConflict $e) {
            return $this->support->fail($io, $e);
        }
        $io->success(sprintf(
            'Applied: %d created, %d updated, %d deleted in %d batch(es)%s.',
            $result->written[ChangeOp::Create->value] ?? 0,
            $result->written[ChangeOp::Update->value] ?? 0,
            $result->written[ChangeOp::Delete->value] ?? 0,
            $result->batches,
            $result->runId instanceof RunId ? ' (run ' . $result->runId->value . ')' : '',
        ));

        return Command::SUCCESS;
    }
}
