<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Ui;

use LogicException;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Controller\SeedKitController;
use Nowo\UiKitBundle\View\Action;
use PHPUnit\Framework\Attributes\Test;

use function dirname;
use function file_get_contents;
use function is_file;
use function preg_match;
use function sprintf;

/**
 * Host integration of the pages: CSRF fails closed at build time (ZCE-L3); every action goes through a UiKit kind a
 * host maps in `nowo_ui_kit.action_icons` (no explicit icon), the pages render under a host icon set without any
 * `bi:` icon, and the UiKit default of every kind the bundle uses is a real Bootstrap Icons name.
 */
final class HostIntegrationTest extends UiTestCase
{
    #[Test]
    public function thePagesRefuseToBuildWithoutFormCsrf(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('require CSRF protection');
        $this->ui([], ['no_csrf' => true]);
    }

    #[Test]
    public function aHostIconSetWithoutBootstrapIconsRendersEveryPage(): void
    {
        $browser = $this->ui([], ['tabler_icons' => true]);
        $this->login('admin');
        $crawler = $browser->request('GET', '/developer/seeds/upload');
        $form    = $crawler->filter('form[data-testid="seed-kit-upload-form"]')->form();
        self::attach($form, "provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - { slug: news, label: News }\n");
        $browser->submit($form);
        $run = (string) $browser->getResponse()->headers->get('Location');

        foreach (['/developer/seeds', '/developer/seeds/upload', '/developer/seeds/runs', $run] as $path) {
            $browser->request('GET', $path);
            self::assertSame(200, $this->code(), $path);
            self::assertSame(0, preg_match('/\bbi[-:]/', $this->body()), sprintf('%s draws a Bootstrap icon', $path));
        }
        $browser->request('GET', '/developer/seeds');
        self::assertStringContainsString('ti ti-list', $this->body(), 'History follows the host map of the "view" kind');
        self::assertStringContainsString('ti ti-upload', $this->body(), 'Upload follows the host map of the "import" kind');
    }

    #[Test]
    public function everyKindHasARealDefaultIcon(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/vendor/nowo-tech/ui-kit-bundle/src/Resources/views/components/_icon.html.twig');
        foreach (SeedKitController::ACTION_KINDS as $kind) {
            self::assertContains($kind, Action::KINDS);
            self::assertSame(1, preg_match('/\n\s+' . $kind . ": 'bi:([a-z0-9-]+)'/", $template, $match), 'UiKit has a default icon for ' . $kind);
            self::assertFileExists(dirname(__DIR__, 3) . '/vendor/twbs/bootstrap-icons/icons/' . $match[1] . '.svg', sprintf('the default icon of "%s" (bi:%s) exists in Bootstrap Icons', $kind, $match[1]));
        }
        self::assertFalse(is_file(dirname(__DIR__, 3) . '/vendor/twbs/bootstrap-icons/icons/history.svg'), 'sanity: bi:history does not exist');
        $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Symfony/Controller/SeedKitController.php');
        self::assertSame(0, preg_match('/\bicon:\s*[\'$]/', $controller), 'no explicit icon: every action goes through a kind the host maps');
    }
}
