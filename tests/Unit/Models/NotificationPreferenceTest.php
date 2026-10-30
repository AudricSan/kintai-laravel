<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\NotificationPreference;
use PHPUnit\Framework\TestCase;

/** Écarts de préférence de notification, contre le vrai schéma de `notification_preferences` (migrations). */
final class NotificationPreferenceTest extends TestCase
{
    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
    }

    public function testPutUpsertsOnUserTypeAndChannel(): void
    {
        NotificationPreference::put(1, 'shift_updated', 'push', false);
        NotificationPreference::put(1, 'shift_updated', 'push', true);
        NotificationPreference::put(1, 'shift_updated', 'email', true);

        $this->assertSame(2, NotificationPreference::count());
        $this->assertEquals([1 => ['shift_updated' => ['push' => true, 'email' => true]]], NotificationPreference::deviationsFor([1]));
    }

    public function testDeviationsForReturnsEachUsersDeviationsInOneCall(): void
    {
        NotificationPreference::put(1, 'a', 'push', false);
        NotificationPreference::put(2, 'a', 'email', true);
        NotificationPreference::put(3, 'a', 'push', false);

        $this->assertSame([1 => ['a' => ['push' => false]], 2 => ['a' => ['email' => true]]], NotificationPreference::deviationsFor([1, 2, 4]));
        $this->assertSame([], NotificationPreference::deviationsFor([]));
        $this->assertSame([], NotificationPreference::deviationsFor([99]));
    }
}
