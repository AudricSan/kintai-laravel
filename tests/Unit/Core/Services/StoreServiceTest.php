<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services;

use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Exceptions\PlanLimitExceededException;
use kintai\Core\Services\LicenseClientService;
use kintai\Core\Services\PlanLimitService;
use kintai\Core\Services\StoreService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class StoreServiceTest extends TestCase
{
    private StoreRepositoryInterface&Stub $stores;
    private ?StoreService $service = null;

    protected function setUp(): void
    {
        $this->stores = $this->createStub(StoreRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function service(): StoreService
    {
        if ($this->service === null) {
            $languages = $this->createStub(LanguageRepositoryInterface::class);
            $languages->method('findAllActive')->willReturn([['code' => 'en']]);
            $this->service = new StoreService(
                $this->stores,
                $this->createStub(StoreUserRepositoryInterface::class),
                $this->createStub(UserRepositoryInterface::class),
                $languages,
                new PlanLimitService(
                    $this->stores,
                    $this->createStub(UserRepositoryInterface::class),
                    new LicenseClientService($this->createStub(AppSettingsRepositoryInterface::class), ['base_url' => '', 'api_key' => '']),
                ),
            );
        }

        return $this->service;
    }

    /**
     * Les fonctionnalités activées par store ne vivent plus que dans la table
     * store_features (via saveFeatures) — la colonne stores.features historique
     * a été retirée pour éviter la désynchronisation entre les deux sources.
     */
    public function testUpdateStorePersistsFeaturesViaSaveFeaturesOnly(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $existingStore = ['id' => 1, 'code' => 'A', 'name' => 'Store A'];
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn($existingStore);

        $decisions = ['shifts' => true, 'timeclock' => false, 'timeoff' => true];
        $data = ['_features' => $decisions];

        $this->stores->expects($this->once())->method('saveFeatureDecisions')->with(1, $decisions);
        $this->stores->expects($this->once())->method('save')->with(
            $this->callback(fn (array $storeData) => !array_key_exists('features', $storeData)),
        )->willReturn(['id' => 1]);

        $this->service()->updateStore(1, $data);
    }

    public function testUpdateStoreWithoutFeaturesDoesNotCallSaveFeatures(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $existingStore = ['id' => 1, 'code' => 'A', 'name' => 'Store A'];
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn($existingStore);

        $this->stores->expects($this->never())->method('saveFeatureDecisions');
        $this->stores->expects($this->once())->method('save')->willReturn($existingStore);

        $this->service()->updateStore(1, ['name' => 'Store A renamed']);
    }

    public function testCreateStoreDefaultsCurrencySymbolStyleToKanji(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->stores->expects($this->once())->method('save')->with(
            $this->callback(fn (array $data) => $data['currency_symbol_style'] === 'kanji'),
        )->willReturn(['id' => 1]);

        $this->service()->createStore(['code' => 'ST01', 'name' => 'Store A']);
    }

    public function testCreateStorePersistsInternationalCurrencySymbolStyle(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->stores->expects($this->once())->method('save')->with(
            $this->callback(fn (array $data) => $data['currency_symbol_style'] === 'international'),
        )->willReturn(['id' => 1]);

        $this->service()->createStore(['code' => 'ST01', 'name' => 'Store A', 'currency_symbol_style' => 'international']);
    }

    public function testCreateStoreThrowsWhenFreePlanStoreLimitReached(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->stores->method('countActive')->willReturn(1);
        $this->stores->expects($this->never())->method('save');

        $this->expectException(PlanLimitExceededException::class);
        $this->service()->createStore(['code' => 'ST01', 'name' => 'Store A']);
    }

    public function testUpdateStoreKeepsExistingCurrencySymbolStyleWhenNotProvided(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $existingStore = ['id' => 1, 'code' => 'A', 'name' => 'Store A', 'currency_symbol_style' => 'international'];
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn($existingStore);

        $this->stores->expects($this->once())->method('save')->with(
            $this->callback(fn (array $data) => $data['currency_symbol_style'] === 'international'),
        )->willReturn($existingStore);

        $this->service()->updateStore(1, ['name' => 'Store A renamed']);
    }

    /** Audit du 01/10/2026 : la devise n'était pas contrôlée en modification et s'affichait telle quelle. */
    public function testUpdateStoreRejectsAForgedCurrency(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'code' => 'A', 'name' => 'Store A']);
        $this->stores->expects($this->never())->method('save');

        $this->expectException(\kintai\Core\Exceptions\ValidationException::class);
        $this->service()->updateStore(1, ['name' => 'Store A', 'currency' => '<a href=//evil>SESSION</a>']);
    }

    public function testUpdateStoreStillAcceptsExistingFreeTypeAndLocale(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        // Données réelles : type libre (コンビニ) et langue hors liste (JA) ne doivent pas bloquer une modification.
        $existingStore = ['id' => 1, 'code' => '58182', 'name' => '航空公園東口店', 'type' => 'コンビニ', 'locale' => 'JA', 'currency' => 'JPY'];
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn($existingStore);
        $this->stores->expects($this->once())->method('save')->willReturn($existingStore);

        $this->service()->updateStore(1, ['name' => '航空公園東口店', 'type' => 'コンビニ', 'locale' => 'JA', 'currency' => 'JPY']);
    }

    public function testUnknownCurrencyCodeCannotInjectMarkup(): void
    {
        $this->assertSame('AHREFEVILSESSIONA', currency_symbol('<a href=//evil>SESSION</a>'));
        $this->assertSame('円', currency_symbol('JPY'));
        $this->assertStringNotContainsString('<', format_currency(12.5, '<b>x</b>'));
    }

    /** Audit du 01/10/2026 (point 7) : un code déjà pris levait une erreur SQL (page 500) au lieu d'un message. */
    public function testCreateStoreRejectsACodeAlreadyUsedByAnotherStore(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->stores->expects($this->atLeastOnce())->method('findByCode')->with('ST01')->willReturn(['id' => 5, 'code' => 'ST01']);
        $this->stores->expects($this->never())->method('save');

        try {
            $this->service()->createStore(['code' => 'ST01', 'name' => 'Store B']);
            $this->fail('Un code déjà pris doit être refusé.');
        } catch (\kintai\Core\Exceptions\ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors, 'refus dû au doublon, pas au format');
        }
    }

    public function testUpdateStoreRejectsACodeAlreadyUsedByAnotherStore(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn(['id' => 1, 'code' => 'A1', 'name' => 'Store A']);
        $this->stores->expects($this->atLeastOnce())->method('findByCode')->with('B2')->willReturn(['id' => 2, 'code' => 'B2']);
        $this->stores->expects($this->never())->method('save');

        try {
            $this->service()->updateStore(1, ['code' => 'b2', 'name' => 'Store A']);
            $this->fail('Un code déjà pris doit être refusé.');
        } catch (\kintai\Core\Exceptions\ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors);
        }
    }

    public function testUpdateStoreKeepingItsOwnCodeIsAccepted(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $store = ['id' => 1, 'code' => 'A1', 'name' => 'Store A'];
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(1)->willReturn($store);
        $this->stores->expects($this->atLeastOnce())->method('findByCode')->with('A1')->willReturn($store);
        $this->stores->expects($this->once())->method('save')->willReturn($store);

        $this->service()->updateStore(1, ['code' => 'A1', 'name' => 'Store A renamed']);
    }

    // -------------------------------------------------------------------------
    // Suppression (audit du 13/10/2026, M8)
    // -------------------------------------------------------------------------

    private function serviceWithAssignments(RoleAssignmentRepositoryInterface $assignments): StoreService
    {
        return new StoreService(
            $this->stores,
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(LanguageRepositoryInterface::class),
            new PlanLimitService(
                $this->stores,
                $this->createStub(UserRepositoryInterface::class),
                new LicenseClientService($this->createStub(AppSettingsRepositoryInterface::class), ['base_url' => '', 'api_key' => '']),
            ),
            null,
            $assignments,
        );
    }

    public function testDeleteStoreSoftDeletesAndRevokesRolesScopedToIt(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->stores->expects($this->atLeastOnce())->method('findById')->with(7)->willReturn(['id' => 7, 'deleted_at' => null]);
        $this->stores->expects($this->once())->method('delete')->with(7)->willReturn(1);

        $assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByScope')->with('store', 7)->willReturn([['id' => 21], ['id' => 22]]);
        $revoked = [];
        $assignments->expects($this->exactly(2))->method('revoke')->willReturnCallback(function (int $id) use (&$revoked): int {
            $revoked[] = $id;
            return 1;
        });

        $this->serviceWithAssignments($assignments)->deleteStore(7);

        $this->assertSame([21, 22], $revoked);
    }

    public function testDeletingAnAlreadyDeletedStoreIsNotFound(): void
    {
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->stores->method('findById')->willReturn(['id' => 7, 'deleted_at' => '2026-10-16 09:00:00']);
        $this->stores->expects($this->never())->method('delete');

        $this->expectException(NotFoundException::class);
        $this->service()->deleteStore(7);
    }
}
