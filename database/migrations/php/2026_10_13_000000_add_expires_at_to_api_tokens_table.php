<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * expires_at manquait de la migration initiale alors que Api\V1\AuthController::login() l'écrit et que
 * ApiAuthMiddleware le lit : sur une installation neuve, POST /api/v1/auth/login répondait 500
 * (« table api_tokens has no column named expires_at »). Les anciennes instances l'ont, héritée d'un ancien schéma.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasColumn('api_tokens', 'expires_at')) {
            return;
        }
        $this->schema()->table('api_tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        if (!$this->schema()->hasColumn('api_tokens', 'expires_at')) {
            return;
        }
        $this->schema()->table('api_tokens', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
