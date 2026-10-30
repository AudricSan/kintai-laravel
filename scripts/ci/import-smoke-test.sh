#!/usr/bin/env bash
# Test de fumée : importation d'une instance Kintai existante par le vrai installateur web (mode « importer »).
#
# Usage : CI=true bash scripts/ci/import-smoke-test.sh <dossier de l'instance à importer> <e-mail Owner> <mot de passe>
#         [<e-mail> <mot de passe>]…
#
# L'instance à importer est déjà installée (en CI : l'ancien Kintai, AudricSan/Kintai, installé par son
# kintai:provision avec les données de démonstration). Le script lance `php -S` sur public/, soumet le formulaire
# de public/install.php en mode importation, puis vérifie que l'installation est verrouillée, que toutes les
# migrations sont appliquées, que les comptes actifs sont tous repris, que la base d'origine n'a pas changé, et que
# chaque compte donné peut se connecter et parcourir ses pages sans erreur (scripts/ci/crawl-smoke.php).
#
# Il écrit dans le dépôt où il tourne (config/database.local.php, storage/, .env) : il refuse donc de s'exécuter
# hors CI, ou si une installation existe déjà.
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
if [ "$#" -lt 3 ]; then
  echo "Usage : $0 <dossier de l'instance à importer> <e-mail Owner> <mot de passe> [<e-mail> <mot de passe>]…" >&2
  exit 2
fi

SOURCE="$(cd "$1" && pwd)"
shift
SOURCE_DB="$SOURCE/storage/app/database.sqlite"
[ -f "$SOURCE_DB" ] || { echo "Base SQLite introuvable : $SOURCE_DB" >&2; exit 2; }

PORT="${SMOKE_PORT:-18766}"
BASE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d)"
SERVER_PID=""
cleanup() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
  rm -rf "$WORK"
}
trap cleanup EXIT

fail() {
  echo "ÉCHEC : $1" >&2
  echo "--- journal du serveur ---" >&2
  tail -n 40 "$WORK/server.log" >&2 || true
  exit 1
}

# Dernière ligne seulement : certains PHP affichent des avertissements de démarrage sur la sortie standard.
sql() { php -r '$d = new PDO("sqlite:" . $argv[1]); echo $d->query($argv[2])->fetchColumn(), PHP_EOL;' "$1" "$2" 2>/dev/null | tail -n 1 | tr -d '[:space:]'; }

source_hash=$(sha1sum "$SOURCE_DB" | cut -d' ' -f1)
source_users=$(sql "$SOURCE_DB" "SELECT COUNT(*) FROM users WHERE is_active = 1")

mkdir -p storage/app storage/logs
php -S "127.0.0.1:$PORT" -t public > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -s -o /dev/null "$BASE/install.php" && break
  sleep 0.2
done

code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/install.php")
[ "$code" = "200" ] || fail "GET /install.php a répondu $code"

curl -s -o "$WORK/install.html" -X POST "$BASE/install.php" \
  --data-urlencode "install_mode=import" \
  --data-urlencode "import_path=$SOURCE" \
  --data-urlencode "app_locale=fr"

if grep -qE "alert--danger|Fatal error|Warning</b>" "$WORK/install.html"; then
  fail "l'importation a renvoyé une erreur : $(grep -oE -m1 '<li>[^<]{0,200}|Fatal error[^<]{0,120}|Warning</b>[^<]{0,120}' "$WORK/install.html") — $(tail -n 5 storage/logs/error.log 2>/dev/null)"
fi
grep -q "alert--success" "$WORK/install.html" || fail "aucun message de réussite après l'importation"
[ -f storage/installed.lock ] || fail "storage/installed.lock absent après l'importation"

DB="$ROOT/storage/app/database.sqlite"
applied=$(sql "$DB" "SELECT COUNT(*) FROM migrations")
expected=$(find database/migrations/php -maxdepth 1 -name '*.php' | wc -l | tr -d '[:space:]')
[ "$applied" = "$expected" ] || fail "$applied migration(s) enregistrée(s) sur $expected"
users=$(sql "$DB" "SELECT COUNT(*) FROM users WHERE is_active = 1")
[ "$users" = "$source_users" ] || fail "$users compte(s) actif(s) importé(s) sur $source_users"
[ "$(sha1sum "$SOURCE_DB" | cut -d' ' -f1)" = "$source_hash" ] || fail "la base de l'instance importée a été modifiée"

# Connexion de chaque compte et parcours de ses pages.
php scripts/ci/crawl-smoke.php "$BASE" "$@" || fail "pages en erreur sur l'instance importée"

echo "OK : instance importée ($users comptes actifs, $applied migrations), base d'origine intacte, pages sans erreur."
