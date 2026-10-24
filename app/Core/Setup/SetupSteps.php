<?php

declare(strict_types=1);

namespace kintai\Core\Setup;

/**
 * Étapes de configuration de l'assistant qui se remplissent par un formulaire (organisation, magasin, e-mail,
 * notifications, exploitation), par identifiant. « Bundles » et « Licence » ont leur propre écran.
 */
final class SetupSteps
{
    /** @var array<string, SetupStepInterface> */
    private array $steps = [];

    public function __construct(
        OrganisationStep $organisation,
        StoreStep $store,
        MailStep $mail,
        NotificationsStep $notifications,
        OperationsStep $operations,
    ) {
        foreach ([$organisation, $store, $mail, $notifications, $operations] as $step) {
            $this->steps[$step->id()] = $step;
        }
    }

    public function get(string $id): ?SetupStepInterface
    {
        return $this->steps[$id] ?? null;
    }

    /** @return array<string, SetupStepInterface> */
    public function all(): array
    {
        return $this->steps;
    }
}
