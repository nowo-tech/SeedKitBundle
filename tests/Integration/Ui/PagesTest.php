<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Ui;

use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\LeaseStoreInterface;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DomCrawler\Crawler;

use function json_decode;
use function preg_match;

/**
 * The opt-in UI end to end through the kernel (contract 12.5, REQ-UI-002 / 003, SC-004): guard per profile, downloads,
 * the upload dialog and page, the run page, apply, discard, runs, progress, conflicts and refusals.
 */
final class PagesTest extends UiTestCase
{
    private const VALID = "provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - { slug: news, label: News }\n  - { slug: blog, label: Blog, color: red }\n";

    #[Test]
    public function theGuardAnswersPerProfile(): void
    {
        $browser = $this->ui();
        $browser->request('GET', '/developer/seeds');
        self::assertSame(401, $this->code(), 'no user: the firewall entry point');

        $this->login('viewer', 'ROLE_VIEWER');
        $browser->request('GET', '/developer/seeds');
        self::assertSame(403, $this->code(), 'no area access');

        $this->login('system');
        $browser->request('GET', '/developer/seeds');
        self::assertSame(403, $this->code(), 'the system actor is refused on the web');

        $this->login('admin');
        $browser->request('GET', '/organizations/nope/seeds');
        self::assertSame(404, $this->code(), 'unknown scope');
        $this->login('bob');
        $browser->request('GET', '/organizations/globex/seeds');
        self::assertSame(404, $this->code(), 'invisible scope = unknown scope');
        $browser->request('GET', '/organizations/acme/seeds');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('/organizations/acme/seeds/upload', $this->body(), 'links keep the scope id');
    }

    #[Test]
    public function theIndexListsWhatTheViewerMayUse(): void
    {
        $browser = $this->ui(['security' => ['providers' => ['host.articles' => ['apply_roles' => ['ROLE_NOBODY']]]]]);
        $this->login('admin');
        $crawler = $browser->request('GET', '/developer/seeds');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertCount(1, $crawler->filter('[data-testid="seed-kit-provider-host.tags"]'));
        self::assertCount(1, $crawler->filter('[data-testid="seed-kit-personal-host.articles"]'));
        self::assertStringContainsString('Export only', $this->body());
        self::assertCount(1, $crawler->filter('[data-testid="seed-kit-upload-dialog"]'), 'the dialog shell, empty until it opens');
        self::assertCount(0, $crawler->filter('[data-testid="seed-kit-upload-dialog"] form'));
        self::assertStringContainsString('Import / export', $crawler->filter('h1')->text());

        $browser->request('GET', '/developer/seeds/providers/host.articles/schema.json');
        self::assertSame(200, $this->code(), 'export-only: the schema stays readable');

        $browser = $this->ui(['security' => ['export_roles' => ['ROLE_NOBODY'], 'apply_roles' => ['ROLE_NOBODY']]]);
        $this->login('admin');
        $crawler = $browser->request('GET', '/developer/seeds');
        self::assertCount(0, $crawler->filter('[data-testid^="seed-kit-provider-"]'));
        self::assertCount(0, $crawler->filter('[data-testid="seed-kit-upload-open"]'));
        $browser->request('GET', '/developer/seeds/providers/host.tags/export');
        self::assertSame(404, $this->code(), 'a provider the viewer cannot use is not found');
        $browser->request('GET', '/developer/seeds/upload');
        self::assertSame(403, $this->code());
    }

    #[Test]
    public function downloadsAreAttachmentsAndPersonalDataIsConfirmedFirst(): void
    {
        $browser = $this->ui();
        $this->login('admin');
        $this->insertTag('news', 'News');
        $this->insertArticle('a1', 'First', 'ana');

        $browser->request('GET', '/developer/seeds/providers/host.tags/export?mode=empty&format=json');
        $response = $browser->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^attachment; filename="host\.tags\.platform\.\d{8}-\d{6}\.seed\.json"$/', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('"provider": "host.tags"', $browser->getInternalResponse()->getContent());

        $browser->request('GET', '/developer/seeds/providers/host.tags/export');
        self::assertSame(200, $this->code());
        $this->connection()->insert('host_note', ['scope_key' => 'platform', 'code' => 'n1', 'body' => 'Hello']);
        $browser->request('GET', '/developer/seeds/providers/host.notes/export?mode=current&format=jsonl');
        self::assertSame(200, $this->code(), 'no personal data: no confirmation');
        self::assertStringContainsString('"n1"', $browser->getInternalResponse()->getContent());
        $browser->request('GET', '/developer/seeds/providers/host.tags/export?mode=everything');
        self::assertSame(404, $this->code());
        $browser->request('POST', '/developer/seeds/providers/host.tags/export');
        self::assertSame(404, $this->code(), 'POST only for a confirmed sensitive export');
        $browser->request('GET', '/developer/seeds/providers/host.nope/export');
        self::assertSame(404, $this->code());
        $browser->request('GET', '/developer/seeds/providers/host.tags/schema.json');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('urn:nowo-seed:host.tags:1', $browser->getInternalResponse()->getContent());

        $crawler = $browser->request('GET', '/developer/seeds/providers/host.articles/export?mode=current');
        self::assertSame(200, $this->code(), 'the confirmation page');
        self::assertCount(1, $crawler->filter('[data-testid="seed-kit-export-confirmation"]'));
        $browser->request('POST', '/developer/seeds/providers/host.articles/export?mode=current', ['seed_kit_action' => ['confirm' => '1', '_token' => 'forged']]);
        self::assertSame(403, $this->code(), 'CSRF');
        $form = $crawler->filter('form[action*="/providers/host.articles/export"]')->form();
        $browser->submit($form);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('ana', $browser->getInternalResponse()->getContent());
    }

