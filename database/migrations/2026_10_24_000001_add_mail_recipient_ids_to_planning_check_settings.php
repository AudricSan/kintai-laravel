<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

/**
 * Destinataires choisis parmi les employés du magasin : planning_check_settings.mail_recipient_ids, liste JSON
 * d'identifiants de compte (l'adresse est relue à l'envoi, donc à jour). La colonne mail_recipients, qui garde les
 * adresses saisies auparavant, reste utilisée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('planning_check_settings') && !Schema::hasColumn('planning_check_settings', 'mail_recipient_ids')) {
            Schema::table('planning_check_settings', function (Blueprint $table) {
                $table->text('mail_recipient_ids')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('planning_check_settings', 'mail_recipient_ids')) {
            Schema::table('planning_check_settings', function (Blueprint $table) {
                $table->dropColumn('mail_recipient_ids');
            });
        }
    }
};
