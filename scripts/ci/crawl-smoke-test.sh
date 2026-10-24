#!/usr/bin/env bash
# Test de fumée : parcours des pages d'une instance de démonstration (propriétaire, gérant, employé).
#
# Installe une instance neuve avec --seed, démarre le serveur et suit les liens internes de chaque rôle
# (scripts/ci/crawl-smoke.php). Les tests unitaires simulent les dépôts : un décalage entre code, vue et schéma
# n'apparaît qu'ici. Il écrit dans le dépôt où il tourne : il refuse donc de s'exécuter hors CI.
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

PORT="${SMOKE_PORT:-18767}"
BASE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d)"
SERVER_PID=""
cleanup() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
  rm -rf "$WORK"
}
trap cleanup EXIT

EMAIL="crawl-smoke@kintai.test"
PASSWORD="$(php -r 'echo bin2hex(random_bytes(12));' 2>/dev/null | tail -n 1)"
DEMO_PASSWORD="Demo1234!"

php scripts/provision.php --admin-email="$EMAIL" --admin-password="$PASSWORD" --seed > "$WORK/provision.txt" 2>&1 \
  || { cat "$WORK/provision.txt" >&2; echo "ÉCHEC : provision.php a échoué" >&2; exit 1; }

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
