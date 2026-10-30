<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Api\V1;

use App\Exceptions\NotFoundException;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\V1\NotificationController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class NotificationControllerTest extends TestCase
{
    private NotificationController $controller;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_user(1);
        kintai_test_user(2);
        $this->controller    = new NotificationController();
    }

    private function makeRequest(int $authUserId, array $routeParams = [], array $query = []): Request
    {
        $_GET = $query;
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => $authUserId]);
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
        $this->seedNotification(7, 1);

        $response = $this->controller->destroy($this->makeRequest(1, ['id' => '7']));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull(Notification::find(7), 'supprimée');
    }

    public function testDestroyThrowsNotFoundForMissingNotification(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller->destroy($this->makeRequest(1, ['id' => '99']));
    }

    public function testMarkReadStillMarksReadRatherThanDeleting(): void
    {
        $this->seedNotification(7, 1);

        $response = $this->controller->markRead($this->makeRequest(1, ['id' => '7']));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotNull(Notification::find(7)->read_at, 'marquée lue');
    }

    // -------------------------------------------------------------------------
    // Ressource strictement personnelle (RBAC/API) : la notification d'un autre
    // utilisateur est traitée comme inexistante.
    // -------------------------------------------------------------------------

    public function testShowRejectsAnotherUsersNotificationAsNotFound(): void
    {
        $this->seedNotification(7, 2);

        $this->expectException(NotFoundException::class);
        $this->controller->show($this->makeRequest(1, ['id' => '7']));
    }

    public function testDestroyRejectsAnotherUsersNotification(): void
    {
        $this->seedNotification(7, 2);

        try {
            $this->controller->destroy($this->makeRequest(1, ['id' => '7']));
            $this->fail('notification d\'un autre compte supprimée');
        } catch (NotFoundException) {
            $this->assertNotNull(Notification::find(7));
        }
    }

    public function testIndexIgnoresUserIdQueryAndListsTokenUsersNotifications(): void
    {
        // L'ancien `?user_id=` permettait de lister les notifications d'autrui
        $this->seedNotification(1, 1);
        $this->seedNotification(2, 2);

        $response = $this->controller->index($this->makeRequest(1, [], ['user_id' => '2']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([1], array_column(json_decode($response->getContent(), true)['data'], 'id'), 'seules les notifications du porteur du jeton');
    }

    public function testMarkAllReadTargetsTokenUserOnly(): void
    {
        $this->seedNotification(1, 1);
        $this->seedNotification(2, 2);

        $response = $this->controller->markAllRead($this->makeRequest(1, [], ['user_id' => '2']));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotNull(Notification::find(1)->read_at);
        $this->assertNull(Notification::find(2)->read_at, 'celles de l\'autre compte restent non lues');
    }

    private function seedNotification(int $id, int $userId): void
    {
        Notification::create(['id' => $id, 'user_id' => $userId, 'type' => 'shift_assigned', 'body' => 'x', 'created_at' => '2026-10-19 10:00:00']);
    }
}
