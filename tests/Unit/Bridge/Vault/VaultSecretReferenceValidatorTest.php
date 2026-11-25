<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Bridge\Vault;

use DateTimeImmutable;
use Nowo\SeedKitBundle\Bridge\Vault\VaultSecretReferenceValidator;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\SecretReference;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Service\VaultAccessCheckInterface;
use Nowo\VaultBundle\Service\VaultItemQueryInterface;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Nowo\VaultBundle\View\VaultItemView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Vault bridge (QB-SK-2): `vault:<item-uuid>` must exist, not be trashed, be owned by the target scope and, for a
 * user actor, be viewable by that user.
 */
final class VaultSecretReferenceValidatorTest extends TestCase
{
    private const ID = '0193a6f0-1234-7abc-8def-0123456789ab';

    /**
     * @return iterable<string, array{string}>
     */
    public static function notItemIds(): iterable
    {
        yield 'not a uuid' => ['vault:item-1'];
        yield 'upper-case uuid' => ['vault:0193A6F0-1234-7ABC-8DEF-0123456789AB'];
        yield 'uuid with a suffix' => ['vault:' . self::ID . '#x'];
    }

    #[Test]
    #[DataProvider('notItemIds')]
    public function refusesReferencesThatAreNotItemIdsWithoutAskingVault(string $reference): void
    {
        $items = $this->createMock(VaultItemQueryInterface::class);
        $items->expects(self::never())->method('findById');
        $access = $this->createMock(VaultAccessCheckInterface::class);
        $access->expects(self::never())->method('canAccessItem');
        $validator = new VaultSecretReferenceValidator($items, $access);

        self::assertFalse($validator->belongsTo(new SecretReference($reference), SeedScope::parse('organization:org-1')));
        self::assertFalse($validator->isUsable(new SecretReference($reference), SeedScope::parse('organization:org-1'), new ActorRef('user-7')));
    }

    /**
     * @return iterable<string, array{VaultItemView|null, string, string, bool}>
     */
    public static function ownership(): iterable
    {
        yield 'unknown item' => [null, 'organization:org-1', '{kind}:{id}', false];
        yield 'trashed item' => [self::item('organization:org-1', new DateTimeImmutable('2026-11-01')), 'organization:org-1', '{kind}:{id}', false];
        yield 'personal item' => [self::item(null), 'organization:org-1', '{kind}:{id}', false];
        yield 'other scope' => [self::item('organization:org-2'), 'organization:org-1', '{kind}:{id}', false];
        yield 'other kind' => [self::item('project:org-1'), 'organization:org-1', '{kind}:{id}', false];
        yield 'same scope' => [self::item('organization:org-1'), 'organization:org-1', '{kind}:{id}', true];
        yield 'scope without an id' => [self::item('platform'), 'platform', '{kind}:{id}', true];
        yield 'scope without an id ignores the format' => [self::item('scope/platform'), 'platform', 'scope/{kind}', false];
        yield 'custom format' => [self::item('org-1'), 'organization:org-1', '{id}', true];
        yield 'custom format mismatch' => [self::item('organization:org-1'), 'organization:org-1', '{id}', false];
        yield 'exact bytes only' => [self::item('organization:ORG-1'), 'organization:org-1', '{kind}:{id}', false];
    }

    #[Test]
    #[DataProvider('ownership')]
    public function checksTheItemIsActiveAndOwnedByTheTargetScope(?VaultItemView $item, string $scope, string $format, bool $expected): void
    {
        $items = $this->createMock(VaultItemQueryInterface::class);
        $items->expects(self::once())->method('findById')->with(self::ID)->willReturn($item);
        $access = $this->createMock(VaultAccessCheckInterface::class);
        $access->expects(self::never())->method(self::anything());

        $validator = new VaultSecretReferenceValidator($items, $access, $format);

        self::assertSame($expected, $validator->belongsTo(new SecretReference('vault:' . self::ID), SeedScope::parse($scope)));
    }

    #[Test]
    public function aForeignItemIsNotUsableAndTheAccessCheckIsSkipped(): void
    {
        $items = $this->createStub(VaultItemQueryInterface::class);
        $items->method('findById')->willReturn(self::item('organization:org-2'));
        $access = $this->createMock(VaultAccessCheckInterface::class);
        $access->expects(self::never())->method('canAccessItem');

        self::assertFalse((new VaultSecretReferenceValidator($items, $access))->isUsable(new SecretReference('vault:' . self::ID), SeedScope::parse('organization:org-1'), new ActorRef('user-7')));
    }

    #[Test]
    public function theSystemActorIsCheckedOnOwnershipOnly(): void
    {
        $items = $this->createStub(VaultItemQueryInterface::class);
        $items->method('findById')->willReturn(self::item('organization:org-1'));
        $access = $this->createMock(VaultAccessCheckInterface::class);
        $access->expects(self::never())->method('canAccessItem');

        self::assertTrue((new VaultSecretReferenceValidator($items, $access))->isUsable(new SecretReference('vault:' . self::ID), SeedScope::parse('organization:org-1'), ActorRef::system()));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function accessAnswers(): iterable
    {
        yield 'allowed' => [true];
        yield 'denied' => [false];
    }

    #[Test]
    #[DataProvider('accessAnswers')]
    public function aUserActorMustBeAllowedToViewTheItem(bool $allowed): void
    {
        $items = $this->createStub(VaultItemQueryInterface::class);
        $items->method('findById')->willReturn(self::item('organization:org-1'));
        $access = $this->createMock(VaultAccessCheckInterface::class);
        $access->expects(self::once())->method('canAccessItem')->with(
            self::callback(static fn (VaultUserRef $user): bool => $user->id === 'user-7' && $user->identifier === '' && $user->roles === [] && $user->user() === null),
            self::ID,
            VaultAccessAction::View,
        )->willReturn($allowed);

        self::assertSame($allowed, (new VaultSecretReferenceValidator($items, $access))->isUsable(new SecretReference('vault:' . self::ID), SeedScope::parse('organization:org-1'), new ActorRef('user-7')));
    }

    private static function item(?string $ownerScope, ?DateTimeImmutable $deletedAt = null): VaultItemView
    {
        $at = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new VaultItemView(
            self::ID,
            VaultItemType::Login,
            'API token',
            $ownerScope === null ? 'user:user-7' : 'scope:' . $ownerScope,
            $ownerScope === null ? 'user-7' : null,
            $ownerScope,
            null,
            [],
            'user-7',
            $at,
            $at,
            null,
            $deletedAt,
            false,
            'edit-token',
        );
    }
}
