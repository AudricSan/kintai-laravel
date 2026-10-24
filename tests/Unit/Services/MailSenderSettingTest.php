<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Mail\MailerService;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Services\AppSettingsService;
use PHPUnit\Framework\TestCase;

/** Expéditeur des e-mails réglé par l'Owner : il l'emporte sur config/mail.php, pour tous les envois. */
final class MailSenderSettingTest extends TestCase
{
    private function settings(array $stored = []): AppSettingsService
    {
        $repo = $this->createStub(AppSettingsRepositoryInterface::class);
        $repo->method('all')->willReturn($stored);

        return new AppSettingsService($repo);
    }

    /** @return array<string, mixed> */
    private function fileConfig(): array
    {
        return ['driver' => 'native', 'from' => ['address' => 'noreply@example.com', 'name' => 'Kintai']];
    }

    public function testWithoutSettingTheFileConfigurationApplies(): void
    {
        $this->assertSame($this->fileConfig(), $this->settings()->mailConfig($this->fileConfig()));
    }

    public function testOwnerAddressAndNameOverrideTheFile(): void
    {
        $config = $this->settings([
            'mail_from_address' => 'rapports@monentreprise.fr',
            'mail_from_name'    => 'Mon entreprise',
        ])->mailConfig($this->fileConfig());

        $this->assertSame('rapports@monentreprise.fr', $config['from']['address']);
        $this->assertSame('Mon entreprise', $config['from']['name']);
        $this->assertSame('native', $config['driver']);
    }

    public function testAnInvalidStoredAddressIsIgnored(): void
    {
        $settings = $this->settings(['mail_from_address' => 'pas une adresse']);

        $this->assertSame('', $settings->mailFromAddress());
        $this->assertSame('noreply@example.com', $settings->mailConfig($this->fileConfig())['from']['address']);
    }

    public function testNameAloneKeepsTheFileAddress(): void
    {
        $config = $this->settings(['mail_from_name' => 'Équipe RH'])->mailConfig($this->fileConfig());

        $this->assertSame('noreply@example.com', $config['from']['address']);
        $this->assertSame('Équipe RH', $config['from']['name']);
    }

    public function testOwnerAddressMakesNativeMailConfigured(): void
    {
        $file = $this->settings()->mailConfig($this->fileConfig());
        $owner = $this->settings(['mail_from_address' => 'rapports@monentreprise.fr'])->mailConfig($this->fileConfig());

        $this->assertFalse((new MailerService($file))->isConfigured());
        $this->assertTrue((new MailerService($owner))->isConfigured());
    }
}
