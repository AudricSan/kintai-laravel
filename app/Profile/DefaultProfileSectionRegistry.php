<?php

namespace App\Profile;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Kintai\Contracts\Profile\ProfileSection;
use Kintai\Contracts\Profile\ProfileSectionRegistry;

/**
 * Registre en mémoire (voir l'interface du contrat pour le mode d'emploi).
 */
class DefaultProfileSectionRegistry implements ProfileSectionRegistry
{
    /** @var array<string, ProfileSection> */
    private array $sections = [];

    /**
     * @param  (Closure(Authenticatable): bool)|null  $visible  Absent : toujours visible.
     */
    public function register(string $key, string $label, string $route, int $order = 100, ?Closure $visible = null): void
    {
        if (isset($this->sections[$key])) {
            throw new InvalidArgumentException("Profile section [{$key}] is already registered.");
        }

        $this->sections[$key] = new ProfileSection($key, $label, $route, $order, $visible);
    }

    /**
     * Sections visibles pour l'utilisateur, triées par ordre puis par ordre d'enregistrement.
     *
     * @return list<ProfileSection>
     */
    public function sections(Authenticatable $user): array
    {
        $sections = array_values(array_filter(
            $this->sections,
            fn (ProfileSection $section): bool => $section->isVisibleTo($user),
        ));

        // usort est stable depuis PHP 8 : à ordre égal, l'ordre d'enregistrement est conservé.
        usort($sections, fn (ProfileSection $a, ProfileSection $b): int => $a->order <=> $b->order);

        return $sections;
    }
}
