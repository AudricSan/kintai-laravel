<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

/**
 * Disposition d'un tableau de bord choisie par l'utilisateur, sur une grille de 12 colonnes en mode « flux » : l'ordre
 * des widgets est l'ordre d'affichage, chacun a une largeur en colonnes (1 à 12) et une hauteur minimale en lignes
 * (1 à 6), et peut être replié. La grille place les widgets (grid-auto-flow: dense), sans position libre : aucun
 * chevauchement possible et un repli responsive trivial.
 *
 * Enregistrée par utilisateur et par tableau de bord (user_dashboard_prefs.layout) ; sans disposition enregistrée, les
 * widgets du Core viennent d'abord dans leur ordre, puis ceux des bundles, avec leur taille par défaut.
 *
 * Forme enregistrée : {"order": ["kpi_counters", …], "widths": {"pending_timeoff": 6, …}, "heights": {"kpi_counters": 2},
 * "collapsed": ["shifts_today"], "density": "compact"}. Seuls « order » et « widths » existaient à l'origine, avec les
 * largeurs "half" (6 colonnes) et "full" (12) : ces valeurs restent lues telles quelles, aucune migration n'est nécessaire.
 * Les autres clés ne sont présentes que si elles diffèrent du défaut.
 */
final class DashboardLayout
{
    public const HALF = 'half';
    public const FULL = 'full';

    public const COLUMNS = 12;
    public const MAX_ROWS = 6;

    /** Rafraîchissement automatique par défaut des widgets du Core, en secondes (absent = jamais, ex. graphiques). */
    public const CORE_REFRESH = [
        'kpi_counters'     => 60,
        'dashboard_alerts' => 120,
        'shifts_today'     => 120,
        'upcoming'         => 300,
        'emp_shifts_today' => 120,
        'emp_upcoming'     => 300,
    ];

    public const COMFORTABLE = 'comfortable';
    public const COMPACT = 'compact';

    /** Clé de widget : celle d'un widget (41 caractères au plus), éventuellement préfixée « emp_ » côté gérant. */
    public const KEY = '/^[a-z][a-z0-9_]{1,47}$/';

    /**
     * Largeur en colonnes d'une valeur enregistrée : « half » = 6, « full » = 12, un entier de 1 à 12 tel quel ;
     * toute autre valeur donne la pleine largeur.
     */
    public static function columns(mixed $width): int
    {
        if ($width === self::HALF) {
            return self::COLUMNS / 2;
        }
        $int = self::boundedInt($width, 1, self::COLUMNS);
        return $int ?? self::COLUMNS;
    }

    /**
     * Disposition nettoyée : clés bien formées et sans doublon, largeurs « half »/« full » ou 1 à 12, hauteurs 1 à 6,
     * widgets repliés, densité.
     *
     * @return array{order: list<string>, widths: array<string, string|int>, heights?: array<string, int>, collapsed?: list<string>, density?: string}
     */
    public static function normalize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $order = [];
        foreach (is_array($raw['order'] ?? null) ? $raw['order'] : [] as $key) {
            if (self::isKey($key) && !in_array($key, $order, true)) {
                $order[] = $key;
            }
        }
        $widths = [];
        foreach (is_array($raw['widths'] ?? null) ? $raw['widths'] : [] as $key => $width) {
            if (!self::isKey($key)) {
                continue;
            }
            if (in_array($width, [self::HALF, self::FULL], true)) {
                $widths[$key] = $width;
            } elseif (($int = self::boundedInt($width, 1, self::COLUMNS)) !== null) {
                $widths[$key] = $int;
            }
        }
        $layout = ['order' => $order, 'widths' => $widths];

        $heights = [];
        foreach (is_array($raw['heights'] ?? null) ? $raw['heights'] : [] as $key => $height) {
            if (self::isKey($key) && ($int = self::boundedInt($height, 1, self::MAX_ROWS)) !== null) {
                $heights[$key] = $int;
            }
        }
        if ($heights !== []) {
            $layout['heights'] = $heights;
        }

        $collapsed = [];
        foreach (is_array($raw['collapsed'] ?? null) ? $raw['collapsed'] : [] as $key) {
            if (self::isKey($key) && !in_array($key, $collapsed, true)) {
                $collapsed[] = $key;
            }
        }
        if ($collapsed !== []) {
            $layout['collapsed'] = $collapsed;
        }

