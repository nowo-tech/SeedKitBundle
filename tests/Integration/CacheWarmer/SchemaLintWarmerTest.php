<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\CacheWarmer;

use LogicException;
use Nowo\SeedKitBundle\Application\ProviderRegistry;
use Nowo\SeedKitBundle\Application\SchemaLinter;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Nowo\SeedKitBundle\Infrastructure\Symfony\CacheWarmer\SchemaLintWarmer;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\RecordingLogger;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\LintBrokenProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function sys_get_temp_dir;

/**
 * The lint warmer (contract section 7.3): logs every schema error, never fails the warmup.
 */
final class SchemaLintWarmerTest extends TestCase
{
    #[Test]
    public function schemaErrorsAreLoggedAndTheWarmupGoesOn(): void
    {
        $logger   = new RecordingLogger();
        $registry = new ProviderRegistry(new ServiceLocator(['host.broken' => static fn (): SeedProviderInterface => new LintBrokenProvider()]), ['host.broken']);
        $warmer   = new SchemaLintWarmer($registry, new SchemaLinter(), $logger);

        self::assertTrue($warmer->isOptional());
        self::assertSame([], $warmer->warmUp(sys_get_temp_dir()));
        self::assertNotSame([], $logger->records);
        foreach ($logger->records as [$level, $message, $context]) {
            self::assertSame('error', $level);
            self::assertSame('Seed provider {provider}: {problem}', $message);
            self::assertSame('host.broken', $context['provider']);
        }
    }

    #[Test]
    public function aFailingProviderIsLoggedNotThrown(): void
    {
        $logger   = new RecordingLogger();
        $registry = new ProviderRegistry(new ServiceLocator(['host.bad' => static fn (): SeedProviderInterface => throw new LogicException('cannot build')]), ['host.bad']);

        self::assertSame([], (new SchemaLintWarmer($registry, new SchemaLinter(), $logger))->warmUp(sys_get_temp_dir(), sys_get_temp_dir()));
        self::assertSame([['error', 'Seed provider lint failed: {exception_class}.', ['exception_class' => LogicException::class]]], $logger->records);
        self::assertSame([], (new SchemaLintWarmer($registry, new SchemaLinter()))->warmUp(sys_get_temp_dir()), 'without a logger');
    }
}
