<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

trait HasBaseUrl
{
    protected function base(): string
    {
        $sn   = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $base = rtrim(str_replace('\\', '/', dirname($sn)), '/');
        return ($base === '.' || $base === '/') ? '' : $base;
    }
}