        if (($raw['density'] ?? null) === self::COMPACT) {
            $layout['density'] = self::COMPACT;
        }
        return $layout;
    }

    /**
     * Disposition à afficher : ordre, largeur, hauteur et état replié de chaque widget proposé.
     *
     * @param list<string>          $keys     Widgets proposés, dans l'ordre par défaut (Core puis bundles)
     * @param array<string, mixed>|null $layout Disposition enregistrée
     * @param array<string, string|int> $defaults Largeurs par défaut
     * @param array<string, int>    $defaultHeights Hauteurs par défaut (en lignes)
     * @return array{order: list<string>, widths: array<string, string|int>, heights: array<string, int>, collapsed: list<string>, density: string}
     */
    public static function resolve(array $keys, ?array $layout, array $defaults = [], array $defaultHeights = []): array
    {
        $heights = [];
        foreach ($keys as $key) {
            $heights[$key] = $layout['heights'][$key] ?? $defaultHeights[$key] ?? 1;
        }
        return [
            'order'     => self::order($keys, $layout),
            'widths'    => self::widths($keys, $layout, $defaults),
            'heights'   => $heights,
            'collapsed' => array_values(array_intersect($layout['collapsed'] ?? [], $keys)),
            'density'   => ($layout['density'] ?? null) === self::COMPACT ? self::COMPACT : self::COMFORTABLE,
        ];
    }

    /**
     * Ordre d'affichage des widgets proposés : ordre enregistré pour ceux qu'il connaît, puis les autres dans l'ordre
     * par défaut (un widget apparu depuis, par exemple d'un bundle installé ensuite, arrive en fin de page).
     *
     * @param list<string> $keys Widgets proposés, dans l'ordre par défaut (Core puis bundles)
     * @param array<string, mixed>|null $layout
     * @return list<string>
     */
    public static function order(array $keys, ?array $layout): array
    {
        $saved = array_values(array_intersect($layout['order'] ?? [], $keys));
        return array_values(array_merge($saved, array_diff($keys, $saved)));
    }

    /**
     * Largeur de chaque widget proposé : choix de l'utilisateur, sinon la largeur par défaut, sinon pleine largeur.
     *
     * @param list<string>           $keys
     * @param array<string, mixed>|null $layout
     * @param array<string, string|int> $defaults Largeurs par défaut (ex. deux widgets du Core côte à côte)
     * @return array<string, string|int>
     */
    public static function widths(array $keys, ?array $layout, array $defaults = []): array
    {
        $widths = [];
        foreach ($keys as $key) {
            $widths[$key] = $layout['widths'][$key] ?? $defaults[$key] ?? self::FULL;
        }
        return $widths;
    }

    /**
     * Nouvelle disposition après une modification faite sur le tableau de bord. La page n'envoie que les widgets
     * affichés : ceux qui ne le sont pas (désactivés, sans permission, bundle désactivé) gardent leur place, les
     * widgets affichés reprennent les places qu'ils occupaient, dans leur nouvel ordre.
     *
     * @param list<string>          $keys     Widgets proposés, dans l'ordre par défaut
     * @param array<string, mixed>|null $previous
     * @param list<string>          $visible  Widgets affichés, dans l'ordre choisi
     * @param array<string, mixed>  $widths   Largeurs choisies pour les widgets affichés
     * @param array{heights?: mixed, collapsed?: mixed, density?: mixed} $extra Hauteurs, widgets repliés et densité
     *                                        envoyés par la page ; une clé absente laisse la valeur enregistrée
     * @return array{order: list<string>, widths: array<string, string|int>, heights?: array<string, int>, collapsed?: list<string>, density?: string}
     */
    public static function update(array $keys, ?array $previous, array $visible, array $widths, array $extra = []): array
    {
        $previous = self::normalize($previous);
        $submitted = self::normalize(['order' => $visible, 'widths' => $widths]);
        $visible = array_values(array_intersect($submitted['order'], $keys));

        // Ordre complet connu : celui enregistré (y compris les widgets d'un bundle désactivé), complété par les
        // widgets proposés qu'il ne connaît pas encore.
        $full = array_values(array_unique(array_merge($previous['order'], $keys)));
        $queue = $visible;
        $order = [];
        foreach ($full as $key) {
            $order[] = in_array($key, $visible, true) ? array_shift($queue) : $key;
        }

        $newWidths = $previous['widths'];
        foreach ($submitted['widths'] as $key => $width) {
            if (in_array($key, $keys, true)) {
                $newWidths[$key] = $width;
            }
        }
        $layout = ['order' => $order, 'widths' => $newWidths, 'heights' => $previous['heights'] ?? [], 'collapsed' => $previous['collapsed'] ?? []];

        if (array_key_exists('heights', $extra)) {
            $incoming = self::normalize(['heights' => $extra['heights']])['heights'] ?? [];
            foreach ($incoming as $key => $height) {
                if (in_array($key, $keys, true)) {
                    $layout['heights'][$key] = $height;
                }
            }
        }
        if (array_key_exists('collapsed', $extra)) {
            // La page envoie les widgets repliés parmi ceux qu'elle affiche : les autres gardent leur état.
            $incoming = self::normalize(['collapsed' => $extra['collapsed']])['collapsed'] ?? [];
            $hiddenKept = array_values(array_diff($layout['collapsed'], $visible));
            $layout['collapsed'] = array_values(array_unique(array_merge($hiddenKept, array_intersect($incoming, $visible))));
        }
        $layout['density'] = array_key_exists('density', $extra) ? $extra['density'] : ($previous['density'] ?? null);

        return self::normalize($layout);
    }

    /**
     * Disposition après l'ajout d'un widget au tableau de bord : il passe en dernière position, les autres widgets
     * gardent leur place et leur taille.
     *
     * @param list<string> $keys Widgets proposés, dans l'ordre par défaut
     * @param array<string, mixed>|null $previous
     * @return array<string, mixed>
     */
    public static function moveToEnd(array $keys, ?array $previous, string $key): array
    {
        $previous = self::normalize($previous);
        $order = array_values(array_diff(array_unique(array_merge($previous['order'], $keys)), [$key]));
        $order[] = $key;
        // Le widget ajouté n'est pas replié, même s'il l'était avant son retrait.
        $previous['collapsed'] = array_values(array_diff($previous['collapsed'] ?? [], [$key]));
        return self::normalize(['order' => $order] + $previous);
    }

    private static function boundedInt(mixed $value, int $min, int $max): ?int
    {
        if (is_string($value) && preg_match('/^\d{1,2}$/', $value) === 1) {
            $value = (int) $value;
        }
        return is_int($value) && $value >= $min && $value <= $max ? $value : null;
    }

    private static function isKey(mixed $key): bool
    {
        return is_string($key) && preg_match(self::KEY, $key) === 1;
    }
}
