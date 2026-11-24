<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Command;

use Nowo\SeedKitBundle\Application\RunMaintenance;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

/**
 * `nowo:seed-kit:actor:forget <ref>` (alias `seed:actor:forget`): nulls the actor of every run of `<ref>` (GDPR
 * erasure; the history stays). Asks unless `--yes`. Exit 0, 1 declined, 2 invalid ref.
 */
#[AsCommand(name: 'nowo:seed-kit:actor:forget', description: 'Remove an actor reference from the seed run history')]
final class ActorForgetCommand extends Command
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
            ->addArgument('ref', InputArgument::REQUIRED, 'Actor reference (user identifier)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = CommandSupport::errorStyle($input, $output);
        try {
            $actor = new ActorRef(CommandSupport::argument($input, 'ref'));
        } catch (SeedKitException $e) {
            return $this->support->fail($io, $e);
        }
        if (!$input->getOption('yes') && (!$input->isInteractive() || !$io->confirm('Remove this actor from every seed run?', false))) {
            $io->warning('Nothing changed.');

            return CommandSupport::EXIT_FAILURE;
        }
        $io->success(sprintf('%d run(s) updated.', $this->runs->forgetActor($actor)));

        return Command::SUCCESS;
    }
}
