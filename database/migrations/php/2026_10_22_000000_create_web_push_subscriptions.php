<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;
use kintai\Core\Database\Migration;
use kintai\Core\Repositories\DatabaseAppSettingsRepository;
use kintai\Core\Services\VapidKeyStore;

/**
 * Web push natif (VAPID) : abonnements des navigateurs, distincts de device_push_tokens qui ne sert plus qu'à FCM
 * pour l'application mobile (android / ios).
 *
 * - Les jetons « web » de device_push_tokens sont supprimés : ils passaient par un projet Firebase web que personne
 *   ne pouvait configurer correctement, donc n'ont jamais reçu de notification.
 * - La paire de clés VAPID de l'instance est créée ici (installation neuve comme instance existante). Si openssl ne
 *   sait pas la créer, la migration réussit quand même : le web push est alors indiqué indisponible.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasTable('web_push_subscriptions')) {
            $this->schema()->create('web_push_subscriptions', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id')->index();
                $table->text('endpoint');
                // Empreinte de l'adresse : un index unique sur un texte de cette longueur est refusé par MySQL.
                $table->char('endpoint_hash', 64)->unique();
                $table->string('p256dh', 255);
                $table->string('auth', 255);
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('last_used_at')->nullable();
            });
        }

        if ($this->schema()->hasTable('device_push_tokens')) {
            $this->capsule->getConnection()->table('device_push_tokens')->where('platform', 'web')->delete();
        }

        try {
            (new VapidKeyStore(new DatabaseAppSettingsRepository()))->ensure();
        } catch (\Throwable) {
            // Table app_settings absente ou openssl indisponible : le web push reste désactivé, rien d'autre ne dépend de ces clés.
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('web_push_subscriptions');
    }
};
