<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * expires_at manquait de la migration initiale alors que DatabasePasswordResetRepository::create()
 * et deleteExpired() en dépendent — la création d'un token de réinitialisation plantait
 * avec "no such column: expires_at" sur une vraie base.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasColumn('password_reset_tokens', 'expires_at')) {
            return;
        }
        $this->schema()->table('password_reset_tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('token');
        });
    }

    public function down(): void
    {
        if (!$this->schema()->hasColumn('password_reset_tokens', 'expires_at')) {
            return;
        }
        $this->schema()->table('password_reset_tokens', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
