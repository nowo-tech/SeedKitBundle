<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Application;

use Nowo\SeedKitBundle\Application\ProviderRegistry;
use Nowo\SeedKitBundle\Domain\Exception\ProviderContractException;
use Nowo\SeedKitBundle\Domain\Exception\UnknownProviderException;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\ScriptedProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\ArticleProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\InMemoryStore;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\TagProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The provider registry: lookup by tag name, kinds, dependency order.
 */
final class ProviderRegistryTest extends TestCase
{
    private function registry(): ProviderRegistry
    {
        $store = new InMemoryStore();

        return new ProviderRegistry(new ServiceLocator([
            'demo.articles' => static fn (): SeedProviderInterface => new ArticleProvider($store),
            'demo.tags'     => static fn (): SeedProviderInterface => new TagProvider($store),
            'demo.liar'     => static fn (): SeedProviderInterface => new ScriptedProvider('demo.other'),
            'demo.object'   => static fn (): object => new stdClass(),
        ]), ['demo.tags', 'demo.articles', 'demo.liar', 'demo.object']);
    }

    /**
     * @param list<SeedProviderInterface> $providers
     *
     * @return list<string>
     */
    private function names(array $providers): array
    {
        return array_map(static fn (SeedProviderInterface $p): string => $p->name()->value, $providers);
    }

    #[Test]
    public function listsAndFindsProviders(): void
    {
        $registry = $this->registry();
        self::assertSame(['demo.tags', 'demo.articles', 'demo.liar', 'demo.object'], array_map(static fn (ProviderName $n): string => $n->value, $registry->names()));
        self::assertTrue($registry->has(new ProviderName('demo.tags')));
        self::assertFalse($registry->has(new ProviderName('demo.nope')));
        self::assertSame('demo.tags', $registry->get(new ProviderName('demo.tags'))->name()->value);
        self::assertSame('demo.articles', $registry->getForKind(new ProviderName('demo.articles'), new ScopeKind('organization'))->name()->value);
    }

    #[Test]
    public function unknownProvider(): void
    {
        $this->expectException(UnknownProviderException::class);
        $this->registry()->get(new ProviderName('demo.nope'));
    }

    #[Test]
    public function providerNotInTheKind(): void
    {
        $this->expectException(UnknownProviderException::class);
        $this->registry()->getForKind(new ProviderName('demo.articles'), new ScopeKind('platform'));
    }

    #[Test]
    public function aNameMismatchBreaksTheContract(): void
    {
        $this->expectException(ProviderContractException::class);
        $this->registry()->get(new ProviderName('demo.liar'));
    }

    #[Test]
    public function aServiceThatIsNotAProviderBreaksTheContract(): void
    {
        $this->expectException(ProviderContractException::class);
        $this->registry()->get(new ProviderName('demo.object'));
    }

    #[Test]
    public function allByKindInDependencyOrder(): void
    {
        $store    = new InMemoryStore();
        $registry = new ProviderRegistry(new ServiceLocator([
            'demo.articles' => static fn (): SeedProviderInterface => new ArticleProvider($store),
            'demo.tags'     => static fn (): SeedProviderInterface => new TagProvider($store),
        ]), ['demo.tags', 'demo.articles']);
        self::assertSame(['demo.tags', 'demo.articles'], $this->names($registry->all()));
        self::assertSame(['demo.tags'], $this->names($registry->all(new ScopeKind('platform'))));
        self::assertSame(['demo.tags', 'demo.articles'], $this->names($registry->all(new ScopeKind('organization'))));
        self::assertSame([], $this->names($registry->all(new ScopeKind('project'))));

        $sorted = $registry->sort([new ProviderName('demo.articles'), new ProviderName('demo.unknown'), new ProviderName('demo.tags')]);
        self::assertSame(['demo.unknown', 'demo.tags', 'demo.articles'], array_map(static fn (ProviderName $n): string => $n->value, $sorted));
    }
}
