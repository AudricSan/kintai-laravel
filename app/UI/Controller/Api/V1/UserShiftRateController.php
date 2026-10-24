<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Api\V1;

use kintai\Core\Auth\UserTargetGuard;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Validation\ApiPayloadRules;
use kintai\Core\Validation\PayloadValidator;

final class UserShiftRateController
{
    public function __construct(
        private readonly UserShiftTypeRateRepositoryInterface $rates,
        private readonly UserTargetGuard $guard,
        private readonly AuditLogger $auditLogger,
        // Vérifie que le type de shift existe ; facultatif pour les tests qui construisent le contrôleur à la main.
        private readonly ?ShiftTypeRepositoryInterface $shiftTypes = null,
    ) {}

    /** GET /api/v1/users/{user_id}/rates */
    public function index(Request $request): Response
    {
        $userId = (int) $request->param('user_id');
        $this->guard->require($request, $userId, 'payroll.view');
        return Response::json($this->rates->findByUser($userId));
    }

    /** GET /api/v1/users/{user_id}/rates/{id} */
    public function show(Request $request): Response
    {
        $userId = (int) $request->param('user_id');
        $id     = (int) $request->param('id');
        $this->guard->require($request, $userId, 'payroll.view');

        $rate = $this->rates->findById($id);
        if ($rate === null || (int) $rate['user_id'] !== $userId) {
            throw new NotFoundException(__('error_rate_not_found'));
        }

        return Response::json($rate);
    }

    /** POST /api/v1/users/{user_id}/rates */
    public function store(Request $request): Response
    {
        $userId = (int) $request->param('user_id');
        $this->guard->require($request, $userId, 'employees.update');

        $body = PayloadValidator::validate($request->json() ?? [], ApiPayloadRules::userRate());
        $this->assertShiftTypeExists($body['shift_type_id']);
        $data = array_merge($body, [
            'user_id'    => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $saved = $this->rates->save($data);
        $this->auditLogger->log($request, 'user_rate.saved', 'user_rate', (int) ($saved['id'] ?? 0), [
            'user_id'       => $userId,
            'shift_type_id' => $data['shift_type_id'] ?? null,
        ]);

        return Response::json($saved, 201);
    }

    /** PUT /api/v1/users/{user_id}/rates/{id} */
    public function update(Request $request): Response
    {
        $userId = (int) $request->param('user_id');
        $id     = (int) $request->param('id');
        $this->guard->require($request, $userId, 'employees.update');

        $rate = $this->rates->findById($id);
        if ($rate === null || (int) $rate['user_id'] !== $userId) {
            throw new NotFoundException(__('error_rate_not_found'));
        }

        $body = PayloadValidator::validateUpdate($request->json() ?? [], ApiPayloadRules::userRate());
        if (isset($body['shift_type_id'])) {
            $this->assertShiftTypeExists($body['shift_type_id']);
        }

        $saved = $this->rates->save(array_merge($body, [
            'id'         => $id,
            'user_id'    => $userId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]));
        $this->auditLogger->logUpdate($request, 'user_rate.saved', 'user_rate', $id, $rate, $saved, [
            'user_id'       => $userId,
            'shift_type_id' => $rate['shift_type_id'] ?? null,
        ]);

        return Response::json($saved);
    }

    /** Un taux vise un type de shift existant : un identifiant inconnu créait un taux orphelin. */
    private function assertShiftTypeExists(int $shiftTypeId): void
    {
        if ($this->shiftTypes !== null && $this->shiftTypes->findById($shiftTypeId) === null) {
            throw new ValidationException(['shift_type_id' => __('error_shift_type_not_found')], __('error_shift_type_not_found'));
        }
    }

    /** DELETE /api/v1/users/{user_id}/rates/{id} */
    public function destroy(Request $request): Response
    {
        $userId = (int) $request->param('user_id');
        $id     = (int) $request->param('id');
        $this->guard->require($request, $userId, 'employees.update');

        $rate = $this->rates->findById($id);
        if ($rate === null || (int) $rate['user_id'] !== $userId) {
            throw new NotFoundException(__('error_rate_not_found'));
        }

        $this->rates->delete($id);
        $this->auditLogger->log($request, 'user_rate.deleted', 'user_rate', $id, [
            'user_id'       => $userId,
            'shift_type_id' => $rate['shift_type_id'] ?? null,
        ]);

        return Response::empty();
    }
}
