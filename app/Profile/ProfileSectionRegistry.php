<?php

namespace App\Profile;

use App\Models\User;
use Closure;
use InvalidArgumentException;

/**
 * Point d'extension : le corps et les bundles y déclarent les pages de la zone « réglages personnels ».
 * Le libellé est une clé de traduction, traduite à l'affichage.
 */
class ProfileSectionRegistry
{
    /** @var array<string, ProfileSection> */
    private array $sections = [];

    /**
     * @param  (Closure(User): bool)|null  $visible  Absent : toujours visible.
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
    public function sections(User $user): array
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
