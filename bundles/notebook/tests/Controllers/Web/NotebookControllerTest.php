<?php

declare(strict_types=1);

namespace Tests\Bundles\Notebook\Controllers\Web;

use kintai\Bundles\Installed\Notebook\Controllers\Web\NotebookController;
use App\Auth\PermissionService;
use App\Exceptions\ForbiddenException;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class NotebookControllerTest extends TestCase
{
    private NotificationService&Stub $notifs;
    private ?NotebookController $controller = null;

    protected function setUp(): void
    {
kintai_test_database();        kintai_test_migrate();        kintai_test_fk_off();
        $this->notifs      = $this->createStub(NotificationService::class);

        kintai_test_stores(findAll: fn() => [['id' => 1, 'name' => 'Store 1'], ['id' => 2, 'name' => 'Store 2']]);
        // Pas de findAll() par défaut ici : plusieurs tests le reconfigurent avec une valeur
        // spécifique (ex. testStoreAllowsAnOrgWideNoteForAGloballyScopedUser), et empiler un
        // second stub inconditionnel sur le même mock est ambigu côté PHPUnit (le premier stub
        // sans contrainte peut rester actif) — vu ici en pratique : la valeur du setUp() gagnait
        // silencieusement sur celle du test, notifyMembers() itérait alors une liste vide.
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): NotebookController
    {
        if ($this->controller === null) {
            $this->controller = new NotebookController(
                kintai_test_views(sys_get_temp_dir()),
                new AuditLogger(),
                $this->notifs,
                new PermissionService(),
            );
        }

        return $this->controller;
    }

    /**
     * Rôle système (is_system) affecté en portée globale à $userId : accorde tout, dont
     * notebook.manage. willReturnCallback() plutôt que with($userId) : notifyMembers() en
     * portée organisation interroge can() pour CHAQUE utilisateur (ex. id 2 sans rôle),
     * qu'une contrainte with() stricte sur un seul id ferait échouer comme "invocation
     * inattendue" au lieu de simplement retourner [] pour les autres.
     */
    private function grantGlobalRole(int $userId): void
    {
        kintai_test_assignments(findByUser: fn(int $uid) => $uid === $userId
                ? [['id' => 1, 'user_id' => $userId, 'role_id' => 9, 'scope_type' => 'global', 'scope_id' => null]]
                : []);
        kintai_test_roles(findById: fn($a0) => $a0 === 9 ? ['id' => 9, 'is_system' => 1] : null);
    }

    private function grantStoreScopedRole(int $userId, int $storeId, array $permissions): void
    {
        kintai_test_assignments(findByUser: fn($a0) => $a0 === $userId ? [
            ['id' => 1, 'user_id' => $userId, 'role_id' => 5, 'scope_type' => 'store', 'scope_id' => $storeId],
        ] : []);
        kintai_test_roles(findById: fn($a0) => $a0 === 5 ? ['id' => 5, 'is_system' => 0] : null, getPermissions: fn($a0) => $a0 === 5 ? $permissions : [], getGlobalPermissionKeys: fn($a0) => $a0 === 5 ? [] : []);
    }

    private function request(int $userId, ?array $managedStoreIds): Request
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => $userId]);
        $req->attributes->set('managed_store_ids', $managedStoreIds);
        return $req;
    }

    private function redirectLocation(Response $response): string
    {
        return (string) $response->headers->get('Location', '');
    }

    protected function tearDown(): void
    {
        $_POST = [];

        if ($this->unchangedSnapshot !== null) {
            $this->assertEquals($this->unchangedSnapshot, $this->allNotes(), 'aucune note ne devait être créée, modifiée ni supprimée');
        }
        if ($this->savedMatcher !== null) {
            $changed = array_filter($this->allNotes(), fn(array $row) => !in_array($row, $this->savedBefore, false));
            $this->assertNotEmpty($changed, 'une note devait être enregistrée');
            $this->assertNotEmpty(array_filter($changed, $this->savedMatcher), 'la note enregistrée ne correspond pas');
        }
        if ($this->deletedId !== null) {
            $this->assertNull(DB::table('notebook_entries')->where('id', $this->deletedId)->first(), 'la note devait être supprimée');
        }
    }

    // ── Notes réelles (table notebook_entries) ───────────────────────────

    /** @var list<array<string, mixed>>|null */
    private ?array $unchangedSnapshot = null;
    private ?\Closure $savedMatcher = null;
    /** @var list<array<string, mixed>> */
    private array $savedBefore = [];
    private ?int $deletedId = null;

    /** @return list<array<string, mixed>> */
    private function allNotes(): array
    {
        return DB::table('notebook_entries')->orderBy('id')->get()->map(fn($r) => (array) $r)->all();
    }

    private function resetNotes(): void
    {
        kintai_test_table('notebook_entries', bundle: 'notebook');
    }

    /** @param list<array<string, mixed>> $rows */
    private function seedNotes(array $rows): void
    {
        kintai_test_table('notebook_entries', bundle: 'notebook', rows: array_map(
            static fn(array $row): array => $row + ['author_id' => 1, 'content' => 'x', 'pinned' => 0, 'created_at' => '2026-01-01 00:00:00'],
            $rows,
        ));
    }

    /** La prochaine note créée recevra cet identifiant. */
    private function nextNoteId(int $id): void
    {
        DB::table('notebook_entries')->insert(['id' => $id - 1, 'author_id' => 1, 'content' => 'x', 'pinned' => 0, 'created_at' => '2026-01-01 00:00:00']);
        DB::table('notebook_entries')->where('id', $id - 1)->delete();
    }

    private function expectNoNoteChange(): void
    {
        $this->unchangedSnapshot = $this->allNotes();
    }

    private function expectNoteSaved(callable $matcher): void
    {
        $this->savedBefore  = $this->allNotes();
        $this->savedMatcher = \Closure::fromCallable($matcher);
    }

    private function expectNoteDeleted(int $id): void
    {
        $this->deletedId = $id;
    }

    // ── store() ──────────────────────────────────────────────────────────

    /** Ouvrir la liste marque comme lues les notifications des notes affichées. */
    public function testIndexMarksTheNotificationsOfTheDisplayedNotesAsRead(): void
    {
        $this->notifs = $this->createMock(NotificationService::class);
        $this->seedNotes([
            ['id' => 3, 'author_id' => 1, 'store_id' => null, 'content' => 'a', 'pinned' => 0, 'created_at' => '2026-01-01 10:00:00', 'expires_at' => null],
            ['id' => 8, 'author_id' => 1, 'store_id' => null, 'content' => 'b', 'pinned' => 1, 'created_at' => '2026-01-02 10:00:00', 'expires_at' => null],
        ]);
        kintai_test_users(findAll: fn() => []);
        $this->notifs->expects($this->once())->method('markReadFor')->with(9, 'notebook_entry', [8, 3]);

        try {
            $this->controller()->index($this->request(9, null));
        } catch (\Throwable) {
            // Le rendu de la vue n'est pas l'objet du test : « lu » est marqué avant.
        }
    }

    public function testStoreRejectsEmptyContent(): void
    {
        $this->resetNotes();
        $this->grantGlobalRole(1);
        $_POST = ['content' => '   '];

        $this->expectNoNoteChange();

        $response = $this->controller()->store($this->request(1, null));

        $this->assertStringContainsString('empty_content', (string) session('error'));
    }

    public function testStoreCreatesAStoreScopedNoteWhenUserManagesThatStore(): void
    {
        $this->resetNotes();
        $this->notifs = $this->createMock(NotificationService::class);
        $this->grantStoreScopedRole(9, 1, ['notebook.create', 'notebook.view']);
        $_POST = ['content' => 'Bonjour équipe', 'store_id' => '1'];

        $this->nextNoteId(42);
        $this->expectNoteSaved(fn(array $data) => $data['author_id'] === 9 && $data['store_id'] === 1 && $data['content'] === 'Bonjour équipe');

        kintai_test_store_users(findByStore: fn(int $id) => $id === 1 ? [['user_id' => 9, 'store_id' => 1]] : []);
        kintai_test_users(findById: fn(int $id) => $id === 9 ? ['id' => 9] : null, findAll: fn() => [['id' => 9, 'first_name' => 'Jean', 'last_name' => 'Dupont']]);
        $this->notifs->expects($this->once())->method('notifyMany')->with(
            [9],
            'notebook_entry_created',
            'notif_notebook_entry_created_body',
            ['author' => 'Dupont Jean', 'excerpt' => 'Bonjour équipe'],
            42,
            '/notebook?highlight=42'
        );

        $response = $this->controller()->store($this->request(9, [1]));

        $this->assertStringContainsString('created', (string) session('success'));
    }

    public function testStoreRejectsAStoreScopedNoteOnAStoreTheUserDoesNotManage(): void
    {
        $this->resetNotes();
        $this->grantStoreScopedRole(9, 1, ['notebook.create']);
        $_POST = ['content' => 'Bonjour', 'store_id' => '2'];

        $this->expectNoNoteChange();

        $this->expectException(ForbiddenException::class);
        $this->controller()->store($this->request(9, [1]));
    }

    public function testStoreRejectsAnOrgWideNoteWhenUserIsNotGloballyScoped(): void
    {
        $this->resetNotes();
        $this->grantStoreScopedRole(9, 1, ['notebook.create']);
        $_POST = ['content' => 'Pour tous', 'store_id' => ''];

        $this->expectNoNoteChange();

        $response = $this->controller()->store($this->request(9, [1]));

        $this->assertStringContainsString('forbidden', (string) session('error'));
    }

    public function testStoreAllowsAnOrgWideNoteForAGloballyScopedUser(): void
    {
        $this->resetNotes();
        $this->notifs = $this->createMock(NotificationService::class);
        // Deux utilisateurs globalement scopés (ex. deux Owners) : les deux doivent être
        // notifiés, pas seulement l'auteur — vérifie que notifyMembers() ne s'arrête pas
        // au premier destinataire trouvé.
        kintai_test_assignments(findByUser: fn(int $uid) => [['id' => 1, 'user_id' => $uid, 'role_id' => 9, 'scope_type' => 'global', 'scope_id' => null]]);
        kintai_test_roles(findById: fn(int $id) => $id === 9 ? ['id' => 9, 'is_system' => 1] : null);
        $_POST = ['content' => 'Pour tous'];

        $this->nextNoteId(7);
        $this->expectNoteSaved(fn(array $data) => $data['store_id'] === null);

        kintai_test_users(findAll: fn() => [['id' => 1, 'display_name' => 'Alice'], ['id' => 2]]);
        $this->notifs->expects($this->once())->method('notifyMany')->with(
            [1, 2],
            'notebook_entry_created',
            'notif_notebook_entry_created_body',
            ['author' => 'Alice', 'excerpt' => 'Pour tous'],
            7,
            '/notebook?highlight=7'
        );

        $response = $this->controller()->store($this->request(1, null));

        $this->assertStringContainsString('created', (string) session('success'));
    }

    // ── update() / destroy() : auteur vs notebook.manage ────────────────

    public function testUpdateAllowsTheAuthorToEditTheirOwnNoteWithoutManagePermission(): void
    {
        $this->resetNotes();
        $this->grantStoreScopedRole(9, 1, ['notebook.create']); // pas de notebook.manage
        $this->seedNotes([['id' => 5, 'author_id' => 9, 'store_id' => 1, 'content' => 'old', 'expires_at' => null]]);
        $_POST = ['content' => 'new content'];

        $req = $this->request(9, [1]);
        $req->setRouteParams(['id' => '5']);

        $this->expectNoteSaved(fn(array $data) => $data['content'] === 'new content');

        $response = $this->controller()->update($req);

        $this->assertStringContainsString('updated', (string) session('success'));
    }

    public function testUpdateAllowsAManagerToEditSomeoneElsesNote(): void
    {
        $this->resetNotes();
        $this->grantStoreScopedRole(2, 1, ['notebook.manage']);
        $this->seedNotes([['id' => 5, 'author_id' => 9, 'store_id' => 1, 'content' => 'old', 'expires_at' => null]]);
        $_POST = ['content' => 'edited by manager'];

        $req = $this->request(2, [1]);
        $req->setRouteParams(['id' => '5']);

        $this->expectNoteSaved(fn(array $row) => true);

        $response = $this->controller()->update($req);

        $this->assertStringContainsString('updated', (string) session('success'));
    }

    public function testUpdateForbidsANonAuthorWithoutManagePermission(): void
    {
        $this->resetNotes();
        $this->grantStoreScopedRole(2, 1, ['notebook.create']); // pas manage
        $this->seedNotes([['id' => 5, 'author_id' => 9, 'store_id' => 1, 'content' => 'old', 'expires_at' => null]]);
        $_POST = ['content' => 'attempted edit'];

        $this->expectNoNoteChange();

        $req = $this->request(2, [1]);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ForbiddenException::class);
        $this->controller()->update($req);
    }

    public function testDestroyForbidsANonAuthorWithoutManagePermission(): void
    {
        $this->resetNotes();
        $this->grantStoreScopedRole(2, 1, ['notebook.create']);
        $this->seedNotes([['id' => 5, 'author_id' => 9, 'store_id' => 1]]);

        $this->expectNoNoteChange();

        $req = $this->request(2, [1]);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ForbiddenException::class);
        $this->controller()->destroy($req);
    }

    public function testDestroyAllowsTheAuthorToDeleteTheirOwnNote(): void
    {
        $this->resetNotes();
        $this->grantStoreScopedRole(9, 1, []);
        $this->seedNotes([['id' => 5, 'author_id' => 9, 'store_id' => 1]]);
        $this->expectNoteDeleted(5);

        $req = $this->request(9, [1]);
        $req->setRouteParams(['id' => '5']);

        $response = $this->controller()->destroy($req);

        $this->assertStringContainsString('deleted', (string) session('success'));
    }

    // ── notes « toute l'organisation » : portée illimitée exigée ─────────

    /** Audit du 13/10/2026 : notebook.manage dans UN magasin ne suffit pas pour une note globale. */
    public function testUpdateForbidsSingleStoreManagerOnAnOrgWideNote(): void
    {
        $this->resetNotes();
        $this->grantStoreScopedRole(2, 1, ['notebook.manage']);
        $this->seedNotes([['id' => 5, 'author_id' => 9, 'store_id' => null, 'content' => 'old', 'expires_at' => null]]);
        $_POST = ['content' => 'attempted edit'];
        $this->expectNoNoteChange();

        $req = $this->request(2, [1]);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ForbiddenException::class);
        $this->controller()->update($req);
    }

    public function testDestroyForbidsSingleStoreManagerOnAnOrgWideNote(): void
    {
        $this->resetNotes();
        $this->grantStoreScopedRole(2, 1, ['notebook.manage']);
        $this->seedNotes([['id' => 5, 'author_id' => 9, 'store_id' => null]]);
        $this->expectNoNoteChange();

        $req = $this->request(2, [1]);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ForbiddenException::class);
        $this->controller()->destroy($req);
    }

    public function testTogglePinForbidsSingleStoreManagerOnAnOrgWideNote(): void
    {
        $this->resetNotes();
        $this->grantStoreScopedRole(2, 1, ['notebook.manage']);
        $this->seedNotes([['id' => 5, 'author_id' => 9, 'store_id' => null, 'pinned' => 0]]);
        $this->expectNoNoteChange();

        $req = $this->request(2, [1]);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ForbiddenException::class);
        $this->controller()->togglePin($req);
    }

    public function testTogglePinAllowsAStoreManagerOnTheirOwnStoreNote(): void
    {
        $this->resetNotes();
        $this->grantStoreScopedRole(2, 1, ['notebook.manage']);
        $this->seedNotes([['id' => 5, 'author_id' => 9, 'store_id' => 1, 'pinned' => 0]]);
        $this->expectNoteSaved(fn(array $row) => true);

        $req = $this->request(2, [1]);
        $req->setRouteParams(['id' => '5']);

        $this->redirectLocation($this->controller()->togglePin($req));
        $this->assertStringContainsString('updated', (string) session('success'));
    }

    // ── togglePin() ──────────────────────────────────────────────────────

    public function testTogglePinFlipsPinnedState(): void
    {
        $this->resetNotes();
        $this->grantGlobalRole(1);
        $this->seedNotes([['id' => 5, 'author_id' => 9, 'store_id' => null, 'pinned' => 0]]);
        $this->expectNoteSaved(fn(array $data) => $data['pinned'] === 1);

        $req = $this->request(1, null);
        $req->setRouteParams(['id' => '5']);

        $response = $this->controller()->togglePin($req);

        $this->assertStringContainsString('updated', (string) session('success'));
    }

    // ── index() ──────────────────────────────────────────────────────────

    public function testIndexOrdersPinnedFirstAndExcludesExpiredByDefault(): void
    {
        $this->grantGlobalRole(1);
        $viewDir = sys_get_temp_dir() . '/kintai-notebook-view-' . uniqid();
        $this->ensureViewFile($viewDir, 'notebook.php', '<?php echo json_encode(array_column($entries, "id")); ?>');

        $view = kintai_test_views($viewDir);
        $view->addNamespace('notebook', $viewDir);
        $controller = new NotebookController(
            $view,
            new AuditLogger(),
            $this->notifs,
            new PermissionService(),
        );

        $this->seedNotes([
            ['id' => 1, 'author_id' => 1, 'store_id' => 1, 'content' => 'récente', 'pinned' => 0, 'expires_at' => null, 'created_at' => '2026-09-20 10:00:00'],
            ['id' => 2, 'author_id' => 1, 'store_id' => null, 'content' => 'épinglée', 'pinned' => 1, 'expires_at' => null, 'created_at' => '2026-09-10 08:00:00'],
            ['id' => 3, 'author_id' => 1, 'store_id' => 1, 'content' => 'expirée', 'pinned' => 0, 'expires_at' => '2020-01-01 00:00:00', 'created_at' => '2026-09-21 10:00:00'],
        ]);

        $response = $controller->index($this->request(1, null));
        $ids = json_decode($response->getContent(), true);

        $this->assertSame([2, 1], $ids);
    }

    public function testIndexIncludesExpiredWhenShowExpiredIsRequested(): void
    {
        $this->grantGlobalRole(1);
        $viewDir = sys_get_temp_dir() . '/kintai-notebook-view-' . uniqid();
        $this->ensureViewFile($viewDir, 'notebook.php', '<?php echo json_encode(array_column($entries, "id")); ?>');

        $view = kintai_test_views($viewDir);
        $view->addNamespace('notebook', $viewDir);
        $controller = new NotebookController(
            $view,
            new AuditLogger(),
            $this->notifs,
            new PermissionService(),
        );

        $this->seedNotes([
            ['id' => 3, 'author_id' => 1, 'store_id' => 1, 'content' => 'expirée', 'pinned' => 0, 'expires_at' => '2020-01-01 00:00:00', 'created_at' => '2026-09-21 10:00:00'],
        ]);

        $_GET = ['show_expired' => '1'];
        $response = $controller->index($this->request(1, null));
        $_GET = [];
        $ids = json_decode($response->getContent(), true);

        $this->assertSame([3], $ids);
    }

    private function ensureViewFile(string $dir, string $filename, string $content): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . $filename, $content);
    }
}
