<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Infrastructure\Routing;

use Nowo\SeedKitBundle\Infrastructure\Symfony\Controller\SeedKitController;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Routing\SeedKitRouteLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One route set per mount, names `nowo_seed_kit_<kind>_<page>`, strict requirements, methods per page.
 */
final class SeedKitRouteLoaderTest extends TestCase
{
    #[Test]
    public function routesFollowTheMounts(): void
    {
        $loader = new SeedKitRouteLoader(['platform' => '/developer/seeds/', 'project' => '/projects/{scopeId}/settings/seeds', 'root' => '/']);
        self::assertTrue($loader->supports('.', 'nowo_seed_kit'));
        self::assertFalse($loader->supports('.', 'annotation'));
        $routes = $loader->load('.');

        self::assertCount(27, $routes);
        $index = $routes->get('nowo_seed_kit_platform_index');
        self::assertSame('/developer/seeds', $index?->getPath());
        self::assertSame(SeedKitController::class . '::index', $index->getDefault('_controller'));
        self::assertSame('platform', $index->getDefault(SeedKitRouteLoader::KIND));
        self::assertSame(['GET'], $index->getMethods());
        self::assertSame('/', $routes->get('nowo_seed_kit_root_index')?->getPath());

        $apply = $routes->get('nowo_seed_kit_project_run_apply');
        self::assertSame('/projects/{scopeId}/settings/seeds/runs/{run}/apply', $apply?->getPath());
        self::assertSame(['POST'], $apply->getMethods());
        self::assertSame('[^/]{1,128}', $apply->getRequirement('scopeId'));
        self::assertSame('[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', $apply->getRequirement('run'));
        $export = $routes->get('nowo_seed_kit_platform_export');
        self::assertNotNull($export);
        self::assertSame(['GET', 'POST'], $export->getMethods());
        self::assertNotNull($export->getRequirement('provider'));
    }
}
