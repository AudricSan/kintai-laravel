<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Core\Auth\PasswordPolicy;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\PasswordResetService;
use kintai\Core\Services\PublicUrlResolver;
use kintai\UI\Controller\Web\HasBaseUrl;
use kintai\UI\ViewRenderer;

final class PasswordResetController
{
    use HasBaseUrl;
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly PasswordResetService $passwordReset,
        private readonly PublicUrlResolver $publicUrl,
    ) {}

    /** GET /forgot-password */
    public function showForgotForm(Request $request): Response
    {
        return Response::html($this->view->render('auth.forgot-password', [
            'title'   => __('forgot_password_title'),
            'sent'    => false,
            'error'   => false,
        ], 'layout.guest'));
    }

    /** POST /forgot-password */
    public function sendLink(Request $request): Response
    {
        $email = trim((string) $request->post('email', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::html($this->view->render('auth.forgot-password', [
                'title' => __('forgot_password_title'),
                'sent'  => false,
                'error' => true,
            ], 'layout.guest'));
        }

        // Anti-énumération : on envoie toujours la page "succès". Le lien de l'e-mail doit être absolu : il est
        // construit sur l'URL publique configurée, jamais sur l'en-tête Host de cette requête (voir PublicUrlResolver).
        // Sans URL configurée, le service n'envoie rien et journalise l'erreur ; l'Owner en est averti dans l'interface.
        $this->passwordReset->sendResetLink($email, $this->publicUrl->resolve() ?? '');

        return Response::html($this->view->render('auth.forgot-password', [
            'title' => __('forgot_password_title'),
            'sent'  => true,
            'error' => false,
        ], 'layout.guest'));
    }

    /** GET /reset-password/{token} */
    public function showResetForm(Request $request): Response
    {
        $token  = (string) $request->param('token', '');
        $record = $this->passwordReset->findValidToken($token);

        return Response::html($this->view->render('auth.reset-password', [
            'title'   => __('new_password'),
            'token'   => $token,
            'valid'   => $record !== null,
            'success' => false,
            'error'   => null,
        ], 'layout.guest'));
    }

    /** POST /reset-password/{token} */
    public function reset(Request $request): Response
    {
        $token    = (string) $request->param('token', '');
        $password = (string) $request->post('password', '');
        $confirm  = (string) $request->post('password_confirmation', '');

        if (!PasswordPolicy::isLongEnough($password)) {
            return $this->resetView($token, __('reset_password_too_short'));
        }

        if ($password !== $confirm) {
            return $this->resetView($token, __('password_mismatch'));
        }

        $ok = $this->passwordReset->reset($token, $password);

        if (!$ok) {
            return $this->resetView($token, __('reset_password_invalid_link'));
        }

        return Response::html($this->view->render('auth.reset-password', [
            'title'   => __('new_password'),
            'token'   => $token,
            'valid'   => true,
            'success' => true,
            'error'   => null,
            'login_url' => $this->base() . '/login',
        ], 'layout.guest'));
    }

    private function resetView(string $token, string $error): Response
    {
        $record = $this->passwordReset->findValidToken($token);

        return Response::html($this->view->render('auth.reset-password', [
            'title'   => __('new_password'),
            'token'   => $token,
            'valid'   => $record !== null,
            'success' => false,
            'error'   => $error,
        ], 'layout.guest'));
    }


}
