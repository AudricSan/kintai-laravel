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
 * Active un bundle installé (Owner seulement). Refusé, avec un message clair, si le bundle exige une version du contrat
 * Kintai que cette installation ne fournit pas.
 */
class EnableBundle
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

        try {
            $changed = $this->bundles->enable($slug);
        } catch (BundleException $e) {
            throw ValidationException::withMessages(['bundle' => $e->getMessage()]);
        }

        if ($changed) {
            $bundle = $this->bundles->find($slug);

            $this->logger->record('bundle.enabled', null, null, [
                'bundle' => $slug,
                'name' => $bundle?->name(),
                'version' => $bundle?->version(),
            ], $actor);
        }

        return $changed;
    }
}
