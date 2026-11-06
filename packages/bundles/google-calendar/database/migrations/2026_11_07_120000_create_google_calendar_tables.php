<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Une liaison par utilisateur. Le jeton de rafraîchissement est chiffré par le modèle, jamais en clair en base.
        Schema::create('google_calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('account_email')->nullable();
            $table->text('refresh_token');
            $table->string('calendar_id')->nullable();
            $table->string('calendar_name')->nullable();
            $table->string('status', 20)->default('active');
            // Nom de l'agenda : celui de l'organisation (`instance`) ou d'un magasin de l'utilisateur (`store`).
            $table->string('name_mode', 20)->default('instance');
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
        });

        // Quel événement Google correspond à quelle source (shift ou congé), avec l'empreinte du contenu envoyé.
        Schema::create('google_calendar_event_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('google_calendar_connections')->cascadeOnDelete();
            $table->string('source_type', 20);
            $table->string('source_key', 100);
            $table->string('external_event_id');
            $table->char('content_hash', 64);
            $table->timestamp('synced_at')->nullable();
            $table->unique(['connection_id', 'source_type', 'source_key']);
        });

        // Sources à réconcilier : une ligne par (liaison, source), l'opération se déduit de l'état réel au traitement.
        Schema::create('google_calendar_sync_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('google_calendar_connections')->cascadeOnDelete();
            $table->string('source_type', 20);
            $table->string('source_key', 100);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['connection_id', 'source_type', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_sync_queue');
        Schema::dropIfExists('google_calendar_event_links');
        Schema::dropIfExists('google_calendar_connections');
    }
};
