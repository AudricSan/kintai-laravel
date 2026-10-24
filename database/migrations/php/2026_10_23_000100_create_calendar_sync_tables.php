<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;
use kintai\Core\Database\Migration;

/**
 * Synchronisation des shifts vers un agenda externe (Google Calendar, sens Kintai → agenda uniquement).
 *
 * - calendar_connections : une liaison par utilisateur et par fournisseur ; le jeton de rafraîchissement n'est jamais
 *   stocké en clair (voir SecretBox).
 * - calendar_event_links : quel événement externe correspond à quel shift, avec l'empreinte du contenu envoyé, pour
 *   modifier ou supprimer le bon événement et ne rien renvoyer quand rien n'a changé.
 * - calendar_sync_queue : sources à réconcilier ; une seule ligne par (liaison, source), l'opération est déduite de
 *   l'état réel du shift au moment du traitement.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasTable('calendar_connections')) {
            $this->schema()->create('calendar_connections', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->string('provider', 20)->default('google');
                $table->string('account_email', 255)->nullable();
                $table->text('refresh_token');
                $table->string('calendar_id', 255)->nullable();
                $table->string('calendar_name', 255)->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamp('last_sync_at')->nullable();
                $table->text('last_error')->nullable();
                $table->text('options')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['user_id', 'provider']);
            });
        }

        if (!$this->schema()->hasTable('calendar_event_links')) {
            $this->schema()->create('calendar_event_links', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('connection_id')->index();
                $table->string('source_type', 20);
                $table->unsignedInteger('source_id');
                $table->string('external_event_id', 255);
                $table->char('content_hash', 64);
                $table->timestamp('synced_at')->nullable();
                $table->unique(['connection_id', 'source_type', 'source_id']);
            });
        }

        if (!$this->schema()->hasTable('calendar_sync_queue')) {
            $this->schema()->create('calendar_sync_queue', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('connection_id')->index();
                $table->string('source_type', 20);
                $table->unsignedInteger('source_id');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable()->index();
                $table->text('last_error')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['connection_id', 'source_type', 'source_id']);
            });
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('calendar_sync_queue');
        $this->schema()->dropIfExists('calendar_event_links');
        $this->schema()->dropIfExists('calendar_connections');
    }
};
