<?php

namespace App\Authorization;

/**
 * Permissions déclarées par le corps de l'application.
 */
final class CorePermissions
{
    /** @var list<string> */
    public const ALL = [
        'employees.view',
        'employees.create',
        'employees.update',
        'employees.delete',
        'shifts.view',
        'shifts.create',
        'shifts.update',
        'shifts.delete',
        'shifts.import',
        'stores.view',
        'stores.create',
        'stores.update',
        'stores.delete',
        'payroll.view',
        'payroll.generate',
        'payroll.export',
    ];

    /** @var list<string> */
    public const MANAGER_DEFAULTS = [
        'employees.view',
        'employees.create',
        'employees.update',
        'employees.delete',
        'shifts.view',
        'shifts.create',
        'shifts.update',
        'shifts.delete',
        'shifts.import',
        'stores.view',
        'stores.update',
        'payroll.view',
        'payroll.generate',
        'payroll.export',
    ];
}
