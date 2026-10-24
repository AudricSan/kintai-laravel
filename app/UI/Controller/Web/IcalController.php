<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Core\Auth\AuthService;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\IcalTokenRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Extension\Absences;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\IcalService;
use kintai\UI\Controller\Web\HasBaseUrl;

final class IcalController
{
    use HasBaseUrl;
    public function __construct(
        private readonly IcalService                        $ical,
        private readonly IcalTokenRepositoryInterface       $icalTokens,
        private readonly ShiftRepositoryInterface           $shifts,
        private readonly ShiftTypeRepositoryInterface       $shiftTypes,
        private readonly StoreRepositoryInterface           $stores,
        private readonly UserRepositoryInterface            $users,
        private readonly AuthService                        $auth,
        private readonly AuditLogger                        $auditLogger,
        private readonly StoreUserRepositoryInterface       $storeUsers,
    ) {}

    /**
     * GET /ical/{token}/{store_id}/shifts.ics
     * Route publique protégée par token.
     */
    public function feed(Request $request): Response
    {
        $token   = $request->param('token') ?? '';
        $storeId = (int) ($request->param('store_id') ?? 0);

        $tokenRow = $this->icalTokens->findByToken($token);
        if (!$tokenRow || (int) $tokenRow['store_id'] !== $storeId) {
            throw new NotFoundException(__('error_ical_feed_not_found'));
        }

        $userId = (int) $tokenRow['user_id'];
        $store  = $this->stores->findById($storeId);
        $user   = $this->users->findById($userId);

        if (!$store || !$user) {
            throw new NotFoundException(__('error_resource_not_found'));
        }

        // Le jeton survit à la désactivation du compte et au départ du magasin : sans ce contrôle, un
        // ancien employé continuait de recevoir le planning (et les congés) de l'équipe dans son agenda.
        if (empty($user['is_active']) || $this->storeUsers->findMembership($storeId, $userId) === null) {
            throw new NotFoundException(__('error_ical_feed_not_found'));
        }

        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
        $shifts = array_values(array_filter(
            $this->shifts->findByUser($userId),
            fn($s) => (int) ($s['store_id'] ?? 0) === $storeId
        ));
        // Shifts supprimés récemment (30 jours) : émis comme VEVENT CANCELLED
        // par IcalService::build() pour que l'agenda synchronisé de l'employé
        // reflète explicitement la suppression au lieu de la manquer silencieusement.
        $shifts = array_merge($shifts, $this->shifts->findRecentDeletionsByUserAndStore($userId, $storeId, $cutoff));

        $timeoffs = array_values(array_filter(
            // Absences lues à travers les fournisseurs (Bundle::absenceProviders()) ; aucune sans bundle timeoff.
            Absences::current()->forUser($userId),
            fn($t) => (int) ($t['store_id'] ?? 0) === $storeId && ($t['status'] ?? '') === 'approved'
        ));

        $typesMap = [];
        foreach ($this->shiftTypes->findAll() as $t) {
            $typesMap[(int) $t['id']] = $t;
        }

        $content  = $this->ical->build($shifts, $timeoffs, $store, $user, $typesMap);
        $filename = 'kintai-' . ($store['code'] ?? $storeId) . '-shifts.ics';

        $this->auditLogger->log($request, 'export.ical', 'ical_token', (int) $tokenRow['id'], [
            'store_id' => $storeId,
            'format'   => 'ical',
        ], $storeId);

        // ETag sur le contenu sans DTSTAMP (qui change à chaque appel) : un client dont l'agenda n'a pas changé reçoit un 304,
        // ce qui limite la charge des relectures fréquentes.
        $etag = '"' . md5((string) preg_replace('/^DTSTAMP:.*\r?\n/m', '', $content)) . '"';
        if ($request->header('If-None-Match') === $etag) {
            return Response::empty()->withStatus(304)->withHeader('ETag', $etag);
        }

        return Response::ical($content, $filename)->withHeader('ETag', $etag);
    }

    /**
     * POST /ical/{store_id}/regenerate
     * Régénère le token pour l'employé connecté + ce store.
     */
    public function regenerate(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return Response::redirect($this->base() . '/login');
        }

        $userId  = (int) $user['id'];
        $storeId = (int) ($request->param('store_id') ?? 0);

        $this->icalTokens->deleteByUserAndStore($userId, $storeId);
        $saved = $this->icalTokens->save([
            'user_id'    => $userId,
            'store_id'   => $storeId,
            'token'      => bin2hex(random_bytes(32)),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->auditLogger->log($request, 'ical_token.regenerated', 'ical_token', (int) ($saved['id'] ?? 0), [
            'store_id' => $storeId,
        ], $storeId, $userId);

        return Response::redirect($this->base() . '/profile?success=1');
    }


}
