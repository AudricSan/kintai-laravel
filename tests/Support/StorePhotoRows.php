<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Lignes réelles du bundle store-photos (soumissions et images) pour ses tests, à la place du dépôt simulé. S'appuie sur
 * RealTable (table store_photo_submissions) et complète les colonnes obligatoires omises. Les semis s'ajoutent les uns
 * aux autres (une ligne de même identifiant en remplace une posée avant).
 */
trait StorePhotoRows
{
    use RealTable;

    private function realTable(): array
    {
        return ['store_photo_submissions', 'store-photos'];
    }

    /** Base neuve des deux tables. */
    private function resetPhotos(): void
    {
        $this->resetRows();
        DB::table('store_photo_images')->delete();
    }

    /** @param list<array<string, mixed>> $rows */
    private function seedSubmissions(array $rows): void
    {
        $this->seedRows($rows);
    }

    /** @param list<array<string, mixed>> $rows */
    private function seedImages(array $rows): void
    {
        foreach ($rows as $row) {
            if (isset($row['id'])) {
                DB::table('store_photo_images')->where('id', $row['id'])->delete();
            }
            DB::table('store_photo_images')->insert($this->completed('store_photo_images', $row));
        }
    }
}
