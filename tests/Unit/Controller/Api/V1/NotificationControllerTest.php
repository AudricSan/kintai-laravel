<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Api\V1;

use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\NotificationRepositoryInterface;
use kintai\Core\Request;
use kintai\UI\Controller\Api\V1\NotificationController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class NotificationControllerTest extends TestCase
{
    private NotificationRepositoryInterface&MockObject $notifications;
    private NotificationController $controller;

    protected function setUp(): void
    {
        $this->notifications = $this->createMock(NotificationRepositoryInterface::class);
        $this->controller    = new NotificationController($this->notifications);
    }

    private function makeRequest(int $authUserId, array $routeParams = [], array $query = []): Request
    {
        $_GET = $query;
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => $authUserId]);
        $req->setRouteParams($routeParams);
        return $req;
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }

    /**
     * Régression : DELETE /api/v1/notifications/{id} appelait markRead() au lieu de
     * réellement supprimer la notification.
     */
    public function testDestroyActuallyDeletesTheNotification(): void
    {
        $this->notifications->method('findById')->with(7)->willReturn(['id' => 7, 'user_id' => 1]);
        $this->notifications->expects($this->once())->method('delete')->with(7);
        $this->notifications->expects($this->never())->method('markRead');

        $response = $this->controller->destroy($this->makeRequest(1, ['id' => '7']));

        $this->assertSame(204, $response->status());
    }

    public function testDestroyThrowsNotFoundForMissingNotification(): void
    {
        $this->notifications->method('findById')->with(99)->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->controller->destroy($this->makeRequest(1, ['id' => '99']));
    }

    public function testMarkReadStillMarksReadRatherThanDeleting(): void
    {
        $this->notifications->method('findById')->with(7)->willReturn(['id' => 7, 'user_id' => 1]);
        $this->notifications->expects($this->once())->method('markRead')->with(7, 1);
        $this->notifications->expects($this->never())->method('delete');

        $response = $this->controller->markRead($this->makeRequest(1, ['id' => '7']));

        $this->assertSame(204, $response->status());
    }

    // -------------------------------------------------------------------------
    // Ressource strictement personnelle (RBAC/API) : la notification d'un autre
    // utilisateur est traitée comme inexistante.
    // -------------------------------------------------------------------------

    public function testShowRejectsAnotherUsersNotificationAsNotFound(): void
    {
        $this->notifications->method('findById')->with(7)->willReturn(['id' => 7, 'user_id' => 2]);

        $this->expectException(NotFoundException::class);
        $this->controller->show($this->makeRequest(1, ['id' => '7']));
    }

    public function testDestroyRejectsAnotherUsersNotification(): void
    {
        $this->notifications->method('findById')->with(7)->willReturn(['id' => 7, 'user_id' => 2]);
        $this->notifications->expects($this->never())->method('delete');

        $this->expectException(NotFoundException::class);
        $this->controller->destroy($this->makeRequest(1, ['id' => '7']));
    }

    public function testIndexIgnoresUserIdQueryAndListsTokenUsersNotifications(): void
    {
        // L'ancien `?user_id=` permettait de lister les notifications d'autrui
        $this->notifications->expects($this->once())->method('findByUser')->with(1, 1000)->willReturn([]);

        $response = $this->controller->index($this->makeRequest(1, [], ['user_id' => '2']));

        $this->assertSame(200, $response->status());
    }

    public function testMarkAllReadTargetsTokenUserOnly(): void
    {
        $this->notifications->expects($this->once())->method('markAllRead')->with(1);

        $response = $this->controller->markAllRead($this->makeRequest(1, [], ['user_id' => '2']));

        $this->assertSame(204, $response->status());
    }
}
