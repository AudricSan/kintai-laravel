#!/usr/bin/env bash
# Test de fumée : installation neuve de bout en bout, par le vrai installateur web.
#
# Lance `php -S` sur public/, soumet le formulaire de public/install.php (SQLite, base vide), puis vérifie que
# les tables existent, que le compte admin est créé, que l'installation est verrouillée et que cet admin peut se
# connecter et afficher le tableau de bord. Aucun test unitaire ne couvre ce parcours : c'est ainsi qu'une
# lecture de `app_settings` au démarrage a pu rendre toute installation neuve impossible sans que rien n'échoue.
#
# Il écrit dans le dépôt où il tourne (config/database.local.php, storage/) : il refuse donc de s'exécuter hors
# CI, ou si une installation existe déjà. En local : `CI=true bash scripts/ci/install-smoke-test.sh` dans un clone
# jetable uniquement.
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

PORT="${SMOKE_PORT:-18765}"
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
sql() { php -r '$d = new PDO("sqlite:" . $argv[1]); echo $d->query($argv[2])->fetchColumn(), PHP_EOL;' "$ROOT/storage/app/database.sqlite" "$1" 2>/dev/null | tail -n 1 | tr -d '[:space:]'; }

mkdir -p storage/app storage/logs
php -S "127.0.0.1:$PORT" -t public > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -s -o /dev/null "$BASE/install.php" && break
  sleep 0.2
done

code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/install.php")
[ "$code" = "200" ] || fail "GET /install.php a répondu $code"

PASSWORD="$(php -r 'echo bin2hex(random_bytes(12));')"
EMAIL="smoke-admin@kintai.test"
curl -s -o "$WORK/install.html" -X POST "$BASE/install.php" \
  --data-urlencode "admin_first_name=Smoke" \
  --data-urlencode "admin_last_name=Test" \
  --data-urlencode "admin_email=$EMAIL" \
  --data-urlencode "admin_password=$PASSWORD" \
  --data-urlencode "admin_password_confirmation=$PASSWORD" \
  --data-urlencode "db_driver=sqlite"

# Les erreurs de l'installateur s'affichent dans un encadré alert--danger, le détail technique part dans error.log.
if grep -qE "alert--danger|Fatal error|Warning</b>" "$WORK/install.html"; then
  fail "l'installateur a renvoyé une erreur : $(grep -oE -m1 '<li>[^<]{0,200}|Fatal error[^<]{0,120}|Warning</b>[^<]{0,120}' "$WORK/install.html") — $(tail -n 5 storage/logs/error.log 2>/dev/null)"
fi
[ -f storage/installed.lock ] || fail "storage/installed.lock absent après installation"

tables=$(sql "SELECT COUNT(*) FROM sqlite_master WHERE type='table'")
[ "$tables" -ge 40 ] || fail "seulement $tables tables créées"
admins=$(sql "SELECT COUNT(*) FROM users WHERE email='$EMAIL'")
[ "$admins" = "1" ] || fail "compte admin absent"
owner=$(sql "SELECT COUNT(*) FROM role_assignments ra JOIN roles r ON r.id = ra.role_id JOIN users u ON u.id = ra.user_id WHERE r.slug='owner' AND u.email='$EMAIL'")
[ "$owner" = "1" ] || fail "le compte admin n'a pas le rôle Owner"

# Connexion de l'admin puis tableau de bord.
JAR="$WORK/cookies.txt"
curl -s -c "$JAR" -b "$JAR" -o "$WORK/login.html" "$BASE/login"
token_field=$(grep -oE -m1 'name="(_token|csrf_token|_csrf)"[^>]*value="[^"]+"' "$WORK/login.html" \
  | sed -E 's/.*name="([^"]+)".*value="([^"]+)".*/\1=\2/') || true
[ -n "$token_field" ] || fail "jeton CSRF introuvable sur /login"
curl -s -o /dev/null -c "$JAR" -b "$JAR" -X POST "$BASE/login" \
  --data "$token_field" --data "login_mode=email" \
  --data-urlencode "email=$EMAIL" --data-urlencode "password=$PASSWORD"
code=$(curl -s -o "$WORK/home.html" -w '%{http_code}' -c "$JAR" -b "$JAR" "$BASE/")
[ "$code" = "200" ] || fail "GET / après connexion a répondu $code"
grep -q 'name="password"' "$WORK/home.html" && fail "toujours sur la page de connexion après login"

echo "OK : installation neuve ($tables tables), compte Owner créé, connexion et tableau de bord fonctionnels."
