<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\{{Studly}}\Controllers\Web;

use kintai\Contract\Http\Request;
use kintai\Contract\Http\Response;
use kintai\Contract\View\ViewRenderer;

final class {{Studly}}Controller
{
    public function __construct(private readonly ViewRenderer $view) {}

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('{{slug}}::index', [
            'title' => __('{{prefix}}_title'),
        ], 'layout.app'));
    }
}
