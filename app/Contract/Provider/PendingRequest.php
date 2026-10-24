<?php

declare(strict_types=1);

namespace kintai\Contract\Provider;

/**
 * Demande en attente de traitement, renvoyée par un PendingRequestsProvider (contrat 1.9).
 *
 * Le Core l'affiche comme une ligne de tableau (page « Demandes ») et la compte (tableau de bord, alertes). Chaque
 * colonne est décrite par son libellé (clé de traduction), sa valeur et son style d'affichage :
 * - TEXT  : texte brut ;
 * - BADGE : texte dans un badge « en attente » ;
 * - USER  : identifiant d'utilisateur, affiché avec son nom ;
 * - STORE : identifiant de magasin, affiché avec son nom ;
 * - TIME  : heure (HH:MM, les secondes sont retirées).
 */
final class PendingRequest
{
    public const TEXT = 'text';
    public const BADGE = 'badge';
    public const USER = 'user';
    public const STORE = 'store';
    public const TIME = 'time';

    public const STYLES = [self::TEXT, self::BADGE, self::USER, self::STORE, self::TIME];

    /**
     * @param int                                                          $id        Identifiant de la demande
     * @param int                                                          $storeId   Magasin concerné
     * @param int|null                                                     $userId    Demandeur
     * @param string                                                       $createdAt Date de dépôt (Y-m-d H:i:s)
     * @param list<array{label: string, value: string|int|null, style?: string}> $columns   Colonnes affichées, dans l'ordre
     * @param string|null                                                  $url       Page de traitement (défaut : celle du fournisseur)
     */
    public function __construct(
        public readonly int $id,
        public readonly int $storeId,
        public readonly ?int $userId,
        public readonly string $createdAt,
        public readonly array $columns = [],
        public readonly ?string $url = null,
    ) {
        foreach ($columns as $column) {
            if (!is_array($column) || !is_string($column['label'] ?? null)
                || !in_array($column['style'] ?? self::TEXT, self::STYLES, true)) {
                throw new \InvalidArgumentException("PendingRequest #{$id} : colonne invalide (label requis, style parmi " . implode(', ', self::STYLES) . ').');
            }
        }
    }
}