    #[Test]
    public function uploadDiffApplyAndHistory(): void
    {
        $browser = $this->ui();
        $this->login('admin');

        $crawler = $browser->request('GET', '/developer/seeds/upload', [], [], ['HTTP_X_NOWO_DIALOG' => '1']);
        self::assertSame(200, $this->code());
        self::assertStringNotContainsString('<html', $this->body(), 'the dialog gets a fragment');
        self::assertCount(1, $crawler->filter('[data-testid="seed-kit-upload-fragment"]'));
        self::assertStringContainsString('X-Nowo-Dialog', (string) $browser->getResponse()->headers->get('Vary'));

        $crawler = $browser->request('GET', '/developer/seeds/upload');
        self::assertSame(200, $this->code());
        self::assertCount(0, $crawler->filter('input[name="seed_kit_upload[prune]"]'), 'prune only with SEED_PRUNE');
        $form = $crawler->filter('form[data-testid="seed-kit-upload-form"]')->form();
        self::attach($form, "provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - { slug: news, label: News, color: purple }\n");
        $crawler = $browser->submit($form);
        self::assertSame(422, $this->code());
        self::assertCount(1, $crawler->filter('[data-testid="seed-kit-upload-error"]'));
        self::assertStringContainsString('news', $crawler->filter('[data-testid="seed-kit-upload-error"]')->text());
        self::assertStringNotContainsString('purple', $crawler->filter('[data-testid="seed-kit-upload-errors"]')->text(), 'never the submitted value');

        $form = $crawler->filter('form[data-testid="seed-kit-upload-form"]')->form();
        self::attach($form, "provider: host.tags\nversion: 1\nscope: platform\nitems: [{\"k\":&a [1]}]\n");
        $browser->submit($form);
        self::assertSame(422, $this->code(), 'the hardened reader refuses anchors');
        self::assertStringContainsString('anchors are not allowed', $this->body());

        $crawler = $browser->request('GET', '/developer/seeds/upload', [], [], ['HTTP_X_NOWO_DIALOG' => '1']);
        $form    = $crawler->filter('form[data-testid="seed-kit-upload-form"]')->form();
        self::attach($form, self::VALID);
        $browser->submit($form, [], ['HTTP_X_NOWO_DIALOG' => '1']);
        self::assertSame(204, $this->code(), 'saved in the dialog');
        $location = (string) $browser->getResponse()->headers->get('X-Nowo-Redirect');
        self::assertSame(1, preg_match('#^/developer/seeds/runs/([0-9a-f-]{36})$#', $location, $match));
        self::assertSame(0, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM host_tag')), 'a dry run writes nothing');

        $crawler = $browser->request('GET', $location);
        self::assertSame(200, $this->code());
        self::assertSame('planned', $crawler->filter('[data-testid="seed-kit-run"]')->attr('data-status'));
        self::assertCount(2, $crawler->filter('[data-testid="seed-kit-diff-row"]'));
        self::assertStringContainsString('2 to create', $crawler->filter('[data-testid="seed-kit-run-planned"]')->text());

        $browser->request('GET', $location . '/progress');
        $progress = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertSame(['status' => 'planned', 'batchesDone' => 0, 'batchesTotal' => 1, 'counts' => ['create' => 0, 'update' => 0, 'delete' => 0], 'errorCode' => null], $progress);

        $browser->request('POST', $location . '/apply', ['seed_kit_apply' => ['plan_hash' => 'sk1:x', '_token' => 'forged']]);
        self::assertSame(422, $this->code(), 'CSRF / invalid form: the page again');

        $browser->submit($crawler->filter('form[action$="/apply"]')->form());
        self::assertSame(303, $this->code());
        $crawler = $browser->followRedirect();
        self::assertSame('applied', $crawler->filter('[data-testid="seed-kit-run"]')->attr('data-status'));
        self::assertSame(2, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM host_tag')));
        self::assertCount(0, $crawler->filter('form[action$="/apply"]'), 'nothing left to apply');

        $crawler = $browser->request('GET', '/developer/seeds/runs');
        self::assertSame(200, $this->code());
        self::assertCount(1, $crawler->filter('[data-testid="seed-kit-run-row-status"]'));
        self::assertCount(0, $crawler->filter('[data-testid="seed-kit-runs-older"]'));

        $this->login('pruner', 'ROLE_PRUNE');
        $crawler = $browser->request('GET', $location);
        self::assertSame(200, $this->code(), 'another viewer with apply on every provider sees the run');
        self::assertCount(0, $crawler->filter('form[action$="/apply"]'));
        $browser->request('POST', $location . '/discard', ['seed_kit_action' => ['confirm' => '1']]);
        self::assertSame(403, $this->code());
    }

    #[Test]
    public function discardAndThePagerOfOlderRuns(): void
    {
        $browser = $this->ui(['ui' => ['runs_per_page' => 5]]);
        $this->login('admin');
        $locations = [];
        for ($i = 0; $i < 6; ++$i) {
            $crawler = $browser->request('GET', '/developer/seeds/upload');
            $form    = $crawler->filter('form[data-testid="seed-kit-upload-form"]')->form();
            self::attach($form, "provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - { slug: t{$i}, label: T }\n");
            $browser->submit($form);
            self::assertSame(303, $this->code());
            $locations[] = (string) $browser->getResponse()->headers->get('Location');
        }
        $crawler = $browser->request('GET', '/developer/seeds/runs');
        self::assertCount(5, $crawler->filter('[data-testid="seed-kit-run-row-status"]'));
        $older   = $crawler->filter('[data-testid="seed-kit-runs-older"]')->attr('href');
        $crawler = $browser->request('GET', (string) $older);
        self::assertCount(1, $crawler->filter('[data-testid="seed-kit-run-row-status"]'));

        $crawler = $browser->request('GET', $locations[0]);
        $browser->submit($crawler->filter('form[action$="/discard"]')->form());
        self::assertSame(303, $this->code());
        $crawler = $browser->request('GET', $locations[0]);
        self::assertSame('discarded', $crawler->filter('[data-testid="seed-kit-run"]')->attr('data-status'));
        $browser->request('GET', '/developer/seeds/runs/01936f2a-0000-7000-8000-000000000099');
        self::assertSame(404, $this->code());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, int, string}>
     */
    public static function refusals(): iterable
    {
        yield 'plan too large without Messenger' => [['ui' => ['max_sync_changes' => 1]], 'none', 413, 'SEED_009'];
        yield 'lease held' => [[], 'lease', 409, 'CONFLICT_001'];
        yield 'stale plan' => [[], 'stale', 409, 'SEED_005'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('refusals')]
    public function refusalsHaveTheirOwnPage(array $config, string $setup, int $status, string $code): void
    {
        $browser = $this->ui($config);
        $this->login('admin');
        $crawler = $this->planned(self::VALID);
        if ($setup === 'lease') {
            $this->service(LeaseStoreInterface::class, LeaseStoreInterface::class)->acquire(new ProviderName('host.tags'), SeedScope::platform(), new RunId('01936f2a-0000-7000-8000-000000000077'), 120);
        }
        if ($setup === 'stale') {
            $this->insertTag('news', 'Meanwhile');
        }
        $browser->submit($crawler->filter('form[action$="/apply"]')->form());
        self::assertSame($status, $this->code());
        $page = new Crawler($this->body());
        self::assertSame($code, $page->filter('[data-testid="seed-kit-message"]')->attr('data-code'));
        if ($setup === 'lease') {
            self::assertSame('CONFLICT_001', $browser->getResponse()->headers->get('X-Conflict-Code'));
            self::assertNotNull($browser->getResponse()->headers->get('Retry-After'));
        }
    }

    #[Test]
    public function aMassPruneIsConfirmedByTypingTheCount(): void
    {
        $browser = $this->ui();
        $this->login('pruner', 'ROLE_PRUNE');
        foreach (['a', 'b', 'c', 'd'] as $slug) {
            $this->insertTag($slug, $slug);
        }
        $crawler = $browser->request('GET', '/developer/seeds/upload');
        $form    = $crawler->filter('form[data-testid="seed-kit-upload-form"]')->form();
        self::attach($form, "provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - { slug: a, label: a }\n");
        self::tick($form);
        $browser->submit($form);
        $crawler = $browser->followRedirect();
        self::assertCount(1, $crawler->filter('[data-testid="seed-kit-run-mass-prune"]'));

        $form                                    = $crawler->filter('[data-testid="seed-kit-apply-mass"] form')->form();
        $form['seed_kit_apply[confirm_deletes]'] = '2';
        $crawler                                 = $browser->submit($form);
        self::assertSame(422, $this->code());
        self::assertStringContainsString('does not match', $this->body());

        $form                                    = $crawler->filter('[data-testid="seed-kit-apply-mass"] form')->form();
        $form['seed_kit_apply[confirm_deletes]'] = '3';
        $browser->submit($form);
        self::assertSame(303, $this->code());
        self::assertSame(1, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM host_tag')));
    }

    #[Test]
    public function uploadRefusalsStayOnTheForm(): void
    {
        $browser = $this->ui(['security' => ['providers' => ['host.articles' => ['apply_roles' => ['ROLE_NOBODY']]]]]);
        $this->login('admin');
        foreach ([
            "provider: host.nope\nversion: 1\nscope: platform\nitems: []\n"                          => 'not registered',
            "provider: host.articles\nversion: 1\nscope: platform\nitems: [{ code: a, title: A }]\n" => 'Access denied',
            "provider: host.tags\nversion: 1\nscope: organization\nitems: []\n"                      => 'does not match',
        ] as $yaml => $message) {
            $crawler = $browser->request('GET', '/developer/seeds/upload');
            $form    = $crawler->filter('form[data-testid="seed-kit-upload-form"]')->form();
            self::attach($form, $yaml);
            $browser->submit($form);
            self::assertSame(422, $this->code());
            self::assertStringContainsString($message, $this->body());
        }
    }

    #[Test]
    public function aProviderFailureAndALatePruneGuardHaveTheirPage(): void
    {
        $browser = $this->ui();
        $this->login('admin');
        $crawler = $this->planned("provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - { slug: x, label: boom }\n");
        $browser->submit($crawler->filter('form[action$="/apply"]')->form());
        self::assertSame(500, $this->code(), 'the host handler failed');
        self::assertSame('SEED_010', (new Crawler($this->body()))->filter('[data-testid="seed-kit-message"]')->attr('data-code'));

        $this->login('pruner', 'ROLE_PRUNE');
        $this->insertTag('a', 'a');
        $this->insertTag('b', 'b');
        $crawler = $browser->request('GET', '/developer/seeds/upload');
        $form    = $crawler->filter('form[data-testid="seed-kit-upload-form"]')->form();
        self::attach($form, "provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - { slug: a, label: a }\n");
        self::tick($form);
        $browser->submit($form);
        $crawler = $browser->followRedirect();
        self::assertCount(0, $crawler->filter('[data-testid="seed-kit-run-mass-prune"]'), 'one of two rows: not a mass prune');
        $this->insertTag('c', 'c');
        $this->insertTag('d', 'd');
        $browser->submit($crawler->filter('form[action$="/apply"]')->form());
        self::assertSame(422, $this->code(), 'the data now makes it a mass prune');
        self::assertSame('SEED_012', (new Crawler($this->body()))->filter('[data-testid="seed-kit-message"]')->attr('data-code'));
    }

    #[Test]
    public function accessWithdrawnBeforeTheApplyIsForbidden(): void
    {
        $browser = $this->ui();
        $this->login('admin');
        $crawler                = $this->planned(self::VALID);
        $this->listener()->deny = ['SEED_APPLY:host.tags'];
        $browser->submit($crawler->filter('form[action$="/apply"]')->form());
        self::assertSame(403, $this->code());
        self::assertSame(0, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM host_tag')));
    }

    #[Test]
    public function aLockConflictOnTheRunTableHasItsPage(): void
    {
        $browser = $this->ui([], ['conflicting_runs' => true]);
        $this->login('admin');
        $crawler = $browser->request('GET', '/developer/seeds/upload');
        $form    = $crawler->filter('form[data-testid="seed-kit-upload-form"]')->form();
        self::attach($form, self::VALID);
        $browser->submit($form);
        self::assertSame(409, $this->code());
        self::assertSame('CONFLICT_001', $browser->getResponse()->headers->get('X-Conflict-Code'));
        self::assertSame('1', $browser->getResponse()->headers->get('Retry-After'));
    }

    #[Test]
    public function aDemoMayAllowEveryone(): void
    {
        $browser = $this->ui(['security' => ['allow_unauthenticated' => true, 'access_checker' => 'allow_all']]);
        $browser->request('GET', '/developer/seeds');
        self::assertSame(200, $this->code());
    }

    /**
     * Uploads a file through the page and opens its run.
     */
    private function planned(string $yaml): Crawler
    {
        $crawler = $this->browser->request('GET', '/developer/seeds/upload');
        $form    = $crawler->filter('form[data-testid="seed-kit-upload-form"]')->form();
        self::attach($form, $yaml);
        $this->browser->submit($form);
        self::assertSame(303, $this->code(), $this->body());

        return $this->browser->followRedirect();
    }
}
