<?php

namespace Kintai\Contracts\Backups;

/**
 * Point d'extension (depuis le contrat 1.4) : les dossiers de fichiers téléversés que le Core inclut dans les
 * sauvegardes et remet en place à la restauration, comme il le fait pour les avatars.
 *
 * Un dossier est un sous-dossier direct du disque privé `local` (jamais accessible par le web), qui ne contient que des
 * fichiers image à plat (jpg, jpeg, png, gif, webp ; noms de lettres, chiffres, point, tiret et tiret bas) : le Core
 * refuse à la restauration toute autre entrée, et ne restaure que les dossiers déclarés. Dans l'archive, ses fichiers
 * sont rangés sous `files/<clé>/`.
 *
 * La déclaration vaut aussi bien bundle activé que désactivé : désactiver un bundle ne supprime aucune donnée, ses
 * fichiers restent donc sauvegardés.
 */
interface BackupFileRegistry
{
    /**
     * Déclare (ou redéclare à l'identique) un dossier.
     *
     * @param  string  $key  Identifiant stable, minuscules, chiffres et tirets (1 à 40 caractères) ; `avatars` est réservé au Core.
     * @param  string  $directory  Sous-dossier du disque privé `local` (minuscules, chiffres, tiret et tiret bas ; pas de chemin).
     *
     * @throws \InvalidArgumentException Clé ou dossier invalide, clé réservée, clé déjà déclarée pour un autre dossier.
     */
    public function register(string $key, string $directory): void;

    /**
     * @return array<string, string> clé => sous-dossier, dans l'ordre de déclaration
     */
    public function all(): array;
}
