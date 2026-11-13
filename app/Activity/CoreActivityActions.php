<?php

namespace App\Activity;

use Kintai\Contracts\Activity\ActivityActionRegistry;

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
            'Settings' => [
                'settings.updated' => 'Instance settings updated',
                'settings.vapid_regenerated' => 'Web Push keys regenerated',
                'settings.mail_tested' => 'Test email sent',
                'cron.token_created' => 'Scheduled tasks token created',
                'cron.token_revoked' => 'Scheduled tasks token revoked',
                'docs.synced' => 'Documentation synchronized',
                'support.issue_reported' => 'Problem reported',
            ],
            'Bundles' => [
                'bundle.enabled' => 'Bundle enabled',
                'bundle.disabled' => 'Bundle disabled',
                'bundle.permissions_granted' => 'Bundle default permissions granted',
            ],
            'Backups and data' => [
                'backup.created' => 'Backup created',
                'backup.downloaded' => 'Backup downloaded',
                'backup.restored' => 'Backup restored',
                'backup.deleted' => 'Backup deleted',
                'backup.deleted_all' => 'All backups deleted',
                'backup.settings_updated' => 'Backup settings updated',
                'app.data_reset' => 'Data reset',
            ],
            'Updates' => [
                'update.applied' => 'Update applied',
                'update.rolled_back' => 'Update rolled back',
                'update.cancelled' => 'Update cancelled',
                'update.channel_changed' => 'Update channel changed',
            ],
            'License' => [
                'license.register' => 'Free license requested',
                'license.activated' => 'License activated',
                'license.validated' => 'License checked',
                'license.refused' => 'License refused by the server',
                'license.deactivated' => 'License deactivated',
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
