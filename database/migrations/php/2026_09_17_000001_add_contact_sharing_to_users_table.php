<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->table('users', function (Blueprint $table) {
            // Opt-in explicite et par champ : contrairement à show_in_directory
            // (qui contrôle l'apparition dans l'annuaire), ces colonnes contrôlent
            // individuellement quelles coordonnées sont visibles des collègues une
            // fois dans l'annuaire. Défaut à false : rien n'est partagé tant que
            // l'employé ne l'a pas explicitement choisi sur /profile.
            $table->boolean('share_email')->default(false)->after('show_in_directory');
            $table->boolean('share_phone')->default(false)->after('share_email');
            $table->boolean('share_mobile_phone')->default(false)->after('share_phone');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', function (Blueprint $table) {
            $table->dropColumn(['share_email', 'share_phone', 'share_mobile_phone']);
        });
    }
};
