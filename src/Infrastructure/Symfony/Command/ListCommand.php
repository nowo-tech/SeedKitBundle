<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Command;

use Nowo\SeedKitBundle\Application\SeedOperations;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function implode;
use function is_string;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

/**
 * `nowo:seed-kit:list` (alias `seed:list`): the registered providers in dependency order, with version, scope kinds,
 * dependencies, personal-data and secret properties.
 */
#[AsCommand(name: 'nowo:seed-kit:list', description: 'List the seed providers')]
final class ListCommand extends Command
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
            ->addOption('scope-kind', null, InputOption::VALUE_REQUIRED, 'Only providers available in this scope kind')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json', 'table');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = CommandSupport::errorStyle($input, $output);
        try {
            $kind = $input->getOption('scope-kind');
            $rows = [];
            foreach ($this->operations->providers(is_string($kind) && $kind !== '' ? new SeedScope(new ScopeKind($kind)) : null) as $provider) {
                $schema = $provider->schema();
                $kinds  = [];
                foreach ($provider->scopes() as $scopeKind) {
                    $kinds[] = $scopeKind->value;
                }
                $depends = [];
                foreach ($provider->dependsOn() as $dependency) {
                    $depends[] = $dependency->value;
                }
                $rows[] = [
                    'name'         => $provider->name()->value,
                    'version'      => $provider->version(),
                    'title'        => $schema->title($provider->name()->value),
                    'scopes'       => $kinds,
                    'dependsOn'    => $depends,
                    'personalData' => $schema->personalDataProperties(),
                    'secrets'      => $schema->secretProperties(),
                ];
            }
        } catch (SeedKitException $e) {
            return $this->support->fail($io, $e);
        }
        if ($input->getOption('format') === 'json') {
            $output->writeln((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }
        $table = [];
        foreach ($rows as $row) {
            $table[] = [$row['name'], $row['version'], $row['title'], implode(', ', $row['scopes']), implode(', ', $row['dependsOn']), implode(', ', $row['personalData']), implode(', ', $row['secrets'])];
        }
        $io->table(['Provider', 'Version', 'Title', 'Scopes', 'Depends on', 'Personal data', 'Secrets'], $table);

        return Command::SUCCESS;
    }
}
