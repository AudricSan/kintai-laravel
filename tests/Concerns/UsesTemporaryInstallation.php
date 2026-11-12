<?php

namespace Tests\Concerns;

use App\Install\EnvironmentFile;
use App\Install\Installer;
use Illuminate\Support\Facades\File;

/**
 * Un dossier temporaire pour `.env`, la base SQLite et le verrou : l'installation de test ne touche jamais au vrai projet.
 */
trait UsesTemporaryInstallation
{
    protected string $installDirectory;

    protected function setUpInstallation(): Installer
    {
        $this->installDirectory = sys_get_temp_dir().'/kintai-install-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->installDirectory);
        File::copy(base_path('.env.example'), $this->installDirectory.'/.env.example');

        config(['kintai.install.lock' => $this->installDirectory.'/installed']);

        $installer = new Installer(
            new EnvironmentFile($this->installDirectory.'/.env', $this->installDirectory.'/.env.example'),
            null,
            $this->installDirectory.'/database.sqlite',
        );
        $this->app->instance(Installer::class, $installer);

        return $installer;
    }

    protected function tearDownInstallation(): void
    {
        if (isset($this->installDirectory)) {
            // Libère le fichier SQLite avant de supprimer le dossier (Windows).
            $this->app['db']->purge();
            File::deleteDirectory($this->installDirectory);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function ownerInput(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Aiko Owner',
            'email' => 'owner@example.test',
            'password' => 'Correct-Horse-Battery-9',
        ];
    }
}
