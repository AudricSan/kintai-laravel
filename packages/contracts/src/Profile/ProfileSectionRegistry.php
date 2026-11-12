<?php

namespace Kintai\Contracts\Profile;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Point d'extension : le Core et les bundles y déclarent les pages de la zone « réglages personnels ».
 * Le libellé est une clé de traduction, traduite à l'affichage.
 */
interface ProfileSectionRegistry
{
    /**
     * @param  (Closure(Authenticatable): bool)|null  $visible  Absent : toujours visible.
     *
     * @throws \InvalidArgumentException Clé déjà enregistrée.
     */
    public function register(string $key, string $label, string $route, int $order = 100, ?Closure $visible = null): void;

    /**
     * Sections visibles pour l'utilisateur, triées par ordre puis par ordre d'enregistrement.
     *
     * @return list<ProfileSection>
     */
    public function sections(Authenticatable $user): array;
}
