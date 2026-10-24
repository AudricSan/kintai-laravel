<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Contract\Extension\ProfileSection;
use kintai\Core\Container;
use kintai\Core\Services\Log;
use kintai\UI\ViewRenderer;

/**
 * Sections du formulaire « Mes informations » du profil apportées par les bundles actifs (point d'extension
 * Bundle::profileSections(), contrat 1.12), par exemple les cases de l'annuaire de team-directory. Le Core n'en
 * affiche aucune de lui-même.
 */
final class ProfileSections
{
    /**
     * Colonnes de users qu'une section de bundle peut enregistrer. Les autres (identité, mot de passe, statut…)
     * restent sous le seul contrôle du Core, quelle que soit la déclaration du bundle.
     */
    public const WRITABLE_USER_COLUMNS = ['show_in_directory', 'share_email', 'share_phone', 'share_mobile_phone'];

    public function __construct(private readonly ?ExtensionRegistry $registry = null) {}

    /** Instance de la requête courante (registre des extensions), ou aucune section si indisponible. */
    public static function current(): self
    {
        try {
            $container = Container::getInstance();
            return new self($container->has(ExtensionRegistry::class) ? $container->make(ExtensionRegistry::class) : null);
        } catch (\Throwable) {
            return new self();
        }
    }

    /**
     * Sections à afficher, déclarées par les bundles actifs, triées par ordre. Une clé déjà prise n'est pas reprise.
     *
     * @return list<ProfileSection>
     */
    public function sections(): array
    {
        $all = [];
        foreach ($this->registry?->profileSectionsByBundle() ?? [] as $declared) {
            array_push($all, ...$declared);
        }

        $sections = [];
        foreach ($all as $section) {
            $sections[$section->key] ??= $section;
        }
        $sections = array_values($sections);
        usort($sections, static fn(ProfileSection $a, ProfileSection $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);
        return $sections;
    }

    /**
     * Cases de users que le formulaire du profil peut enregistrer : celles des sections affichées, limitées aux colonnes
     * ouvertes aux bundles (une autre colonne est ignorée et journalisée).
     *
     * @return list<string>
     */
    public function flags(): array
    {
        $flags = [];
        foreach ($this->sections() as $section) {
            foreach ($section->flags as $flag) {
                if (in_array($flag, self::WRITABLE_USER_COLUMNS, true)) {
                    $flags[$flag] = true;
                } else {
                    $this->log('bundle_extension_invalid', ['point' => 'profileSections', 'key' => $section->key, 'flag' => $flag]);
                }
            }
        }
        return array_keys($flags);
    }

    /**
     * HTML des sections pour l'utilisateur connecté. Une section dont la vue lève une exception est retirée
     * (journalisée).
     *
     * @param array<string, mixed> $user
     * @return list<string>
     */
    public function render(array $user, ViewRenderer $view): array
    {
        $html = [];
        foreach ($this->sections() as $section) {
            $level = ob_get_level();
            try {
                $html[] = $view->renderPartial($section->view, ['user' => $user]);
            } catch (\Throwable $e) {
                // Une vue qui lève une exception laisse ouvert le tampon de sortie de renderPartial.
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
                $this->log('bundle_extension_failed', ['point' => 'profileSections', 'key' => $section->key, 'error' => $e->getMessage()]);
            }
        }
        return $html;
    }

    /** @param array<string, mixed> $context */
    private function log(string $event, array $context): void
    {
        try {
            Log::warning($event, $context);
        } catch (\Throwable) {
        }
    }
}
