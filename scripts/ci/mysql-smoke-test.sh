#!/usr/bin/env bash
# Test de fumée MySQL : installation neuve (scripts/provision.php --db-driver=mysql, données de démonstration), migrations
# rejouées sans rien en attente, connexion de l'Owner, pages principales et API. Jusqu'ici MySQL n'était jamais exécuté
# en CI (audit n°8) : les écarts SQLite / MySQL n'étaient que déduits du code.
#
# Variables : MYSQL_HOST, MYSQL_PORT, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DATABASE (service MySQL du job).
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

HOST="${MYSQL_HOST:-127.0.0.1}"
DBPORT="${MYSQL_PORT:-3306}"
DBUSER="${MYSQL_USER:-root}"
DBPASS="${MYSQL_PASSWORD:-}"
DBNAME="${MYSQL_DATABASE:-kintai}"
PORT="${SMOKE_PORT:-18767}"
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
  [ -f "$WORK/out.txt" ] && { echo "--- sortie ---" >&2; cat "$WORK/out.txt" >&2; }
  [ -f "$WORK/server.log" ] && { echo "--- serveur ---" >&2; tail -n 40 "$WORK/server.log" >&2; }
  [ -f storage/logs/error.log ] && { echo "--- error.log ---" >&2; tail -n 40 storage/logs/error.log >&2; }
  exit 1
}

# Dernière ligne seulement : certains PHP affichent des avertissements de démarrage sur la sortie standard.
sql() {
  php -r '$d = new PDO("mysql:host=" . $argv[1] . ";port=" . $argv[2] . ";dbname=" . $argv[5], $argv[3], $argv[4]);
          echo $d->query($argv[6])->fetchColumn(), PHP_EOL;' \
    "$HOST" "$DBPORT" "$DBUSER" "$DBPASS" "$DBNAME" "$1" 2>/dev/null | tail -n 1 | tr -d '[:space:]'
}

EMAIL="mysql-smoke@kintai.test"
PASSWORD="$(php -r 'echo bin2hex(random_bytes(12));' 2>/dev/null | tail -n 1)"

# ─── Installation sur MySQL ──────────────────────────────────────────────
php scripts/provision.php --admin-email="$EMAIL" --admin-password="$PASSWORD" --app-url=https://kintai.example.com \
  --db-driver=mysql --db-host="$HOST" --db-port="$DBPORT" --db-user="$DBUSER" --db-pass="$DBPASS" --db-name="$DBNAME" \
  --seed > "$WORK/out.txt" 2>&1 || fail "provision.php a échoué sur MySQL"

tables=$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DBNAME'")
[ "$tables" -ge 40 ] || fail "seulement $tables tables créées"
[ "$(sql "SELECT COUNT(*) FROM stores")" = "2" ] || fail "--seed : 2 magasins attendus"
[ "$(sql "SELECT COUNT(*) FROM users")" = "7" ] || fail "--seed : 7 comptes attendus"
[ "$(sql "SELECT COUNT(*) FROM shifts")" -gt 0 ] || fail "--seed : aucun shift créé"

# Migrations rejouées : rien ne doit rester en attente ni échouer.
php scripts/db-migrate.php > "$WORK/out.txt" 2>&1 || fail "db-migrate.php a échoué sur une base à jour"

# ─── Connexion et pages principales ──────────────────────────────────────
php -S "127.0.0.1:$PORT" -t public > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -s -o /dev/null "$BASE/login" && break
  sleep 0.2
done
JAR="$WORK/cookies.txt"
curl -s -c "$JAR" -b "$JAR" -o "$WORK/login.html" "$BASE/login"
token_field=$(grep -oE -m1 'name="_token"[^>]*value="[^"]+"' "$WORK/login.html" \
  | sed -E 's/.*name="([^"]+)".*value="([^"]+)".*/\1=\2/') || true
[ -n "$token_field" ] || fail "jeton CSRF introuvable sur /login"
curl -s -o /dev/null -c "$JAR" -b "$JAR" -X POST "$BASE/login" \
  --data "$token_field" --data "login_mode=email" \
  --data-urlencode "email=$EMAIL" --data-urlencode "password=$PASSWORD"

# L'assistant de configuration n'empêche pas d'ouvrir ces pages : une erreur SQL propre à MySQL y donnerait 500.
for path in / /employee /admin/shifts /admin/shifts/calendar /admin/shifts/timeline /admin/shifts/conflicts \
            /admin/users /admin/stores /admin/shift-types /admin/activity /admin/roles /profile /notifications; do
  code=$(curl -s -o "$WORK/page.html" -w '%{http_code}' -c "$JAR" -b "$JAR" "$BASE$path")
  [ "$code" = "200" ] || fail "GET $path a répondu $code sur MySQL"
  grep -q 'name="password"' "$WORK/page.html" && [ "$path" != "/profile" ] && fail "$path : renvoyé vers la connexion"
done

# ─── API ─────────────────────────────────────────────────────────────────
api_resp=$(curl -s -w '%{http_code}' -X POST "$BASE/api/v1/auth/login" -H 'Content-Type: application/json' \
  --data "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\",\"name\":\"smoke\"}")
[ "${api_resp: -3}" = "201" ] || fail "POST /api/v1/auth/login a répondu ${api_resp: -3}"
api_token=$(printf '%s' "$api_resp" | head -n1 | grep -oE '"token":"[a-f0-9]+"' | cut -d'"' -f4)
for path in /api/v1/auth/me /api/v1/users /api/v1/stores /api/v1/shifts?store_id=1; do
  code=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $api_token" "$BASE$path")
  [ "$code" = "200" ] || fail "GET $path (API) a répondu $code sur MySQL"
done

echo "OK : installation MySQL ($tables tables), migrations à jour, pages principales et API fonctionnelles."
