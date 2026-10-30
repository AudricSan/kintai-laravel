<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Repositories\DatabaseNotificationRepository;
use App\Models\DevicePushToken;
use App\Repositories\JsonLanguageRepository;
use App\Repositories\JsonTranslationRepository;
use App\Repositories\UserRepositoryInterface;
use App\Services\NotificationService;
use App\Services\PushNotificationService;
use App\BundleManager;
use App\Extension\ExtensionRegistry;
use App\Services\NotificationTypeRegistry;
use Illuminate\Translation\Translator;
use App\Models\Notification;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

/**
 * Utilise le vrai DatabaseNotificationRepository contre un vrai schéma
 * SQLite plutôt qu'un mock — notify()/notifyMany() doivent réellement
 * pouvoir écrire une ligne, pas seulement "être appelées avec les bons
 * arguments" (ce que les tests des contrôleurs métier, qui mockent
 * NotificationService en entier, ne vérifient jamais).
 *
 * TranslationService est construit avec les vrais lang/*.json du dépôt
 * (comme BundleTranslationsRealFilesTest) plutôt qu'un stub, pour vérifier
 * que la résolution de locale par destinataire (users.language) produit
 * réellement le texte attendu dans chaque langue — pas seulement qu'une
 * traduction quelconque a été appelée.
 */
final class NotificationServiceTest extends TestCase
{
    private NotificationService $service;
    private PushNotificationService $push;
    private Translator $translations;
    private UserRepositoryInterface $users;

