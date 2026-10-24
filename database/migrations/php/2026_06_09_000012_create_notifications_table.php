<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Schéma cible des notifications (refonte d'octobre 2026) : le texte est stocké sous forme de clé de traduction +
 * paramètres (body_key/params), traduit à l'affichage dans la langue courante du lecteur ; body n'est qu'un
 * instantané de repli (langue du destinataire au moment de l'envoi) pour une clé devenue introuvable (bundle
 * désactivé) ou les lignes antérieures à ce schéma. Les instances existantes sont alignées par
 * 2026_10_19_000000_split_notifications_data.php.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('notifications')) {
            return;
        }
        $this->schema()->create('notifications', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('type');
            $table->string('body_key')->nullable();
            $table->text('params')->nullable();
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->string('reference_type', 40)->nullable();
            $table->integer('reference_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'id']);
            $table->index(['user_id', 'reference_type', 'reference_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('notifications');
    }
};
