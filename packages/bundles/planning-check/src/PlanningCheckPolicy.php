<?php

namespace Kintai\Bundles\PlanningCheck;

use App\Models\Shift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheckSetting;

/**
 * Règles de temps de la validation du planning, toutes évaluées dans le fuseau de l'instance (`kintai.timezone`) : quel
 * jour est visé (le lendemain), à partir de quand la validation est attendue, quand elle est en retard, et si ce jour fait
 * partie des jours concernés. Fonctions pures, sans accès aux données.
 */
class PlanningCheckPolicy
{
    /**
     * L'instant donné (ou maintenant) dans le fuseau de l'instance.
     */
    public static function local(?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now())->copy()->setTimezone((string) config('kintai.timezone', 'UTC'));
    }

    /**
     * Le lendemain (Y-m-d).
     */
    public static function targetDate(?Carbon $now = null): string
    {
        return self::local($now)->addDay()->toDateString();
    }

    /**
     * La validation est attendue : l'heure locale a atteint le début de la fenêtre.
     */
    public static function windowOpen(PlanningCheckSetting $setting, ?Carbon $now = null): bool
    {
        return self::local($now)->format('H:i') >= $setting->window_start_time;
    }

    /**
     * L'heure limite est passée.
     */
    public static function isLate(PlanningCheckSetting $setting, ?Carbon $now = null): bool
    {
        return self::local($now)->format('H:i') >= $setting->deadline_time;
    }

    /**
     * Le magasin travaille ce jour-là (jour de la semaine coché dans les réglages).
     */
    public static function isWorkingDay(PlanningCheckSetting $setting, string $date): bool
    {
        return in_array((int) Carbon::parse($date)->format('N'), $setting->days(), true);
    }

    /**
     * La validation est attendue maintenant pour ce jour : activée, jour travaillé, fenêtre ouverte.
     */
    public static function isDue(PlanningCheckSetting $setting, string $date, ?Carbon $now = null): bool
    {
        return $setting->enabled && self::isWorkingDay($setting, $date) && self::windowOpen($setting, $now);
    }

    /**
     * Empreinte des shifts d'un jour : change dès qu'un shift est ajouté, supprimé ou modifié.
     *
     * @param  Collection<int, Shift>  $shifts
     */
    public static function shiftsHash(Collection $shifts): string
    {
        $rows = $shifts->map(fn (Shift $shift): string => implode('|', [
            $shift->id, (int) $shift->user_id, (int) $shift->shift_type_id,
            $shift->starts_at->getTimestamp(), $shift->ends_at->getTimestamp(), $shift->pause_minutes,
        ]))->sort()->values()->all();

        return hash('sha256', implode("\n", $rows));
    }
}
