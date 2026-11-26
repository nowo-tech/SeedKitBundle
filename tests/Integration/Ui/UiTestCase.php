<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Ui;

use Nowo\SeedKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_replace_recursive;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Boots the UI kernel (TwigBundle, UiKit, FormKit, SecurityBundle with http_basic, the UI routes) on the test
 * database with two mounts: `/developer/seeds` (platform) and `/organizations/{scopeId}/seeds` (organization, the
 * host resolver knows `acme` and `globex`; `globex` is hidden from `bob`).
 */
abstract class UiTestCase extends KernelTestCase
{
    protected KernelBrowser $browser;

    /**
     * @param array<string, mixed> $config merged over the UI defaults
     * @param array<string, bool> $features
     */
    protected function ui(array $config = [], array $features = []): KernelBrowser
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive([
            'scope_kinds'    => ['platform' => ['requires_id' => false], 'organization' => ['requires_id' => true]],
            'scope_resolver' => 'test.scope_resolver',
            'security'       => ['providers' => ['host.tags' => ['prune_roles' => ['ROLE_PRUNE']]]],
            'ui'             => ['enabled' => true, 'mounts' => ['platform' => ['path' => '/developer/seeds'], 'organization' => ['path' => '/organizations/{scopeId}/seeds']]],
        ], $config);
        $this->boot($merged, ['ui' => true] + $features);
        self::assertNotNull($this->kernel);
        $this->browser = new KernelBrowser($this->kernel);
        $this->browser->disableReboot();

        return $this->browser;
    }

    /**
     * Signs a user of the in-memory provider in.
     */
    protected function login(string $identifier, string $role = 'ROLE_ADMIN'): void
    {
        $this->browser->loginUser(new InMemoryUser($identifier, 'x', $role === 'ROLE_PRUNE' ? ['ROLE_ADMIN', 'ROLE_PRUNE'] : [$role]), 'main');
    }

    /**
     * A temp file with the content and a name with the extension.
     */
    protected static function upload(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'seed-ui');
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Attaches a temp file with the content to the upload field.
     */
    protected static function attach(Form $form, string $content): void
    {
        $field = $form['seed_kit_upload[file]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload(self::upload($content));
    }

    /**
     * Ticks the prune box of the upload form.
     */
    protected static function tick(Form $form): void
    {
        $field = $form['seed_kit_upload[prune]'];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->tick();
    }

    /**
     * The status of the last response.
     */
    protected function code(): int
    {
        return $this->browser->getResponse()->getStatusCode();
    }

    /**
     * The body of the last response.
     */
    protected function body(): string
    {
        return (string) $this->browser->getResponse()->getContent();
    }
}
