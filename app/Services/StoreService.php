<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\LanguageRepositoryInterface;
use App\Models\RoleAssignment;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Routing\RouteSlugService;
use App\Validation\StoreValidator;

final class StoreService implements StoreServiceInterface
{
    public function __construct(
        private readonly LanguageRepositoryInterface $languages,
        private readonly PlanLimitService $planLimits,
        private readonly ?RouteSlugService $routeSlugs = null,
        // Facultatif pour les tests qui construisent le service à la main.
    ) {}

    public function getStoresForAdmin(?array $managedIds, string $sort = 'name_asc'): array
    {
        $all = Store::findAll();
        if ($managedIds !== null) {
            $all = array_values(array_filter($all, fn($s) => in_array((int) $s['id'], $managedIds, true)));
        }
        usort($all, function ($a, $b) use ($sort) {
            $codeA  = strtolower($a['code'] ?? '');
            $codeB  = strtolower($b['code'] ?? '');
            $nameA  = strtolower($a['name'] ?? '');
            $nameB  = strtolower($b['name'] ?? '');
            $typeA  = strtolower($a['type'] ?? '');
            $typeB  = strtolower($b['type'] ?? '');
            $statA  = (int) (!empty($a['is_active']) && empty($a['deleted_at']));
            $statB  = (int) (!empty($b['is_active']) && empty($b['deleted_at']));
            return match ($sort) {
                'name_desc'   => strcmp($nameB, $nameA),
                'code_asc'    => strcmp($codeA, $codeB) ?: strcmp($nameA, $nameB),
                'code_desc'   => strcmp($codeB, $codeA) ?: strcmp($nameA, $nameB),
                'type_asc'    => strcmp($typeA, $typeB) ?: strcmp($nameA, $nameB),
                'type_desc'   => strcmp($typeB, $typeA) ?: strcmp($nameA, $nameB),
                'status_asc'  => $statA <=> $statB ?: strcmp($nameA, $nameB),
                'status_desc' => $statB <=> $statA ?: strcmp($nameA, $nameB),
                default       => strcmp($nameA, $nameB),
            };
        });
        return $all;
    }

    public function getStoreMembers(int $storeId): array
    {
        $memberships = StoreUser::findByStore($storeId);
        $users = [];
        foreach ($memberships as $m) {
            $u = User::findById((int) $m['user_id']);
            if ($u) {
                $users[] = array_merge($u, ['membership' => $m]);
            }
        }
        return $users;
    }

    public function getStoreMembersMap(int $storeId): array
    {
        $map = [];
        foreach ($this->getStoreMembers($storeId) as $m) {
            $map[(int) $m['id']] = $m;
        }
        return $map;
    }

    public function createStore(array $data): array
    {
        $this->planLimits->assertCanCreateStore();

        ValidationException::check((new StoreValidator($this->languages))->make($data));
        $this->assertCodeAvailable(strtoupper(trim((string) ($data['code'] ?? ''))), 0);
        $manualSlug = $this->validatedManualSlug($data, 0);

        $saved = Store::put([
            'code'            => strtoupper(trim($data['code'] ?? '')),
            'name'            => $data['name'] ?? '',
            'type'            => $data['type'] ?? 'retail',
            'timezone'        => $data['timezone'] ?? 'UTC',
            'locale'          => $data['locale'] ?? 'en',
            'currency'        => strtoupper(trim($data['currency'] ?? 'EUR')),
            'currency_symbol_style' => ($data['currency_symbol_style'] ?? '') === 'international' ? 'international' : 'kanji',
            'phone'           => ($data['phone'] ?? '') ?: null,
            'email'           => ($data['email'] ?? '') ?: null,
            'address_street'  => ($data['address_street'] ?? '') ?: null,
            'address_city'    => ($data['address_city'] ?? '') ?: null,
            'address_postal'  => ($data['address_postal'] ?? '') ?: null,
            'address_country' => ($data['address_country'] ?? '') ?: null,
            'is_active'       => 1,
        ]);

        $this->routeSlugs?->syncStore((int) $saved['id'], (string) $saved['name'], $manualSlug);

        return $saved;
    }

    /**
     * Le code magasin est unique en base : un code déjà pris par un autre magasin levait une erreur SQL, donc une
     * page 500, au lieu d'un message. $storeId vaut 0 pour un magasin pas encore créé.
     *
     * @throws ValidationException
     */
    private function assertCodeAvailable(string $code, int $storeId): void
    {
        if ($code === '') {
            return;
        }
        $existing = Store::findByCode($code);
        if ($existing !== null && (int) $existing['id'] !== $storeId) {
            $message = __('val_store_code_taken', ['code' => $code]);
            throw new ValidationException(['code' => $message], $message);
        }
    }

    /**
     * Slug d'URL saisi à la main (champ « slug »), normalisé et validé. null = champ absent (API : un slug
     * manuel existant est conservé), '' = pas de slug manuel (alias tiré du nom).
     *
     * @throws ValidationException
     */
    private function validatedManualSlug(array $data, int $storeId): ?string
    {
        if (!array_key_exists('slug', $data) || $data['slug'] === null) {
            return null;
        }
        $slug = mb_strtolower(trim((string) $data['slug']), 'UTF-8');
        if ($slug === '' || $this->routeSlugs === null) {
            return $slug;
        }
        $error = $this->routeSlugs->manualStoreSlugError($slug, $storeId);
        if ($error !== null) {
            throw new ValidationException(['slug' => __($error)], __($error));
        }

        return $slug;
    }

