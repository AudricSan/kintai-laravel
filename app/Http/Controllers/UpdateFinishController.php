<?php

namespace App\Http\Controllers;

use App\Activity\ActivityLogger;
use App\Update\UpdateApplier;
use App\Update\UpdateException;
use App\Update\UpdateState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Fin d'une mise à jour (Owner seulement) : migrations, caches, maintenance rétablie. Ces deux routes sont des requêtes
 * NEUVES, donc servies par le code neuf. L'Owner connecté passe la maintenance (voir MaintenanceMode).
 */
class UpdateFinishController extends Controller
{
    /** Page autonome (ni Livewire ni mise en page de l'application) qui soumet aussitôt le formulaire de fin. */
    public function show(UpdateApplier $applier): View|RedirectResponse
    {
        Gate::authorize('manage-settings');

        try {
            if ($applier->state()->phase !== UpdateState::SWAPPED) {
                return redirect()->route('updates.index');
            }
        } catch (UpdateException $e) {
            return redirect()->route('updates.index')->with('update_error', $e->getMessage());
        }

        return view('updates.finishing');
    }

    public function store(Request $request, UpdateApplier $applier, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $actor = $request->user();
        $from = null;
        $to = null;

        try {
            $state = $applier->state();
            $from = $state->from;
            $to = $state->to;

            $result = $applier->finish();
        } catch (UpdateException $e) {
            // Si les migrations ont échoué, le moteur a fait le retour arrière : on le consigne.
            $logger->record('update.rolled_back', null, null, ['from' => $from, 'to' => $to, 'reason' => $e->getMessage()], $actor);

            return redirect()->route('updates.index')->with('update_error', $e->getMessage());
        }

        return redirect()->route('updates.index')->with('update_success', __('Kintai was updated from :from to :to.', ['from' => $result['from'], 'to' => $result['to']]));
    }
}
