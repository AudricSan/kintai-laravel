<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\{{Studly}};

use kintai\Contract\Bundle;
use kintai\Contract\Extension\NavItem;

final class {{Studly}}Bundle extends Bundle
{
    public function getName(): string
    {
        return '{{slug}}';
    }

    public function getVersion(): string
    {
        return '0.1.0';
    }

    public function getLabel(): string
    {
        return __('bundle_{{prefix}}');
    }

    public function getDescription(): string
    {
        return __('bundle_{{prefix}}_desc');
    }

    public function register(): void
    {
        $this->loadViewsFrom($this->getPath() . '/Views', '{{slug}}');
        $this->loadRoutesFrom($this->getPath() . '/routes.php');
        $this->loadAssetsFrom('public');
    }

    /** Entrée de menu : le libellé est une clé de traduction du bundle. */
    public function navigation(): array
    {
        return [
            new NavItem('{{prefix}}', NavItem::MAIN, '{{prefix}}', '{{prefixDot}}.index', '{{prefixDot}}.index', 'clipboard', activePaths: ['/{{slug}}']),
        ];
    }

    /** Catégorie de permissions du bundle : « {{prefix}}.view » protège la route. */
    public function permissions(): array
    {
        return ['{{prefix}}' => ['view']];
    }
}
