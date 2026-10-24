#!/usr/bin/env bash
# Test de fumée : parcours des pages d'une instance de démonstration AVEC les 12 bundles officiels.
#
# Chaque bundle est cloné depuis sa branche (BUNDLES_REF, develop par défaut), empaqueté au format zipball GitHub puis
# installé avec le vrai BundleInstallerService (manifeste, migrations, droits du gérant) ; tous sont ensuite activés.
# Les tests des bundles simulent les dépôts : un décalage entre code et schéma n'apparaît qu'ici.
# Il écrit dans le dépôt où il tourne : il refuse donc de s'exécuter hors CI.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if [ "${CI:-}" != "true" ]; then
  echo "Refus : à lancer en CI (CI=true), jamais sur une installation réelle." >&2
  exit 2
fi
if [ -e config/database.local.php ] || [ -e storage/installed.lock ]; then
  echo "Refus : une installation existe déjà dans $ROOT." >&2
  exit 2
fi

PORT="${SMOKE_PORT:-18768}"
BASE="http://127.0.0.1:$PORT"
REF="${BUNDLES_REF:-develop}"
WORK="$(mktemp -d)"
SERVER_PID=""
cleanup() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
  rm -rf "$WORK"
}
trap cleanup EXIT

# Source de vérité : la liste des bundles officiels du Core.
SLUGS=$(php -r '$s = require "config/official-bundles.php"; echo implode(" ", $s);' 2>/dev/null | tail -n 1)
[ -n "$SLUGS" ] || { echo "ÉCHEC : liste des bundles officiels introuvable" >&2; exit 1; }

EMAIL="crawl-bundles@kintai.test"
PASSWORD="$(php -r 'echo bin2hex(random_bytes(12));' 2>/dev/null | tail -n 1)"
DEMO_PASSWORD="Demo1234!"

php scripts/provision.php --admin-email="$EMAIL" --admin-password="$PASSWORD" --seed > "$WORK/provision.txt" 2>&1 \
  || { cat "$WORK/provision.txt" >&2; echo "ÉCHEC : provision.php a échoué" >&2; exit 1; }

for slug in $SLUGS; do
  git clone -q --depth 1 --branch "$REF" "https://github.com/AudricSan/kintai-bundle-$slug.git" "$WORK/src/kintai-bundle-$slug" \
    || { echo "ÉCHEC : clone de kintai-bundle-$slug ($REF)" >&2; exit 1; }
  # Format zipball GitHub : un dossier racine unique.
  (cd "$WORK/src" && rm -rf "kintai-bundle-$slug/.git" && php -r '
    $z = new ZipArchive(); $z->open($argv[2], ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1], FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if ($f->isFile()) { $p = str_replace("\\", "/", $f->getPathname()); $z->addFile($p, $p); } }
    $z->close();' "kintai-bundle-$slug" "$WORK/$slug.zip" 2>/dev/null)
  php scripts/ci/install-bundle-archive.php "$slug" "$WORK/$slug.zip" 2>&1 | grep -v "already loaded" | tee -a "$WORK/install.txt" \
    | tail -n 1 | grep -q "installé" || { cat "$WORK/install.txt" >&2; echo "ÉCHEC : installation de $slug" >&2; exit 1; }
done

# Activation de tous les bundles (le quota de la version gratuite ne s'applique pas à ce test).
php -r '
  $d = new PDO("sqlite:" . $argv[1]);
  $st = $d->prepare("INSERT INTO app_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
  $st->execute(["enabled_bundles", json_encode(explode(" ", $argv[2]))]);' "$ROOT/storage/app/database.sqlite" "$SLUGS" 2>/dev/null

php -S "127.0.0.1:$PORT" -t public > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -s -o /dev/null "$BASE/login" && break
  sleep 0.2
done

php scripts/ci/crawl-smoke.php ${CRAWL_FLAGS:-} "$BASE" \
  "$EMAIL" "$PASSWORD" \
  alice.martin@kintai.local "$DEMO_PASSWORD" \
  chloe.dupont@kintai.local "$DEMO_PASSWORD" \
  || { echo "--- journal du serveur ---" >&2; tail -n 40 "$WORK/server.log" >&2; exit 1; }
