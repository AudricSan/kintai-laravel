<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Liens d'ajout en un clic d'un flux iCal (https://…/shifts.ics) aux agendas courants : abonnement `webcal://` (Apple
 * Calendrier, Thunderbird), « Ajouter à Google Calendar » et « Ajouter à Outlook ». Pur : aucune lecture de la requête, le
 * Core fournit l'URL publique déjà construite (sous-dossier et schéma respectés).
 *
 * Google relit un abonnement externe toutes les 12 à 24 h environ, quoi qu'annonce le flux (REFRESH-INTERVAL) : pour une mise
 * à jour rapide, c'est la liaison Google Calendar qu'il faut proposer (voir GoogleCalendarClient).
 */
final class CalendarSubscriptionLinks
{
    /**
     * @param string $feedUrl URL https (ou http en développement) du flux
     * @param string $name    nom de l'agenda proposé dans Outlook
     * @return array{https: string, webcal: string, google: string, outlook: string}
     */
    public static function for(string $feedUrl, string $name = 'Kintai'): array
    {
        $webcal = (string) preg_replace('#^https?://#i', 'webcal://', $feedUrl);

        return [
            'https'   => $feedUrl,
            'webcal'  => $webcal,
            'google'  => 'https://calendar.google.com/calendar/r?cid=' . rawurlencode($webcal),
            'outlook' => 'https://outlook.live.com/calendar/0/addfromweb?url=' . rawurlencode($feedUrl) . '&name=' . rawurlencode($name),
        ];
    }
}
