<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * La table employee_feedbacks a été créée pour l'ancien modèle "note + commentaire par date",
 * mais FeedbackController::submit() envoie depuis l'origine (Initial commit) category/message/anonymous
 * et ne renseigne jamais feedback_date : chaque soumission de feedback échouait avec
 * "no such column: category". De plus user_id était NOT NULL alors que le contrôleur le met à null
 * pour un feedback anonyme, ce qui aurait aussi fait échouer ce cas précis. Corrige le schéma pour
 * refléter les colonnes et contraintes réellement utilisées.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasColumn('employee_feedbacks', 'category')) {
            return;
        }

        // MySQL se sert de l'index composite pour la clé étrangère store_id et refuse de le supprimer (erreur 1553) :
        // un index simple sur store_id doit d'abord prendre le relais. SQLite n'en a pas besoin.
        if ($this->capsule->getConnection()->getDriverName() === 'mysql') {
            $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
                $table->index('store_id');
            });
        }
        $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'feedback_date']);
            $table->dropForeign(['user_id']);
        });
        $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
            $table->dropColumn(['rating', 'feedback_date', 'comment', 'user_id']);
        });
        $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
            $table->integer('user_id')->nullable()->after('id');
            $table->string('category', 30)->default('other')->after('shift_id');
            $table->integer('rating')->nullable()->after('category');
            $table->text('message')->nullable()->after('rating');
            $table->boolean('anonymous')->default(false)->after('message');
            $table->index(['store_id', 'created_at']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (!$this->schema()->hasColumn('employee_feedbacks', 'category')) {
            return;
        }

        $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'created_at']);
            $table->dropForeign(['user_id']);
            $table->dropColumn(['category', 'rating', 'message', 'anonymous', 'user_id']);
        });
        $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
            $table->integer('user_id');
            $table->string('feedback_date');
            $table->integer('rating');
            $table->text('comment')->nullable();
            $table->index(['store_id', 'feedback_date']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
