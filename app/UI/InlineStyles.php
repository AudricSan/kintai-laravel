<?php

declare(strict_types=1);

namespace kintai\UI;

/**
 * Styles dynamiques sans attribut style="…" (dette de l'audit n°8) : une couleur de shift, une largeur de barre, une
 * hauteur de graphique… sont enregistrées ici comme une règle de classe, et toutes les règles de la page sont émises
 * dans un seul <style nonce="…"> (voir inline_styles()). La Content-Security-Policy refuse les styles en ligne
 * (pas de 'unsafe-inline' dans style-src) sans casser l'affichage, y compris à l'impression et dans les aperçus PDF (mPDF comprend les
 * classes ; il n'exécute aucun JavaScript).
 *
 * Usage dans une vue : <div class="bar <?= style_class(['height' => $h . 'px', 'background' => $color]) ?>">.
 * Le layout émet les règles dans son <head> (il est rendu après le contenu) ; une page autonome (impression, aperçu
 * PDF) appelle inline_styles() avant </body>.
 *
 * Les valeurs sont filtrées : une valeur qui contient autre chose que des caractères de valeur CSS sûrs (lettres,
 * chiffres, #, %, ., ,, -, espaces, parenthèses) est ignorée, si bien qu'aucune donnée (couleur saisie, nom) ne peut
 * fermer la règle ni en injecter une autre.
 */
final class InlineStyles
{
    /** @var array<string, string> nom de classe => déclarations */
    private static array $rules = [];

    /** @param array<string, string|int|float|null> $declarations propriété CSS (ou --variable) => valeur */
    public static function classFor(array $declarations): string
    {
        $css = self::declarations($declarations);
        if ($css === '') {
            return '';
        }
        $class = 's-' . substr(hash('sha1', $css), 0, 10);
        self::$rules[$class] = $css;
        return $class;
    }

    /** Déclarations sûres sous forme de texte CSS (« background:#fff;height:12px »), propriétés invalides ignorées. */
    public static function declarations(array $declarations): string
    {
        $out = [];
        foreach ($declarations as $property => $value) {
            $property = strtolower(trim((string) $property));
            if ($value === null || !preg_match('/^(--[a-z0-9-]+|[a-z-]+)$/', $property)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '' || !preg_match('/^[#a-zA-Z0-9%., ()\-]+$/', $value)) {
                continue;
            }
            $out[] = $property . ':' . $value;
        }
        return implode(';', $out);
    }

    /** Règles enregistrées depuis le dernier appel, sous forme de feuille de style (sans balise). */
    public static function pull(): string
    {
        $css = '';
        foreach (self::$rules as $class => $declarations) {
            $css .= '.' . $class . '{' . $declarations . '}';
        }
        self::$rules = [];
        return $css;
    }

    /** Règle :root des variables du thème (priorité d'un style en ligne, d'où !important). */
    public static function rootVariables(string $inlineStyle): string
    {
        $out = [];
        foreach (explode(';', $inlineStyle) as $pair) {
            [$name, $value] = array_pad(explode(':', $pair, 2), 2, '');
            $name = trim($name);
            $value = trim($value);
            if (preg_match('/^--[a-z0-9-]+$/', $name) && $value !== '' && preg_match('/^[#a-zA-Z0-9%., ()\-]+$/', $value)) {
                $out[] = $name . ':' . $value . ' !important';
            }
        }
        return $out === [] ? '' : ':root{' . implode(';', $out) . '}';
    }
}
