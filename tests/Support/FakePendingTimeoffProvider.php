<?php

declare(strict_types=1);

namespace kintai\Tests\Support;

use kintai\Contract\Provider\PendingRequest;
use kintai\Contract\Provider\PendingRequestsProvider;

/**
 * Fournisseur de demandes en attente factice, aux valeurs de celui que déclare timeoff (congés) : pour tester les pages
 * du Core qui lisent les demandes des bundles (tableau de bord, page « Demandes »). Les lignes ont la forme de la table
 * timeoff_requests ; seules celles en attente des magasins demandés sont renvoyées.
 */
final class FakePendingTimeoffProvider implements PendingRequestsProvider
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows) {}

    public function key(): string { return 'timeoff'; }
    public function label(): string { return 'pending_timeoff'; }
    public function emptyLabel(): string { return 'no_pending_request'; }
    public function permission(): string { return 'timeoff.view'; }
    public function storeFeature(): ?string { return 'timeoff'; }
    public function order(): int { return 10; }
    public function manageUrl(): string { return '/admin/timeoff'; }

    public function pending(?array $storeIds): array
    {
        $requests = [];
        foreach ($this->rows as $r) {
            $storeId = (int) ($r['store_id'] ?? 0);
            if (($r['status'] ?? '') !== 'pending' || ($storeIds !== null && !in_array($storeId, $storeIds, true))) {
                continue;
            }
            $requests[] = new PendingRequest((int) $r['id'], $storeId, (int) ($r['user_id'] ?? 0), (string) ($r['created_at'] ?? ''), [
                ['label' => '#', 'value' => (int) $r['id']],
                ['label' => 'user', 'value' => (int) ($r['user_id'] ?? 0), 'style' => PendingRequest::USER],
                ['label' => 'type', 'value' => (string) ($r['type'] ?? '—'), 'style' => PendingRequest::BADGE],
                ['label' => 'from', 'value' => (string) ($r['start_date'] ?? '—')],
                ['label' => 'to', 'value' => (string) ($r['end_date'] ?? '—')],
            ]);
        }
        return $requests;
    }
}
