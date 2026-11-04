<?php

namespace App\Console\Commands;

use App\Push\VapidKeys;
use Illuminate\Console\Command;

/**
 * Génère la paire de clés VAPID de l'instance et l'écrit dans `.env`, comme `key:generate`.
 * Régénérer les clés invalide tous les abonnements Web Push existants (les navigateurs se réabonnent à leur prochaine visite).
 */
class GenerateWebPushKeysCommand extends Command
{
    protected $signature = 'kintai:webpush:keys
                            {--show : Display the keys instead of modifying the .env file}
                            {--force : Replace the keys if they already exist}';

    protected $description = 'Generate the VAPID key pair used for Web Push notifications';

    public function handle(): int
    {
        $keys = VapidKeys::generate();

        if ($keys === null) {
            $this->components->error('The VAPID keys could not be generated (is the openssl extension available?).');

            return self::FAILURE;
        }

        if ($this->option('show')) {
            $this->line('VAPID_PUBLIC_KEY='.$keys['public']);
            $this->line('VAPID_PRIVATE_KEY='.$keys['private']);

            return self::SUCCESS;
        }

        $path = $this->laravel->environmentFilePath();
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        if (! $this->option('force') && $this->hasKeys($contents)) {
            $this->components->error('VAPID keys already exist. Use --force to replace them (existing browser subscriptions will stop working).');

            return self::FAILURE;
        }

        $contents = $this->set($this->set($contents, 'VAPID_PUBLIC_KEY', $keys['public']), 'VAPID_PRIVATE_KEY', $keys['private']);

        file_put_contents($path, $contents);

        $this->components->info('VAPID keys set successfully.');

        return self::SUCCESS;
    }

    private function hasKeys(string $env): bool
    {
        return preg_match('/^VAPID_(PUBLIC|PRIVATE)_KEY=\S+/m', $env) === 1
            || app(VapidKeys::class)->isConfigured();
    }

    private function set(string $env, string $name, string $value): string
    {
        $line = $name.'='.$value;

        if (preg_match('/^'.$name.'=.*$/m', $env) === 1) {
            return (string) preg_replace('/^'.$name.'=.*$/m', $line, $env);
        }

        return rtrim($env, "\r\n").($env === '' ? '' : "\n").$line."\n";
    }
}
