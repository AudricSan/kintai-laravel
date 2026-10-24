<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use kintai\Core\Services\StoreTimezone;

/**
 * Répare les fuseaux de magasin saisis à la main (« UTC+9 »…) et force la mise à jour des agendas déjà synchronisés.
 *
 * Le champ du formulaire de magasin était un texte libre. Une valeur qui n'est pas un identifiant IANA (« UTC+9 ») était
 * ignorée par Google Agenda, qui plaçait alors les événements en UTC : un shift de 06:00 à 15:00 s'affichait de 15:00 à minuit
 * à Tokyo. Ici, chaque fuseau est converti (UTC+9 → Asia/Tokyo) ; une valeur inconnaissable prend le fuseau de l'instance.
 *
 * Les événements déjà envoyés à Google gardent l'ancien contenu tant que rien ne les réécrit. L'empreinte enregistrée dans
 * calendar_event_links couvre le fuseau : une fois les magasins corrigés, la prochaine réconciliation voit la différence et
 * corrige chaque événement. On annule ici la date de la dernière réconciliation de chaque liaison pour que la prochaine
 * exécution de la tâche calendar-sync la fasse tout de suite, au lieu d'attendre jusqu'à 6 heures.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $db = $this->capsule->getConnection();

        if ($this->schema()->hasTable('stores') && $this->schema()->hasColumn('stores', 'timezone')) {
            foreach ($db->table('stores')->get(['id', 'timezone']) as $store) {
                $fixed = StoreTimezone::resolve((string) $store->timezone);
                if ($fixed !== (string) $store->timezone) {
                    $db->table('stores')->where('id', $store->id)->update(['timezone' => $fixed]);
                }
            }
        }

        if ($this->schema()->hasTable('calendar_connections')) {
            foreach ($db->table('calendar_connections')->get(['id', 'options']) as $connection) {
                $options = json_decode((string) $connection->options, true);
                $options = is_array($options) ? $options : [];
                $options['reconciled_at'] = 0;
                $db->table('calendar_connections')->where('id', $connection->id)->update(['options' => json_encode($options)]);
            }
        }
    }

    public function down(): void
    {
        // Correction de données : l'ancienne valeur invalide n'est pas restaurée.
    }
};
