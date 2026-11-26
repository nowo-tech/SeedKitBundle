<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Ui;

use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Routing\SeedKitRouteLoader;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\SeedKitGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * The `ui` / `messenger` / `scheduler` configuration (contract 15, REQ-SF-006): mounts checked against the scope kinds,
 * the current-scope port for mounts without `{scopeId}`, `nowo_seed_kit.ui_paths`, the document TTL range.
 */
final class ConfigurationTest extends UiTestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'no mount' => [['ui' => ['mounts' => null]], 'at least one ui.mounts'];
        yield 'unknown kind' => [['ui' => ['mounts' => ['project' => ['path' => '/p/{scopeId}']]]], 'not a configured scope kind'];
        yield 'id kind without id' => [['ui' => ['mounts' => ['organization' => ['path' => '/organization/seeds']]]], 'needs a scope id'];
        yield 'id on a kind without id' => [['ui' => ['mounts' => ['platform' => ['path' => '/p/{scopeId}']]]], 'has no scope id'];
        yield 'bad path' => [['ui' => ['mounts' => ['platform' => ['path' => 'relative']]]], 'absolute path'];
        yield 'other placeholder' => [['ui' => ['mounts' => ['platform' => ['path' => '/p/{id}']]]], 'absolute path'];
        yield 'ttl too short' => [['runs' => ['document_ttl' => 'PT10S']], 'document_ttl'];
        yield 'ttl not a duration' => [['runs' => ['document_ttl' => 'P']], 'document_ttl'];
        yield 'ttl not text' => [['runs' => ['document_ttl' => 60]], 'document_ttl'];
        yield 'bad frequency' => [['scheduler' => ['frequency' => 'often']], 'frequency'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('invalid')]
    public function anInvalidUiConfigurationFailsTheBuild(array $config, string $message): void
    {
        try {
            $this->ui($config);
            self::fail('expected a build failure');
        } catch (InvalidConfigurationException|InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    #[Test]
    public function aMountWithoutScopeIdUsesTheCurrentScope(): void
    {
        $browser = $this->ui(['ui' => ['current_scope' => 'test.current_scope', 'mounts' => ['organization' => ['path' => '/organization/seeds']]], 'runs' => ['document_ttl' => 'P1DT2H']]);
        self::assertSame(['/developer/seeds', '/organization/seeds'], $this->kernel?->getContainer()->getParameter('nowo_seed_kit.ui_paths'));
        $this->login('admin');
        $browser->request('GET', '/organization/seeds');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('data-scope-kind="organization"', $this->body());
        $this->login('nomad');
        $browser->request('GET', '/organization/seeds');
        self::assertSame(404, $this->code(), 'no current organization');
    }

    #[Test]
    public function withoutTheUiNothingIsMountedAndTheAreaNeedsAUser(): void
    {
        $this->boot([], ['security' => true]);
        self::assertSame([], $this->kernel?->getContainer()->getParameter('nowo_seed_kit.ui_paths'));
        self::assertFalse($this->service(SeedKitGuard::class, SeedKitGuard::class)->canUseArea(SeedScope::platform()), 'no token');
        self::assertCount(0, $this->service(SeedKitRouteLoader::class, SeedKitRouteLoader::class)->load('.', 'nowo_seed_kit'), 'the loader stays, with no route');
    }
}
