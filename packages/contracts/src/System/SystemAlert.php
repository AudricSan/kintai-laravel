<?php

namespace Kintai\Contracts\System;

/**
 * Une alerte système détectée : `key` désigne l'état du problème (un changement de clé = une nouvelle alerte, la
 * même clé n'est jamais renvoyée), `body` la clé de traduction du texte, `link` un chemin interne.
 */
final class SystemAlert
{
    /**
     * @param  string  $key  État du problème : version, ensemble de migrations, message d'erreur…
     * @param  string  $body  Clé de traduction du corps de la notification.
     * @param  array<string, scalar|null>  $params
     * @param  string|null  $link  Chemin interne (sans domaine) ouvert depuis la notification.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $body,
        public readonly array $params = [],
        public readonly ?string $link = null,
    ) {}
}
