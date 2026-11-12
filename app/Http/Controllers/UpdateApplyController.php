<?php

namespace App\Http\Controllers;

use App\Activity\ActivityLogger;
use App\Backups\PasswordConfirmation;
use App\Update\UpdateApplier;
use App\Update\UpdateException;
use App\Update\UpdateState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Application d'une mise à jour préparée (Owner seulement, mot de passe revérifié). Volontairement HORS Livewire.
 *
 * Après `apply()`, le disque porte le code NEUF alors que ce processus exécute l'ANCIEN : ce contrôleur ne fait donc rien
 * d'autre, ensuite, que renvoyer une redirection construite avant l'appel (pas de vue, pas de composant, pas de classe
 * nouvelle). L'exécution des migrations est faite par la requête suivante (UpdateFinishController), avec le code neuf.
 */
class UpdateApplyController extends Controller
{
    public function __invoke(Request $request, UpdateApplier $applier, PasswordConfirmation $passwords, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $actor = $request->user();

        try {
            $passwords->verify($actor, (string) $request->input('password', ''));
            $state = $applier->state();

            if ($state->phase !== UpdateState::STAGED) {
                throw new UpdateException(__('There is no prepared update to apply.'));
            }
        } catch (ValidationException $e) {
            return redirect()->route('updates.index')->with('update_error', (string) collect($e->errors())->flatten()->first());
        } catch (UpdateException $e) {
            return redirect()->route('updates.index')->with('update_error', $e->getMessage());
        }

        // Tout ce dont la fin de la requête a besoin est préparé ici, avant que le code ne change sur le disque.
        $finish = redirect()->route('updates.finish.show');
        $failure = redirect()->route('updates.index');

        $logger->record('update.applied', null, null, ['from' => $state->from, 'to' => $state->to], $actor);

        try {
            $applier->apply();
        } catch (UpdateException $e) {
            // Le moteur a déjà remis les fichiers d'avant : le code chargé et celui du disque sont de nouveau les mêmes.
            $logger->record('update.rolled_back', null, null, ['from' => $state->from, 'to' => $state->to, 'reason' => $e->getMessage()], $actor);

            return $failure->with('update_error', $e->getMessage());
        }

        return $finish;
    }
}
