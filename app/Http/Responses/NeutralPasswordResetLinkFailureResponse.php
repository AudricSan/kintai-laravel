<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;

/**
 * Réponse identique à celle du succès, que l'adresse existe ou non : `/forgot-password` ne doit pas révéler
 * quels comptes existent.
 */
class NeutralPasswordResetLinkFailureResponse implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(protected string $status = '') {}

    public function toResponse($request)
    {
        $message = trans('passwords.sent');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
