<?php

namespace Kintai\Contracts\Planning;

/**
 * Une demande de congé, vue par les statistiques (depuis 1.7).
 */
final readonly class TimeOffRequestSummary
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REFUSED = 'refused';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REFUSED, self::CANCELLED];

    public const TYPES = ['vacation', 'sick', 'personal', 'unpaid', 'other'];

    /**
     * @param  string  $startDate  AAAA-MM-JJ
     * @param  string  $endDate  AAAA-MM-JJ, inclus
     * @param  string  $status  `pending`, `approved`, `refused` ou `cancelled`.
     * @param  string  $type  `vacation`, `sick`, `personal`, `unpaid` ou `other`.
     */
    public function __construct(
        public int $userId,
        public string $startDate,
        public string $endDate,
        public string $status,
        public string $type,
    ) {}
}
