<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('roles')) {
            return;
        }
        $this->schema()->create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color')->nullable();
            $table->string('description')->nullable();
            $table->string('icon')->nullable();
            // Rôle système (Owner) : toutes les permissions implicitement, non
            // modifiable/non supprimable depuis l'interface d'administration.
            $table->integer('is_system')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('roles');
    }
};
