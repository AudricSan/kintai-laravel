<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\AuthService;
use App\Exceptions\NotFoundException;
use App\Models\IcalToken;
use App\Repositories\ShiftRepositoryInterface;
use App\Repositories\ShiftTypeRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Extension\Absences;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use App\Services\IcalService;
use App\Services\SchedulePublication;
use App\Http\Controllers\HasBaseUrl;

final class IcalController
{
    use HasBaseUrl;
    public function __construct(
        private readonly IcalService                        $ical,
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
        $token   = $request->route('token') ?? '';
        $storeId = (int) ($request->route('store_id') ?? 0);

        $tokenRow = IcalToken::findByToken($token);
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
        // Brouillons écartés (publication du planning) : ils arrivent dans l'agenda une fois publiés.
        $shifts = SchedulePublication::staffView(array_values(array_filter(
            $this->shifts->findByUser($userId),
            fn($s) => (int) ($s['store_id'] ?? 0) === $storeId
        )));
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
            return response()->noContent()->setStatusCode(304)->header('ETag', $etag);
        }

        return response()->ical($content, $filename)->header('ETag', $etag);
    }

    /**
     * POST /ical/{store_id}/regenerate
     * Régénère le token pour l'employé connecté + ce store.
     */
    public function regenerate(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user) {
            return redirect()->away($this->base() . '/login');
        }

        $userId  = (int) $user['id'];
        $storeId = (int) ($request->route('store_id') ?? 0);

        IcalToken::deleteByUserAndStore($userId, $storeId);
        $saved = IcalToken::put([
            'user_id'    => $userId,
            'store_id'   => $storeId,
            'token'      => bin2hex(random_bytes(32)),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->auditLogger->log($request, 'ical_token.regenerated', 'ical_token', (int) ($saved['id'] ?? 0), [
            'store_id' => $storeId,
        ], $storeId, $userId);

        return redirect()->away($this->base() . '/profile?success=1');
    }


}
