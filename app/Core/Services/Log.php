<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Container;
use kintai\Core\Repositories\LogRepositoryInterface;

final class Log
{
    private static ?LogRepositoryInterface $repo = null;
    private static ?Container $container = null;

    public static function setContainer(Container $c): void
    {
        self::$container = $c;
        self::$repo = null;
    }

    /** Réinitialise l'état statique (tests uniquement) : oublie le container et le repo mis en cache. */
    public static function reset(): void
    {
        self::$container = null;
        self::$repo = null;
    }

    private static function repo(): ?LogRepositoryInterface
    {
        if (self::$repo === null) {
            self::$repo = self::$container?->make(LogRepositoryInterface::class);
        }
        return self::$repo;
    }

    private static function resolveRequestContext(): array
    {
        return [
            'ip_address'      => self::resolveIp(),
            'user_agent'      => self::resolveUserAgent(),
            'request_method'  => $_SERVER['REQUEST_METHOD'] ?? null,
            'request_uri'     => $_SERVER['REQUEST_URI'] ?? null,
        ];
    }

    /**
     * Utilisateur à l'origine de l'entrée : seulement l'attribut « auth_user » de la requête, posé par
     * AuthMiddleware/ApiAuthMiddleware. Jamais $_REQUEST : « ?auth_user[id]=1 » dans une URL attribuait l'entrée
     * du journal à n'importe quel utilisateur.
     */
    private static function resolveUserId(): ?int
    {
        if (self::$container !== null) {
            try {
                $req = self::$container->make(\kintai\Core\Request::class);
                $user = $req->getAttribute('auth_user');
                if (is_array($user)) {
                    return isset($user['id']) ? (int) $user['id'] : null;
                }
            } catch (\Throwable) {
            }
        }
        return null;
    }

    private static function resolveStoreId(): ?int
    {
        if (self::$container !== null) {
            try {
                $req = self::$container->make(\kintai\Core\Request::class);
                $user = $req->getAttribute('auth_user');
                if (is_array($user) && isset($user['store_id'])) {
                    return (int) $user['store_id'];
                }
            } catch (\Throwable) {
            }
        }
        return null;
    }

    // ── Niveaux standards ──────────────────────────────────────────────────

    public static function debug(string $message, array $context = [], ?int $userId = null, ?int $storeId = null): void
    {
        self::write(LogRepositoryInterface::LEVEL_DEBUG, LogRepositoryInterface::CHANNEL_BUSINESS, $message, $context, $userId, $storeId);
    }

    public static function info(string $message, array $context = [], ?int $userId = null, ?int $storeId = null): void
    {
        self::write(LogRepositoryInterface::LEVEL_INFO, LogRepositoryInterface::CHANNEL_BUSINESS, $message, $context, $userId, $storeId);
    }

    public static function warning(string $message, array $context = [], ?int $userId = null, ?int $storeId = null): void
    {
        self::write(LogRepositoryInterface::LEVEL_WARNING, LogRepositoryInterface::CHANNEL_BUSINESS, $message, $context, $userId, $storeId);
    }

    public static function error(string $message, array $context = [], ?int $userId = null, ?int $storeId = null): void
    {
        self::write(LogRepositoryInterface::LEVEL_ERROR, LogRepositoryInterface::CHANNEL_BUSINESS, $message, $context, $userId, $storeId);
    }

    public static function critical(string $message, array $context = [], ?int $userId = null, ?int $storeId = null): void
    {
        self::write(LogRepositoryInterface::LEVEL_CRITICAL, LogRepositoryInterface::CHANNEL_BUSINESS, $message, $context, $userId, $storeId);
    }

    // ── Canaux spéciaux ────────────────────────────────────────────────────

    public static function audit(string $message, array $context = [], ?int $userId = null, ?int $storeId = null): void
    {
        self::write(LogRepositoryInterface::LEVEL_INFO, LogRepositoryInterface::CHANNEL_AUDIT, $message, $context, $userId, $storeId);
    }

    public static function access(string $message, array $context = [], ?int $userId = null, ?int $storeId = null): void
    {
        self::write(LogRepositoryInterface::LEVEL_INFO, LogRepositoryInterface::CHANNEL_ACCESS, $message, $context, $userId, $storeId);
    }

    public static function business(string $message, array $context = [], ?int $userId = null, ?int $storeId = null): void
    {
        self::write(LogRepositoryInterface::LEVEL_INFO, LogRepositoryInterface::CHANNEL_BUSINESS, $message, $context, $userId, $storeId);
    }

    /**
     * Log structuré d'action avec ressource.
     * Équivalent enrichi de audit() avec action, resource_type, resource_id explicites.
     */
    public static function record(string $action, string $resourceType, ?int $resourceId = null, string $message = '', array $context = [], ?int $userId = null, ?int $storeId = null): void
    {
        $repo = self::repo();
        if ($repo === null) {
            return;
        }

        $rc = self::resolveRequestContext();

        $repo->record(
            LogRepositoryInterface::LEVEL_INFO,
            LogRepositoryInterface::CHANNEL_AUDIT,
            $message ?: $action,
            $action,
            $resourceType,
            $resourceId,
            $context,
            $userId ?? self::resolveUserId(),
            $storeId ?? self::resolveStoreId(),
            $rc['ip_address'],
            $rc['user_agent'],
            $rc['request_method'],
            $rc['request_uri'],
            null,
            null,
            session_id() ?: null,
        );
    }

    /**
     * Méthode générique : écrit un log avec niveau et canal.
     */
    public static function write(string $level, string $channel, string $message, array $context = [], ?int $userId = null, ?int $storeId = null, ?int $responseStatus = null, ?int $durationMs = null): void
    {
        $repo = self::repo();
        if ($repo === null) {
            return;
        }

        $rc = self::resolveRequestContext();

        $repo->record(
            $level,
            $channel,
            $message,
            null,
            null,
            null,
            $context,
            $userId ?? self::resolveUserId(),
            $storeId ?? self::resolveStoreId(),
            $rc['ip_address'],
            $rc['user_agent'],
            $rc['request_method'],
            $rc['request_uri'],
            $responseStatus,
            $durationMs,
            session_id() ?: null,
        );
    }

    /**
     * Adresse réelle de la connexion. X-Forwarded-For et Client-IP sont envoyés par le client lui-même : les lire en
     * premier laissait n'importe qui inscrire l'IP de son choix dans le journal. Même source que la limitation des
     * connexions (Request::ip()).
     */
    private static function resolveIp(): ?string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $ip !== '' ? $ip : null;
    }

    private static function resolveUserAgent(): ?string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }
}
