<?php

namespace Kintai\Contracts\Stores;

/**
 * Un collègue vu par un utilisateur donné. Objet valeur de `ColleagueDirectory` : les coordonnées ne sont renseignées
 * que si le Core autorise cet utilisateur à les voir (partagées par le collègue, ou droit de gérer ses magasins).
 */
final readonly class Colleague
{
    /**
     * @param  array<int, string>  $stores  Magasins en commun avec l'utilisateur : identifiant => nom.
     * @param  string|null  $avatarUrl  Adresse de l'avatar ; null = pas d'avatar (initiales).
     * @param  string|null  $email  Null = non partagé (ou absent).
     * @param  string|null  $phone  Null = non partagé (ou absent).
     * @param  string|null  $mobilePhone  Null = non partagé (ou absent).
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $firstName,
        public string $lastName,
        public string $color,
        public ?string $avatarUrl,
        public array $stores,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $mobilePhone = null,
        public bool $isSelf = false,
    ) {}

    public function hasContact(): bool
    {
        return $this->email !== null || $this->phone !== null || $this->mobilePhone !== null;
    }

    public function initials(): string
    {
        $initials = mb_strtoupper(mb_substr($this->lastName, 0, 1).mb_substr($this->firstName, 0, 1));

        return $initials !== '' ? $initials : mb_strtoupper(mb_substr($this->name, 0, 1));
    }
}
