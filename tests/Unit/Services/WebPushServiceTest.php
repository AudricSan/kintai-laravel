<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use GuzzleHttp\Psr7\Response as PsrResponse;
use App\Repositories\AppSettingsRepositoryInterface;
use App\Models\WebPushSubscription;
use App\Services\VapidKeyStore;
use App\Services\WebPushEndpointValidator;
use App\Services\WebPushService;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\TestCase;

/**
 * Envoi web push contre un faux client HTTP : les requêtes sont réellement construites (chiffrement de la charge,
 * en-tête VAPID) par la bibliothèque, seul le réseau est simulé.
 */
final class WebPushServiceTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $sent = [];
    /** @var array<string, int> statut renvoyé par adresse (201 par défaut) */
    private array $statusByEndpoint = [];
    private bool $networkDown = false;

    /** @var array<int, array<string, mixed>> */
    /** @var list<int> identifiants des abonnements enregistrés par le test */
    private array $seeded = [];

    private VapidKeyStore $keys;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        $settings = new class implements AppSettingsRepositoryInterface {
            public array $values = [];

            public function all(): array { return $this->values; }
            public function get(string $key): ?string { return $this->values[$key] ?? null; }
            public function set(string $key, string $value): void { $this->values[$key] = $value; }
            public function setMany(array $settings): void { $this->values = $settings + $this->values; }
        };
        $this->keys = new VapidKeyStore($settings);
        $this->keys->ensure();

    }

    /** Abonnement navigateur valide : vraie clé P-256 et secret d'authentification de 16 octets. */
    private function subscription(int $id, int $userId, string $endpoint): array
    {
        $pair = VapidKeyStore::generate();

        return [
            'id' => $id, 'user_id' => $userId, 'endpoint' => $endpoint,
            'p256dh' => $pair['public'],
            'auth'   => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
        ];
    }

    private function service(): WebPushService
    {
        $client = new class($this) implements ClientInterface {
            public function __construct(private WebPushServiceTest $test) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->test->handle($request);
            }
        };

        return new WebPushService($this->keys, $client);
    }

    /** @internal appelé par le faux client */
    public function handle(RequestInterface $request): ResponseInterface
    {
        if ($this->networkDown) {
            throw new class('réseau coupé') extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {};
        }
        $this->sent[] = $request;
        return new PsrResponse($this->statusByEndpoint[(string) $request->getUri()] ?? 201);
    }

    /** Le nom a pu changer de cible depuis l'abonnement (rebinding DNS) : l'adresse est recontrôlée à chaque envoi. */
    public function testDoesNotContactAnEndpointThatNoLongerPassesTheCheck(): void
    {
        $this->store($this->subscription(1, 7, 'https://devenu-interne.exemple.org/a'));
        $this->store($this->subscription(2, 7, 'https://toujours-public.exemple.org/b'));

        $validator = new WebPushEndpointValidator(static fn(string $host): array => str_starts_with($host, 'devenu-interne.') ? ['10.0.0.5'] : ['142.250.74.10']);
        $client = new class($this) implements ClientInterface {
            public function __construct(private WebPushServiceTest $test) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->test->handle($request);
            }
        };
        (new WebPushService($this->keys, $client, $validator))->sendToUser(7, 'Titre', 'Corps');

        $this->assertCount(1, $this->sent, 'seule l\'adresse restée publique est contactée');
        $this->assertSame('https://toujours-public.exemple.org/b', (string) $this->sent[0]->getUri());
        $this->assertSame([], $this->deletedIds(), 'un contrôle refusé ne supprime pas l\'abonnement');
    }

    public function testSendsAnEncryptedVapidSignedMessageToEachBrowserOfTheUser(): void
    {
        $this->store($this->subscription(1, 7, 'https://push.example/a'));
        $this->store($this->subscription(2, 7, 'https://push.example/b'));
        $this->store($this->subscription(3, 8, 'https://push.example/autre-compte'));

        $this->service()->sendToUser(7, 'Nouveau shift', 'Lundi 9h', ['type' => 'shift_assigned', 'link' => '/employee/shifts/day', 'notification_id' => 42]);

        $this->assertSame(['https://push.example/a', 'https://push.example/b'], array_map(fn($r) => (string) $r->getUri(), $this->sent));
        $request = $this->sent[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertSame('86400', $request->getHeaderLine('TTL'));
        $this->assertSame('normal', $request->getHeaderLine('Urgency'));
        $this->assertStringStartsWith('vapid t=', $request->getHeaderLine('Authorization'));
        $this->assertStringContainsString('k=' . $this->keys->publicKey(), $request->getHeaderLine('Authorization'));
        $body = (string) $request->getBody();
        $this->assertNotSame('', $body);
        $this->assertStringNotContainsString('Nouveau shift', $body, 'la charge utile est chiffrée');
        $this->assertSame([1, 2], $this->touchedIds());
        $this->assertSame([], $this->deletedIds());
    }

    public function testAnExpiredSubscriptionIsDeleted(): void
    {
        $this->store($this->subscription(1, 7, 'https://push.example/gone'));
        $this->store($this->subscription(2, 7, 'https://push.example/not-found'));
        $this->store($this->subscription(3, 7, 'https://push.example/ok'));
        $this->statusByEndpoint = ['https://push.example/gone' => 410, 'https://push.example/not-found' => 404];

        $this->service()->sendToUser(7, 'Titre', 'Corps');

        $this->assertSame([1, 2], $this->deletedIds());
        $this->assertSame([3], $this->touchedIds());
    }

    public function testAnotherErrorKeepsTheSubscriptionAndDoesNotThrow(): void
    {
        $this->store($this->subscription(1, 7, 'https://push.example/overloaded'));
        $this->statusByEndpoint = ['https://push.example/overloaded' => 503];

        $this->service()->sendToUser(7, 'Titre', 'Corps');

        $this->assertSame([], $this->deletedIds());
        $this->assertSame([], $this->touchedIds());
        $this->assertCount(1, $this->sent, 'pas de relance');
    }

    public function testANetworkFailureNeverThrows(): void
    {
        $this->store($this->subscription(1, 7, 'https://push.example/a'));
        $this->networkDown = true;

        $this->service()->sendToUser(7, 'Titre', 'Corps');

        $this->assertSame([], $this->deletedIds());
        $this->addToAssertionCount(1);
    }

    public function testDoesNothingWithoutKeysOrWithoutSubscription(): void
    {
        $this->service()->sendToUser(7, 'Titre', 'Corps');
        $this->assertSame([], $this->sent, 'aucun abonnement');

        $this->store($this->subscription(1, 7, 'https://push.example/a'));
        $emptyKeys = new VapidKeyStore(new class implements AppSettingsRepositoryInterface {
            public function all(): array { return []; }
            public function get(string $key): ?string { return null; }
            public function set(string $key, string $value): void {}
            public function setMany(array $settings): void {}
        });
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface { throw new \LogicException('ne doit pas être appelé'); }
        };

        (new WebPushService($emptyKeys, $client))->sendToUser(7, 'Titre', 'Corps');

        $this->addToAssertionCount(1);
    }

    public function testPreloadLoadsSeveralUsersAtOnce(): void
    {
        $this->store($this->subscription(1, 7, 'https://push.example/a'));
        $this->store($this->subscription(2, 8, 'https://push.example/b'));
        $service = $this->service();

        $service->preload([7, 8]);
        $service->sendToUser(7, 'Titre', 'Corps');
        $service->sendToUser(8, 'Titre', 'Corps');

        $this->assertSame(['https://push.example/a', 'https://push.example/b'], array_map(fn($r) => (string) $r->getUri(), $this->sent));
    }

    /** Enregistre l'abonnement dans la table et en retient l'identifiant. */
    private function store(array $subscription): void
    {
        WebPushSubscription::create($subscription + ['endpoint_hash' => hash('sha256', $subscription['endpoint'])]);
        $this->seeded[] = $subscription['id'];
    }

    /** @return list<int> abonnements enregistrés par le test puis supprimés */
    private function deletedIds(): array
    {
        return array_values(array_filter($this->seeded, static fn(int $id): bool => WebPushSubscription::find($id) === null));
    }

    /** @return list<int> abonnements enregistrés par le test dont l'envoi a été horodaté */
    private function touchedIds(): array
    {
        return array_values(array_filter($this->seeded, static fn(int $id): bool => WebPushSubscription::find($id)?->last_used_at !== null));
    }
}
