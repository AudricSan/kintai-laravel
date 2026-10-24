<?php

declare(strict_types=1);

namespace kintai\Contract\Provider;

/**
 * Demande d'échange de shifts renvoyée par un SwapProvider (contrat 1.18) : qui la demande, à qui, dans quel magasin,
 * quand, et où elle en est.
 */
final class Swap
{
    /**
     * @param int    $id           Identifiant (unique pour le fournisseur)
     * @param int    $storeId      Magasin
     * @param int    $requesterId  Employé qui demande l'échange
     * @param int    $targetUserId Employé sollicité
     * @param string $createdAt    Date de la demande (Y-m-d H:i:s)
     * @param string $status       Statut (ex. pending, approved, rejected, cancelled)
     */
    public function __construct(
        public readonly int $id,
        public readonly int $storeId,
        public readonly int $requesterId,
        public readonly int $targetUserId,
        public readonly string $createdAt,
        public readonly string $status,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $createdAt) !== 1) {
            throw new \InvalidArgumentException("Swap #{$id} : date de demande invalide « {$createdAt} ».");
        }
    }

    /** Représentation sous forme de ligne, aux noms de colonnes historiques des demandes d'échange. */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'store_id'       => $this->storeId,
            'requester_id'   => $this->requesterId,
            'target_user_id' => $this->targetUserId,
            'created_at'     => $this->createdAt,
            'status'         => $this->status,
        ];
    }
}
