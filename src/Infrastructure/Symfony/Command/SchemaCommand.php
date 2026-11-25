<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Command;

use Nowo\SeedKitBundle\Application\SeedOperations;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function fclose;
use function fwrite;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * `nowo:seed-kit:schema <provider>` (alias `seed:schema`): the document JSON Schema of a provider (draft 2020-12,
 * contract section 7.1), for editors and AI tools. Exit 0, or 2 for an unknown provider.
 */
#[AsCommand(name: 'nowo:seed-kit:schema', description: 'Print the JSON Schema of a seed provider document')]
final class SchemaCommand extends Command
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
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'File to write, or - for stdout', '-')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing output file')
            ->addOption('allow-any-path', null, InputOption::VALUE_NONE, 'Allow an output outside console.allowed_dirs');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = CommandSupport::errorStyle($input, $output);
        try {
            $schema = $this->operations->schema(new ProviderName(CommandSupport::argument($input, 'provider')));
            $stream = $this->support->output($input);
            fwrite($stream, json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
            fclose($stream);
        } catch (SeedKitException $e) {
            return $this->support->fail($io, $e);
        }

        return Command::SUCCESS;
    }
}
