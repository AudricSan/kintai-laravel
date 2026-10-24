<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * La migration initiale a créé la colonne alias_name alors que tout le code applicatif
 * (DatabaseImportAliasRepository, ExcelShiftImportService, contrôleurs, vues JS) lit/écrit
 * staff_name — upsert() plantait avec "no such column: staff_name" sur une vraie base.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasColumn('import_aliases', 'alias_name') || $this->schema()->hasColumn('import_aliases', 'staff_name')) {
            return;
        }
        $this->schema()->table('import_aliases', function (Blueprint $table) {
            $table->renameColumn('alias_name', 'staff_name');
        });
    }

    public function down(): void
    {
        if (!$this->schema()->hasColumn('import_aliases', 'staff_name') || $this->schema()->hasColumn('import_aliases', 'alias_name')) {
            return;
        }
        $this->schema()->table('import_aliases', function (Blueprint $table) {
            $table->renameColumn('staff_name', 'alias_name');
        });
    }
};
