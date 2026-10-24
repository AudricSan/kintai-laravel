<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\PushNotificationService;
use PHPUnit\Framework\TestCase;

/** Message FCM : normal, ou muet et en priorité basse pendant les heures silencieuses de l'utilisateur. */
final class PushNotificationMessageTest extends TestCase
{
    public function testBuildMessageIsPlainOutsideQuietHours(): void
    {
        $message = PushNotificationService::buildMessage('tok', 'Titre', 'Corps', ['type' => 'shift_assigned']);

        $this->assertSame(['token', 'notification', 'data'], array_keys($message));
        $this->assertSame(['type' => 'shift_assigned'], $message['data']);
    }

    public function testBuildMessageIsLowPriorityAndSilentDuringQuietHours(): void
    {
        $message = PushNotificationService::buildMessage('tok', 'Titre', 'Corps', ['type' => 'shift_assigned', 'quiet' => '1']);

        $this->assertSame('1', $message['data']['quiet'], 'l\'application mobile peut aussi décider');
        $this->assertSame('PRIORITY_LOW', $message['android']['notification']['notification_priority']);
        $this->assertFalse($message['android']['notification']['default_sound']);
        $this->assertFalse($message['android']['notification']['default_vibrate_timings']);
        $this->assertSame('kintai_quiet', $message['android']['notification']['channel_id']);
        $this->assertSame('5', $message['apns']['headers']['apns-priority']);
        $this->assertJson(json_encode(['message' => $message], JSON_THROW_ON_ERROR));
    }
}
