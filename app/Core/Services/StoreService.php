<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Routing\RouteSlugService;
use kintai\Core\Validation\StoreValidator;

final class StoreService implements StoreServiceInterface
{
    public function __construct(
        private readonly StoreRepositoryInterface $stores,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly UserRepositoryInterface $users,
        private readonly LanguageRepositoryInterface $languages,
        private readonly PlanLimitService $planLimits,
        private readonly ?RouteSlugService $routeSlugs = null,
        // Facultatif pour les tests qui construisent le service à la main.
        private readonly ?RoleAssignmentRepositoryInterface $roleAssignments = null,
    ) {}

    public function getStoresForAdmin(?array $managedIds, string $sort = 'name_asc'): array
    {
        $all = $this->stores->findAll();
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
        $memberships = $this->storeUsers->findByStore($storeId);
        $users = [];
        foreach ($memberships as $m) {
            $u = $this->users->findById((int) $m['user_id']);
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

        $validator = new StoreValidator($this->languages);
        $validator->validate($data)->throwIfInvalid();
        $this->assertCodeAvailable(strtoupper(trim((string) ($data['code'] ?? ''))), 0);
        $manualSlug = $this->validatedManualSlug($data, 0);

        $saved = $this->stores->save([
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
        $existing = $this->stores->findByCode($code);
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
        $store = $this->stores->findById($storeId);
        if ($store === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }

        // Seule la devise est revalidée ici (voir StoreValidator::currencyErrors()) : une valeur forgée hors de la
        // liste du formulaire finissait affichée telle quelle dans les pages de statistiques et de rentabilité.
        $currencyErrors = StoreValidator::currencyErrors($data);
        if ($currencyErrors !== []) {
            throw new ValidationException(['currency' => $currencyErrors[0]], $currencyErrors[0]);
        }
        $timezoneErrors = StoreValidator::timezoneErrors($data);
        if ($timezoneErrors !== []) {
            throw new ValidationException(['timezone' => $timezoneErrors[0]], $timezoneErrors[0]);
        }
        $timezoneErrors = StoreValidator::timezoneErrors($data);
        if ($timezoneErrors !== []) {
            throw new ValidationException(['timezone' => $timezoneErrors[0]], $timezoneErrors[0]);
        }
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
            $this->stores->saveImportSettings($storeId, $data['_excel_settings']);
        }
        if (isset($data['_deduction_settings'])) {
            $this->stores->saveDeductionSettings($storeId, $data['_deduction_settings']);
        }
        if (isset($data['_features'])) {
            // Décisions des seules fonctionnalités proposées par le formulaire (clé => active ?).
            $this->stores->saveFeatureDecisions($storeId, $data['_features']);
        }

        $saved = $this->stores->save($storeData);
        $this->routeSlugs?->syncStore($storeId, (string) $saved['name'], $manualSlug);

        return $saved;
    }

    public function deleteStore(int $storeId): void
    {
        $store = $this->stores->findById($storeId);
        if ($store === null || !empty($store['deleted_at'])) {
            throw new NotFoundException(__('error_store_not_found'));
        }
        $this->stores->delete($storeId);

        // Un rôle limité à ce magasin ne donnerait plus de droits que sur un magasin invisible : retiré.
        if ($this->roleAssignments !== null) {
            foreach ($this->roleAssignments->findByScope('store', $storeId) as $assignment) {
                $this->roleAssignments->revoke((int) $assignment['id']);
            }
        }
    }

    public function addMember(int $storeId, int $userId): ?array
    {
        if ($this->storeUsers->findMembership($storeId, $userId) !== null) {
            return null;
        }

        return $this->storeUsers->save([
            'store_id' => $storeId,
            'user_id'  => $userId,
        ]);
    }

    public function removeMember(int $membershipId, int $storeId): void
    {
        $membership = $this->storeUsers->findById($membershipId);
        if ($membership === null || (int) $membership['store_id'] !== $storeId) {
            throw new NotFoundException(__('error_membership_not_found'));
        }
        $this->storeUsers->delete($membershipId);
    }

    public function saveMemberDeductions(int $membershipId, int $storeId, bool $subjectTo): void
    {
        $membership = $this->storeUsers->findById($membershipId);
        if ($membership === null || (int) $membership['store_id'] !== $storeId) {
            throw new NotFoundException(__('error_member_not_found'));
        }
        $this->storeUsers->setSubjectToDeductions($membershipId, $subjectTo);
    }

    public function getDeductionSettings(int $storeId): array
    {
        return $this->stores->getDeductionSettings($storeId);
    }

    public function getDeductionOverrides(int $membershipId): array
    {
        return ['subject_to_deductions' => $this->storeUsers->getSubjectToDeductions($membershipId)];
    }
}
