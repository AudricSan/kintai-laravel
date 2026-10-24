<?php

declare(strict_types=1);

/**
 * Parcours GET d'une instance de démonstration, un compte par rôle, en suivant les liens internes.
 *
 * Usage : php scripts/ci/crawl-smoke.php [--forms] <url-de-base> <e-mail> <mot-de-passe> [<e-mail> <mot-de-passe> ...]
 *
 * --forms : soumet aussi, une fois par action, chaque formulaire POST trouvé (champs remplis de valeurs plausibles,
 * jeton CSRF conservé). Une réponse 4xx (refus, validation) est normale ; seuls 5xx et erreurs PHP échouent. Les actions
 * qui détruiraient la session ou l'instance de test sont ignorées (SKIP_FORMS).
 *
 * Échoue si une page répond 5xx, ou affiche une erreur PHP (Fatal error, Warning, Notice, Stack trace). Les tests
 * unitaires simulent les dépôts et ne voient pas ces pannes (schéma, vue, droit) : seul un vrai parcours les trouve.
 * Une page par « motif » d'URL (identifiants remplacés) ne suffit pas, d'où MAX_PER_PATTERN exemplaires.
 */

const MAX_PER_PATTERN = 3;
const MAX_DEPTH       = 4;
// Actions sans page à vérifier, flux continus, exports binaires, ressources statiques et routes à effet de bord.
const SKIP = '#^/(logout|cron/|api/|ical/|bundle-assets/|sw\.js|manifest\.json|lang/|assets/|storage/)|/stream$|/export|/download|\.ics$|/pdf|/print|/backup|/docs/#';

const SKIP_FORMS = '#/(logout|login|install|reset|restore|backup|uninstall|update|delete-account)|/bundles/|/language|/password#';

$args  = array_slice($argv, 1);
$forms = in_array('--forms', $args, true);
$args  = array_values(array_diff($args, ['--forms']));
$base  = $args[0] ?? null;
$accounts = array_chunk(array_slice($args, 1), 2);
if ($base === null || $accounts === [] || count($accounts[count($accounts) - 1]) !== 2) {
    fwrite(STDERR, "Usage : crawl-smoke.php <url-de-base> <e-mail> <mot-de-passe> [...]\n");
    exit(2);
}
$base = rtrim($base, '/');

/** @return array{0:int,1:string,2:string} statut, corps, en-tête Location */
function request(string $url, string $jar, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_TIMEOUT        => 60,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $size);
    $loc  = preg_match('/^Location:\s*(\S+)/mi', $head, $m) ? $m[1] : '';
    return [$status, substr($raw, $size), $loc];
}

function pathOf(string $base, string $url): ?string
{
    if (str_starts_with($url, $base)) {
        $url = substr($url, strlen($base));
    }
    return str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : null;
}

