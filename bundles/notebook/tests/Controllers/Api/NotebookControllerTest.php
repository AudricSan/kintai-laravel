<?php

declare(strict_types=1);

namespace Tests\Bundles\Notebook\Controllers\Api;

use App\Auth\PermissionService;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use kintai\Bundles\Installed\Notebook\Controllers\Api\NotebookController;
use PHPUnit\Framework\TestCase;

final class NotebookControllerTest extends TestCase
{
    private ?NotebookController $controller = null;

    /** Notes de la base de test (table réelle notebook_entries). */
    private function notes(array $rows = []): void
    {
        kintai_test_table('notebook_entries', bundle: 'notebook', rows: $rows);
    }

    /** Objet testé, construit à la première utilisation. */
    private function controller(): NotebookController
    {
        if ($this->controller === null) {
            $this->controller = new NotebookController(new PermissionService());
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }

    private function grantStoreScoped(int $userId, int $storeId, array $permissions): void
    {
        kintai_test_assignments(findByUser: fn(int $uid) => $uid === $userId
                ? [['id' => 1, 'user_id' => $uid, 'role_id' => 5, 'scope_type' => 'store', 'scope_id' => $storeId]]
                : []);
        kintai_test_roles(findById: fn($a0) => $a0 === 5 ? ['id' => 5, 'is_system' => 0] : null, getPermissions: fn($a0) => $a0 === 5 ? $permissions : [], getGlobalPermissionKeys: fn($a0) => $a0 === 5 ? [] : []);
    }

    /** Rôle système en portée globale (Owner) : aucune restriction de magasin. */
    private function grantGlobal(int $userId): void
    {
        kintai_test_assignments(findByUser: fn(int $uid) => $uid === $userId
                ? [['id' => 1, 'user_id' => $uid, 'role_id' => 1, 'scope_type' => 'global', 'scope_id' => null]]
                : []);
        kintai_test_roles(findById: fn($a0) => $a0 === 1 ? ['id' => 1, 'is_system' => 1] : null, getGlobalPermissionKeys: fn($a0) => $a0 === 1 ? [] : []);
    }

    private function requestFor(int $userId): Request
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => $userId]);
        return $req;
    }

    private function withJsonBody(Request $req, array $body): Request
    {
        kintai_test_set_json($req, $body);
        return $req;
    }

    private function note(int $id, int $authorId, ?int $storeId, string $content = 'x'): array
    {
        return ['id' => $id, 'author_id' => $authorId, 'store_id' => $storeId, 'content' => $content, 'pinned' => 0, 'created_at' => '2026-01-01 10:00:00'];
    }

    public function testIndexRestrictsResultsToTheUsersGrantedScope(): void
    {
        $this->grantStoreScoped(9, 1, ['notebook.view']);
        $this->notes([$this->note(1, 9, 1, 'a'), $this->note(2, 9, 2, 'b')]);

        $response = $this->controller()->index($this->requestFor(9));
        $data     = json_decode($response->getContent(), true);

        $this->assertSame([1], array_column($data['data'], 'id'));
    }

    public function testShowThrowsNotFoundForAnUnknownId(): void
    {
        $this->grantStoreScoped(9, 1, ['notebook.view']);
        $this->notes();

        $req = $this->requestFor(9);
        $req->setRouteParams(['id' => '999']);

        $this->expectException(NotFoundException::class);
        $this->controller()->show($req);
    }

    public function testShowIsAllowedForTheAuthorEvenWithoutPermission(): void
    {
        $this->grantStoreScoped(9, 1, []); // aucune permission accordée
        $this->notes([$this->note(5, 9, 1)]);

        $req = $this->requestFor(9);
        $req->setRouteParams(['id' => '5']);

        $response = $this->controller()->show($req);
        $data     = json_decode($response->getContent(), true);

        $this->assertSame(5, $data['id']);
    }

    public function testShowForbidsANonAuthorWithoutThePermission(): void
    {
        $this->grantStoreScoped(2, 1, []); // pas notebook.view
        $this->notes([$this->note(5, 9, 1)]);

        $req = $this->requestFor(2);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ForbiddenException::class);
        $this->controller()->show($req);
    }

    public function testStoreSetsTheAuthenticatedUserAsAuthorRegardlessOfPayload(): void
    {
        $this->grantGlobal(9); // note « organisation » : portée globale requise
        $this->notes();

        $req = $this->withJsonBody($this->requestFor(9), ['content' => 'hello', 'author_id' => 999]);

        $response = $this->controller()->store($req);

        $this->assertSame(201, $response->getStatusCode());
        $row = DB::table('notebook_entries')->first();
        $this->assertSame(9, (int) $row->author_id);
        $this->assertSame(0, (int) $row->pinned);
        $this->assertSame('hello', $row->content);
    }

    public function testUpdateStripsAuthorIdFromThePayloadSoItCanNeverChangeOwnership(): void
    {
        $this->grantStoreScoped(9, 1, []); // auteur, pas besoin de permission
        $this->notes([$this->note(5, 9, 1, 'old')]);

        $req = $this->withJsonBody($this->requestFor(9), ['content' => 'updated', 'author_id' => 999]);
        $req->setRouteParams(['id' => '5']);

        $this->controller()->update($req);

        $row = DB::table('notebook_entries')->where('id', 5)->first();
        $this->assertSame(9, (int) $row->author_id);
        $this->assertSame('updated', $row->content);
    }

    public function testDestroyForbidsANonAuthorWithoutNotebookCreatePermission(): void
    {
        $this->grantStoreScoped(2, 1, []); // pas notebook.create
        $this->notes([$this->note(5, 9, 1)]);

        $req = $this->requestFor(2);
        $req->setRouteParams(['id' => '5']);

        try {
            $this->controller()->destroy($req);
            $this->fail('ForbiddenException attendue');
        } catch (ForbiddenException) {
            $this->assertSame(1, DB::table('notebook_entries')->count(), 'la note est conservée');
        }
    }

    public function testDestroyAllowsAUserHoldingNotebookCreateOnTheStore(): void
    {
        $this->grantStoreScoped(2, 1, ['notebook.create']);
        $this->notes([$this->note(5, 9, 1)]);

        $req = $this->requestFor(2);
        $req->setRouteParams(['id' => '5']);

        $response = $this->controller()->destroy($req);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(0, DB::table('notebook_entries')->count());
    }

    // --- Audit du 03/10/2026 : liste blanche à la création et à la modification ---

    public function testStoreIgnoresIdSoItCannotOverwriteSomeoneElsesNote(): void
    {
        $this->grantStoreScoped(9, 1, ['notebook.create']);
        $this->notes([$this->note(5, 1, 1, 'original')]);

        $req = $this->withJsonBody($this->requestFor(9), ['id' => 5, 'content' => 'x', 'store_id' => 1, 'pinned' => 1, 'author_id' => 1]);

        $this->assertSame(201, $this->controller()->store($req)->getStatusCode());

        $this->assertSame('original', DB::table('notebook_entries')->where('id', 5)->value('content'), 'la note existante est intacte');
        $created = DB::table('notebook_entries')->where('id', '!=', 5)->first();
        $this->assertSame(9, (int) $created->author_id);
        $this->assertSame(0, (int) $created->pinned);
        $this->assertSame(1, (int) $created->store_id);
    }

    public function testStoreForbidsAnOrganisationWideNoteWithoutGlobalScope(): void
    {
        $this->grantStoreScoped(9, 1, ['notebook.create']);
        $this->notes();

        try {
            $this->controller()->store($this->withJsonBody($this->requestFor(9), ['content' => 'pour tous']));
            $this->fail('ForbiddenException attendue');
        } catch (ForbiddenException) {
            $this->assertSame(0, DB::table('notebook_entries')->count());
        }
    }

    public function testStoreForbidsANoteInAStoreTheCallerDoesNotManage(): void
    {
        $this->grantStoreScoped(9, 1, ['notebook.create']);
        $this->notes();

        try {
            $this->controller()->store($this->withJsonBody($this->requestFor(9), ['content' => 'x', 'store_id' => 2]));
            $this->fail('ForbiddenException attendue');
        } catch (ForbiddenException) {
            $this->assertSame(0, DB::table('notebook_entries')->count());
        }
    }

    public function testUpdateCannotMoveTheNoteNorPinItNorChangeItsCreationDate(): void
    {
        $this->grantStoreScoped(9, 1, []); // auteur, sans notebook.manage
        $this->notes([$this->note(5, 9, 1, 'old')]);

        $req = $this->withJsonBody($this->requestFor(9), ['content' => 'updated', 'store_id' => null, 'pinned' => 1, 'created_at' => '2000-01-01', 'id' => 99]);
        $req->setRouteParams(['id' => '5']);

        $this->controller()->update($req);

        $row = DB::table('notebook_entries')->where('id', 5)->first();
        $this->assertSame('updated', $row->content);
        $this->assertSame(1, (int) $row->store_id);
        $this->assertSame(0, (int) $row->pinned);
        $this->assertSame('2026-01-01 10:00:00', $row->created_at);
        $this->assertSame(1, DB::table('notebook_entries')->count());
    }
}
