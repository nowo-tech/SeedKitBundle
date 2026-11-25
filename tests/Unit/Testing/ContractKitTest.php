<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Testing;

use Nowo\SeedKitBundle\Application\Planner;
use Nowo\SeedKitBundle\Application\ProviderRegistry;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Tests\Fixtures\Reader\RefusingOpenStream;
use Nowo\SeedKitBundle\Tests\Fixtures\Testing\DefaultsProbeContract;
use Nowo\SeedKitBundle\Tests\Fixtures\Testing\LaxProbeContract;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * The contract test kit itself: optional checks skip by default and fail on a provider that breaks them; the kit's
 * own plumbing fails loudly.
 */
final class ContractKitTest extends TestCase
{
    #[Test]
    public function optionalChecksAreSkippedByDefault(): void
    {
        $probe = new DefaultsProbeContract('refusesItemsThatEscalate');
        try {
            $probe->refusesItemsThatEscalate();
            self::fail('expected a skip');
        } catch (SkippedTest $e) {
            self::assertStringContainsString('escalatingItem() is null', $e->getMessage());
        }
        try {
            $probe->restrictedActorSeesOnlyItsRows();
            self::fail('expected a skip');
        } catch (SkippedTest $e) {
            self::assertStringContainsString('restrictedActor() is null', $e->getMessage());
        }
    }

    #[Test]
    public function theMandatoryChecksPassForAWellBehavedProvider(): void
    {
        $probe = new DefaultsProbeContract('applyIsIdempotentAndExportRoundTrips');
        $probe->schemaPassesTheLint();
        $probe->applyIsIdempotentAndExportRoundTrips();
        $probe->exportsAreStableAndCarryNoSecretValue();
        self::assertArrayHasKey('["a"]', $probe->provider()->rows);
    }

    #[Test]
    public function anEscalatingItemThatIsAcceptedFailsTheKit(): void
    {
        $probe = new LaxProbeContract('refusesItemsThatEscalate');
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('An item granting more than the actor holds must be refused.');
        $probe->refusesItemsThatEscalate();
    }

    #[Test]
    public function aRestrictedActorWithoutHiddenKeysChecksNothing(): void
    {
        $probe = new LaxProbeContract('restrictedActorSeesOnlyItsRows');
        $probe->restrictedActorSeesOnlyItsRows();
        self::assertArrayHasKey('["a"]', $probe->provider()->rows, 'the sample was applied');
    }

    #[Test]
    public function theKitContainerRefusesAnUnknownProvider(): void
    {
        $planner  = (new DefaultsProbeContract('x'))->exposedPlanner();
        $registry = (new ReflectionProperty(Planner::class, 'registry'))->getValue($planner);
        self::assertInstanceOf(ProviderRegistry::class, $registry);
        $container = (new ReflectionProperty(ProviderRegistry::class, 'providers'))->getValue($registry);
        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertTrue($container->has('demo.items'));
        self::assertFalse($container->has('demo.nope'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown provider.');
        $container->get('demo.nope');
    }

    #[Test]
    public function exportAndReadNeedAMemoryStream(): void
    {
        $probe = new DefaultsProbeContract('x');
        foreach ([static fn (): mixed => $probe->exportAs(new ActorRef('alice')), static fn (): mixed => $probe->readYaml('x: 1')] as $call) {
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', RefusingOpenStream::class);
            // Restored in the finally block below; swallows the fopen() warning of the refused memory stream.
            // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
            set_error_handler(static fn (): bool => true);
            try {
                $call();
                self::fail('expected');
            } catch (RuntimeException $e) {
                self::assertSame('No memory stream.', $e->getMessage());
            } finally {
                restore_error_handler();
                stream_wrapper_restore('php');
            }
        }
    }
}
