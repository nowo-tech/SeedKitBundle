<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\CacheWarmer;

use Nowo\SeedKitBundle\Application\ProviderRegistry;
use Nowo\SeedKitBundle\Application\SchemaLinter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Throwable;

/**
 * Lints every provider schema on `cache:warmup` (contract section 7.3) and logs each problem as an error; it never
 * fails the warmup (run `nowo:seed-kit:lint` in CI to fail the build). Optional warmer, nothing written.
 */
final readonly class SchemaLintWarmer implements CacheWarmerInterface
{
    /**
     * Builds the warmer.
     */
    public function __construct(private ProviderRegistry $registry, private SchemaLinter $linter, private ?LoggerInterface $logger = null)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function isOptional(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        try {
            foreach ($this->registry->all() as $provider) {
                foreach ($this->linter->lint($provider)['errors'] as $error) {
                    $this->logger?->error('Seed provider {provider}: {problem}', ['provider' => $provider->name()->value, 'problem' => $error]);
                }
            }
        } catch (Throwable $e) {
            $this->logger?->error('Seed provider lint failed: {exception_class}.', ['exception_class' => $e::class]);
        }

        return [];
    }
}
