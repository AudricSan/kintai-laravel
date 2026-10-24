<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('color');
            $table->text('bio')->nullable()->after('avatar_path');
            $table->text('skills')->nullable()->after('bio');
            $table->text('languages_spoken')->nullable()->after('skills');
            $table->text('hobbies')->nullable()->after('languages_spoken');
            // Opt-out : un employé peut retirer son profil enrichi de l'annuaire
            // collègues (bundle TeamDirectory) sans supprimer les données elles-mêmes.
            $table->boolean('show_in_directory')->default(true)->after('hobbies');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', function (Blueprint $table) {
            $table->dropColumn([
                'avatar_path', 'bio', 'skills', 'languages_spoken',
                'hobbies', 'show_in_directory',
            ]);
        });
    }
};
