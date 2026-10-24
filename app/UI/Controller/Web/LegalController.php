<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Core\Request;
use kintai\Core\Response;
use kintai\UI\ViewRenderer;

final class LegalController
{
    public function __construct(
        private readonly ViewRenderer $view,
    ) {}

    public function mentions(Request $request): Response
    {
        return Response::html($this->view->render('legal.mentions', [
            'title' => __('legal_notice'),
        ], 'layout.app'));
    }

    public function terms(Request $request): Response
    {
        return Response::html($this->view->render('legal.terms', [
            'title' => __('terms_of_use'),
        ], 'layout.app'));
    }

    public function license(Request $request): Response
    {
        return Response::html($this->view->render('legal.license', [
            'title' => __('license'),
        ], 'layout.app'));
    }
}
