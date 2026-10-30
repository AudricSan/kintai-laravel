<?php
declare(strict_types=1);

namespace App\Models;

use App\Extension\DashboardLayout;
use Illuminate\Database\Eloquent\Model;

final class UserDashboardLayout extends Model
{
    protected $table = 'user_dashboard_layouts';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['is_active' => 'boolean'];

    /** Nombre maximal de mises en page nommées par utilisateur et par tableau de bord. */
    public const MAX_PER_DASHBOARD = 5;

    /** @return list<array{id: int, name: string, layout: array<string, mixed>, is_active: bool, position: int}> */
    public static function forUser(int $userId, string $dashboardType): array
    {
        $rows = static::where('user_id', $userId)->where('dashboard_type', $dashboardType)->orderBy('position')->orderBy('id')->get();

        return array_values(array_map(static fn(self $row): array => $row->summary(), $rows->all()));
    }

    /** Une mise en page par son identifiant, avec son propriétaire et son type de tableau de bord ; null si elle n'existe pas. */
    public static function describe(int $id): ?array
    {
        $row = static::find($id);

        return $row === null ? null : $row->summary() + ['user_id' => (int) $row->user_id, 'dashboard_type' => (string) $row->dashboard_type];
    }

    /** Crée une mise en page et en fait la seule active de ce tableau de bord. */
    public static function createActive(int $userId, string $dashboardType, string $name, array $layout): int
    {
        static::deactivateAll($userId, $dashboardType);
        $position = (int) static::where('user_id', $userId)->where('dashboard_type', $dashboardType)->max('position') + 1;
        $row = static::create([
            'user_id'        => $userId,
            'dashboard_type' => $dashboardType,
            'name'           => $name,
            'layout'         => json_encode(DashboardLayout::normalize($layout), JSON_THROW_ON_ERROR),
            'is_active'      => true,
            'position'       => $position,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        return (int) $row->id;
    }

    /** Rend cette mise en page active (les autres du même tableau de bord ne le sont plus). */
    public static function activate(int $id): void
    {
        $row = static::find($id);
        if ($row === null) {
            return;
        }
        static::deactivateAll((int) $row->user_id, (string) $row->dashboard_type);
        $row->update(['is_active' => true]);
    }

    /** Reporte une modification de la disposition en cours sur la mise en page active. */
    public static function updateActive(int $userId, string $dashboardType, array $layout): void
    {
        static::where('user_id', $userId)->where('dashboard_type', $dashboardType)->where('is_active', true)->update([
            'layout'     => json_encode(DashboardLayout::normalize($layout), JSON_THROW_ON_ERROR),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function deactivateAll(int $userId, string $dashboardType): void
    {
        static::where('user_id', $userId)->where('dashboard_type', $dashboardType)->update(['is_active' => false]);
    }

    /** @return array{id: int, name: string, layout: array<string, mixed>, is_active: bool, position: int} */
    private function summary(): array
    {
        $decoded = json_decode((string) $this->layout, true);

        return [
            'id'        => (int) $this->id,
            'name'      => (string) $this->name,
            'layout'    => DashboardLayout::normalize(is_array($decoded) ? $decoded : []),
            'is_active' => (bool) $this->is_active,
            'position'  => (int) $this->position,
        ];
    }}
