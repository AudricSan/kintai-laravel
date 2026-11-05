<?php

namespace Kintai\Contracts\System;

use Closure;

/**
 * Point d'extension : les détecteurs d'alertes système adressées aux Owners (mise à jour disponible, quota, service
 * extérieur en panne...). Un détecteur rend une `SystemAlert`, ou null quand tout va bien. Le Core n'envoie une alerte
 * que si son état change, et la marque lue quand le problème disparaît. Le type de notification du même nom doit être
 * déclaré dans `NotificationTypeRegistry` (catégorie `system`, `ownerOnly`).
 */
interface SystemAlertRegistry
{
    /**
     * @param  string  $type  Type de notification de l'alerte (unique).
     * @param  int  $referenceId  Identifiant stable du type (nature « system »), pour le « lu » automatique.
     * @param  Closure(): ?SystemAlert  $detect
     *
     * @throws \InvalidArgumentException Type déjà enregistré.
     */
    public function register(string $type, int $referenceId, Closure $detect): void;

    /**
     * @return list<string>
     */
    public function types(): array;
}
