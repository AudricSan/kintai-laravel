<?php

declare(strict_types=1);

namespace kintai\Core\Auth;

use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Services\CalendarSyncService;

/**
 * Suppression d'un compte = anonymisation, jamais effacement de la ligne.
 *
 * Avant l'audit du 13/10/2026 (M8), la suppression par un admin effaçait la ligne `users` : sous MySQL les
 * clés étrangères supprimaient en cascade shifts, pointages, congés, messages… (historique de paie perdu),
 * sous SQLite tout restait orphelin. Les deux pilotes donnent maintenant le même résultat, celui qu'avait
 * déjà la suppression de son propre compte : données personnelles effacées, compte inactif et marqué
 * supprimé (deleted_at), droits et jetons retirés ; l'historique reste rattaché à « Utilisateur supprimé ».
 */
final class UserAnonymizer
{
    public const DISPLAY_NAME = 'Utilisateur supprimé';

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly RoleAssignmentRepositoryInterface $roleAssignments,
        private readonly CredentialRevoker $revoker,
        private readonly ?string $avatarDir = null,
        private readonly ?CalendarSyncService $calendar = null,
    ) {}

    public function anonymize(int $userId): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return;
        }

        // La photo de profil est un fichier sur disque : elle survivrait sinon à l'anonymisation.
        if (!empty($user['avatar_path'])) {
            @unlink($this->avatarDir() . '/' . basename((string) $user['avatar_path']));
        }

        $now = date('Y-m-d H:i:s');
        $this->users->save([
            'id'                  => $userId,
            'first_name'          => '[Supprimé]',
            'last_name'           => '',
            'display_name'        => self::DISPLAY_NAME,
            'email'               => self::email($userId),
            'employee_code'       => null,
            'password_hash'       => '',
            'phone'               => null,
            'mobile_phone'        => null,
            'furigana_last_name'  => null,
            'furigana_first_name' => null,
            'gender'              => null,
            'tax_classification'  => null,
            'birth_date'          => null,
            'education'           => null,
            'postal_code'         => null,
            'address'             => null,
            'guarantor_name'      => null,
            'guarantor_phone'     => null,
            'avatar_path'         => null,
            'bio'                 => null,
            'skills'              => null,
            'languages_spoken'    => null,
            'hobbies'             => null,
            'show_in_directory'   => 0,
            'share_email'         => 0,
            'share_phone'         => 0,
            'share_mobile_phone'  => 0,
            'is_active'           => 0,
            'deleted_at'          => $user['deleted_at'] ?? $now,
            'updated_at'          => $now,
        ]);

        // Un compte supprimé ne garde aucun rôle (sinon un Owner « supprimé » compterait encore comme Owner).
        foreach ($this->roleAssignments->findByUser($userId) as $assignment) {
            $this->roleAssignments->revoke((int) $assignment['id']);
        }

        // Cookies « rester connecté » et jetons d'API ; les sessions ouvertes sont refusées (compte inactif).
        $this->revoker->revokeAllFor($userId);

        // Liaison d'agenda externe : les événements ne doivent plus suivre un compte supprimé, et le jeton est révoqué chez Google.
        try {
            $this->calendar?->disconnect($userId, true);
        } catch (\Throwable) {
            // Réseau coupé ou table absente : la suppression du compte prime.
        }
    }

    /** Adresse de remplacement, unique par compte (la colonne email est unique). */
    public static function email(int $userId): string
    {
        return 'deleted-' . $userId . '@kintai.local';
    }

    private function avatarDir(): string
    {
        return $this->avatarDir ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3)) . '/storage/uploads/avatars';
    }
}
