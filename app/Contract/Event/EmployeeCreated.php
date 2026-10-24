<?php

declare(strict_types=1);

namespace kintai\Contract\Event;

/**
 * Événement « employé créé » (contrat 1.13), annoncé par le Core aux bundles qui l'écoutent (`Bundle::eventListeners()`)
 * juste après la création d'un compte : formulaire « Nouvel employé », création rapide de l'import Excel et
 * `POST /api/v1/users`.
 *
 * Par exemple, hiring-report y crée le rapport d'embauche de l'employé.
 */
final class EmployeeCreated
{
    /** Colonnes de users jamais transmises aux bundles. */
    private const SECRET_COLUMNS = ['password_hash' => true];

    /** @var array<string, mixed> Ligne de la table users telle qu'enregistrée, sans secrets */
    public readonly array $user;

    /**
     * @param array<string, mixed> $user      Ligne de la table users telle qu'enregistrée (les secrets en sont retirés)
     * @param int|null             $storeId   Magasin auquel l'employé a été affecté à la création, null sinon
     * @param int|null             $createdBy Utilisateur qui a créé le compte (null si inconnu)
     * @param string               $source    Origine : « form », « excel_import » ou « api »
     */
    public function __construct(
        array $user,
        public readonly ?int $storeId,
        public readonly ?int $createdBy,
        public readonly string $source,
    ) {
        $this->user = array_diff_key($user, self::SECRET_COLUMNS);
    }

    /** Identifiant de l'employé créé. */
    public function userId(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }
}
