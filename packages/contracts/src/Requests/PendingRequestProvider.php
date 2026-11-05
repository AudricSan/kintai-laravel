<?php

namespace Kintai\Contracts\Requests;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Une source de demandes en attente (congés, échanges de shifts...). Objet valeur du contrat Core / bundles.
 */
final class PendingRequestProvider
{
    /**
     * @param  string  $key  Identifiant stable.
     * @param  string  $label  Clé de traduction, traduite à l'affichage.
     * @param  string  $route  Nom de la route qui liste ces demandes.
     * @param  Closure(Authenticatable): int  $count  Nombre de demandes en attente que l'utilisateur doit traiter ou suivre.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $route,
        private readonly Closure $count,
    ) {}

    public function countFor(Authenticatable $user): int
    {
        return max(0, (int) ($this->count)($user));
    }
}
