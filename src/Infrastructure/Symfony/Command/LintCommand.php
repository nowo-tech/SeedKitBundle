<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Command;

use Nowo\SeedKitBundle\Application\ProviderRegistry;
use Nowo\SeedKitBundle\Application\SchemaLinter;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function is_string;
use function sprintf;

/**
 * `nowo:seed-kit:lint [provider]` (alias `seed:lint`): lints the provider schemas (contract section 7.3). Exit 0, or 2
 * when a schema has errors (warnings are printed only).
 */
#[AsCommand(name: 'nowo:seed-kit:lint', description: 'Lint the JSON Schemas of the seed providers')]
final class LintCommand extends Command
{
    /**
     * Builds the command.
     */
    public function __construct(private readonly ProviderRegistry $registry, private readonly SchemaLinter $linter, private readonly CommandSupport $support)
    {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->addArgument('provider', InputArgument::OPTIONAL, 'Only this provider');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = CommandSupport::errorStyle($input, $output);
        try {
            $name      = $input->getArgument('provider');
            $providers = is_string($name) ? [$this->registry->get(new ProviderName($name))] : $this->registry->all();
        } catch (SeedKitException $e) {
            return $this->support->fail($io, $e);
        }
        $failed = false;
        foreach ($providers as $provider) {
            $result = $this->linter->lint($provider);
            foreach ($result['errors'] as $error) {
                $io->writeln(sprintf('<error>%s</error> %s', $provider->name()->value, $error));
                $failed = true;
            }
            foreach ($result['warnings'] as $warning) {
                $io->writeln(sprintf('<comment>%s</comment> %s', $provider->name()->value, $warning));
            }
        }
        if ($failed) {
            return CommandSupport::EXIT_INVALID;
        }
        $io->success(sprintf('%d provider schema(s) OK.', count($providers)));

        return Command::SUCCESS;
    }
}
