<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Services;

use App\Models\AppSetting;
use App\Repositories\LanguageRepositoryInterface;
use App\Models\Store;
use App\Models\StoreUser;
use App\Repositories\UserRepositoryInterface;
use App\Exceptions\PlanLimitExceededException;
use App\Services\LicenseClientService;
use App\Services\PlanLimitService;
use App\Services\StoreService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use App\Exceptions\NotFoundException;
use App\Models\RoleAssignment;
use PHPUnit\Framework\TestCase;

final class StoreServiceTest extends TestCase
{
    private ?StoreService $service = null;

    protected function setUp(): void
    {
kintai_test_database();        kintai_test_migrate();        kintai_test_fk_off();
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function service(): StoreService
    {
        if ($this->service === null) {
            $languages = $this->createStub(LanguageRepositoryInterface::class);
            $languages->method('findAllActive')->willReturn([['code' => 'en']]);
            $this->service = new StoreService(
                $this->createStub(UserRepositoryInterface::class),
                $languages,
                new PlanLimitService(
                    $this->createStub(UserRepositoryInterface::class),
                    new LicenseClientService(['base_url' => '', 'api_key' => '']),
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
        $existingStore = ['id' => 1, 'code' => 'A', 'name' => 'Store A'];
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? $existingStore : null);
        $decisions = ['shifts' => true, 'timeclock' => false, 'timeoff' => true];
        $data = ['_features' => $decisions];

        $this->service()->updateStore(1, $data);

        $saved = \App\Models\Store::getFeatureDecisions(1);
        foreach ($decisions as $key => $enabled) {
            $this->assertSame($enabled, $saved[$key] ?? null, $key);
        }
        $this->assertArrayNotHasKey('features', \App\Models\Store::findById(1));
    }

    public function testUpdateStoreWithoutFeaturesDoesNotCallSaveFeatures(): void
    {
        $existingStore = ['id' => 1, 'code' => 'A', 'name' => 'Store A'];
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? $existingStore : null);
        $before = \App\Models\Store::getFeatureDecisions(1);
        $this->service()->updateStore(1, ['name' => 'Store A renamed']);

        $this->assertSame($before, \App\Models\Store::getFeatureDecisions(1));
        $this->assertSame('Store A renamed', \App\Models\Store::findById(1)['name']);
    }

    public function testCreateStoreDefaultsCurrencySymbolStyleToKanji(): void
    {
        kintai_test_stores(countActive: fn() => 0);
        $this->service()->createStore(['code' => 'ST01', 'name' => 'Store A']);

        $this->assertSame('kanji', \App\Models\Store::findByCode('ST01')['currency_symbol_style']);
    }

    public function testCreateStorePersistsInternationalCurrencySymbolStyle(): void
    {
        kintai_test_stores(countActive: fn() => 0);
        $this->service()->createStore(['code' => 'ST01', 'name' => 'Store A', 'currency_symbol_style' => 'international']);

        $this->assertSame('international', \App\Models\Store::findByCode('ST01')['currency_symbol_style']);
    }

    public function testCreateStoreThrowsWhenFreePlanStoreLimitReached(): void
    {
        kintai_test_stores(countActive: fn() => 1);
        $this->expectException(PlanLimitExceededException::class);
        $this->service()->createStore(['code' => 'ST01', 'name' => 'Store A']);
    }

    public function testUpdateStoreKeepsExistingCurrencySymbolStyleWhenNotProvided(): void
    {
        $existingStore = ['id' => 1, 'code' => 'A', 'name' => 'Store A', 'currency_symbol_style' => 'international'];
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? $existingStore : null);
        $this->service()->updateStore(1, ['name' => 'Store A renamed']);

        $this->assertSame('international', \App\Models\Store::findById(1)['currency_symbol_style']);
    }

    /** Audit du 01/10/2026 : la devise n'était pas contrôlée en modification et s'affichait telle quelle. */
    public function testUpdateStoreRejectsAForgedCurrency(): void
    {
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'code' => 'A', 'name' => 'Store A'] : null);
        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->service()->updateStore(1, ['name' => 'Store A', 'currency' => '<a href=//evil>SESSION</a>']);
    }

    public function testUpdateStoreStillAcceptsExistingFreeTypeAndLocale(): void
    {
        // Données réelles : type libre (コンビニ) et langue hors liste (JA) ne doivent pas bloquer une modification.
        $existingStore = ['id' => 1, 'code' => '58182', 'name' => '航空公園東口店', 'type' => 'コンビニ', 'locale' => 'JA', 'currency' => 'JPY'];
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? $existingStore : null);
        $this->service()->updateStore(1, ['name' => '航空公園東口店', 'type' => 'コンビニ', 'locale' => 'JA', 'currency' => 'JPY']);

        $this->assertSame('JA', \App\Models\Store::findById(1)['locale']);
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
        kintai_test_stores(findByCode: fn() => ['id' => 5, 'code' => 'ST01', 'is_active' => 0], countActive: fn() => 0);
        try {
            $this->service()->createStore(['code' => 'ST01', 'name' => 'Store B']);
            $this->fail('Un code déjà pris doit être refusé.');
        } catch (\App\Exceptions\ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors, 'refus dû au doublon, pas au format');
        }
    }

    public function testUpdateStoreRejectsACodeAlreadyUsedByAnotherStore(): void
    {
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? ['id' => 1, 'code' => 'A1', 'name' => 'Store A'] : null, findByCode: fn() => ['id' => 2, 'code' => 'B2']);
        try {
            $this->service()->updateStore(1, ['code' => 'b2', 'name' => 'Store A']);
            $this->fail('Un code déjà pris doit être refusé.');
        } catch (\App\Exceptions\ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors);
        }
    }

    public function testUpdateStoreKeepingItsOwnCodeIsAccepted(): void
    {
        $store = ['id' => 1, 'code' => 'A1', 'name' => 'Store A'];
        kintai_test_stores(findById: fn(int $id) => $id === 1 ? $store : null, findByCode: fn() => $store);
        $this->service()->updateStore(1, ['code' => 'A1', 'name' => 'Store A renamed']);

        $this->assertSame('Store A renamed', \App\Models\Store::findById(1)['name']);
    }

    // -------------------------------------------------------------------------
    // Suppression (audit du 13/10/2026, M8)
    // -------------------------------------------------------------------------

    private function serviceWithAssignments(): StoreService
    {
        return new StoreService(
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(LanguageRepositoryInterface::class),
            new PlanLimitService(
                $this->createStub(UserRepositoryInterface::class),
                new LicenseClientService(['base_url' => '', 'api_key' => '']),
            ),
            null,
        );
    }

    public function testDeleteStoreSoftDeletesAndRevokesRolesScopedToIt(): void
    {
        kintai_test_stores(findById: fn(int $id) => $id === 7 ? ['id' => 7, 'deleted_at' => null] : null);
        kintai_test_assignments(findByScope: fn(string $type, ?int $id) => $type === 'store' && $id === 7 ? [
            ['id' => 21, 'user_id' => 4, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 7],
            ['id' => 22, 'user_id' => 5, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 7],
        ] : []);
        $this->serviceWithAssignments()->deleteStore(7);

        $this->assertNotNull(\App\Models\Store::findById(7)['deleted_at'], 'magasin supprimé (suppression douce)');
        $this->assertSame(0, \App\Models\RoleAssignment::query()->where('scope_type', 'store')->where('scope_id', 7)->count(), 'rôles liés au magasin révoqués');
    }

    public function testDeletingAnAlreadyDeletedStoreIsNotFound(): void
    {
        kintai_test_stores(findById: fn() => ['id' => 7, 'deleted_at' => '2026-10-16 09:00:00']);
        $this->expectException(NotFoundException::class);
        $this->service()->deleteStore(7);
    }
}
