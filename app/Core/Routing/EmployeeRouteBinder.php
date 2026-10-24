<?php

declare(strict_types=1);

namespace kintai\Core\Routing;

use kintai\Core\Repositories\RouteSlugRepositoryInterface;

/**
 * Employé ↔ segment d'URL : son numéro d'employé (/admin/users/057/edit), jamais son nom (donnée personnelle
 * qui finirait dans les journaux du serveur et l'en-tête Referer). Sans numéro : « id-42 ».
 *
 * Ordre de résolution : id-N, numéro courant, numéro courant à la casse près, ancien numéro (historique),
 * identifiant numérique nu (ancien lien). Un segment qui est à la fois un numéro existant et un identifiant
 * désigne le numéro : seul un très vieux lien par identifiant peut alors viser un autre employé, et les
 * contrôles d'accès s'appliquent quand même.
 */
final class EmployeeRouteBinder implements RouteParamBinder
{
    private const ID_PREFIX = 'id-';

    /** @var array<int, string|null>|null */
    private ?array $codes = null;

    public function __construct(private readonly RouteSlugRepositoryInterface $slugs) {}

    /**
     * Un numéro d'employé utilisable tel quel dans une URL : lettres, chiffres, « - » et « _ » seulement (un « / »
     * encodé est refusé par Apache), et jamais « ID-… », réservé au repli des comptes sans numéro.
     */
    public static function isValidCode(string $code): bool
    {
        return preg_match('/^[A-Za-z0-9_-]+$/', $code) === 1 && stripos($code, self::ID_PREFIX) !== 0;
    }

    public function resolve(string $segment): ?BoundParam
    {
        $codes = $this->codes();

        if (preg_match('/^id-(\d+)$/i', $segment, $m) === 1) {
            $id = (int) $m[1];
            if (!array_key_exists($id, $codes)) {
                return null;
            }
            return new BoundParam($id, $codes[$id] === null && $segment === self::ID_PREFIX . $id);
        }

        $id = array_search($segment, $codes, true);
        if ($id !== false) {
            return new BoundParam((int) $id, true);
        }

        foreach ($codes as $userId => $code) {
            if ($code !== null && strcasecmp($code, $segment) === 0) {
                return new BoundParam((int) $userId, false);
            }
        }

        try {
            $row = $this->slugs->findBySlug(RouteSlugRepositoryInterface::TYPE_EMPLOYEE, $segment);
        } catch (\Illuminate\Database\QueryException) {
            $row = null; // Table route_slugs absente (migration pas encore jouée) : pas d'historique.
        }
        if ($row !== null && array_key_exists($row['entity_id'], $codes)) {
            return new BoundParam($row['entity_id'], false);
        }

        if (ctype_digit($segment) && array_key_exists((int) $segment, $codes)) {
            return new BoundParam((int) $segment, false);
        }

        return null;
    }

    public function segmentFor(int $id): string
    {
        return $this->codes()[$id] ?? self::ID_PREFIX . $id;
    }

    /** Oublie le cache (après un changement de numéro dans la même requête). */
    public function reset(): void
    {
        $this->codes = null;
    }

    /** @return array<int, string|null> */
    private function codes(): array
    {
        return $this->codes ??= $this->slugs->employeeCodes();
    }
}
