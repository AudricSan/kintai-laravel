<?php

namespace Kintai\Contracts\Activity;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Écrit une ligne du journal d'activité. Point d'entrée du Core et des bundles :
 *
 *     app(ActivityRecorder::class)->record('timeoff.approved', $request, $storeId, ['employee' => $name]);
 *
 * - l'acteur par défaut est l'utilisateur connecté (null pour une tâche planifiée) ;
 * - mots de passe, jetons et secrets sont retirés des propriétés, à tous les niveaux ;
 * - un échec d'écriture est signalé mais ne casse jamais l'action qui est journalisée.
 *
 * Le bundle déclare aussi le libellé de ses actions dans `ActivityActionRegistry`.
 */
interface ActivityRecorder
{
    /**
     * @param  array<string, mixed>  $properties  Contexte lisible : noms, avant / après…
     * @return object|null L'entrée écrite, null en cas d'échec.
     */
    public function record(string $action, ?Model $subject = null, ?int $storeId = null, array $properties = [], ?Authenticatable $actor = null): ?object;

    /**
     * Comme `record()`, pour une cible qui n'existe plus (supprimée) : on ne dispose que de son type et de son identifiant.
     *
     * @param  array<string, mixed>  $properties
     * @return object|null L'entrée écrite, null en cas d'échec.
     */
    public function recordFor(string $action, string $subjectType, int $subjectId, ?int $storeId = null, array $properties = [], ?Authenticatable $actor = null): ?object;
}
