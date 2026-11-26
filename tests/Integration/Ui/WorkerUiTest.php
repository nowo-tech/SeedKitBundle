<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Ui;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function gc_collect_cycles;
use function memory_get_usage;
use function preg_match;
use function str_contains;

/**
 * REQ-RUNTIME-002 for the UI (FrankenPHP worker, kernel reset off): one booted container answers many requests of two
 * users through `http_kernel` directly (no `services_resetter` between requests), with HTTP Basic credentials per
 * request. The run page of one user never shows the other user's apply form or diff, every page answers the same, and
 * memory stays flat after the warm-up.
 */
final class WorkerUiTest extends UiTestCase
{
    private const ITERATIONS = 300;

    private const WARM_UP = 60;

    #[Test]
    public function oneContainerServesBothUsersWithoutLeaks(): void
    {
        $this->ui(['security' => ['providers' => ['host.tags' => ['prune_roles' => []]]]], ['quiet' => true, 'no_debug' => true]);
        $kernel = $this->kernel?->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $kernel);

        // Alice's planned run (form CSRF is session-bound: the upload goes through the browser of the test case).
        $this->login('admin');
        $crawler = $this->browser->request('GET', '/developer/seeds/upload');
        $form    = $crawler->filter('form[data-testid="seed-kit-upload-form"]')->form();
        self::attach($form, "provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - { slug: secret-plan, label: Admin only }\n");
        $this->browser->submit($form);
        self::assertSame(1, preg_match('#/runs/([0-9a-f-]{36})$#', (string) $this->browser->getResponse()->headers->get('Location'), $match));
        $run = '/developer/seeds/runs/' . $match[1];

        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            if ($i === self::WARM_UP) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
            $user = $i % 2 === 0 ? 'admin' : 'bob';
            switch ($i % 3) {
                case 0:
                    $response = $this->get($kernel, '/developer/seeds', $user);
                    self::assertSame(200, $response->getStatusCode());
                    self::assertStringContainsString('host.tags', (string) $response->getContent());
                    break;
                case 1:
                    $response = $this->get($kernel, $run, $user);
                    self::assertSame(200, $response->getStatusCode());
                    $content = (string) $response->getContent();
                    self::assertSame($user === 'admin', str_contains($content, 'secret-plan'), 'iteration ' . $i . ': the diff belongs to the run actor only');
                    self::assertSame($user === 'admin', str_contains($content, '/apply"'), 'iteration ' . $i . ': the apply form belongs to the run actor only');
                    break;
                default:
                    $response = $this->get($kernel, $run . '/progress', $user);
                    self::assertSame('{"status":"planned","batchesDone":0,"batchesTotal":1,"counts":{"create":0,"update":0,"delete":0},"errorCode":null}', $response->getContent());
            }
        }
        gc_collect_cycles();
        self::assertLessThan(3 * 1024 * 1024, memory_get_usage() - $baseline, 'flat memory without a kernel reset');
    }

    private function get(HttpKernelInterface $kernel, string $path, string $user): Response
    {
        return $kernel->handle(Request::create($path, 'GET', [], [], [], ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => 'x']));
    }
}
