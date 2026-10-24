# Image Kintai : Apache + PHP 8.3 (Ubuntu 24.04), code et dépendances inclus. Les données de l'instance (base SQLite,
# sauvegardes, bundles installés, fichiers envoyés, .env et config/database.local.php) vivent dans le volume storage/ :
# recréer le conteneur ou passer à une image plus récente ne perd rien. Voir « Docker » dans le README.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
    && apt-get install -y --no-install-recommends apache2 ca-certificates libapache2-mod-php8.3 php8.3-bcmath php8.3-cli \
        php8.3-curl php8.3-gd php8.3-intl php8.3-mbstring php8.3-mysql php8.3-opcache php8.3-sqlite3 php8.3-xml php8.3-zip unzip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/* /var/www/html/index.html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Seul public/ est servi ; ses .htaccess (réécriture, en-têtes, pages d'erreur) s'appliquent. Journaux d'Apache vers
# la sortie du conteneur (docker compose logs). variables_order=EGPCS : les variables d'environnement du conteneur
# (docker compose « environment ») parviennent à PHP sous Apache, comme en ligne de commande.
RUN sed -ri 's!DocumentRoot /var/www/html!DocumentRoot /var/www/html/public!' /etc/apache2/sites-available/000-default.conf \
    && printf 'ServerName localhost\n<Directory /var/www/html/public>\n    AllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/kintai.conf \
    && a2enconf kintai \
    && ln -sf /dev/stdout /var/log/apache2/access.log \
    && ln -sf /dev/stderr /var/log/apache2/error.log \
    && printf 'memory_limit=512M\nupload_max_filesize=32M\npost_max_size=40M\nvariables_order=EGPCS\n' > /etc/php/8.3/apache2/conf.d/90-kintai.ini

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p storage/instance bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache config

COPY docker/entrypoint.sh /usr/local/bin/kintai-entrypoint
RUN chmod +x /usr/local/bin/kintai-entrypoint

VOLUME /var/www/html/storage
EXPOSE 80
ENTRYPOINT ["kintai-entrypoint"]
CMD ["apache2ctl", "-D", "FOREGROUND"]
