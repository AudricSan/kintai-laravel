#!/bin/sh
# Démarrage du conteneur Kintai : fichiers locaux de l'instance rangés dans le volume storage/, puis migrations en
# attente quand l'instance est déjà installée (passage à une image plus récente).
set -e
cd /var/www/html

mkdir -p storage/instance
chown www-data:www-data storage storage/instance

# .env et config/database.local.php sont écrits par l'installateur et les réglages de l'instance : un lien vers le
# volume les garde d'une image à l'autre. Un fichier écrit à la place du lien (installation annulée, qui supprime le
# lien, puis refaite) est d'abord rangé dans le volume.
keep() {
    if [ -f "$1" ] && [ ! -L "$1" ]; then
        mv "$1" "$2"
    fi
    ln -sfn "$2" "$1"
}
keep .env /var/www/html/storage/instance/.env
keep config/database.local.php /var/www/html/storage/instance/database.local.php
# L'installateur vérifie qu'il peut écrire .env : le fichier doit exister au bout du lien (database.local.php, lui,
# ne doit apparaître qu'à l'installation, qu'il signale).
if [ ! -e storage/instance/.env ]; then
    touch storage/instance/.env
fi
chown www-data:www-data storage/instance/.env

if [ -f storage/installed.lock ]; then
    su www-data -s /bin/sh -c 'php artisan kintai:migrate'
fi

exec "$@"
