<?php

declare(strict_types=1);

namespace Tests\Bundles\DailyReport\Services;

use kintai\Bundles\Installed\DailyReport\Services\DailyReportMailService;
use kintai\Bundles\Installed\DailyReport\Services\DailyReportPermissionService;
use App\Auth\PermissionService;
use App\Mail\MailerService;
use App\Mail\MailTransportInterface;
use App\Repositories\LanguageRepositoryInterface;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\TranslationRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\Services\TranslationService;
use App\Services\UserSelection;
use PHPUnit\Framework\TestCase;

/**
 * Destinataires du rapport journalier : employés choisis dans le magasin (relus à l'envoi), plus les adresses saisies
 * avant la sélection. Un e-mail par personne, dans sa langue ; les comptes inactifs ou retirés du magasin sont écartés.
 */
final class DailyReportRecipientsTest extends TestCase
{
    /** @var list<array{to: list<string>, subject: string, body: string}> */
    private array $sent = [];

    /** @var array<int, array<string, mixed>> */
    private array $users = [
        1 => ['id' => 1, 'display_name' => 'Aki', 'email' => 'aki@example.test', 'language' => 'ja', 'is_active' => 1],
        2 => ['id' => 2, 'display_name' => 'Zoé', 'email' => 'zoe@example.test', 'language' => 'fr', 'is_active' => 1],
        3 => ['id' => 3, 'display_name' => 'Off', 'email' => 'off@example.test', 'language' => 'fr', 'is_active' => 0],
    ];

    private function service(): DailyReportMailService
    {
        $transport = new class ($this->sent) implements MailTransportInterface {
            /** @param list<array<string, mixed>> $sent */
            public function __construct(private array &$sent) {}

            public function send(string $fromAddress, string $fromName, array $to, string $subject, string $htmlBody, array $attachmentPaths = [], array $extraHeaders = []): bool
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $htmlBody];
                return true;
            }
        };

        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findByStore')->willReturn([['user_id' => 1, 'is_active' => 1], ['user_id' => 2, 'is_active' => 1], ['user_id' => 3, 'is_active' => 1]]);
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn(int $id): ?array => $this->users[$id] ?? null);

        $permissions = new PermissionService(
            $this->createStub(RoleAssignmentRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
        );

        return new DailyReportMailService(
            new DailyReportPermissionService($permissions),
            new MailerService(['driver' => 'native', 'from' => ['address' => 'test@kintai.test']], $transport),
            new TranslationService($this->createStub(TranslationRepositoryInterface::class), $this->createStub(LanguageRepositoryInterface::class)),
            new UserSelection($storeUsers, $users, $permissions),
        );
    }

    /** @param array<string, mixed> $settings */
    private function store(array $settings): array
    {
        return ['id' => 4, 'name' => 'Shibuya', 'currency' => '¥', 'daily_report_settings' => json_encode($settings)];
    }

    private function report(): array
    {
        return ['id' => 1, 'report_date' => '2026-10-23', 'sales_total' => 1000, 'customer_count' => 3, 'labor_cost' => 200, 'waste_total' => 0, 'notes' => 'RAS'];
    }

    public function testEachChosenEmployeeReceivesHisOwnMailInHisLanguage(): void
    {
        $ok = $this->service()->send($this->report(), $this->store(['mail_recipient_ids' => [1, 2]]));

        $this->assertTrue($ok);
        $this->assertSame([['aki@example.test'], ['zoe@example.test']], array_column($this->sent, 'to'));
        $this->assertStringContainsString('<html lang="ja">', $this->sent[0]['body']);
        $this->assertStringContainsString('<html lang="fr">', $this->sent[1]['body']);
    }

    public function testNoMailShowsTheOtherRecipientsAddresses(): void
    {
        $this->service()->send($this->report(), $this->store(['mail_recipient_ids' => [1, 2], 'mail_recipients' => ['boss@example.test']]));

        $this->assertCount(3, $this->sent);
        foreach ($this->sent as $mail) {
            $this->assertCount(1, $mail['to']);
        }
    }

    public function testAnInactiveOrRemovedAccountReceivesNothing(): void
    {
        $this->service()->send($this->report(), $this->store(['mail_recipient_ids' => [3, 99, 2]]));

        $this->assertSame([['zoe@example.test']], array_column($this->sent, 'to'));
    }

    public function testAddressesEnteredBeforeTheSelectionStillReceiveTheReport(): void
    {
        $ok = $this->service()->send($this->report(), $this->store(['mail_recipients' => ['boss@example.test', 'not-an-address']]));

        $this->assertTrue($ok);
        $this->assertSame([['boss@example.test']], array_column($this->sent, 'to'));
    }

    public function testAnEmployeeAlsoListedAsAnAddressIsNotMailedTwice(): void
    {
        $this->service()->send($this->report(), $this->store(['mail_recipient_ids' => [2], 'mail_recipients' => ['ZOE@example.test']]));

        $this->assertCount(1, $this->sent);
    }

    public function testAnExplicitLocaleWinsOverTheLanguageOfTheAccount(): void
    {
        $this->service()->send($this->report(), $this->store(['mail_recipient_ids' => [1]]), null, [], 'en');

        $this->assertStringContainsString('<html lang="en">', $this->sent[0]['body']);
    }

    public function testNothingIsSentWithoutAnyRecipient(): void
    {
        $this->assertFalse($this->service()->send($this->report(), $this->store(['mail_recipient_ids' => [3]])));
        $this->assertSame([], $this->sent);
    }
}
