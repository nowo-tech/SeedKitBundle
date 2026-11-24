<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogService;
use App\Form\LoginType;
use LogicException;
use Nowo\SeedKitBundle\Application\RunMaintenance;
use Nowo\SeedKitBundle\Application\SeedOperations;
use Nowo\UiKitBundle\View\AuthPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

use function array_keys;
use function array_map;
use function count;
use function implode;

/**
 * Demo pages: the home (providers, console commands to try, row counts, last runs) and the form login. Bundle 0.1.0 has
 * no UI of its own: the page only calls the bundle's application services ({@see SeedOperations::providers()},
 * {@see RunMaintenance::list()}).
 */
final class DemoController extends AbstractController
{
    /**
     * Builds the controller.
     */
    public function __construct(
        private readonly SeedOperations $operations,
        private readonly RunMaintenance $runs,
        private readonly CatalogService $catalog,
    ) {
    }

    #[Route('/', name: 'demo_home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        $counts    = $this->catalog->counts();
        $providers = [];
        foreach ($this->operations->providers() as $provider) {
            $schema      = $provider->schema();
            $name        = $provider->name()->value;
            $providers[] = [
                'name'       => $name,
                'version'    => $provider->version(),
                'key'        => implode(', ', $schema->keyProperties()),
                'depends_on' => implode(', ', array_map(static fn ($dependency): string => $dependency->value, $provider->dependsOn())),
                'rows'       => 'catalog.categories' === $name ? $counts['categories'] : $counts['products'],
                'fields'     => count($schema->properties()),
            ];
        }
        $runs = [];
        foreach ($this->runs->list(null, null, 10) as $run) {
            $runs[] = [
                'id'        => $run->id->value,
                'status'    => $run->status->value,
                'actor'     => $run->actor?->value ?? '(forgotten)',
                'channel'   => $run->channel->value,
                'providers' => implode(', ', array_keys($run->providers)),
                'prune'     => $run->prune,
                'counts'    => $run->counts,
                'created'   => $run->createdAt->format('Y-m-d H:i:s'),
            ];
        }

        return $this->render('demo/home.html.twig', [
            'providers' => $providers,
            'runs'      => $runs,
            'worker'    => $request->server->has('FRANKENPHP_WORKER'),
            'commands'  => [
                'bin/console seed:list',
                'bin/console seed:export catalog.products --empty --actor=admin',
                'bin/console seed:diff config/seeds/catalog.yaml --actor=admin',
                'bin/console seed:apply config/seeds/catalog.yaml --yes --actor=admin',
                'bin/console seed:apply config/seeds/products-extra.jsonl --yes --actor=admin',
                'bin/console seed:export catalog.products --actor=admin',
            ],
        ]);
    }

    #[Route('/login', name: 'demo_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $auth): Response
    {
        $form  = $this->createForm(LoginType::class, null, [
            'action'        => $this->generateUrl('demo_login'),
            'last_username' => $auth->getLastUsername(),
            'target_path'   => $this->generateUrl('demo_home'),
        ]);
        $error = $auth->getLastAuthenticationError();

        return $this->render('@NowoUiKit/page/auth.html.twig', ['page' => new AuthPage(
            'Sign in',
            $form->createView(),
            'Demo users: admin (ROLE_ADMIN: may export and apply) and viewer (ROLE_USER: refused by the guard). Password "demo".',
            'Seed Kit demo',
            $error !== null ? 'The username or password is incorrect.' : null,
            'Sign in',
            formAttributes: ['data-turbo' => 'false', 'data-testid' => 'login-form'],
            documentTitle: 'Sign in',
        )], new Response(status: $error !== null ? 401 : 200));
    }

    #[Route('/logout', name: 'demo_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new LogicException('Handled by the firewall.');
    }
}
