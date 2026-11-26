<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Ui;

use Nowo\SeedKitBundle\Bridge\Messenger\ApplySeedRun;
use Nowo\SeedKitBundle\Bridge\Messenger\ApplySeedRunHandler;
use Nowo\SeedKitBundle\Bridge\Scheduler\PurgeSeedRuns;
use Nowo\SeedKitBundle\Bridge\Scheduler\PurgeSeedRunsHandler;
use Nowo\SeedKitBundle\Bridge\Scheduler\SeedKitPurgeSchedule;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The asynchronous apply through a real Messenger transport (contract 10.5, REQ-MSG-001): a large UI apply is queued as
 * {@see ApplySeedRun} (run id + plan hash only), the handler applies it as the run's actor once, a redelivery is a
 * no-op; without `trust_run_actor` and without a host `canForActor()` it is refused for good. The Scheduler bridge.
 */
final class MessengerTest extends UiTestCase
{
    private const FILE = "provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - { slug: news, label: News }\n  - { slug: blog, label: Blog }\n";

    #[Test]
    public function aLargeApplyIsQueuedAndAppliedOnce(): void
    {
        $this->ui(['messenger' => ['enabled' => true, 'async_threshold_changes' => 1, 'trust_run_actor' => true]], ['messenger' => true]);
        $this->login('admin');
        $crawler = $this->planned();
        $this->browser->submit($crawler->filter('form[action$="/apply"]')->form());
        $crawler = $this->browser->followRedirect();
        self::assertSame('queued', $crawler->filter('[data-testid="seed-kit-run"]')->attr('data-status'));
        self::assertCount(1, $crawler->filter('[data-testid="seed-kit-run-progress"]'), 'the auto-refresh region');
        self::assertCount(1, $crawler->filter('[data-testid="seed-kit-run-refresh"]'), 'the refresh link without JavaScript');

        $message = $this->sentMessage();
        self::assertSame(0, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM host_tag')));

        $handler = $this->service(ApplySeedRunHandler::class, ApplySeedRunHandler::class);
        $handler($message);
        $handler($message);
        self::assertSame(2, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM host_tag')), 'a redelivery applies nothing twice');
        $crawler = $this->browser->request('GET', '/developer/seeds/runs/' . $message->runId);
        self::assertSame('applied', $crawler->filter('[data-testid="seed-kit-run"]')->attr('data-status'));
    }

    #[Test]
    public function withoutAHostCheckerTheStoredActorIsRefused(): void
    {
        $this->ui(['messenger' => ['enabled' => true, 'async_threshold_changes' => 1]], ['messenger' => true]);
        $this->login('admin');
        $crawler = $this->planned();
        $this->browser->submit($crawler->filter('form[action$="/apply"]')->form());
        $message = $this->sentMessage();

        try {
            $this->service(ApplySeedRunHandler::class, ApplySeedRunHandler::class)($message);
            self::fail('refused');
        } catch (UnrecoverableMessageHandlingException) {
        }
        $crawler = $this->browser->request('GET', '/developer/seeds/runs/' . $message->runId);
        self::assertSame('failed', $crawler->filter('[data-testid="seed-kit-run"]')->attr('data-status'));
        self::assertSame(0, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM host_tag')));
    }

    #[Test]
    public function theScheduleExpiresDocumentsAndPurgesHistory(): void
    {
        $this->ui(['scheduler' => ['enabled' => true, 'frequency' => '30 minutes']], ['messenger' => true]);
        $this->login('admin');
        $this->planned();
        $schedule = $this->service(SeedKitPurgeSchedule::class, SeedKitPurgeSchedule::class)->getSchedule();
        self::assertCount(1, $schedule->getRecurringMessages());

        $this->service('test.mock_clock', MockClock::class)->modify('+2 hours');
        $this->service(PurgeSeedRunsHandler::class, PurgeSeedRunsHandler::class)(new PurgeSeedRuns());
        self::assertSame('expired', $this->connection()->fetchOne('SELECT status FROM nowo_seed_run'));
        self::assertSame(1, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_seed_run WHERE document IS NULL')));
    }

    /**
     * The message the apply sent: the transport is reset after each kernel request, so it is captured by a listener
     * of the bus (SendMessageToTransportsEvent would do too); here the run row tells what was sent.
     */
    private function sentMessage(): ApplySeedRun
    {
        $row = $this->connection()->fetchAssociative("SELECT id, plan_hash FROM nowo_seed_run WHERE status = 'queued'");
        self::assertIsArray($row);
        self::assertIsString($row['id']);
        self::assertIsString($row['plan_hash']);

        return new ApplySeedRun($row['id'], $row['plan_hash']);
    }

    /**
     * Uploads the file and opens its run.
     */
    private function planned(): Crawler
    {
        $crawler = $this->browser->request('GET', '/developer/seeds/upload');
        $form    = $crawler->filter('form[data-testid="seed-kit-upload-form"]')->form();
        self::attach($form, self::FILE);
        $this->browser->submit($form);
        self::assertSame(303, $this->code(), $this->body());

        return $this->browser->followRedirect();
    }
}
