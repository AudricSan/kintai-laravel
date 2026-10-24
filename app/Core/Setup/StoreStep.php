<?php

declare(strict_types=1);

namespace kintai\Core\Setup;

use kintai\Core\Exceptions\PlanLimitExceededException;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\InstanceLocale;
use kintai\Core\Services\StoreServiceInterface;
use kintai\Core\Validation\StoreValidator;

/**
 * Premier magasin : nom, code, fuseau, devise et adresse, avec les mêmes contrôles que le formulaire « Nouveau magasin »
 * (StoreService::createStore : validation, code unique, quota du plan). L'Owner y est rattaché. S'il existe déjà un
 * magasin (instance de démonstration, étape revisitée), l'étape l'indique et ne crée rien de plus.
 */
final class StoreStep implements SetupStepInterface
{
    public function __construct(
        private readonly StoreServiceInterface $storeService,
        private readonly StoreRepositoryInterface $stores,
        private readonly InstanceLocale $locale,
    ) {}

    public function id(): string
    {
        return 'store';
    }

    public function data(Request $request): array
    {
        $existing = array_values(array_filter($this->stores->findAll(), static fn(array $s): bool => !empty($s['is_active'])));
        return [
            'existing'   => array_map(static fn(array $s): string => (string) ($s['name'] ?? ''), $existing),
            'timezones'  => \DateTimeZone::listIdentifiers(),
            'currencies' => StoreValidator::CURRENCIES,
            'defaults'   => ['timezone' => date_default_timezone_get(), 'currency' => 'EUR', 'type' => 'retail'],
        ];
    }

    public function handle(Request $request, int $userId): array
    {
        $name = trim((string) $request->post('name', ''));
        $code = strtoupper(trim((string) $request->post('code', '')));
        if ($code === '' && $name !== '') {
            // Code proposé à partir du nom : lettres et chiffres, 6 au plus.
            $code = strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name), 0, 6));
        }
        $timezone = (string) $request->post('timezone', '');
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return ['setup_store_timezone_invalid'];
        }

        try {
            $store = $this->storeService->createStore([
                'code'            => $code,
                'name'            => $name,
                'type'            => 'retail',
                'timezone'        => $timezone,
                'locale'          => $this->locale->code(),
                'currency'        => strtoupper(trim((string) $request->post('currency', 'EUR'))),
                'currency_symbol_style' => 'kanji',
                'address_street'  => trim((string) $request->post('address_street', '')) ?: null,
                'address_city'    => trim((string) $request->post('address_city', '')) ?: null,
                'address_postal'  => trim((string) $request->post('address_postal', '')) ?: null,
                'address_country' => trim((string) $request->post('address_country', '')) ?: null,
            ]);
        } catch (ValidationException $e) {
            // Erreurs par champ, chacune une phrase ou une liste de phrases déjà traduites.
            $messages = [];
            foreach ($e->errors as $fieldErrors) {
                foreach ((array) $fieldErrors as $message) {
                    $messages[] = (string) $message;
                }
            }
            return $messages !== [] ? $messages : ['setup_store_invalid'];
        } catch (PlanLimitExceededException $e) {
            return [$e->getMessage()];
        }

        // L'Owner est rattaché au magasin (il gère déjà tout, mais figure dans ses membres).
        $this->storeService->addMember((int) $store['id'], $userId);
        return [];
    }

    public function summary(): ?string
    {
        $names = array_map(static fn(array $s): string => (string) ($s['name'] ?? ''), array_filter($this->stores->findAll(), static fn(array $s): bool => !empty($s['is_active'])));
        return $names === [] ? null : implode(', ', $names);
    }
}
