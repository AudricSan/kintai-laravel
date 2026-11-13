<?php

namespace App\License;

use App\Models\Store;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Plafonds de la licence appliqués à la création (`max_stores`, `max_employees`) : seul point de contrôle, appelé par les
 * actions de création (formulaires, API v1, import). Rien n'est jamais supprimé ni bloqué parmi l'existant : au plafond
 * (ou au-delà, après un retour au palier gratuit), on refuse seulement d'en créer un de plus. Null = illimité.
 */
class LicenseLimits
{
    public function __construct(private readonly LicenseState $state) {}

    /**
     * @throws ValidationException
     */
    public function assertCanCreateStore(): void
    {
        $max = $this->state->limit('max_stores');

        if ($max !== null && Store::query()->count() >= $max) {
            throw ValidationException::withMessages(['name' => __('The store limit of your license is reached (:max). Upgrade your license to add more stores.', ['max' => $max])]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertCanCreateEmployee(): void
    {
        $max = $this->state->limit('max_employees');

        if ($max !== null && User::query()->count() >= $max) {
            throw ValidationException::withMessages(['last_name' => __('The employee limit of your license is reached (:max). Upgrade your license to add more employees.', ['max' => $max])]);
        }
    }
}