    /** @var array<int, array{id:int, language:?string}> */
    private array $usersById = [];

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('notifications', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('type');
            $table->string('body_key')->nullable();
            $table->text('params')->nullable();
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->string('reference_type', 40)->nullable();
            $table->integer('reference_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // FCM désactivé (config vide) : PushNotificationService::sendToUser() est un
        // no-op immédiat, aucun appel réseau n'a lieu dans ce test.
        $push = new PushNotificationService([]);

        $translations = kintai_test_real_translator();

        $this->usersById = [];
        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturnCallback(
            fn(int $id) => $this->usersById[$id] ?? null
        );

        $this->push         = $push;
        $this->translations = $translations;
        $this->service      = new NotificationService(new DatabaseNotificationRepository(), $push, $translations, $this->users);
    }

    private function registerUser(int $id, ?string $language): void
    {
        $this->usersById[$id] = ['id' => $id, 'language' => $language];
    }

    private function lang(string $locale): array
    {
        return json_decode((string) file_get_contents(BASE_PATH . "/lang/{$locale}.json"), true);
    }

    public function testPushPayloadIsFlaggedQuietOnlyDuringQuietHours(): void
    {
        $normal = NotificationService::pushPayload('shift_assigned', 12, '/employee/shifts', 42, false);
        $this->assertSame(['type' => 'shift_assigned', 'reference_id' => '12', 'link' => '/employee/shifts', 'notification_id' => '42'], $normal);

        $quiet = NotificationService::pushPayload('shift_assigned', null, null, 42, true);
        $this->assertSame('1', $quiet['quiet']);
        $this->assertSame('', $quiet['reference_id']);
        $this->assertSame('', $quiet['link']);
    }

    /** Un lien qui n'est pas un chemin interne n'est jamais enregistré : il finirait dans la cloche, le push et l'e-mail. */
    public function testNotifyDropsALinkThatIsNotAnInternalPath(): void
    {
        $this->registerUser(1, 'fr');
        foreach (['//evil.example', '/\evil.example', 'https://evil.example/x', 'javascript:alert(1)', '/ok' . "
" . 'x'] as $bad) {
            $this->service->notify(1, 'shift_assigned', 'notif_shift_assigned_body', [], null, $bad);
        }
        $this->service->notify(1, 'shift_assigned', 'notif_shift_assigned_body', [], null, '/employee/shifts/day?start=2026-08-03&highlight=4');

        $links = Notification::orderBy('id')->pluck('link')->all();
        $this->assertSame([null, null, null, null, null, '/employee/shifts/day?start=2026-08-03&highlight=4'], $links);
    }

    public function testNotifyInsertsARealRowWithoutThrowing(): void
    {
        $this->registerUser(5, 'fr');
        $this->service->notify(5, 'timeoff_approved', 'notif_timeoff_approved_body', [], 42);

        $this->assertSame(1, Notification::count());
        $row = Notification::first()->toArray();
        $this->assertSame(5, (int) $row['user_id']);
        $this->assertSame('timeoff_approved', $row['type']);
        $this->assertNull($row['read_at']);
    }

    public function testNotifyWithoutReferenceIdDoesNotThrow(): void
    {
        $this->registerUser(5, 'fr');
        $this->service->notify(5, 'message_received', 'notif_message_received_body', ['subject' => 'Test']);

        $this->assertSame(1, Notification::count());
    }

    public function testNotifyManyInsertsOneRowPerUniqueUser(): void
    {
        $this->registerUser(5, 'fr');
        $this->registerUser(6, 'fr');
        $this->registerUser(7, 'fr');
        $this->service->notifyMany([5, 6, 6, 7], 'open_shift_published', 'notif_shift_claim_pending_body');

        $this->assertSame(3, Notification::count());
    }

    /**
     * Coeur du fix i18n : deux destinataires avec des langues différentes reçoivent
     * un corps de notification traduit dans leur propre langue, pas dans une locale
     * globale partagée.
     */
    public function testNotifyResolvesLocalePerRecipient(): void
    {
        $this->registerUser(10, 'en');
        $this->registerUser(20, 'ja');

        $this->service->notify(10, 'timeoff_approved', 'notif_timeoff_approved_body', [], 1);
        $this->service->notify(20, 'timeoff_approved', 'notif_timeoff_approved_body', [], 2);

        $rows = Notification::orderBy('user_id')->get()->toArray();
        $this->assertCount(2, $rows);

        $bodyForUser10 = $rows[0]['body'];
        $bodyForUser20 = $rows[1]['body'];

        $this->assertSame($this->lang('en')['notif_timeoff_approved_body'], $bodyForUser10);
        $this->assertSame($this->lang('ja')['notif_timeoff_approved_body'], $bodyForUser20);
        $this->assertNotSame($bodyForUser10, $bodyForUser20);
    }

    /** Utilisateur introuvable (supprimé entre-temps) → repli sur 'fr'. */
    /** Langue de l'instance (APP_LOCALE) le temps d'un test. */
    private function withInstanceLocale(string $code, callable $fn): void
    {
        $previous = $_ENV['APP_LOCALE'] ?? null;
        $_ENV['APP_LOCALE'] = $code;
        try {
            $fn();
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_LOCALE']);
            } else {
                $_ENV['APP_LOCALE'] = $previous;
            }
        }
    }

    public function testNotifyFallsBackToTheInstanceLanguageWhenUserNotFound(): void
    {
        // 999 n'a jamais été enregistré via registerUser() → findById() renvoie null.
        $this->withInstanceLocale('ja', fn() => $this->service->notify(999, 'timeoff_approved', 'notif_timeoff_approved_body', [], 1));

        $row = Notification::first()->toArray();
        $body = $row['body'];
        $this->assertSame($this->lang('ja')['notif_timeoff_approved_body'], $body);
    }

    /** language vide → l'utilisateur suit la langue de l'instance. */
    public function testNotifyFallsBackToTheInstanceLanguageWhenLanguageIsEmpty(): void
    {
        $this->registerUser(30, '');
        $this->withInstanceLocale('en', fn() => $this->service->notify(30, 'timeoff_approved', 'notif_timeoff_approved_body', [], 1));

        $row = Notification::first()->toArray();
        $body = $row['body'];
        $this->assertSame($this->lang('en')['notif_timeoff_approved_body'], $body);
    }

    /** Les placeholders :xxx sont bien substitués dans la locale résolue. */
    public function testNotifyReplacesPlaceholdersInResolvedLocale(): void
    {
        $this->registerUser(40, 'en');
        $this->service->notify(40, 'shift_assigned', 'notif_shift_assigned_body', [
            'date' => '2026-09-20', 'start' => '09:00', 'end' => '17:00', 'store' => 'Test Store',
        ]);

        $row = Notification::first()->toArray();
        $body = $row['body'];
        $this->assertSame(
            str_replace(
                [':date', ':start', ':end', ':store'],
                ['2026-09-20', '09:00', '17:00', 'Test Store'],
                $this->lang('en')['notif_shift_assigned_body']
            ),
            $body
        );
    }

    /**
     * Régression : une notification "nouveau shift" sans indiquer lequel obligeait
     * l'employé à aller vérifier son planning. Le lien permet de cliquer la
     * notification pour être amené directement dessus (voir NotificationController::open()).
     */
    public function testNotifyPersistsLink(): void
    {
        $this->registerUser(5, 'fr');
        $this->service->notify(
            5, 'shift_assigned', 'notif_shift_assigned_body', ['date' => '2026-09-20'], 42,
            '/employee/shifts/day?start=2026-09-20'
        );

        $this->assertSame('/employee/shifts/day?start=2026-09-20', Notification::first()->link);
    }

    /**
     * Refonte d'octobre 2026 : la clé et les paramètres sont conservés pour retraduire la notification dans la
     * langue courante du lecteur (notification_body()), body restant l'instantané de repli.
     */
    public function testNotifyStoresBodyKeyAndStringParams(): void
    {
        $this->registerUser(5, 'fr');
        $this->service->notify(5, 'shift_assigned', 'notif_shift_assigned_body', [
            'date' => '2026-09-20', 'count' => 3, 'nested' => ['ignored'],
        ]);

        $row = Notification::first();
        $this->assertSame('notif_shift_assigned_body', $row->body_key);
        $this->assertSame(['date' => '2026-09-20', 'count' => '3', 'nested' => ''], json_decode((string) $row->params, true));
        $this->assertNotSame('', (string) $row->body);
    }

    public function testNotifyWithoutLinkStoresNullLink(): void
    {
        $this->registerUser(5, 'fr');
        $this->service->notify(5, 'timeoff_approved', 'notif_timeoff_approved_body', [], 1);

        $this->assertNull(Notification::first()->link);
    }
    private function serviceWithRegistry(): NotificationService
    {
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, []);

        return new NotificationService(
            new DatabaseNotificationRepository(),
            $this->push,
            $this->translations,
            $this->users,
            new NotificationTypeRegistry(new ExtensionRegistry($manager)),
        );
    }

