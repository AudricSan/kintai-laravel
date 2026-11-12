<?php

namespace Kintai\Bundles\ResignationReport;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Kintai\Bundles\ResignationReport\Models\ResignationReport;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Employees\AccountActivation;

/**
 * Désactivation du compte lié à un rapport de départ (démission ou licenciement : une seule date) à la date du rapport.
 * Le rapport n'est appliqué qu'une fois (`deactivated_at`) : un compte réactivé à la main le reste. Si la date est repoussée
 * dans le futur après l'application, le compte est réactivé. Les garde-fous (dernier Owner, soi-même) sont ceux du Core
 * (`AccountActivation`). Le journal ne porte que des identifiants.
 */
class ResignationDeactivation
{
    public function __construct(
        private readonly AccountActivation $accounts,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Aujourd'hui, dans le fuseau de l'instance.
     */
    public function today(): string
    {
        return Carbon::now((string) config('kintai.timezone', 'UTC'))->toDateString();
    }

    public function isDue(ResignationReport $report, ?string $today = null): bool
    {
        return $report->resignation_date === null || $report->resignation_date->toDateString() <= ($today ?? $this->today());
    }

    /**
     * Applique ou lève la désactivation selon la date. Enregistre le rapport quand `deactivated_at` change.
     *
     * Avec un acteur (saisie manuelle), un refus d'un garde-fou du Core remonte (`AuthorizationException`) et rien n'est
     * modifié. Sans acteur (tâche planifiée), le refus est ignoré et journalisé : le rapport reste à appliquer.
     *
     * @throws AuthorizationException
     */
    public function sync(ResignationReport $report, ?User $actor = null, ?string $today = null): void
    {
        if ($report->user_id === null) {
            return;
        }

        if ($this->isDue($report, $today)) {
            if ($report->deactivated_at === null) {
                $this->apply($report, $actor);
            }

            return;
        }

        if ($report->deactivated_at !== null) {
            $this->lift($report, $actor);
        }
    }

    /**
     * Applique les rapports échus, non encore appliqués. Renvoie le nombre de comptes désactivés et celui des refus.
     *
     * @return array{deactivated: int, skipped: int}
     */
    public function applyDue(?string $today = null): array
    {
        $today ??= $this->today();
        $result = ['deactivated' => 0, 'skipped' => 0];

        $reports = ResignationReport::query()
            ->whereNotNull('user_id')->whereNull('deactivated_at')
            ->where(fn ($query) => $query->whereNull('resignation_date')->orWhere('resignation_date', '<=', $today))
            ->orderBy('id')->get();

        foreach ($reports as $report) {
            $this->sync($report, null, $today);
            $report->deactivated_at === null ? $result['skipped']++ : $result['deactivated']++;
        }

        return $result;
    }

    /**
     * Réactive le compte de l'employé donné s'il est désactivé. Ne touche pas à `deactivated_at` : après une réactivation à la
     * main, le rapport reste appliqué et ne désactivera plus le compte.
     */
    public function reactivate(ResignationReport $report, ?int $userId, ?User $actor = null): void
    {
        if ($userId !== null && $this->accounts->reactivate($userId)) {
            $this->activity->record('resignation_report.reactivated', $report, $report->store_id, ['employee_id' => $userId], $actor);
        }
    }

    /**
     * Marque le rapport comme appliqué sans toucher au compte (compte réactivé à la main alors que la date est atteinte).
     */
    public function markApplied(ResignationReport $report): void
    {
        if ($report->deactivated_at === null && $this->isDue($report)) {
            $report->deactivated_at = Carbon::now();
            $report->save();
        }
    }

    private function apply(ResignationReport $report, ?User $actor): void
    {
        try {
            $changed = $this->accounts->deactivate((int) $report->user_id, $actor?->id);
        } catch (AuthorizationException $e) {
            if ($actor !== null) {
                throw $e;
            }

            $this->activity->record('resignation_report.deactivation_skipped', $report, $report->store_id, ['employee_id' => $report->user_id]);

            return;
        }

        $report->deactivated_at = Carbon::now();
        $report->save();

        if ($changed) {
            $this->activity->record('resignation_report.deactivated', $report, $report->store_id, ['employee_id' => $report->user_id], $actor);
        }
    }

    private function lift(ResignationReport $report, ?User $actor): void
    {
        $this->reactivate($report, $report->user_id, $actor);

        $report->deactivated_at = null;
        $report->save();
    }
}
