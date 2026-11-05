<?php

namespace Kintai\Bundles\Notebook\Livewire;

/**
 * Widget « Notes de mes magasins » du tableau de bord employé : mêmes notes, autre titre.
 */
class EmployeeWidget extends ManagerWidget
{
    protected function title(): string
    {
        return __('Notes from my stores');
    }
}
