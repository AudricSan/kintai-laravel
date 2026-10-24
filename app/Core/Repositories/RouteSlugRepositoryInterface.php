<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

/**
 * Alias d'URL des entités adressables par un nom lisible (voir kintai\Core\Routing).
 *
 * - type « store » : une ligne courante par magasin (son slug actuel) et des lignes d'historique
 *   (anciens slugs, redirigés en 301 vers le courant).
 * - type « employee » : historique seulement. Le segment courant d'un employé est lu directement
 *   dans users.employee_code ; seuls les anciens numéros sont conservés ici.
 */
interface RouteSlugRepositoryInterface
{
    public const TYPE_STORE    = 'store';
    public const TYPE_EMPLOYEE = 'employee';

    /** @return array<int, string> identifiant de l'entité → slug courant */
    public function currentSlugs(string $type): array;

    /** @return array{entity_id: int, slug: string, is_current: bool, is_manual: bool}|null */
    public function current(string $type, int $entityId): ?array;

    /** @return array{entity_id: int, slug: string, is_current: bool, is_manual: bool}|null */
    public function findBySlug(string $type, string $slug): ?array;

    /** Le slug est-il déjà porté (courant ou historique) par une autre entité que $exceptEntityId ? */
    public function isTakenByOther(string $type, string $slug, int $exceptEntityId): bool;

    /**
     * Fait de $slug l'alias courant de l'entité ; l'ancien alias courant passe en historique.
     * Une ligne d'une autre entité portant le même slug est supprimée : l'appelant a vérifié que c'était permis.
     */
    public function setCurrent(string $type, int $entityId, string $slug, bool $manual): void;

    /** Conserve $slug comme ancien alias de l'entité (redirection 301), sauf s'il est déjà connu. */
    public function addHistory(string $type, int $entityId, string $slug): void;

    /** Supprime toute ligne d'une autre entité portant ce slug (il vient d'être attribué ailleurs). */
    public function releaseFromOthers(string $type, string $slug, int $entityId): void;

    /** Supprime tous les alias d'une entité (SQLite n'applique pas les cascades ici). */
    public function deleteForEntity(string $type, int $entityId): void;

    /** @return array<int, string|null> identifiant utilisateur → numéro d'employé (null si absent) */
    public function employeeCodes(): array;
}
