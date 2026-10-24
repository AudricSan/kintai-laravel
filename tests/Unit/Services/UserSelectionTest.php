<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Services\UserSelection;
use kintai\Tests\Support\ApiOwnerAuth;
use PHPUnit\Framework\TestCase;

/**
 * Sélection d'employés d'un magasin : seuls les comptes actifs, membres actifs du magasin, sont proposés ou retenus ;
 * la sélection se fait par identifiant de compte (l'adresse est relue à l'usage).
 */
final class UserSelectionTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $users = [
        1 => ['id' => 1, 'display_name' => 'Zoé Martin', 'email' => 'zoe@example.test', 'language' => 'fr', 'is_active' => 1],
        2 => ['id' => 2, 'display_name' => 'Aki Tanaka', 'email' => 'aki@example.test', 'language' => 'ja', 'is_active' => 1],
        3 => ['id' => 3, 'display_name' => 'Compte désactivé', 'email' => 'off@example.test', 'language' => 'fr', 'is_active' => 0],
        4 => ['id' => 4, 'display_name' => 'Sans adresse', 'email' => '', 'language' => 'fr', 'is_active' => 1],
        5 => ['id' => 5, 'display_name' => 'Retiré du magasin', 'email' => 'out@example.test', 'language' => 'fr', 'is_active' => 1],
        6 => ['id' => 6, 'display_name' => '', 'first_name' => 'Léa', 'last_name' => 'Roy', 'email' => 'lea@example.test', 'language' => 'en', 'is_active' => 1],
    ];

    private function selection(): UserSelection
    {
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findByStore')->willReturnCallback(static fn(int $storeId): array => $storeId !== 7 ? [] : [
            ['user_id' => 1, 'is_active' => 1],
            ['user_id' => 2, 'is_active' => 1],
            ['user_id' => 3, 'is_active' => 1],
            ['user_id' => 4, 'is_active' => 1],
            ['user_id' => 5, 'is_active' => 0],
            ['user_id' => 6, 'is_active' => 1],
            ['user_id' => 99, 'is_active' => 1],
        ]);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn(int $id): ?array => $this->users[$id] ?? null);

        return new UserSelection($storeUsers, $users, ApiOwnerAuth::permissions($this));
    }

    public function testCandidatesAreTheActiveMembersSortedByName(): void
    {
        $names = array_column($this->selection()->candidates(7), 'name');

        $this->assertSame(['Aki Tanaka', 'Léa Roy', 'Sans adresse', 'Zoé Martin'], $names);
    }

    public function testCandidatesFallBackToFirstAndLastNameAndCarryTheLanguage(): void
    {
        $byId = array_column($this->selection()->candidates(7), null, 'id');

        $this->assertSame('Léa Roy', $byId[6]['name']);
        $this->assertSame('en', $byId[6]['language']);
        $this->assertSame('aki@example.test', $byId[2]['email']);
    }

    public function testAStoreWithoutMembersOnlyOffersTheOwner(): void
    {
        $candidates = $this->selection()->candidates(8);

        // Le socle de test fait de l'utilisateur 1 l'Owner : proposé même sans appartenir au magasin.
        $this->assertSame([1], array_column($candidates, 'id'));
        $this->assertTrue($candidates[0]['owner']);
    }

    public function testOnlyTheOwnerIsFlaggedAsOwner(): void
    {
        $byId = array_column($this->selection()->candidates(7), null, 'id');

        $this->assertTrue($byId[1]['owner']);
        $this->assertFalse($byId[2]['owner']);
    }

    public function testThePermissionFilterKeepsTheHoldersOnly(): void
    {
        // Le socle de test fait de l'appelant l'Owner : tous les comptes détiennent la permission.
        $this->assertCount(4, $this->selection()->candidates(7, 'planning_check.approve'));
    }

    public function testResolveKeepsTheSelectionOrderAndDropsUnusableAccounts(): void
    {
        $resolved = $this->selection()->resolve(7, [2, 1, 3, 4, 5, 99, 1, 'x', 0, '6']);

        $this->assertSame([2, 1, 6], array_column($resolved, 'id'));
        $this->assertSame(['aki@example.test', 'zoe@example.test', 'lea@example.test'], array_column($resolved, 'email'));
    }

    public function testResolveOnAnotherStoreKeepsTheOwnerOnly(): void
    {
        $this->assertSame([1], array_column($this->selection()->resolve(8, [1, 2]), 'id'));
    }

    public function testValidateAcceptsAnEmptySelectionUnlessRequired(): void
    {
        $this->assertSame(['ids' => [], 'error' => null], $this->selection()->validate(7, []));
        $this->assertSame('required', $this->selection()->validate(7, [], required: true)['error']);
    }

    public function testValidateNormalizesIds(): void
    {
        $result = $this->selection()->validate(7, ['2', 2, '1', '', 'abc']);

        $this->assertSame([2, 1], $result['ids']);
        $this->assertNull($result['error']);
    }

    public function testValidateRefusesAnAccountOutsideTheStore(): void
    {
        $this->assertSame('unknown', $this->selection()->validate(7, [1, 5])['error']);
        $this->assertSame('unknown', $this->selection()->validate(7, [3])['error']);
        $this->assertSame('unknown', $this->selection()->validate(8, [2])['error']);
        $this->assertNull($this->selection()->validate(8, [1])['error'], 'l\'Owner reste choisissable dans tout magasin');
    }

    public function testValidateEnforcesTheMaximum(): void
    {
        $this->assertSame('max', $this->selection()->validate(7, [1, 2, 6], max: 2)['error']);
        $this->assertNull($this->selection()->validate(7, [1, 2], max: 2)['error']);
    }
}
