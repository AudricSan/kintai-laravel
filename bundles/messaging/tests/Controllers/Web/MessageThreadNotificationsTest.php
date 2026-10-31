<?php

declare(strict_types=1);

namespace Tests\Bundles\Messaging\Controllers\Web;

use kintai\Bundles\Installed\Messaging\Controllers\Web\MessageController;
use Tests\Support\MessagingRows;
use kintai\Contract\Data\Store;
use kintai\Contract\Data\StoreUser;
use kintai\Contract\Data\User;
use Illuminate\Http\Request;
use kintai\Contract\Services\AuditLogger;
use kintai\Contract\Services\NotificationService;
use kintai\Contract\View\ViewFactory;
use PHPUnit\Framework\TestCase;

/** Ouvrir un fil marque comme lues les notifications « nouveau message » de ce fil, et seulement les siennes. */
final class MessageThreadNotificationsTest extends TestCase
{
    use MessagingRows;

    private function controller(NotificationService $notifs): MessageController
    {
        $dir = sys_get_temp_dir() . '/kintai-messaging-views';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        touch($dir . '/messages-thread.php');
        if (!is_dir(sys_get_temp_dir() . '/layout')) {
            mkdir(sys_get_temp_dir() . '/layout', 0777, true);
        }
        touch(sys_get_temp_dir() . '/layout/app.php');

        $view = kintai_test_views(sys_get_temp_dir());
        $view->addNamespace('messaging', $dir);
        kintai_test_users(findAll: fn() => []);
        kintai_test_stores(findAll: fn() => []);
        return new MessageController(
            $view,
            new AuditLogger(),
            $notifs,
        );
    }

    private function request(int $threadId): Request
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 9, 'is_admin' => 1]);
        $req->setRouteParams(['id' => (string) $threadId]);

        return $req;
    }

    public function testOpeningAThreadMarksItsNotificationsAsRead(): void
    {
        $this->resetMessaging();
        $this->thread(12, 1, 1, ['subject' => 'Planning']);
        $this->participant(12, 9, ['is_read' => 1]);

        $notifs = $this->createMock(NotificationService::class);
        $notifs->expects($this->once())->method('markReadFor')->with(9, 'message_thread', 12);

        $this->assertSame(200, $this->controller($notifs)->messagesThread($this->request(12))->getStatusCode());
    }

    public function testAThreadTheUserDoesNotBelongToMarksNothing(): void
    {
        $this->resetMessaging();
        $this->thread(12, 1);

        $notifs = $this->createMock(NotificationService::class);
        $notifs->expects($this->never())->method('markReadFor');

        $this->expectException(\kintai\Contract\Exceptions\ForbiddenException::class);
        $this->controller($notifs)->messagesThread($this->request(12));
    }
}
