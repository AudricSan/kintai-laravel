<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AppSetting;
use App\Services\VapidKeyStore;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\TestCase;

/** Clés VAPID de l'instance : création, conservation, contact transmis aux services de push. */
final class VapidKeyStoreTest extends TestCase
{
    protected function setUp(): void
    {
        kintai_test_settings();
    }

    private static function decode(string $base64Url): string
    {
        return (string) base64_decode(strtr($base64Url, '-_', '+/') . str_repeat('=', (4 - strlen($base64Url) % 4) % 4), true);
    }

    public function testGenerateProducesAValidP256KeyPair(): void
    {
        $pair = VapidKeyStore::generate();

        $this->assertNotNull($pair, 'openssl doit savoir créer une clé P-256 (avec le repli config/openssl.cnf au besoin)');
        $this->assertSame(65, strlen(self::decode($pair['public'])), 'point non compressé : 0x04 + X + Y');
        $this->assertSame("\x04", self::decode($pair['public'])[0]);
        $this->assertSame(32, strlen(self::decode($pair['private'])));
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $pair['public'] . $pair['private'], 'base64 URL sans remplissage');
        // La bibliothèque d'envoi accepte cette paire.
        $vapid = VAPID::validate(['subject' => 'mailto:a@b.example', 'publicKey' => $pair['public'], 'privateKey' => $pair['private']]);
        $this->assertSame(self::decode($pair['public']), $vapid['publicKey']);
    }

    public function testEveryGenerationIsDifferent(): void
    {
        $this->assertNotSame(VapidKeyStore::generate()['private'], VapidKeyStore::generate()['private']);
    }

    public function testEnsureCreatesTheKeysOnceAndKeepsThem(): void
    {
        $store = new VapidKeyStore();
        $this->assertNull($store->publicKey());
        $this->assertFalse($store->isAvailable());

        $this->assertTrue($store->ensure());
        $public  = $store->publicKey();
        $private = $store->privateKey();
        $this->assertNotNull($public);
        $this->assertNotNull($private);
        $this->assertTrue($store->isAvailable());

        $this->assertTrue($store->ensure());
        $this->assertSame($public, $store->publicKey(), 'ensure() ne remplace jamais une paire existante');
        $this->assertSame($private, $store->privateKey());
    }

    public function testRegenerateReplacesThePair(): void
    {
        $store = new VapidKeyStore();
        $store->ensure();
        $before = $store->publicKey();

        $this->assertTrue($store->regenerate());

        $this->assertNotSame($before, $store->publicKey());
    }

    public function testNotAvailableWithOnlyOneKey(): void
    {
        AppSetting::put(VapidKeyStore::PUBLIC_KEY, 'public');

        $this->assertFalse((new VapidKeyStore())->isAvailable());
    }

    public function testSubjectPrefersExplicitSettingThenSupportEmailThenPublicUrl(): void
    {
        $store = new VapidKeyStore();
        $this->assertSame('https://github.com/AudricSan/Kintai', $store->subject(), 'contact de repli');

        AppSetting::put('app_public_url', 'https://kintai.example');
        $this->assertSame('https://kintai.example', $store->subject());

        AppSetting::put('app_public_url', 'http://pas-https.example');
        $this->assertSame('https://github.com/AudricSan/Kintai', $store->subject(), 'une URL non HTTPS n\'est pas un contact valide');

        AppSetting::put('app_support_email', 'support@kintai.example');
        $this->assertSame('mailto:support@kintai.example', $store->subject());

        AppSetting::put('app_support_email', 'pas-un-email');
        $this->assertSame('https://github.com/AudricSan/Kintai', $store->subject());

        AppSetting::put(VapidKeyStore::SUBJECT, 'mailto:owner@kintai.example');
        $this->assertSame('mailto:owner@kintai.example', $store->subject());
    }

    public function testMissingExtensionsListsOnlyWhatIsNotLoaded(): void
    {
        $missing = VapidKeyStore::missingExtensions();

        foreach ($missing as $ext) {
            $this->assertFalse(extension_loaded($ext));
        }
        $this->assertSame([], $missing, 'openssl, curl et mbstring sont requis par la CI');
    }
}
