<?php

declare(strict_types=1);

namespace kintai\Tests\Support;

use kintai\Contract\Provider\PendingRequest;
use kintai\Contract\Provider\PendingRequestsProvider;

/**
 * Fournisseur de demandes en attente factice, aux valeurs de celui que déclare shift-claim (candidatures à la bourse) :
 * pour tester les pages du Core qui lisent les demandes des bundles (tableau de bord, page « Demandes »).
 */
final class FakeClaimsProvider implements PendingRequestsProvider
{
    /** @param list<PendingRequest> $requests */
    public function __construct(private readonly array $requests) {}

    /** Une candidature en attente : magasin, employé, jour et horaires du shift. */
    public static function claim(int $id, int $storeId, int $userId, string $date, string $start, string $end): PendingRequest
    {
        return new PendingRequest($id, $storeId, $userId, $date . ' 08:00:00', [
            ['label' => 'applicant', 'value' => $userId, 'style' => PendingRequest::USER],
            ['label' => 'store', 'value' => $storeId, 'style' => PendingRequest::STORE],
            ['label' => 'date', 'value' => $date],
            ['label' => 'start', 'value' => $start, 'style' => PendingRequest::TIME],
            ['label' => 'end', 'value' => $end, 'style' => PendingRequest::TIME],
        ]);
    }

    public function key(): string { return 'open_shifts'; }
    public function label(): string { return 'pending_claims'; }
    public function emptyLabel(): string { return 'no_pending_claims'; }
    public function permission(): string { return 'open_shifts.view'; }
    public function storeFeature(): ?string { return 'open_shifts'; }
    public function order(): int { return 30; }
    public function manageUrl(): string { return '/admin/open-shifts'; }

    public function pending(?array $storeIds): array
    {
        return array_values(array_filter(
            $this->requests,
            fn(PendingRequest $r): bool => $storeIds === null || in_array($r->storeId, $storeIds, true)
        ));
    }
}
