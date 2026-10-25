<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;
use kintai\Core\Database\Migration;

/**
 * Tables de la file d'attente de Laravel (QUEUE_CONNECTION=database, voir config/queue.php) : tâches en attente et
 * tâches en échec. Inutilisées avec la file par défaut (« deferred »), mais présentes pour qu'on puisse en changer
 * sans migration.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasTable('jobs')) {
            $this->schema()->create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (!$this->schema()->hasTable('failed_jobs')) {
            $this->schema()->create('failed_jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('failed_jobs');
        $this->schema()->dropIfExists('jobs');
    }
};
