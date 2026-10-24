<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use kintai\Core\Services\UserDisplayName;

/**
 * Le formulaire employé enregistrait un nom affiché vide sans contrôle côté serveur : ces comptes apparaissaient sans
 * nom dans les listes et le planning. Leur nom affiché devient « Nom Prénom » (UserDisplayName, règle désormais
 * appliquée à chaque enregistrement). Un compte sans nom ni prénom n'est pas touché.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $conn = $this->capsule->getConnection();
        $rows = $conn->table('users')->select(['id', 'display_name', 'first_name', 'last_name'])->get();
        foreach ($rows as $row) {
            if (trim((string) $row->display_name) !== '') {
                continue;
            }
            $name = UserDisplayName::resolve(null, $row->first_name, $row->last_name);
            if ($name !== '') {
                $conn->table('users')->where('id', $row->id)->update(['display_name' => $name]);
            }
        }
    }

    public function down(): void
    {
        // Noms complétés : rien à défaire (un nom vide n'était pas un état voulu).
    }
};
