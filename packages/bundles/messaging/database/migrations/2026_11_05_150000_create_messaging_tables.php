<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index('store_id');
        });

        Schema::create('thread_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('message_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_read')->default(false);
            $table->timestamp('last_read_at')->nullable();
            // Conversation retirée de la liste de ce participant (les autres la gardent).
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();

            $table->unique(['thread_id', 'user_id']);
            $table->index(['user_id', 'is_read']);
        });

        Schema::create('thread_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('message_threads')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            // Contenu effacé (anonymisation de l'expéditeur) : la conversation reste lisible pour les autres.
            $table->timestamp('erased_at')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['thread_id', 'sent_at']);
            $table->index('sender_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_messages');
        Schema::dropIfExists('thread_participants');
        Schema::dropIfExists('message_threads');
    }
};
