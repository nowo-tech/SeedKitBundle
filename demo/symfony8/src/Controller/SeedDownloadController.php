<?php

declare(strict_types=1);

namespace App\Controller;

use Nowo\SeedKitBundle\Application\SeedOperations;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use function fclose;
use function fopen;
use function json_encode;
use function rewind;
use function sprintf;
use function stream_get_contents;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * GET downloads of the empty template, the current export and the JSON Schema of a provider. The page is behind the
 * firewall (login required); the export goes through {@see SeedOperations::export()} with the HTTP channel and the
 * signed-in user's identifier as actor, so the bundle's guard decides: the viewer (ROLE_USER) gets 403, the admin the
 * document. The schema is the public document contract: any signed-in user may read it.
 */
final class SeedDownloadController extends AbstractController
{
    /**
     * Builds the controller.
     */
    public function __construct(private readonly SeedOperations $operations)
    {
    }

    #[Route('/seed/{provider}/{kind}', name: 'demo_seed_download', requirements: ['provider' => '[a-z][a-z0-9_.]*', 'kind' => 'empty|current|schema'], methods: ['GET'])]
    public function download(string $provider, string $kind, Request $request): Response
    {
        $user = $this->getUser();
        if ($user === null) {
            throw new AccessDeniedHttpException();
        }
        try {
            $name = new ProviderName($provider);
            if ($kind === 'schema') {
                return $this->document(json_encode($this->operations->schema($name), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 'application/schema+json', sprintf('%s.schema.json', $provider));
            }
            $format = DocumentFormat::tryFrom((string) $request->query->get('format', 'yaml')) ?? DocumentFormat::Yaml;
            $mode   = $kind === 'empty' ? ExportMode::Empty : ExportMode::Current;
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new NotFoundHttpException();
            }
            try {
                $this->operations->export($name, SeedScope::platform(), $mode, $format, new ActorRef($user->getUserIdentifier()), Channel::Http, $stream);
                rewind($stream);
                $body = (string) stream_get_contents($stream);
            } finally {
                fclose($stream);
            }

            return $this->document($body, $format->mediaType(), sprintf('%s.%s.seed.%s', $provider, $kind, $format->extension()));
        } catch (AccessDeniedException) {
            throw new AccessDeniedHttpException('The seed guard refused this export.');
        } catch (SeedKitException) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * A download response: attachment, no sniffing, never cached.
     */
    private function document(string $body, string $mediaType, string $filename): Response
    {
        return new Response($body, Response::HTTP_OK, [
            'Content-Type'           => $mediaType . '; charset=utf-8',
            'Content-Disposition'    => sprintf('attachment; filename="%s"', $filename),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store',
        ]);
    }
}
