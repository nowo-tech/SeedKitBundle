<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Routing;

use Nowo\SeedKitBundle\Infrastructure\Symfony\Controller\SeedKitController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function rtrim;
use function str_contains;

/**
 * The routes of the opt-in UI (contract section 12.5), one set per configured mount (`ui.mounts`, scope kind → path
 * prefix chosen by the host): `nowo_seed_kit_<kind>_<page>`. Import it with `resource: . type: nowo_seed_kit` (the
 * Flex recipe and docs/INSTALLATION.md). A prefix with `{scopeId}` takes the scope id from the URL; without it the
 * id comes from the host's current-scope port. The prefixes are also the container parameter `nowo_seed_kit.ui_paths`
 * for the host's `access_control`.
 */
final class SeedKitRouteLoader extends Loader
{
    /** Loader type. */
    public const TYPE = 'nowo_seed_kit';

    /** Request attribute carrying the scope kind of the route. */
    public const KIND = '_nowo_seed_kit_kind';

    /** Pages: name => [path suffix, controller method, methods]. */
    private const PAGES = [
        'index'        => ['', 'index', ['GET']],
        'export'       => ['/providers/{provider}/export', 'export', ['GET', 'POST']],
        'schema'       => ['/providers/{provider}/schema.json', 'schema', ['GET']],
        'upload'       => ['/upload', 'upload', ['GET', 'POST']],
        'runs'         => ['/runs', 'runs', ['GET']],
        'run'          => ['/runs/{run}', 'run', ['GET']],
        'run_apply'    => ['/runs/{run}/apply', 'apply', ['POST']],
        'run_discard'  => ['/runs/{run}/discard', 'discard', ['POST']],
        'run_progress' => ['/runs/{run}/progress', 'progress', ['GET']],
    ];

    /** Path requirements. */
    private const REQUIREMENTS = [
        'scopeId'  => '[^/]{1,128}',
        'provider' => '[a-z][a-z0-9_]{0,31}(\.[a-z][a-z0-9_]{0,31}){0,3}',
        'run'      => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
    ];

    /**
     * @param array<string, string> $mounts scope kind → path prefix
     */
    public function __construct(private readonly array $mounts = [], ?string $env = null)
    {
        parent::__construct($env);
    }

    /**
     * {@inheritDoc}
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        foreach ($this->mounts as $kind => $prefix) {
            $prefix = rtrim($prefix, '/');
            foreach (self::PAGES as $page => [$suffix, $method, $methods]) {
                $path         = ($prefix . $suffix) === '' ? '/' : $prefix . $suffix;
                $requirements = [];
                foreach (self::REQUIREMENTS as $name => $pattern) {
                    if (str_contains($path, '{' . $name . '}')) {
                        $requirements[$name] = $pattern;
                    }
                }
                $routes->add('nowo_seed_kit_' . $kind . '_' . $page, new Route(
                    $path,
                    ['_controller' => SeedKitController::class . '::' . $method, self::KIND => $kind],
                    $requirements,
                    [],
                    '',
                    [],
                    $methods,
                ));
            }
        }

        return $routes;
    }

    /**
     * {@inheritDoc}
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
