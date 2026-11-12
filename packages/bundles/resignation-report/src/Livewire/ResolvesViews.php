<?php

namespace Kintai\Bundles\ResignationReport\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Kintai\Bundles\ResignationReport\ResignationReportAccess;
use Kintai\Bundles\ResignationReport\ResignationReportServiceProvider;

/**
 * Aides communes aux composants : nom d'une vue du bundle, utilisateur connecté et droits. Les vues n'existent dans
 * l'espace de noms du bundle que s'il est activé : l'analyse statique, qui le voit désactivé, ne doit pas les prendre pour
 * des vues manquantes.
 */
trait ResolvesViews
{
    protected function viewName(string $name): string
    {
        return ResignationReportServiceProvider::SLUG.'::'.$name;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function access(): ResignationReportAccess
    {
        return app(ResignationReportAccess::class);
    }
}
