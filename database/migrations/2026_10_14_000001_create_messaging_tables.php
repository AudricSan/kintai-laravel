<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables de la messagerie, créées si absentes (installation neuve), avec le schéma qu'utilise le code du bundle.
 *
 * Le Core les créait jusqu'ici (migration 2026_06_09_000011, devenue vide à l'extraction) avec un schéma qui ne
 * correspondait pas au code : thread_messages.created_at au lieu de sent_at, si bien que l'envoi d'un message échouait
 * sur une installation neuve. Une table déjà présente n'est pas touchée ici : la migration suivante
 * (2026_10_14_000002) aligne les tables existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('message_threads')) {
            Schema::create('message_threads', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('store_id');
                $table->integer('creator_id');
                $table->string('subject')->nullable();
                $table->string('type')->default('direct');
                $table->timestamp('last_message_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();

                $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
                $table->foreign('creator_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('thread_participants')) {
            Schema::create('thread_participants', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('thread_id');
                $table->integer('user_id');
                $table->integer('is_read')->default(0);
                $table->timestamp('last_read_at')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['thread_id', 'user_id']);
                $table->foreign('thread_id')->references('id')->on('message_threads')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('thread_messages')) {
            Schema::create('thread_messages', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('thread_id');
                $table->integer('sender_id');
                $table->text('body');
                // Horodatage d'envoi : lu et écrit par le web, l'API et le flux en direct.
                $table->timestamp('sent_at')->useCurrent();

                $table->index('thread_id');
                $table->foreign('thread_id')->references('id')->on('message_threads')->onDelete('cascade');
                $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        // Pas de suppression : les tables existaient avant cette migration sur les instances mises à jour, et un retour
        // arrière ne doit pas effacer les conversations.
    }
};
