<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Request;

final class AuditLogger
{
    public function log(
        Request $request,
        string $action,
        string $resourceType,
        ?int $resourceId = null,
        array $details = [],
        ?int $storeId = null,
        ?int $userId = null,
    ): void {
        Log::record(
            $action,
            $resourceType,
            $resourceId,
            $action,
            $details,
            $userId,
            $storeId,
        );
    }

    /**
     * Ancien ou nouvel état nul = « rien » (ressource absente avant ou après) : 55 appelants passent le résultat d'un
     * findById()/getEnabledWidgets() qui peut renvoyer null (première sauvegarde des widgets, shift déjà supprimé), et
     * un journal ne doit jamais transformer une action réussie en erreur 500.
     */
    public function logUpdate(
        Request $request,
        string $action,
        string $resourceType,
        ?int $resourceId,
        ?array $oldData,
        ?array $newData,
        array $extraContext = [],
        ?int $storeId = null,
        ?int $userId = null,
    ): void {
        $changes = DiffHelper::diff($oldData ?? [], $newData ?? []);

        $context = $extraContext;
        if ($changes !== []) {
            $context['changes'] = $changes;
        }

        Log::record(
            $action,
            $resourceType,
            $resourceId,
            $action,
            $context,
            $userId,
            $storeId,
        );
    }
}
