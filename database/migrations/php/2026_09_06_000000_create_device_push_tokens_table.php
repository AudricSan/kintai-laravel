<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('device_push_tokens')) {
            return;
        }
        $this->schema()->create('device_push_tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            // Jeton d'enregistrement FCM (couvre Android, iOS et web push via un seul
            // fournisseur) — peut migrer d'un utilisateur à l'autre sur un appareil
            // partagé (ex. tablette de magasin) : unique globalement, pas par utilisateur.
            $table->string('token', 255)->unique();
            $table->string('platform', 20)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_used_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('device_push_tokens');
    }
};
