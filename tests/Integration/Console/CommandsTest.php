<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Console;

use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\LeaseStoreInterface;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\CommandSupport;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\RecordingLogger;
use Nowo\SeedKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SeedKitBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\SeedKitBundle\Tests\Support\Stdin;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function array_column;
use function file_get_contents;
use function json_decode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * Every console command through the kernel (contract section 12.4) on the standard host with the DB-backed fixture
 * providers: outputs, formats, files and every exit code.
 */
final class CommandsTest extends KernelTestCase
{
    private const TAGS = <<<'YAML'
        provider: host.tags
        version: 1
        scope: platform
        items:
          - {slug: news, label: News, color: red, apiToken: 'vault:ok-1'}
          - {slug: Sport, label: Sport}

        YAML;

    private const BOTH = <<<'YAML'
        provider: host.tags
        version: 1
        scope: platform
        items:
          - {slug: news, label: News}
        ---
        provider: host.articles
        version: 1
        scope: platform
        items:
          - {code: A-1, title: First, tag: news, level: 2, owner: bob}

        YAML;

    protected function setUp(): void
    {
        $this->boot();
    }

    /**
     * @return array<mixed>
     */
    private static function json(string $text): array
    {
        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * A nested value.
     */
    private static function at(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    #[Test]
    public function listShowsTheProvidersAsATableOrJson(): void
    {
        $table = $this->command('nowo:seed-kit:list');
        self::assertSame(0, $table->getStatusCode());
        self::assertStringContainsString('host.tags', $table->getDisplay());
        self::assertStringContainsString('apiToken', $table->getDisplay());

        $json = self::json($this->command('seed:list', ['--format' => 'json', '--scope-kind' => 'organization'])->getDisplay());
        self::assertCount(2, $json);
        self::assertSame(['name' => 'host.articles', 'version' => 1, 'title' => 'Host articles', 'scopes' => ['platform', 'organization'], 'dependsOn' => ['host.tags'], 'personalData' => ['owner'], 'secrets' => []], $json[1]);

        self::assertSame(CommandSupport::EXIT_INVALID, $this->command('nowo:seed-kit:list', ['--scope-kind' => 'Bad Kind'])->getStatusCode());
    }

    #[Test]
    public function schemaWritesTheDocumentSchemaToAFile(): void
    {
        $file   = TestKernel::allowedDir() . '/host.tags.v1.schema.json';
        $tester = $this->command('nowo:seed-kit:schema', ['provider' => 'host.tags', '--output' => $file]);
        self::assertSame(0, $tester->getStatusCode());
        $schema = self::json((string) file_get_contents($file));
        self::assertSame('urn:nowo-seed:host.tags:1', $schema['$id']);

        self::assertSame(CommandSupport::EXIT_INVALID, $this->command('nowo:seed-kit:schema', ['provider' => 'host.tags', '--output' => $file])->getStatusCode(), 'exists, no --force');
        self::assertSame(0, $this->command('nowo:seed-kit:schema', ['provider' => 'host.tags', '--output' => $file, '--force' => true])->getStatusCode());
        $unknown = $this->command('nowo:seed-kit:schema', ['provider' => 'host.unknown', '--output' => $file, '--force' => true]);
        self::assertSame(CommandSupport::EXIT_INVALID, $unknown->getStatusCode());
        self::assertStringContainsString('[SEED_001]', $unknown->getDisplay());
    }

    #[Test]
    public function exportWritesTemplatesAndCurrentRows(): void
    {
        $this->insertTag('news', 'News', 'red', 'vault:ok-1');
        $this->insertTag('old', 'Old', null, 'vault:other');
        $this->insertArticle('A-1', 'Mine', 'bob');
        $this->insertArticle('B-1', 'Theirs', 'alice');
        $dir = TestKernel::allowedDir();

        $empty = $this->command('nowo:seed-kit:export', ['provider' => 'host.tags', '--empty' => true, '--actor' => 'ops', '--output' => $dir . '/empty.yaml'], ['verbosity' => 256]);
        self::assertSame(0, $empty->getStatusCode());
        self::assertStringContainsString('Empty template written.', $empty->getDisplay());
        self::assertStringContainsString('#   - slug: news', (string) file_get_contents($dir . '/empty.yaml'));

        $current = $this->command('nowo:seed-kit:export', ['provider' => 'host.tags', '--format' => 'json', '--actor' => 'ops', '--output' => $dir . '/tags.json'], ['verbosity' => 256]);
        self::assertSame(0, $current->getStatusCode());
        self::assertStringContainsString('Exported 2 row(s).', $current->getDisplay());
        $json = (string) file_get_contents($dir . '/tags.json');
        self::assertStringContainsString('vault:ok-1', $json);
        self::assertStringNotContainsString('vault:other', $json, 'a reference of another scope is omitted');

        self::assertSame(0, $this->command('nowo:seed-kit:export', ['provider' => 'host.articles', '--format' => 'jsonl', '--actor' => 'bob', '--output' => $dir . '/bob.jsonl'])->getStatusCode());
        $lines = (string) file_get_contents($dir . '/bob.jsonl');
        self::assertStringContainsString('A-1', $lines);
        self::assertStringNotContainsString('B-1', $lines, 'bob reads only his articles');
        $logger = $this->service('logger', RecordingLogger::class);
        self::assertContains('Seed {action} by {actor} in {scope}: {outcome}.', array_column($logger->records, 1), 'a personal-data export is audited');

        self::assertSame(CommandSupport::EXIT_INVALID, $this->command('nowo:seed-kit:export', ['provider' => 'host.tags', '--format' => 'xml', '--actor' => 'ops'])->getStatusCode());
        self::assertSame(CommandSupport::EXIT_INVALID, $this->command('nowo:seed-kit:export', ['provider' => 'host.tags', '--output' => $dir . '/x.yaml'])->getStatusCode(), '--actor required');
        self::assertSame(CommandSupport::EXIT_DENIED, $this->command('nowo:seed-kit:export', ['provider' => 'host.tags', '--scope' => 'organization:acme', '--actor' => 'ops', '--output' => $dir . '/y.yaml'])->getStatusCode());
        $this->listener()->deny = ['SEED_EXPORT'];
        $denied                 = $this->command('nowo:seed-kit:export', ['provider' => 'host.tags', '--actor' => 'ops', '--output' => $dir . '/z.yaml'], ['capture_stderr_separately' => true]);
        self::assertSame(CommandSupport::EXIT_DENIED, $denied->getStatusCode());
        self::assertStringContainsString('[ACCESS_002] Access denied.', $denied->getErrorOutput());
    }

    #[Test]
    public function withoutRequireActorTheConsoleActsAsSystem(): void
    {
        $this->boot(['console' => ['require_actor' => false]]);

        self::assertSame(0, $this->command('nowo:seed-kit:export', ['provider' => 'host.tags', '--output' => TestKernel::allowedDir() . '/system.yaml', '--force' => true])->getStatusCode());
    }

    #[Test]
    public function diffPrintsThePlanAsTextOrJson(): void
    {
        $this->insertTag('news', 'Old news', 'blue');
        $this->insertTag('sport', 'Sport');
        $file = $this->file('tags.yaml', self::TAGS);

        $text = $this->command('nowo:seed-kit:diff', ['file' => $file, '--actor' => 'ops']);
        self::assertSame(0, $text->getStatusCode());
        $display = $text->getDisplay();
        self::assertStringContainsString('host.tags v1: 0 to create, 1 to update, 1 unchanged, 0 to delete', $display);
        self::assertStringContainsString('~ ["news"]', $display);
        self::assertStringContainsString('/label: "Old news" -> "News"', $display);
        self::assertStringContainsString('/apiToken: (absent) -> "vault:ok-1"', $display);
        self::assertStringContainsString('Plan hash: sk1:', $display);

        self::assertSame(CommandSupport::EXIT_CHANGES, $this->command('seed:diff', ['file' => $file, '--actor' => 'ops', '--exit-code' => true])->getStatusCode());

        $json = self::json($this->command('nowo:seed-kit:diff', ['file' => $file, '--actor' => 'ops', '--format' => 'json', '--max-rows' => 'many'])->getDisplay());
        self::assertSame('platform', $json['scope']);
        self::assertSame(1, $json['changes']);
        self::assertIsArray($json['documents']);
        self::assertIsArray($json['documents'][0]);
        $rows = $json['documents'][0]['rows'];
        self::assertIsArray($rows);
        self::assertSame(['op' => 'update', 'key' => ['news'], 'index' => 0, 'fields' => [
            ['path' => '/label', 'before' => ['kind' => 'value', 'value' => 'Old news'], 'after' => ['kind' => 'value', 'value' => 'News']],
            ['path' => '/color', 'before' => ['kind' => 'value', 'value' => 'blue'], 'after' => ['kind' => 'value', 'value' => 'red']],
            ['path' => '/apiToken', 'before' => ['kind' => 'absent'], 'after' => ['kind' => 'value', 'value' => 'vault:ok-1']],
        ]], $rows[0]);
    }

    #[Test]
    public function diffMasksValuesTheActorMayNotExportAndShowsWarnings(): void
    {
        $this->insertTag('news', 'Old news');
        $file                   = $this->file('masked.yaml', "provider: host.tags\nversion: 1\nscope: platform\nmeta: {exportedFrom: acme}\nitems:\n  - {slug: news, label: News}\n  - {slug: a, label: A}\n  - {slug: b, label: B}\n");
        $this->listener()->deny = ['SEED_EXPORT'];

        $text = $this->command('nowo:seed-kit:diff', ['file' => $file, '--actor' => 'ops', '--max-rows' => '1', '--exit-code' => true]);
        self::assertSame(CommandSupport::EXIT_CHANGES, $text->getStatusCode());
        self::assertStringContainsString('/label: (hidden) -> "News"', $text->getDisplay());
        self::assertStringContainsString('  …', $text->getDisplay());
        self::assertStringContainsString('warning: ', $text->getDisplay());

        $json = self::json($this->command('nowo:seed-kit:diff', ['file' => $file, '--actor' => 'ops', '--format' => 'json', '--max-rows' => '2'])->getDisplay());
        self::assertIsArray($json['warnings']);
        self::assertCount(1, $json['warnings']);
        self::assertIsArray($json['documents']);
        self::assertIsArray($json['documents'][0]);
        self::assertIsArray($json['documents'][0]['rows']);
        self::assertCount(2, $json['documents'][0]['rows']);
        self::assertSame(['kind' => 'hidden'], self::at($json, 'documents', 0, 'rows', 0, 'fields', 0, 'before'));
    }

    #[Test]
    public function diffRefusesInvalidDocuments(): void
    {
        $invalid = $this->file('invalid.yaml', "provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - {slug: news, label: News, color: purple}\n");
        $tester  = $this->command('nowo:seed-kit:diff', ['file' => $invalid, '--actor' => 'ops'], ['capture_stderr_separately' => true]);
        self::assertSame(CommandSupport::EXIT_INVALID, $tester->getStatusCode());
        self::assertStringContainsString('[SEED_003]', $tester->getErrorOutput());
        self::assertStringContainsString('/color', $tester->getErrorOutput());
        self::assertStringNotContainsString('purple', $tester->getErrorOutput(), 'values never appear');

        $plain  = $this->file('plain.yaml', "provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - {slug: news, label: News, apiToken: hunter2}\n");
        $tester = $this->command('nowo:seed-kit:diff', ['file' => $plain, '--actor' => 'ops']);
        self::assertSame(CommandSupport::EXIT_INVALID, $tester->getStatusCode());
        self::assertStringNotContainsString('hunter2', $tester->getDisplay());

        $broken = $this->file('broken.yaml', "provider: host.tags\nitems: &a [1]\n");
        self::assertSame(CommandSupport::EXIT_INVALID, $this->command('nowo:seed-kit:diff', ['file' => $broken, '--actor' => 'ops'])->getStatusCode());
        self::assertSame(CommandSupport::EXIT_INVALID, $this->command('nowo:seed-kit:diff', ['file' => '/etc/hostname', '--actor' => 'ops'])->getStatusCode(), 'outside the allowed directories');
        self::assertSame(CommandSupport::EXIT_DENIED, $this->command('nowo:seed-kit:diff', ['file' => $plain, '--actor' => 'ops', '--scope' => 'project'])->getStatusCode(), 'unknown scope kind');
    }

    #[Test]
    public function applyWritesWithYesAndReportsTheRun(): void
    {
        $file   = $this->file('both.yaml', self::BOTH);
        $tester = $this->command('nowo:seed-kit:apply', ['file' => $file, '--actor' => 'ops', '--yes' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/Applied: 2 created, 0 updated, 0 deleted in 2 batch\(es\) \(run\s+[0-9a-f-]{36}\)\./', $tester->getDisplay());
        self::assertSame('News', $this->connection()->fetchOne("SELECT label FROM host_tag WHERE slug = 'news'"));
        self::assertSame('First', $this->connection()->fetchOne("SELECT title FROM host_article WHERE code = 'A-1'"));

        $again = $this->command('seed:apply', ['file' => $file, '--actor' => 'ops', '--yes' => true]);
        self::assertSame(0, $again->getStatusCode());
        self::assertStringContainsString('Nothing to do', $again->getDisplay());

        $runs = $this->command('nowo:seed-kit:runs:list', ['--status' => 'applied', '--scope' => 'platform', '--limit' => 'all']);
        self::assertSame(0, $runs->getStatusCode());
        self::assertStringContainsString('host.tags, host.articles', $runs->getDisplay());
        self::assertStringContainsString('create 2', $runs->getDisplay());
        self::assertStringContainsString('ops', $runs->getDisplay());
    }

    #[Test]
    public function applyAsksAndCanBeDeclined(): void
    {
        $file = $this->file('tags.yaml', self::TAGS);

        self::assertSame(CommandSupport::EXIT_DECLINED, $this->command('nowo:seed-kit:apply', ['file' => $file, '--actor' => 'ops'])->getStatusCode(), 'no TTY, no --yes');
        self::assertSame(CommandSupport::EXIT_DECLINED, $this->command('nowo:seed-kit:apply', ['file' => $file, '--actor' => 'ops'], ['interactive' => true], ['no'])->getStatusCode());
        self::assertSame(0, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM host_tag')));

        $yes = $this->command('nowo:seed-kit:apply', ['file' => $file, '--actor' => 'ops'], ['interactive' => true], ['yes']);
        self::assertSame(0, $yes->getStatusCode());
        self::assertStringContainsString('Apply 2 change(s)?', $yes->getDisplay());
        self::assertSame(2, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM host_tag')));
    }

    #[Test]
    public function applyHonoursTheReviewedHash(): void
    {
        $file = $this->file('tags.yaml', self::TAGS);
        $plan = self::json($this->command('nowo:seed-kit:diff', ['file' => $file, '--actor' => 'ops', '--format' => 'json'])->getDisplay());
        self::assertIsString($plan['planHash']);

        self::assertSame(CommandSupport::EXIT_STALE, $this->command('nowo:seed-kit:apply', ['file' => $file, '--actor' => 'ops', '--yes' => true, '--expect-hash' => 'sk1:' . str_repeat('0', 64)])->getStatusCode());
        self::assertSame(CommandSupport::EXIT_INVALID, $this->command('nowo:seed-kit:apply', ['file' => $file, '--actor' => 'ops', '--yes' => true, '--expect-hash' => 'md5:nope'])->getStatusCode());
        self::assertSame(0, $this->command('nowo:seed-kit:apply', ['file' => $file, '--actor' => 'ops', '--yes' => true, '--expect-hash' => $plan['planHash']])->getStatusCode());
    }

    #[Test]
    public function applyAnswersALeaseHeldByAnotherRun(): void
    {
        $leases = $this->service(LeaseStoreInterface::class, LeaseStoreInterface::class);
        self::assertSame(0, $leases->acquire(new ProviderName('host.tags'), SeedScope::platform(), new RunId('01936f2a-0000-7000-8000-000000000001'), 120));

        $tester = $this->command('nowo:seed-kit:apply', ['file' => $this->file('tags.yaml', self::TAGS), '--actor' => 'ops', '--yes' => true]);
        self::assertSame(CommandSupport::EXIT_CONFLICT, $tester->getStatusCode());
        self::assertStringContainsString('Retry after 120 second(s).', $tester->getDisplay());
    }

    #[Test]
    public function aProviderFailureBeforeAnyCommitExitsOneAndAfterExitsSix(): void
    {
        $this->boot(['apply' => ['batch_size' => 1]]);
        $first = $this->command('nowo:seed-kit:apply', ['file' => $this->file('boom.yaml', "provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - {slug: a, label: boom}\n"), '--actor' => 'ops', '--yes' => true]);
        self::assertSame(CommandSupport::EXIT_FAILURE, $first->getStatusCode());
        self::assertStringContainsString('[SEED_010]', $first->getDisplay());

        $later = $this->command('nowo:seed-kit:apply', ['file' => $this->file('later.yaml', "provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - {slug: a, label: A}\n  - {slug: b, label: boom}\n"), '--actor' => 'ops', '--yes' => true]);
        self::assertSame(CommandSupport::EXIT_PARTIAL, $later->getStatusCode());
        self::assertSame('A', $this->connection()->fetchOne("SELECT label FROM host_tag WHERE slug = 'a'"));

        $runs = $this->command('nowo:seed-kit:runs:list', ['--status' => 'partially_applied']);
        self::assertStringContainsString('SEED_010', $runs->getDisplay());
        self::assertStringContainsString('failed', $this->command('nowo:seed-kit:runs:list')->getDisplay());
    }

    #[Test]
    public function applyIsDeniedWithoutAccessAndPrunesWithIt(): void
    {
        $this->insertTag('gone', 'Gone');
        $this->insertTag('stays', 'Stays');
        $file = $this->file('prune.yaml', "provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - {slug: stays, label: Stays}\n  - {slug: new, label: New}\n");

        $this->listener()->deny = ['SEED_PRUNE:host.tags'];
        self::assertSame(CommandSupport::EXIT_DENIED, $this->command('nowo:seed-kit:apply', ['file' => $file, '--actor' => 'ops', '--yes' => true, '--prune' => true])->getStatusCode());
        $this->listener()->reset();

        $tester = $this->command('nowo:seed-kit:apply', ['file' => $file, '--actor' => 'ops', '--yes' => true, '--prune' => true, '--allow-mass-prune' => true, '--allow-empty-prune' => true]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Applied: 1 created, 0 updated, 1 deleted', $tester->getDisplay());
        self::assertFalse($this->connection()->fetchOne("SELECT label FROM host_tag WHERE slug = 'gone'"));
    }

    #[Test]
    public function runsListRefusesAnUnknownStatus(): void
    {
        self::assertSame(CommandSupport::EXIT_INVALID, $this->command('nowo:seed-kit:runs:list', ['--status' => 'bogus'])->getStatusCode());
        self::assertSame(CommandSupport::EXIT_INVALID, $this->command('nowo:seed-kit:runs:list', ['--scope' => 'Bad:'])->getStatusCode());
    }

    #[Test]
    public function runsPurgeCountsAndDeletesFinishedRuns(): void
    {
        self::assertSame(0, $this->command('nowo:seed-kit:apply', ['file' => $this->file('tags.yaml', self::TAGS), '--actor' => 'ops', '--yes' => true])->getStatusCode());

        $dry = $this->command('nowo:seed-kit:runs:purge', ['--before' => '2030-01-01', '--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode());
        self::assertStringContainsString('1 run(s) would be deleted.', $dry->getDisplay());
        self::assertStringContainsString('0 run(s) deleted.', $this->command('seed:runs:purge')->getDisplay(), 'history window: nothing old enough');
        self::assertSame(CommandSupport::EXIT_INVALID, $this->command('nowo:seed-kit:runs:purge', ['--before' => 'not a date'])->getStatusCode());
        self::assertStringContainsString('1 run(s) deleted.', $this->command('nowo:seed-kit:runs:purge', ['--before' => '2030-01-01'])->getDisplay());
    }

    #[Test]
    public function actorForgetAsksThenNullsTheActor(): void
    {
        self::assertSame(0, $this->command('nowo:seed-kit:apply', ['file' => $this->file('tags.yaml', self::TAGS), '--actor' => 'ops', '--yes' => true])->getStatusCode());

        self::assertSame(CommandSupport::EXIT_FAILURE, $this->command('nowo:seed-kit:actor:forget', ['ref' => 'ops'])->getStatusCode(), 'non-interactive without --yes');
        self::assertSame(CommandSupport::EXIT_FAILURE, $this->command('nowo:seed-kit:actor:forget', ['ref' => 'ops'], ['interactive' => true], ['no'])->getStatusCode());
        self::assertSame(CommandSupport::EXIT_INVALID, $this->command('nowo:seed-kit:actor:forget', ['ref' => ' ops'])->getStatusCode());
        self::assertStringContainsString('1 run(s) updated.', $this->command('nowo:seed-kit:actor:forget', ['ref' => 'ops'], ['interactive' => true], ['yes'])->getDisplay());
        self::assertStringContainsString('0 run(s) updated.', $this->command('seed:actor:forget', ['ref' => 'ops', '--yes' => true])->getDisplay());
        self::assertNull($this->connection()->fetchOne('SELECT actor_ref FROM nowo_seed_run'));
    }

    #[Test]
    public function lintChecksEveryOrOneProvider(): void
    {
        $all = $this->command('nowo:seed-kit:lint');
        self::assertSame(0, $all->getStatusCode());
        self::assertStringContainsString('2 provider schema(s) OK.', $all->getDisplay());
        self::assertStringContainsString('1 provider schema(s) OK.', $this->command('seed:lint', ['provider' => 'host.tags'])->getDisplay());
        self::assertSame(CommandSupport::EXIT_INVALID, $this->command('nowo:seed-kit:lint', ['provider' => 'host.unknown'])->getStatusCode());

        $this->boot([], ['broken' => true], 'standard', false);
        $broken = $this->command('nowo:seed-kit:lint');
        self::assertSame(CommandSupport::EXIT_INVALID, $broken->getStatusCode());
        self::assertStringContainsString('host.broken', $broken->getDisplay());
        self::assertStringContainsString('looks like a secret', $broken->getDisplay());

        $this->boot([], [], 'ddd', false);
        $warned = $this->command('nowo:seed-kit:lint', ['provider' => 'host.notes']);
        self::assertSame(0, $warned->getStatusCode());
        self::assertStringContainsString('Property "body" has no description', $warned->getDisplay());
    }

    #[Test]
    public function aDashReadsTheDocumentFromStandardInput(): void
    {
        try {
            $tester = Stdin::with(self::TAGS, fn () => $this->command('nowo:seed-kit:apply', ['file' => '-', '--actor' => 'ops', '--yes' => true]));
        } catch (RuntimeException $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(2, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM host_tag')));
    }
}
