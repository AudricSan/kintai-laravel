<?php

namespace App\Actions\Bundles;

use App\Activity\ActivityLogger;
use App\Bundles\BundleException;
use App\Bundles\BundleManager;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Désactive un bundle (Owner seulement). Il disparaît du menu, des tableaux de bord, des droits et des routes, mais ses
 * tables et ses données sont conservées : le réactiver les retrouve.
 */
class DisableBundle
{
    public function __construct(
        private readonly BundleManager $bundles,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @return bool Vrai si l'état a changé.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, string $slug): bool
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        $bundle = $this->bundles->find($slug);

        try {
            $changed = $this->bundles->disable($slug);
        } catch (BundleException $e) {
            throw ValidationException::withMessages(['bundle' => $e->getMessage()]);
        }

        if ($changed) {
            $this->logger->record('bundle.disabled', null, null, [
                'bundle' => $slug,
                'name' => $bundle?->name(),
                'version' => $bundle?->version(),
            ], $actor);
        }

        return $changed;
    }
}
