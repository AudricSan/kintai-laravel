<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Contracts\View\Factory as ViewFactory;

final class LegalController
{
    public function __construct(
        private readonly ViewFactory $view,
    ) {}

    public function mentions(Request $request): Response
    {
        return response($this->view->make('legal.mentions', [
            'title' => __('legal_notice'),
        ])->render());
    }

    public function terms(Request $request): Response
    {
        return response($this->view->make('legal.terms', [
            'title' => __('terms_of_use'),
        ])->render());
    }

    public function license(Request $request): Response
    {
        return response($this->view->make('legal.license', [
            'title' => __('license'),
        ])->render());
    }
}
