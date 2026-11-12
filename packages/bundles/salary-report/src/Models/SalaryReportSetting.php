<?php

namespace Kintai\Bundles\SalaryReport\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Retenues d'un magasin (une ligne par magasin, créée à la première sauvegarde ; sans ligne, aucune retenue, voir
 * `forStore()`). Les taux sont des pourcentages du brut, la taxe résidentielle un montant mensuel fixe.
 *
 * @property int $store_id
 * @property bool $deductions_enabled
 * @property numeric-string $health_insurance_rate
 * @property numeric-string $pension_rate
 * @property numeric-string $employment_insurance_rate
 * @property numeric-string $income_tax_rate
 * @property numeric-string $resident_tax_monthly
 */
#[Fillable(['store_id', 'deductions_enabled', 'health_insurance_rate', 'pension_rate', 'employment_insurance_rate', 'income_tax_rate', 'resident_tax_monthly'])]
class SalaryReportSetting extends Model
{
    /** Taux en pourcentage, dans l'ordre d'affichage. */
    public const RATES = ['health_insurance_rate', 'pension_rate', 'employment_insurance_rate', 'income_tax_rate'];

    protected $table = 'salary_report_settings';

    protected $primaryKey = 'store_id';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deductions_enabled' => 'boolean',
            'health_insurance_rate' => 'decimal:2',
            'pension_rate' => 'decimal:2',
            'employment_insurance_rate' => 'decimal:2',
            'income_tax_rate' => 'decimal:2',
            'resident_tax_monthly' => 'decimal:2',
        ];
    }

    /**
     * Réglages du magasin, ou valeurs par défaut (retenues désactivées) non enregistrées s'il n'y a pas de ligne.
     */
    public static function forStore(int $storeId): self
    {
        return self::query()->find($storeId) ?? new self([
            'store_id' => $storeId, 'deductions_enabled' => false, 'health_insurance_rate' => 0, 'pension_rate' => 0,
            'employment_insurance_rate' => 0, 'income_tax_rate' => 0, 'resident_tax_monthly' => 0,
        ]);
    }
}
