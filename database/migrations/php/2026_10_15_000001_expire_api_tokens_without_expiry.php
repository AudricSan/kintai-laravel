<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * Avant le 15/10/2026, un jeton API délivré sans expires_in n'expirait jamais. Les nouveaux jetons expirent
 * désormais après 90 jours au plus (AuthController::TOKEN_TTL). Les jetons existants sans date d'expiration
 * reçoivent la même échéance, comptée à partir de cette migration : ils continuent de fonctionner, puis le
 * client devra se reconnecter.
 */
return new class($this->capsule) extends Migration {
    private const TTL = 90 * 86400;

    public function up(): void
    {
        $schema = $this->schema();
        if (!$schema->hasTable('api_tokens') || !$schema->hasColumn('api_tokens', 'expires_at')) {
            return;
        }

        $this->capsule->getConnection()->table('api_tokens')
            ->whereNull('expires_at')
            ->update(['expires_at' => date('Y-m-d H:i:s', time() + self::TTL)]);
    }

    public function down(): void
    {
        // Irréversible : on ne distingue plus les jetons qui n'avaient pas d'échéance.
    }
};
