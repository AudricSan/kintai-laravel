<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\After;

/**
 * Outils des tests de contrôleurs et de services qui lisaient et écrivaient par un dépôt simulé : la table réelle du
 * modèle (SQLite en mémoire, migrations du Cœur et du bundle) la remplace. La classe de test déclare
 * `realTable(): array{0: string, 1: ?string}` (nom de la table, slug du bundle qui la crée ou null).
 *
 *  - seedRows()          : la table ne contient que ces lignes ; les colonnes obligatoires omises reçoivent une valeur neutre ;
 *  - nextRowId()         : la prochaine ligne créée recevra cet identifiant ;
 *  - expectNoChange()    : la table doit être identique en fin de test ;
 *  - expectSaved()       : une ligne créée ou modifiée pendant le test doit satisfaire le critère ;
 *  - expectDeleted()     : la ligne doit avoir disparu en fin de test ;
 *  - startCapture() puis capturedRow() : la ligne créée ou modifiée entre les deux.
 */
trait RealTable
{
    /** @var list<array<string, mixed>>|null */
    private ?array $rtUnchanged = null;
    private ?\Closure $rtSavedMatcher = null;
    /** @var list<array<string, mixed>> */
    private array $rtSavedBefore = [];
    private ?int $rtDeletedId = null;

    /** @return array{0: string, 1: ?string} */
    abstract private function realTable(): array;

    /** @return list<array<string, mixed>> */
    private function rowsInTable(): array
    {
        return DB::table($this->realTable()[0])->orderBy('id')->get()->map(fn($r) => (array) $r)->all();
    }

    private function resetRows(): void
    {
        [$table, $bundle] = $this->realTable();
        kintai_test_table($table, bundle: $bundle);
    }

    /** @param list<array<string, mixed>> $rows */
    private function seedRows(array $rows): void
    {
        [$table, $bundle] = $this->realTable();
        kintai_test_table($table, bundle: $bundle);
        foreach ($rows as $row) {
            DB::table($table)->insert($this->completed($table, $row));
        }
    }

    /** La prochaine ligne créée recevra cet identifiant (une ligne temporaire fait avancer le compteur, puis disparaît). */
    private function nextRowId(int $id): void
    {
        $table = $this->realTable()[0];
        DB::table($table)->insert($this->completed($table, ['id' => $id - 1]));
        DB::table($table)->where('id', $id - 1)->delete();
    }

    private function expectNoChange(): void
    {
        $this->rtUnchanged = $this->rowsInTable();
    }

    private function expectSaved(callable $matcher): void
    {
        $this->rtSavedBefore  = $this->rowsInTable();
        $this->rtSavedMatcher = \Closure::fromCallable($matcher);
    }

    /** @var list<array<string, mixed>> */
    private array $rtCaptureBefore = [];

    /** Mémorise l'état de la table : capturedRow() rendra la ligne créée ou modifiée ensuite. */
    private function startCapture(): void
    {
        $this->rtCaptureBefore = $this->rowsInTable();
    }

    /** @return array<string, mixed>|null première ligne créée ou modifiée depuis startCapture() */
    private function capturedRow(): ?array
    {
        foreach ($this->rowsInTable() as $row) {
            if (!in_array($row, $this->rtCaptureBefore, false)) {
                return $row;
            }
        }

        return null;
    }

    private function expectDeleted(int $id): void
    {
        $this->rtDeletedId = $id;
    }

    /**
     * La ligne donnée, complétée par une valeur neutre pour chaque colonne obligatoire sans défaut, et limitée aux
     * colonnes qui existent.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function completed(string $table, array $row): array
    {
        $columns = Schema::getColumns($table);
        foreach ($columns as $column) {
            $name = $column['name'];
            if (array_key_exists($name, $row) || $column['nullable'] || $column['default'] !== null || $column['auto_increment']) {
                continue;
            }
            $type = strtolower((string) $column['type_name']);
            $row[$name] = match (true) {
                str_contains($type, 'int') || str_contains($type, 'bool') => 1,
                str_contains($type, 'datetime') || str_contains($type, 'timestamp') => '2026-01-01 00:00:00',
                str_contains($type, 'date') => '2026-01-01',
                str_contains($type, 'time') => '00:00:00',
                default => 'x',
            };
        }

        return array_intersect_key($row, array_flip(array_column($columns, 'name')));
    }

    #[After]
    public function verifyRealTable(): void
    {
        if ($this->rtUnchanged !== null) {
            $this->assertEquals($this->rtUnchanged, $this->rowsInTable(), 'aucune ligne ne devait être créée, modifiée ni supprimée');
        }
        if ($this->rtSavedMatcher !== null) {
            $changed = array_filter($this->rowsInTable(), fn(array $row) => !in_array($row, $this->rtSavedBefore, false));
            $this->assertNotEmpty($changed, 'une ligne devait être enregistrée');
            $this->assertNotEmpty(array_filter($changed, $this->rtSavedMatcher), 'la ligne enregistrée ne correspond pas au critère');
        }
        if ($this->rtDeletedId !== null) {
            $this->assertNull(DB::table($this->realTable()[0])->where('id', $this->rtDeletedId)->first(), 'la ligne devait être supprimée');
        }
    }
}