    /** Le type d'objet vient du registre : visiter l'objet pourra marquer ses notifications comme lues. */
    public function testNotifyStoresTheReferenceTypeOfTheRegistry(): void
    {
        $this->registerUser(5, 'fr');
        $this->serviceWithRegistry()->notify(5, 'shift_assigned', 'notif_shift_assigned_body', [], 12);

        $this->assertSame('shift', Notification::first()->reference_type);
        $this->assertSame(12, (int) Notification::first()->reference_id);
    }

    public function testNotifyLeavesReferenceTypeEmptyWithoutIdOrKnownType(): void
    {
        $this->registerUser(5, 'fr');
        $service = $this->serviceWithRegistry();
        $service->notify(5, 'shift_assigned', 'notif_shift_assigned_body');
        $service->notify(5, 'unknown_type', 'notif_shift_assigned_body', [], 3);

        foreach (Notification::all() as $row) {
            $this->assertNull($row->reference_type);
        }
    }
    public function testMarkReadForMarksTheObjectNotificationsAsRead(): void
    {
        $this->registerUser(5, 'fr');
        $service = $this->serviceWithRegistry();
        $service->notify(5, 'shift_assigned', 'notif_shift_assigned_body', [], 12);
        $service->notify(5, 'shift_assigned', 'notif_shift_assigned_body', [], 13);

        $service->markReadFor(5, 'shift', 12);

        $this->assertNotNull(Notification::where('reference_id', 12)->first()->read_at);
        $this->assertNull(Notification::where('reference_id', 13)->first()->read_at);
    }

    /** Une panne du « lu » automatique ne doit jamais empêcher d'afficher la page. */
    public function testMarkReadForNeverThrows(): void
    {
        $repo = $this->createStub(\App\Repositories\NotificationRepositoryInterface::class);
        $repo->method('markReadByReference')->willThrowException(new \RuntimeException('base indisponible'));
        $service = new NotificationService($repo, $this->push, $this->translations, $this->users);

        $service->markReadFor(5, 'shift', [1, 2]);

        $this->addToAssertionCount(1);
    }
}
