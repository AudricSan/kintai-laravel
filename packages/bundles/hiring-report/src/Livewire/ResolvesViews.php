<?php

namespace Kintai\Bundles\HiringReport\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\HiringReport\HiringReportAccess;
use Kintai\Bundles\HiringReport\HiringReportServiceProvider;

/**
 * Aides communes aux composants : nom d'une vue du bundle, utilisateur connecté et droits. Les vues n'existent dans
 * l'espace de noms du bundle que s'il est activé : l'analyse statique, qui le voit désactivé, ne doit pas les prendre pour
 * des vues manquantes.
 */
trait ResolvesViews
{
    protected function viewName(string $name): string
    {
        return HiringReportServiceProvider::SLUG.'::'.$name;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function access(): HiringReportAccess
    {
        return app(HiringReportAccess::class);
    }
}
