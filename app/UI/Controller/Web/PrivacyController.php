<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Core\Request;
use kintai\Core\Response;
use kintai\UI\ViewRenderer;

final class PrivacyController
{
    public function __construct(
        private readonly ViewRenderer $view,
    ) {}

    public function show(Request $request): Response
    {
        return Response::html($this->view->render('privacy', [
            'title' => 'Politique de confidentialité',
        ], 'layout.app'));
    }
}