/** Champs d'un formulaire HTML avec une valeur plausible : [nom => valeur]. */
function formFields(string $form): array
{
    $out = [];
    if (preg_match_all('#<(input|textarea|select)\b([^>]*)>#i', $form, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($tags as $t) {
            $attrs = $t[2][0];
            if (!preg_match('/\bname="([^"]+)"/', $attrs, $n) || preg_match('/\bdisabled\b/', $attrs)) {
                continue;
            }
            $name  = html_entity_decode($n[1]);
            $type  = preg_match('/\btype="([^"]*)"/i', $attrs, $ty) ? strtolower($ty[1]) : 'text';
            $value = preg_match('/\bvalue="([^"]*)"/', $attrs, $v) ? html_entity_decode($v[1]) : '';
            $kind  = strtolower($t[1][0]);
            if ($kind === 'select') {
                $rest = substr($form, $t[0][1] + strlen($t[0][0]));
                $body = explode('</select>', $rest, 2)[0];
                $pick = '';
                if (preg_match('/<option\b[^>]*\bselected\b[^>]*\bvalue="([^"]*)"/', $body, $o)
                    || preg_match('/<option\b[^>]*\bvalue="([^"]*)"[^>]*\bselected\b/', $body, $o)) {
                    $pick = $o[1];
                } elseif (preg_match_all('/<option\b[^>]*\bvalue="([^"]+)"/', $body, $o)) {
                    $pick = $o[1][0];
                }
                $out[$name] = html_entity_decode($pick);
            } elseif ($kind === 'textarea') {
                $out[$name] = 'Test';
            } elseif (in_array($type, ['submit', 'button', 'image', 'reset', 'file'], true)) {
                continue;
            } elseif (in_array($type, ['checkbox', 'radio'], true)) {
                if (preg_match('/\bchecked\b/', $attrs)) {
                    $out[$name] = $value !== '' ? $value : 'on';
                }
            } elseif ($type === 'hidden' || $value !== '') {
                $out[$name] = $value;
            } else {
                $out[$name] = match ($type) {
                    'email'  => 'test@example.com',
                    'number', 'range' => '1',
                    'date'   => date('Y-m-d'),
                    'time'   => '10:00',
                    'month'  => date('Y-m'),
                    'url'    => 'https://example.com',
                    'tel'    => '0300000000',
                    'password' => 'Test1234!x',
                    default  => 'Test',
                };
            }
        }
    }
    return $out;
}

$failures = [];
foreach ($accounts as [$email, $password]) {
    $jar = tempnam(sys_get_temp_dir(), 'crawl');
    [, $page] = request($base . '/login', $jar);
    if (!preg_match('/name="(_token|csrf_token|_csrf)"[^>]*value="([^"]+)"/', $page, $t)) {
        fwrite(STDERR, "Jeton CSRF introuvable sur /login\n");
        exit(1);
    }
    request($base . '/login', $jar, [$t[1] => $t[2], 'login_mode' => 'email', 'email' => $email, 'password' => $password]);

    $seen = $perPattern = $postedForms = [];
    $sent = 0;
    $frontier = ['/'];
    $count = 0;
    for ($depth = 0; $depth < MAX_DEPTH && $frontier !== []; $depth++) {
        $next = [];
        foreach ($frontier as $path) {
            $pattern = preg_replace('#/\d+#', '/{n}', explode('?', $path)[0]);
            if (isset($seen[$path]) || preg_match(SKIP, $path) || ($perPattern[$pattern] ?? 0) >= MAX_PER_PATTERN) {
                continue;
            }
            $seen[$path] = true;
            $perPattern[$pattern] = ($perPattern[$pattern] ?? 0) + 1;
            [$status, $body, $loc] = request($base . $path, $jar);
            $count++;

            if ($status >= 500 || $status === 0) {
                $failures[] = "[$email] $path → $status";
            } elseif (preg_match('/(Fatal error|Parse error|Warning|Notice|Deprecated):|Stack trace:/', $body, $m)) {
                $failures[] = "[$email] $path → erreur PHP affichée ({$m[0]})";
            }
            if ($status >= 300 && $status < 400 && ($p = pathOf($base, $loc)) !== null) {
                $next[] = $p;
            }
            if ($forms && $status === 200 && preg_match_all('#<form\b([^>]*)>(.*?)</form>#is', $body, $fs, PREG_SET_ORDER)) {
                foreach ($fs as $f) {
                    if (!preg_match('/\bmethod="post"/i', $f[1])) {
                        continue;
                    }
                    $action = preg_match('/\baction="([^"]*)"/', $f[1], $a) ? html_entity_decode($a[1]) : $path;
                    $target = pathOf($base, $action === '' ? $path : $action);
                    if ($target === null || preg_match(SKIP_FORMS, $target)) {
                        continue;
                    }
                    $key = preg_replace('#/\d+#', '/{n}', explode('?', $target)[0]);
                    if (isset($postedForms[$key])) {
                        continue;
                    }
                    $postedForms[$key] = true;
                    [$ps, $pb] = request($base . $target, $jar, formFields($f[2]));
                    $sent++;
                    if ($ps >= 500 || $ps === 0) {
                        $failures[] = "[$email] POST $target (depuis $path) → $ps";
                    } elseif (preg_match('/(Fatal error|Parse error|Warning|Notice|Deprecated):|Stack trace:/', $pb, $m)) {
                        $failures[] = "[$email] POST $target (depuis $path) → erreur PHP affichée ({$m[0]})";
                    }
                }
            }
            if ($status === 200 && preg_match_all('/href="([^"#]*)"/', $body, $links)) {
                foreach ($links[1] as $href) {
                    if (($p = pathOf($base, html_entity_decode($href))) !== null) {
                        $next[] = $p;
                    }
                }
            }
        }
        $frontier = $next;
    }
    echo "$email : $count pages parcourues" . ($forms ? ", $sent formulaires soumis" : "") . "\n";
    @unlink($jar);
}

if ($failures !== []) {
    fwrite(STDERR, "ÉCHEC : " . count($failures) . " page(s) en erreur\n  " . implode("\n  ", array_unique($failures)) . "\n");
    exit(1);
}
echo "OK : aucune page en erreur.\n";
