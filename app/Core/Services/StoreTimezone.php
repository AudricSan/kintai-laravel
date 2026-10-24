<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Fuseau horaire d'un magasin : toujours un identifiant IANA valide (Asia/Tokyo, Europe/Paris…).
 *
 * Le champ du formulaire de magasin était un texte libre. Une valeur comme « UTC+9 » n'est pas un fuseau pour Google Agenda ni
 * pour PHP (DateTimeZone refuse « UTC+9 ») : l'événement partait alors en UTC et s'affichait avec le décalage du pays, un shift de
 * 06:00 à 15:00 apparaissant à 15:00 à Tokyo. normalize() reconnaît ces écritures courantes (« UTC+9 », « GMT-05:30 », « JST »…)
 * et les convertit ; resolve() ne renvoie jamais autre chose qu'un identifiant valide.
 */
final class StoreTimezone
{
    /** Abréviations courantes qui désignent un seul fuseau sans ambiguïté pour l'usage de l'application. */
    private const ABBREVIATIONS = ['JST' => 'Asia/Tokyo', 'KST' => 'Asia/Seoul', 'CET' => 'Europe/Paris', 'CEST' => 'Europe/Paris', 'UTC' => 'UTC', 'GMT' => 'UTC'];

    /**
     * Identifiant IANA équivalent, ou null si la valeur n'est pas reconnaissable.
     */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (in_array($value, \DateTimeZone::listIdentifiers(), true)) {
            return $value;
        }
        // Même identifiant écrit avec une autre casse (« asia/tokyo »).
        foreach (\DateTimeZone::listIdentifiers() as $identifier) {
            if (strcasecmp($identifier, $value) === 0) {
                return $identifier;
            }
        }
        if (isset(self::ABBREVIATIONS[strtoupper($value)])) {
            return self::ABBREVIATIONS[strtoupper($value)];
        }

        // « UTC+9 », « UTC +09:00 », « GMT-5 », « +09:00 » : un décalage fixe, sans heure d'été.
        if (preg_match('/^(?:UTC|GMT)?\s*([+-])\s*(\d{1,2})(?::?(\d{2}))?$/i', $value, $m) !== 1) {
            return null;
        }
        $hours   = (int) $m[2];
        $minutes = (int) ($m[3] ?? 0);
        if ($hours > 14 || $minutes > 59) {
            return null;
        }
        $sign = $m[1];
        if ($hours === 9 && $minutes === 0 && $sign === '+') {
            return 'Asia/Tokyo';
        }
        if ($minutes === 0) {
            // Etc/GMT inverse le signe (Etc/GMT-9 est UTC+9) ; Etc/GMT+0 n'existe pas, c'est UTC.
            return $hours === 0 ? 'UTC' : 'Etc/GMT' . ($sign === '+' ? '-' : '+') . $hours;
        }

        // Décalage à la demi-heure (+05:30, +09:30…) : Google comme PHP acceptent « +05:30 » comme fuseau fixe.
        return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
    }

    /**
     * Identifiant valide à utiliser : le fuseau normalisé, sinon celui de l'instance (APP_TIMEZONE), jamais un texte inconnu.
     */
    public static function resolve(?string $value): string
    {
        return self::normalize($value) ?? date_default_timezone_get();
    }
}
