<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\View\ViewRenderer;

final class PrivacyController
{
    public function __construct(
        private readonly ViewRenderer $view,
    ) {}

    public function show(Request $request): Response
    {
        return response($this->view->render('privacy', [
            'title' => 'Politique de confidentialité',
        ], 'layout.app'));
    }
}
