<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface LogRepositoryInterface
{
    /** Niveaux de log standards */
    public const LEVEL_DEBUG    = 'debug';
    public const LEVEL_INFO     = 'info';
    public const LEVEL_WARNING  = 'warning';
    public const LEVEL_ERROR    = 'error';
    public const LEVEL_CRITICAL = 'critical';

    /** Canaux de log */
    public const CHANNEL_AUDIT    = 'audit';
    public const CHANNEL_ACCESS   = 'access';
    public const CHANNEL_BUSINESS = 'business';

    /** Insère une entrée de log simple. */
    public function log(string $level, string $channel, string $message, array $context = [], ?int $userId = null, ?int $storeId = null, ?string $ipAddress = null, ?string $userAgent = null): void;

    /** Insère une entrée enrichie (action, ressource, contexte requête). */
    public function record(string $level, string $channel, string $message, ?string $action = null, ?string $resourceType = null, ?int $resourceId = null, array $context = [], ?int $userId = null, ?int $storeId = null, ?string $ipAddress = null, ?string $userAgent = null, ?string $requestMethod = null, ?string $requestUri = null, ?int $responseStatus = null, ?int $durationMs = null, ?string $sessionId = null): void;

    /**
     * Retourne les logs paginés avec filtres optionnels.
     * Filtres reconnus : level, channel, action, resource_type, user_id,
     * store_id (int), store_ids (int[], portée multi-store — ex. manager
     * scopé à plusieurs stores via PermissionMiddleware), from, to, query.
     */
    public function findAll(int $page = 1, int $perPage = 50, array $filters = []): array;

    /** Retourne le nombre total de logs correspondant aux filtres (voir findAll()). */
    public function countAll(array $filters = []): int;

    /** Retourne un log par son ID. */
    public function findById(int $id): ?array;

    /** Retourne les canaux disponibles (distincts). */
    public function findChannels(): array;

    /** Retourne les actions distinctes (pour les filtres). */
    public function findActions(): array;

    /** Retourne les types de ressource distincts (pour les filtres). */
    public function findResourceTypes(): array;

    /** Supprime les entrées antérieures à la date donnée (Y-m-d H:i:s). Retourne le nombre de lignes supprimées. */
    public function purgeOlderThan(string $before): int;

    /** Supprime toutes les entrées du journal. Retourne le nombre de lignes supprimées. */
    public function purgeAll(): int;

    /**
     * Rend au disque l'espace des lignes supprimées (VACUUM en SQLite, OPTIMIZE TABLE en MySQL) : sans cela, le
     * fichier de base ne rétrécit pas. Sans effet si le moteur ne sait pas le faire.
     */
    public function reclaimSpace(): void;
}
