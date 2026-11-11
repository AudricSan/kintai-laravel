<?php

namespace Kintai\Contracts\Shifts;

use Carbon\CarbonImmutable;

/**
 * Une demande d'échange de shifts, vue par les statistiques (depuis 1.7).
 */
final readonly class ShiftSwapSummary
{
    public const PENDING = 'pending';

    /** Échange appliqué au planning. */
    public const ACCEPTED = 'accepted';

    public const REFUSED = 'refused';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING, self::ACCEPTED, self::REFUSED, self::CANCELLED];

    /**
     * @param  int  $requesterId  Le demandeur (identifiant).
     * @param  int  $targetUserId  Le collègue sollicité (identifiant).
     * @param  string  $status  `pending`, `accepted`, `refused` ou `cancelled`.
     */
    public function __construct(
        public int $requesterId,
        public int $targetUserId,
        public string $status,
        public CarbonImmutable $createdAt,
    ) {}
}
