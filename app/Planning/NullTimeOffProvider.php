<?php

namespace App\Planning;

use App\Contracts\Planning\TimeOffProvider;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Sans bundle de congés : aucun congé à publier.
 */
class NullTimeOffProvider implements TimeOffProvider
{
    public function approvedFor(User $user, Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        return [];
    }
}
