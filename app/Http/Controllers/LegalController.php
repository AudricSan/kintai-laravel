<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\View\ViewRenderer;

final class LegalController
{
    public function __construct(
        private readonly ViewRenderer $view,
    ) {}

    public function mentions(Request $request): Response
    {
        return response($this->view->render('legal.mentions', [
            'title' => __('legal_notice'),
        ], 'layout.app'));
    }

    public function terms(Request $request): Response
    {
        return response($this->view->render('legal.terms', [
            'title' => __('terms_of_use'),
        ], 'layout.app'));
    }

    public function license(Request $request): Response
    {
        return response($this->view->render('legal.license', [
            'title' => __('license'),
        ], 'layout.app'));
    }
}