    public function updateStore(int $storeId, array $data): array
    {
        $store = Store::findById($storeId);
        if ($store === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }

        // Seules la devise et le fuseau horaire sont revalidés ici (voir StoreValidator::forUpdate()) : une valeur forgée
        // hors de la liste du formulaire finissait affichée telle quelle dans les pages de statistiques et de rentabilité.
        ValidationException::check(StoreValidator::forUpdate($data));
        $this->assertCodeAvailable(strtoupper(trim((string) ($data['code'] ?? $store['code'] ?? ''))), $storeId);

        $storeData = array_merge($store, [
            'code'                 => strtoupper(trim($data['code'] ?? $store['code'] ?? '')),
            'name'                 => $data['name'] ?? $store['name'] ?? '',
            'type'                 => $data['type'] ?? $store['type'] ?? 'retail',
            'timezone'             => StoreTimezone::resolve($data['timezone'] ?? $store['timezone'] ?? null),
            'locale'               => $data['locale'] ?? $store['locale'] ?? 'en',
            'currency'             => strtoupper(trim($data['currency'] ?? $store['currency'] ?? 'EUR')),
            'currency_symbol_style' => ($data['currency_symbol_style'] ?? $store['currency_symbol_style'] ?? '') === 'international' ? 'international' : 'kanji',
            'phone'                => ($data['phone'] ?? '') ?: null,
            'email'                => ($data['email'] ?? '') ?: null,
            'address_street'       => ($data['address_street'] ?? '') ?: null,
            'address_city'         => ($data['address_city'] ?? '') ?: null,
            'address_postal'       => ($data['address_postal'] ?? '') ?: null,
            'address_country'      => ($data['address_country'] ?? '') ?: null,
            'week_start_day'       => max(1, min(7, (int) ($data['week_start_day'] ?? 1))),
            'min_shift_minutes'    => (int) ($data['min_shift_minutes'] ?? 120),
            'max_shift_minutes'    => (int) ($data['max_shift_minutes'] ?? 480),
            'min_staff_per_day'    => (int) ($data['min_staff_per_day'] ?? 0),
            'low_staff_hour_start' => max(-1, min(23, (int) ($data['low_staff_hour_start'] ?? -1))),
            'low_staff_hour_end'   => max(-1, min(23, (int) ($data['low_staff_hour_end'] ?? -1))),
            'is_active'            => !empty($data['is_active']) ? 1 : 0,
        ]);

        $manualSlug = $this->validatedManualSlug($data, $storeId);

        if (isset($data['_excel_settings'])) {
            Store::saveImportSettings($storeId, $data['_excel_settings']);
        }
        if (isset($data['_deduction_settings'])) {
            Store::saveDeductionSettings($storeId, $data['_deduction_settings']);
        }
        if (isset($data['_features'])) {
            // Décisions des seules fonctionnalités proposées par le formulaire (clé => active ?).
            Store::saveFeatureDecisions($storeId, $data['_features']);
        }

        $saved = Store::put($storeData);
        $this->routeSlugs?->syncStore($storeId, (string) $saved['name'], $manualSlug);

        return $saved;
    }

    public function deleteStore(int $storeId): void
    {
        $store = Store::findById($storeId);
        if ($store === null || !empty($store['deleted_at'])) {
            throw new NotFoundException(__('error_store_not_found'));
        }
        Store::remove($storeId);

        // Un rôle limité à ce magasin ne donnerait plus de droits que sur un magasin invisible : retiré.
        foreach (RoleAssignment::findByScope('store', $storeId) as $assignment) {
            RoleAssignment::revoke((int) $assignment['id']);
        }
    }

    public function addMember(int $storeId, int $userId): ?array
    {
        if (StoreUser::findMembership($storeId, $userId) !== null) {
            return null;
        }

        return StoreUser::put([
            'store_id' => $storeId,
            'user_id'  => $userId,
        ]);
    }

    public function removeMember(int $membershipId, int $storeId): void
    {
        $membership = StoreUser::findById($membershipId);
        if ($membership === null || (int) $membership['store_id'] !== $storeId) {
            throw new NotFoundException(__('error_membership_not_found'));
        }
        StoreUser::remove($membershipId);
    }

    public function saveMemberDeductions(int $membershipId, int $storeId, bool $subjectTo): void
    {
        $membership = StoreUser::findById($membershipId);
        if ($membership === null || (int) $membership['store_id'] !== $storeId) {
            throw new NotFoundException(__('error_member_not_found'));
        }
        StoreUser::setSubjectToDeductions($membershipId, $subjectTo);
    }

    public function getDeductionSettings(int $storeId): array
    {
        return Store::getDeductionSettings($storeId);
    }

    public function getDeductionOverrides(int $membershipId): array
    {
        return ['subject_to_deductions' => StoreUser::getSubjectToDeductions($membershipId)];
    }
}
