<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Contracts\View\Factory as ViewFactory;

final class PrivacyController
{
    public function __construct(
        private readonly ViewFactory $view,
    ) {}

    public function show(Request $request): Response
    {
        return response($this->view->make('privacy', [
            'title' => 'Politique de confidentialité',
        ])->render());
    }
}
