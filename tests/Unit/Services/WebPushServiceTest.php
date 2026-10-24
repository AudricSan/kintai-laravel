<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use GuzzleHttp\Psr7\Response as PsrResponse;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\WebPushSubscriptionRepositoryInterface;
use kintai\Core\Services\VapidKeyStore;
use kintai\Core\Services\WebPushEndpointValidator;
use kintai\Core\Services\WebPushService;
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
    private array $rows = [];
    /** @var list<int> */
    private array $deleted = [];
    /** @var list<int> */
    private array $touched = [];

    private VapidKeyStore $keys;
    private WebPushSubscriptionRepositoryInterface $subscriptions;

    protected function setUp(): void
    {
        $settings = new class implements AppSettingsRepositoryInterface {
            public array $values = [];

            public function all(): array { return $this->values; }
            public function get(string $key): ?string { return $this->values[$key] ?? null; }
            public function set(string $key, string $value): void { $this->values[$key] = $value; }
            public function setMany(array $settings): void { $this->values = $settings + $this->values; }
        };
        $this->keys = new VapidKeyStore($settings);
        $this->keys->ensure();

        $test = $this;
        $this->subscriptions = new class($this->rows, $this->deleted, $this->touched) implements WebPushSubscriptionRepositoryInterface {
            public function __construct(private array &$rows, private array &$deleted, private array &$touched) {}

            public function findByUser(int $userId): array { return $this->findByUsers([$userId])[$userId] ?? []; }
            public function findByUsers(array $userIds): array
            {
                $out = [];
                foreach ($this->rows as $row) {
                    if (in_array($row['user_id'], $userIds, true)) {
                        $out[$row['user_id']][] = $row;
                    }
                }
                return $out;
            }
            public function findByEndpoint(string $endpoint): ?array { return null; }
            public function save(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): array { return []; }
            public function deleteByEndpoint(string $endpoint, ?int $userId = null): int { return 0; }
            public function deleteById(int $id): void { $this->deleted[] = $id; }
            public function touchLastUsed(int $id): void { $this->touched[] = $id; }
            public function deleteAllForUser(int $userId): void {}
            public function countAll(): int { return 0; }
            public function purgeUnusedSince(string $before): int { return 0; }
        };
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

        return new WebPushService($this->subscriptions, $this->keys, $client);
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
        $this->rows[] = $this->subscription(1, 7, 'https://devenu-interne.exemple.org/a');
        $this->rows[] = $this->subscription(2, 7, 'https://toujours-public.exemple.org/b');

        $validator = new WebPushEndpointValidator(static fn(string $host): array => str_starts_with($host, 'devenu-interne.') ? ['10.0.0.5'] : ['142.250.74.10']);
        $client = new class($this) implements ClientInterface {
            public function __construct(private WebPushServiceTest $test) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->test->handle($request);
            }
        };
        (new WebPushService($this->subscriptions, $this->keys, $client, $validator))->sendToUser(7, 'Titre', 'Corps');

        $this->assertCount(1, $this->sent, 'seule l\'adresse restée publique est contactée');
        $this->assertSame('https://toujours-public.exemple.org/b', (string) $this->sent[0]->getUri());
        $this->assertSame([], $this->deleted, 'un contrôle refusé ne supprime pas l\'abonnement');
    }

    public function testSendsAnEncryptedVapidSignedMessageToEachBrowserOfTheUser(): void
    {
        $this->rows[] = $this->subscription(1, 7, 'https://push.example/a');
        $this->rows[] = $this->subscription(2, 7, 'https://push.example/b');
        $this->rows[] = $this->subscription(3, 8, 'https://push.example/autre-compte');

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
        $this->assertSame([1, 2], $this->touched);
        $this->assertSame([], $this->deleted);
    }

    public function testAnExpiredSubscriptionIsDeleted(): void
    {
        $this->rows[] = $this->subscription(1, 7, 'https://push.example/gone');
        $this->rows[] = $this->subscription(2, 7, 'https://push.example/not-found');
        $this->rows[] = $this->subscription(3, 7, 'https://push.example/ok');
        $this->statusByEndpoint = ['https://push.example/gone' => 410, 'https://push.example/not-found' => 404];

        $this->service()->sendToUser(7, 'Titre', 'Corps');

        $this->assertSame([1, 2], $this->deleted);
        $this->assertSame([3], $this->touched);
    }

    public function testAnotherErrorKeepsTheSubscriptionAndDoesNotThrow(): void
    {
        $this->rows[] = $this->subscription(1, 7, 'https://push.example/overloaded');
        $this->statusByEndpoint = ['https://push.example/overloaded' => 503];

        $this->service()->sendToUser(7, 'Titre', 'Corps');

        $this->assertSame([], $this->deleted);
        $this->assertSame([], $this->touched);
        $this->assertCount(1, $this->sent, 'pas de relance');
    }

    public function testANetworkFailureNeverThrows(): void
    {
        $this->rows[] = $this->subscription(1, 7, 'https://push.example/a');
        $this->networkDown = true;

        $this->service()->sendToUser(7, 'Titre', 'Corps');

        $this->assertSame([], $this->deleted);
        $this->addToAssertionCount(1);
    }

    public function testDoesNothingWithoutKeysOrWithoutSubscription(): void
    {
        $this->service()->sendToUser(7, 'Titre', 'Corps');
        $this->assertSame([], $this->sent, 'aucun abonnement');

        $this->rows[] = $this->subscription(1, 7, 'https://push.example/a');
        $emptyKeys = new VapidKeyStore(new class implements AppSettingsRepositoryInterface {
            public function all(): array { return []; }
            public function get(string $key): ?string { return null; }
            public function set(string $key, string $value): void {}
            public function setMany(array $settings): void {}
        });
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface { throw new \LogicException('ne doit pas être appelé'); }
        };

        (new WebPushService($this->subscriptions, $emptyKeys, $client))->sendToUser(7, 'Titre', 'Corps');

        $this->addToAssertionCount(1);
    }

    public function testPreloadLoadsSeveralUsersAtOnce(): void
    {
        $this->rows[] = $this->subscription(1, 7, 'https://push.example/a');
        $this->rows[] = $this->subscription(2, 8, 'https://push.example/b');
        $service = $this->service();

        $service->preload([7, 8]);
        $service->sendToUser(7, 'Titre', 'Corps');
        $service->sendToUser(8, 'Titre', 'Corps');

        $this->assertSame(['https://push.example/a', 'https://push.example/b'], array_map(fn($r) => (string) $r->getUri(), $this->sent));
    }
}
