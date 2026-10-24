<?php

declare(strict_types=1);

namespace kintai\Contract\Provider;

/**
 * Périmètre d'un export, déjà autorisé par le Core (contrat 1.30) : un fournisseur de lignes (ExportRowsProvider) n'a plus
 * à vérifier de droits, il lit seulement ce qui est demandé ici et rien d'autre.
 *
 * - storeIds : magasins que l'utilisateur peut exporter pour ce jeu de données (jamais vide).
 * - userIds  : personnes retenues, toutes membres de ces magasins ; null = tous les membres (export d'un magasin entier).
 *              Ignoré par les jeux de données qui ne portent pas sur des personnes (ExportDataset::$perPerson à false).
 * - from, to : période Y-m-d incluse, pour les jeux de données qui en ont une.
 * - locale   : langue des libellés que le fournisseur produirait lui-même (fr, en, ja).
 */
final class ExportQuery
{
    /**
     * @param list<int>      $storeIds
     * @param list<int>|null $userIds
     */
    public function __construct(
        public readonly array $storeIds,
        public readonly ?array $userIds,
        public readonly string $from,
        public readonly string $to,
        public readonly string $locale = 'fr',
    ) {}

    /** La personne est-elle retenue ? (true pour tout le monde quand userIds est null) */
    public function includesUser(int $userId): bool
    {
        return $this->userIds === null || in_array($userId, $this->userIds, true);
    }
}
