<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Command;

use Nowo\SeedKitBundle\Application\SeedOperations;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function fclose;
use function is_string;

/**
 * `nowo:seed-kit:export <provider>` (alias `seed:export`): the empty commented template (`--empty`) or the current rows
 * the actor may read, as YAML (default), JSON or JSON Lines, to stdout or `--output`. Secrets leave only as `vault:`
 * references of the scope. Exit 0, 2 invalid, 4 denied / scope not found.
 */
#[AsCommand(name: 'nowo:seed-kit:export', description: 'Export a seed provider (empty template or current rows)')]
final class ExportCommand extends Command
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
            ->addArgument('provider', InputArgument::REQUIRED, 'Provider name (e.g. crm.contacts)')
            ->addOption('empty', null, InputOption::VALUE_NONE, 'Write the empty commented template instead of the current rows')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Target scope: kind or kind:id', 'platform')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'yaml, json or jsonl', 'yaml')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'File to write, or - for stdout', '-')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing output file')
            ->addOption('allow-any-path', null, InputOption::VALUE_NONE, 'Allow an output outside console.allowed_dirs')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who exports (audit); system for fresh installs');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = CommandSupport::errorStyle($input, $output);
        try {
            $format = $input->getOption('format');
            $format = is_string($format) ? DocumentFormat::tryFrom($format) : null;
            if ($format === null) {
                throw new InvalidValueException('--format must be yaml, json or jsonl.');
            }
            $provider = new ProviderName(CommandSupport::argument($input, 'provider'));
            $scope    = CommandSupport::scope($input);
            $actor    = $this->support->actor($input);
            $stream   = $this->support->output($input);
            try {
                $rows = $this->operations->export($provider, $scope, $input->getOption('empty') ? ExportMode::Empty : ExportMode::Current, $format, $actor, Channel::Console, $stream);
            } finally {
                fclose($stream);
            }
        } catch (SeedKitException $e) {
            return $this->support->fail($io, $e);
        }
        $io->writeln($input->getOption('empty') ? 'Empty template written.' : 'Exported ' . $rows . ' row(s).', OutputInterface::VERBOSITY_VERBOSE);

        return Command::SUCCESS;
    }
}
