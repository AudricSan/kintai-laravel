#!/usr/bin/env bash
# Test de fumée : installation en ligne de commande (scripts/provision.php) sur une instance vierge.
#
# Vérifie les refus (entrées invalides, instance déjà installée, compte existant), puis une installation complète :
# tables, compte admin avec le rôle Owner, URL publique, verrou, et connexion de cet admin au tableau de bord.
# Ce script avait cessé de fonctionner (fichier SQLite jamais créé, colonne users.is_admin supprimée, plantage
# silencieux) sans qu'aucun test ne le remarque.
#
# Il écrit dans le dépôt où il tourne : il refuse donc de s'exécuter hors CI, ou si une installation existe déjà.
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
  [ -f "$WORK/out.txt" ] && { echo "--- sortie ---" >&2; cat "$WORK/out.txt" >&2; }
  exit 1
}

# Dernière ligne seulement : certains PHP affichent des avertissements de démarrage sur la sortie standard.
sql() { php -r '$d = new PDO("sqlite:" . $argv[1]); echo $d->query($argv[2])->fetchColumn(), PHP_EOL;' "$ROOT/storage/app/database.sqlite" "$1" 2>/dev/null | tail -n 1 | tr -d '[:space:]'; }

provision() { php scripts/provision.php "$@" > "$WORK/out.txt" 2>&1; }

EMAIL="provision-smoke@kintai.test"
PASSWORD="$(php -r 'echo bin2hex(random_bytes(12));' 2>/dev/null | tail -n 1)"

# ─── Refus attendus, avant toute écriture ───────────────────────────────
provision --admin-email=not-an-email --admin-password="$PASSWORD" && fail "e-mail invalide accepté"
provision --admin-email="$EMAIL" --admin-password=short && fail "mot de passe trop court accepté"
provision --admin-email="$EMAIL" --admin-password="$PASSWORD" --app-url=/Kintai && fail "--app-url relative acceptée"
provision --admin-email="$EMAIL" --admin-password="$PASSWORD" --locale=xx && fail "--locale inconnue acceptée"
[ -e config/database.local.php ] && fail "une entrée refusée a quand même écrit la configuration"

# ─── Installation ────────────────────────────────────────────────────────
provision --admin-email="$EMAIL" --admin-password="$PASSWORD" --app-url=https://kintai.example.com/ --seed \
  || fail "provision.php a échoué"
grep -q "Undefined array key\|Warning:" "$WORK/out.txt" && grep -v "Module .* is already loaded" "$WORK/out.txt" | grep -q "Warning:" \
  && fail "avertissements PHP pendant l'installation"

[ -f storage/installed.lock ] || fail "storage/installed.lock absent"
# Sans --locale, une instance neuve est en anglais (APP_LOCALE), quel que soit le is_default de lang/languages.json.
grep -q "^APP_LOCALE=en$" .env || fail "APP_LOCALE=en attendu dans .env sans --locale"
tables=$(sql "SELECT COUNT(*) FROM sqlite_master WHERE type='table'")
[ "$tables" -ge 40 ] || fail "seulement $tables tables créées"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='$EMAIL'")" = "1" ] || fail "compte admin absent"
# Données de démonstration (--seed) : 2 magasins, 6 comptes + l'admin, des shifts, 2 gérants.
[ "$(sql "SELECT COUNT(*) FROM stores")" = "2" ] || fail "--seed : 2 magasins attendus"
[ "$(sql "SELECT COUNT(*) FROM users")" = "7" ] || fail "--seed : 7 comptes attendus (6 de démonstration + l'admin)"
[ "$(sql "SELECT COUNT(*) FROM shifts")" -gt 0 ] || fail "--seed : aucun shift créé"
managers=$(sql "SELECT COUNT(*) FROM role_assignments ra JOIN roles r ON r.id = ra.role_id WHERE r.slug='manager'")
[ "$managers" = "2" ] || fail "--seed : 2 gérants attendus, $managers trouvés"
owner=$(sql "SELECT COUNT(*) FROM role_assignments ra JOIN roles r ON r.id = ra.role_id JOIN users u ON u.id = ra.user_id WHERE r.slug='owner' AND u.email='$EMAIL'")
[ "$owner" = "1" ] || fail "le compte admin n'a pas le rôle Owner"
[ "$(sql "SELECT value FROM app_settings WHERE key='app_public_url'")" = "https://kintai.example.com" ] || fail "URL publique non enregistrée"

# ─── Réinstallation ──────────────────────────────────────────────────────
provision --admin-email="$EMAIL" --admin-password="$PASSWORD" && fail "réinstallation sans --force acceptée"
provision --admin-email="$EMAIL" --admin-password="$PASSWORD" --force && fail "--force a recréé un compte existant"
grep -q "existe déjà" "$WORK/out.txt" || fail "message d'erreur attendu pour un compte existant"
[ "$(sql "SELECT COUNT(*) FROM users WHERE email='$EMAIL'")" = "1" ] || fail "compte admin dupliqué"

# ─── Connexion de l'admin créé ───────────────────────────────────────────
php -S "127.0.0.1:$PORT" -t public > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -s -o /dev/null "$BASE/login" && break
  sleep 0.2
done
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

# ─── API : connexion, jeton, appel authentifié ───────────────────────────
# Une installation neuve n'avait pas la colonne api_tokens.expires_at : la connexion API répondait 500.
api_resp=$(curl -s -w '%{http_code}' -X POST "$BASE/api/v1/auth/login" -H 'Content-Type: application/json' \
  --data "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\",\"name\":\"smoke\"}")
api_code=${api_resp: -3}
[ "$api_code" = "201" ] || fail "POST /api/v1/auth/login a répondu $api_code (attendu 201) : ${api_resp%???}"
api_token=$(printf '%s' "$api_resp" | head -n1 | grep -oE '"token":"[a-f0-9]+"' | cut -d'"' -f4)
[ -n "$api_token" ] || fail "jeton API absent de la réponse de connexion"
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $api_token" "$BASE/api/v1/auth/me")
[ "$code" = "200" ] || fail "GET /api/v1/auth/me avec le jeton a répondu $code"

echo "OK : installation en ligne de commande ($tables tables), refus attendus, compte Owner, URL publique et connexion fonctionnels."
