<?php

namespace App\Activity;

/**
 * Actions de journal du Core. Les bundles déclarent les leurs dans `ActivityActionRegistry`.
 */
final class CoreActivityActions
{
    public static function registerIn(ActivityActionRegistry $registry): void
    {
        $actions = [
            'Authentication' => [
                'auth.login' => 'Sign in',
                'auth.logout' => 'Sign out',
                'auth.login_failed' => 'Failed sign-in attempt',
                'auth.api_login' => 'API sign-in',
                'auth.api_logout' => 'API sign-out',
                'auth.api_token_revoked' => 'API token revoked',
                'user.password_reset' => 'Password reset by the user',
                'user.password_changed' => 'Password changed',
                'user.profile_updated' => 'Profile updated',
            ],
            'Employees' => [
                'employee.created' => 'Employee created',
                'employee.updated' => 'Employee updated',
                'employee.password_reset' => 'Employee password reset',
                'employee.rates_updated' => 'Employee hourly rates updated',
                'employee.anonymized' => 'Account anonymized',
            ],
            'Stores' => [
                'store.created' => 'Store created',
                'store.updated' => 'Store updated',
                'store.deleted' => 'Store deleted',
                'store.member_added' => 'Member added to a store',
                'store.member_updated' => 'Store member updated',
                'store.member_removed' => 'Member removed from a store',
            ],
            'Planning' => [
                'shift.created' => 'Shift created',
                'shift.updated' => 'Shift updated',
                'shift.deleted' => 'Shift deleted',
                'shift.restored' => 'Shift restored',
                'shift.replaced' => 'Shift replaced',
                'shift_type.created' => 'Shift type created',
                'shift_type.updated' => 'Shift type updated',
                'shift_type.deleted' => 'Shift type deleted',
            ],
            'Roles' => [
                'role.created' => 'Role created',
                'role.updated' => 'Role updated',
                'role.deleted' => 'Role deleted',
                'role.holders_changed' => 'Role holders changed',
            ],
            'Exports and calendar' => [
                'export.downloaded' => 'Data exported',
                'export.schedule' => 'Own schedule exported',
                'export.personal_data' => 'Personal data exported',
                'ical.token_regenerated' => 'Calendar link generated',
                'ical.token_deleted' => 'Calendar link deleted',
            ],
            'Activity log' => [
                'activity.exported' => 'Activity log exported',
                'activity.purged' => 'Activity log purged',
            ],
        ];

        foreach ($actions as $category => $items) {
            foreach ($items as $key => $label) {
                $registry->register($key, $label, $category);
            }
        }
    }
}
