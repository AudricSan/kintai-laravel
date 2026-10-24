<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Nettoyage issu de l'audit de septembre 2026 (task/mermission.md) : colonnes
 * jamais lues ni écrites par le code applicatif, remplacées en cours de route
 * par d'autres colonnes/tables sans que l'ancienne ait été retirée.
 *
 * - users.profile_image : superseded par avatar_path (2026_09_17_000000),
 *   jamais utilisé depuis.
 * - users.email_verified_at : jamais implémenté (pas de flux de vérification
 *   d'email dans l'app).
 * - users.furigana / hiring_reports.furigana : champ combiné remplacé par
 *   furigana_last_name/furigana_first_name (2026_06_09_000039).
 * - store_user.hourly_rates / deduction_overrides : jamais adoptés,
 *   remplacés par la table normalisée user_shift_type_rates et le booléen
 *   subject_to_deductions.
 * - stores.deduction_settings / excel_import_settings / break_settings /
 *   opening_hours : même anti-pattern déjà corrigé pour stores.features en
 *   juillet 2026 (colonnes JSON dupliquant des tables normalisées dédiées :
 *   store_deduction_settings, store_import_settings).
 * - daily_reports.is_finalized / finalized_by / finalized_at : ancien
 *   workflow de validation remplacé par status + validated_by/validated_at.
 * - daily_reports.pdf_path / pdf_generated_at : le PDF est généré à la volée
 *   en mémoire (DailyReportPdfService), jamais mis en cache sur disque.
 * - roles.icon : jamais exposé dans le formulaire de rôle ni ailleurs.
 * - shifts.wage_breakdown : détail par tranche du salaire estimé, mis en
 *   cache uniquement à l'import Excel, jamais relu (les calculs de paie
 *   recalculent ce détail à la volée via ShiftWageCalculator::costOf()).
 */
return new class($this->capsule) extends Migration {
    /** @var array<string, string[]> */
    private const DEAD_COLUMNS = [
        'users' => ['profile_image', 'email_verified_at', 'furigana'],
        'hiring_reports' => ['furigana'],
        'store_user' => ['hourly_rates', 'deduction_overrides'],
        'stores' => ['deduction_settings', 'excel_import_settings', 'break_settings', 'opening_hours'],
        'daily_reports' => ['is_finalized', 'finalized_by', 'finalized_at', 'pdf_path', 'pdf_generated_at'],
        'roles' => ['icon'],
        'shifts' => ['wage_breakdown'],
    ];

    public function up(): void
    {
        foreach (self::DEAD_COLUMNS as $tableName => $columns) {
            $toDrop = array_filter($columns, fn (string $c) => $this->schema()->hasColumn($tableName, $c));
            if ($toDrop === []) {
                continue;
            }
            $this->schema()->table($tableName, function (Blueprint $table) use ($toDrop) {
                $table->dropColumn(array_values($toDrop));
            });
        }
    }

    public function down(): void
    {
        // Colonnes mortes retirées après audit complet : rien à recréer,
        // plus aucun code ne les lit ni ne les écrit.
    }
};
