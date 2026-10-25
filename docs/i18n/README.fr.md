# 🕒 Kintai

🌐 [English](../../README.md) · **Français** · [日本語](README.ja.md)

**Gestion open-source des plannings, du pointage et des ressources humaines pour les entreprises multi-magasins.**

[![Version](https://img.shields.io/github/v/release/AudricSan/Kintai?include_prereleases&label=version&color=purple)](https://github.com/AudricSan/Kintai/releases)
[![License: AGPL v3](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.3-8892bf.svg)](https://php.net)
[![Tests](https://github.com/AudricSan/Kintai/actions/workflows/tests.yml/badge.svg)](https://github.com/AudricSan/Kintai/actions/workflows/tests.yml)
[![Architecture](https://img.shields.io/badge/architecture-custom%20MVC-orange.svg)]()

Kintai est le pont entre le tableur Excel et l'ERP d'entreprise : planning, pointage, congés, échanges de shifts, rapports journaliers et estimation de la paie pour les entreprises de retail et d'hôtellerie-restauration gérant plusieurs magasins — auto-hébergé, sur votre propre infrastructure, avec vos propres données.

> **Statut : bêta, pré-1.0.** Kintai est fonctionnel et utilisé quotidiennement sur l'instance de démonstration ci-dessous, mais l'API et le schéma de données peuvent encore évoluer avant une version stable `1.0.0`. Voir [CHANGELOG.fr.md](CHANGELOG.fr.md).

---

## 🌍 Essayer en ligne

**Démo :** [kintai-lv1b.onrender.com](https://kintai-lv1b.onrender.com)
*(Instance sur plan gratuit — le premier chargement peut prendre 30 à 60 s, les données sont réinitialisées à chaque redémarrage.)*

| Rôle | Email | Mot de passe |
| :--- | :--- | :--- |
| Super admin | `admin@kintai.local` | `Admin1234!` |
| Employé | `alice.martin@kintai.local` | `Staff1234!` |

---

## Pourquoi Kintai

- **Vous restez propriétaire de vos données.** Pas de boîte noire multi-tenant : chaque déploiement est une instance dédiée avec sa propre base de données. Passer de la démo hébergée à votre propre serveur, c'est juste un dump de base et un `git clone` — aucun verrouillage propriétaire.
- **Pensé pour le multi-magasin réel.** Fuseaux horaires, devises, règles de pause et seuils de sous-effectif par magasin, fonctionnalités activables individuellement — pas un outil mono-site étiré pour l'occasion.
- **Prêt pour le Japon, utilisable partout.** Export PDF compatible CJK, ordre des noms `姓 名`, affichage JPY/EUR/USD, traductions FR/EN/JA incluses — pensé pour les usages du retail japonais mais utilisable ailleurs.
- **Modulaire par conception.** Un cœur Laravel 13 gère le planning, les utilisateurs, les magasins et l'authentification ; tout le reste (rapports journaliers, messagerie, échanges de shifts, paie) est livré en bundles autonomes, activables un par un. Désactivez ce dont vous n'avez pas besoin, gardez l'instance légère. Voir [Architecture (wiki)](https://github.com/AudricSan/Kintai/wiki/fr/Architecture).
- **Réellement testé.** 589 tests PHPUnit couvrant le cœur, les repositories et les contrôleurs, exécutés à chaque push via GitHub Actions.

---

## Fonctionnalités

**Planning & shifts** — CRUD shifts/types de shifts, vues liste/calendrier/semaine/jour/timeline Gantt, drag & drop, actions en masse, impression, détection des conflits de chevauchement, shifts ouverts + bourse aux shifts, import Excel avec analyse visuelle et score de confiance.

**Temps & présence** — Pointage clock-in/out avec chronomètre en direct, édition admin du pointage, estimation automatique du salaire à partir des shifts/pauses/taux horaires/déductions.

**Espace employé** — Tableau de bord personnalisé, disponibilités hebdomadaires, demandes de congés, échanges de shifts, flux iCal.

**Communication** — Messagerie interne par fils de discussion (mise à jour en direct via SSE), centre de notifications avec toasts et statut lu/non lu.

**Rapports journaliers** — Rapports KPI par magasin (ventes, clients, coût de personnel) avec workflow brouillon → soumis → validé, export PDF (mPDF, polices CJK), auto-validation planifiée et envoi par email.

**API REST** — Versionnée (`/api/v1`), authentification par token Bearer, pagination, couvrant toutes les ressources principales. Référence : [.wiki/API.md](https://github.com/AudricSan/Kintai/wiki/API) *(wiki en cours de portage depuis le dépôt pré-bêta)*.

**Exploitation** — Mise à jour en un clic depuis les Releases GitHub (avec barre de progression et filet de sécurité backup/rollback automatique), sauvegarde/restauration SQLite/MySQL, endpoints cron protégés par token pour les planificateurs externes.

Détail complet dans [Architecture (wiki)](https://github.com/AudricSan/Kintai/wiki/fr/Architecture) et [Stratégie base de données (wiki)](https://github.com/AudricSan/Kintai/wiki/fr/Strategie-base-de-donnees).

---

## Rôles & workflows

| Rôle | Périmètre |
| :--- | :--- |
| Super admin | Tous les magasins, journal d'audit, configuration globale |
| Manager/admin magasin | Planning, personnel, congés, échanges, rapports et stats de son/ses magasin(s) |
| Staff | Son propre planning, pointage, congés, échanges, messages |

Flux typique : shifts créés ou importés → conflits vérifiés → employés notifiés → présence pointée → rapports journaliers validés → paie estimée.

---

## Démarrage

### Prérequis

- PHP 8.3+, Composer 2.x
- `pdo_sqlite` ou `pdo_mysql`, `mbstring`, `gd`, `intl`

### Installation locale

```bash
git clone https://github.com/AudricSan/Kintai.git
cd Kintai
composer install
php -S 127.0.0.1:8000 -t public
```

Ouvrir ensuite `http://127.0.0.1:8000/install.php` — l'installateur web crée `config/database.local.php`, exécute les migrations, crée le compte administrateur, enregistre la langue de l'instance choisie (`APP_LOCALE` dans `.env`, modifiable ensuite dans les paramètres de l'instance), vous connecte et ouvre l'assistant de configuration (`/admin/setup`) : saisissez une clé de licence ou continuez avec le plan gratuit, puis choisissez les bundles (fonctionnalités) à installer — autant que votre licence le permet (4 en plan gratuit). Ils sont téléchargés depuis le catalogue de bundles ; ceux qui n'ont pas pu être installés sont listés pour être réessayés depuis le catalogue. L'installateur vérifie les prérequis du serveur (version de PHP, extensions, dossiers inscriptibles) avant toute écriture, et une installation qui échoue est entièrement annulée : il suffit d'en corriger la cause et de recommencer.

**Reprendre une instance existante** (ancienne version d'avant Laravel comprise) : sur la même page, choisissez **Importer une instance existante** et indiquez le dossier de l'instance installée sur le même serveur (par exemple `D:\xampp\htdocs\Kintai`). Sa base, son `.env`, ses fichiers envoyés, ses sauvegardes et ses bundles installés sont repris et les migrations en attente appliquées ; l'ancien dossier n'est jamais modifié (une base MySQL est en revanche mise à jour sur place : sauvegardez-la avant et ne l'utilisez plus depuis l'ancienne instance). Connectez-vous avec votre compte habituel, puis mettez à jour les bundles importés vers leur version Laravel (3.x) depuis **Bundles › Catalogue**.

L'installateur enregistre aussi l'adresse depuis laquelle vous installez comme **URL publique** de l'instance, utilisée pour les liens des e-mails (réinitialisation du mot de passe). Vérifiez-la dans les réglages Owner (`/admin/owner-settings`) si les utilisateurs accèdent à Kintai par une autre adresse ; la variable d'environnement `APP_URL`, si elle est définie, prime. Sans URL publique, les e-mails de réinitialisation ne partent pas et l'Owner voit un avertissement.

Pour installer sans navigateur (déploiement scripté), `scripts/provision.php` fait la même chose en ligne de commande — SQLite par défaut, sinon `--db-driver=mysql` et les options `--db-*`, avec les variables d'environnement en repli (voir l'en-tête du script). Passez `--app-url`, puisqu'aucune adresse de requête ne permet d'en déduire l'URL publique. L'instance est en anglais sauf `--locale=fr` ou `--locale=ja` (même choix par défaut que l'installateur web) :

```bash
php scripts/provision.php --admin-email=admin@example.com --admin-password='…' --app-url=https://kintai.example.com
```

### Serveur web

Seul `public/` doit être servi. Faites-y pointer la racine de documents du serveur web (`DocumentRoot` d'Apache, `root` de nginx) — jamais la racine du dépôt, sous peine de rendre `.env`, `storage/app/database.sqlite`, les sauvegardes et le code source téléchargeables. `php -S … -t public` le fait déjà. Sous Apache, si le serveur (ou le dossier `www/` d'un hébergement mutualisé) pointe malgré tout sur la racine du dépôt, un `.htaccess` à la racine réécrit de façon transparente chaque requête vers `public/` — l'URL reste propre, rien en dehors de `public/` n'est accessible, et cela fonctionne aussi depuis un sous-dossier (`/Kintai/`). nginx n'a pas d'équivalent, son `root` doit donc être correct.

Sous Apache, `public/.htaccess` redirige toute requête en HTTP simple vers HTTPS (301), sauf les hôtes de développement local (`localhost`, `127.x`, `[::1]`, `*.test`, `*.localhost`, adresses de réseau privé) et les requêtes déjà marquées HTTPS par un proxy ou un hébergeur qui termine lui-même le TLS (`X-Forwarded-Proto: https`). Servez le site de production en HTTPS avec un certificat valide.

### Mise à jour

```bash
php scripts/db-migrate.php --dry-run   # aperçu des migrations en attente
php scripts/db-migrate.php             # les applique
```

Les propriétaires peuvent aussi déclencher une mise à jour en un clic depuis `/admin/update`, en choisissant un canal (stable/beta/alpha) : elle récupère la Release GitHub correspondante, sauvegarde la base et le code au préalable, et affiche une progression en direct.

Les instances encore en 0.x (avant Laravel) se mettent à jour de la même façon : l'archive de la release contient un relais `src/` → `app/`, si bien que la requête de mise à jour se termine sur le nouveau code et que le site sort du mode maintenance comme d'habitude.

### Docker

```bash
git clone https://github.com/AudricSan/Kintai.git
cd Kintai
docker compose up -d
```

Ouvrez ensuite `http://localhost:8080/install.php` et installez comme ci-dessus (SQLite fonctionne tel quel ; pour MySQL, indiquez votre serveur de base de données à l'installateur). L'image fait tourner Apache et PHP 8.3 avec toutes les extensions dont Kintai a besoin, et ne sert que `public/`. Tout ce qui appartient à l'instance vit dans le volume `kintai-storage` : la base SQLite, les sauvegardes, les bundles installés, les fichiers envoyés, ainsi que `.env` et `config/database.local.php` (liés dans le volume au démarrage). Les variables d'environnement de `docker-compose.yml` (`APP_URL`, `MAIL_*`…) arrivent jusqu'à PHP.

Pour mettre à jour, reconstruisez ou récupérez une image plus récente et recréez le conteneur (`docker compose build --pull && docker compose up -d`) : les migrations en attente s'appliquent au démarrage. N'utilisez pas la mise à jour en un clic dans un conteneur, dont les fichiers sont remplacés quand il est recréé. Les tâches planifiées passent par une ligne de la crontab de l'hôte, chaque minute : `* * * * * docker compose -f /chemin/vers/docker-compose.yml exec -T -u www-data kintai php artisan schedule:run` (horaires dans `config/schedule.php`), ou par l'URL de cron avec un jeton (voir `scripts/cron.php`).

---

## Stack technique

PHP 8.3 sur **Laravel 13** (Eloquent, Artisan). SQLite ou MySQL, interchangeables via la config. Vues Blade rendues côté serveur, CSS/JS vanilla modulaire, aucun bundler.

```
public/index.php         → front controller de Laravel
bootstrap/app.php        → application Laravel
routes/web.php           → routes Laravel ; routes/kintai.php → routes de Kintai
app/Core/                → cœur de Kintai : conteneur, routeur, middlewares, repositories, services
app/UI/                  → contrôleurs Web + API, vues Blade
app/Contract/            → le contrat de bundle (API stable pour les bundles)
public/assets/           → CSS/JS modulaire, aucun build
database/migrations/php/ → migrations Eloquent, SQLite + MySQL depuis un seul schéma
```

Les bundles officiels vivent dans [kintai-laravel-bundles](https://github.com/AudricSan/kintai-laravel-bundles) (un dossier par bundle, vues Blade, contrat de bundle 1.31), vers lequel pointe le registry officiel. Leurs tests tournent contre ce dépôt.

Pour aller plus loin : [Architecture (wiki)](https://github.com/AudricSan/Kintai/wiki/fr/Architecture) · [Stratégie base de données (wiki)](https://github.com/AudricSan/Kintai/wiki/fr/Strategie-base-de-donnees) · [Multi-Tenancy (wiki)](https://github.com/AudricSan/Kintai/wiki/fr/Multi-Tenancy)

---

## Contribuer

Les contributions sont bienvenues — rapports de bugs, idées de fonctionnalités et pull requests.

```bash
composer install
./vendor/bin/phpunit
```

Lire [CONTRIBUTING.fr.md](CONTRIBUTING.fr.md) pour les conventions de code (types stricts, injection par constructeur, règles de migration) avant d'ouvrir une PR, et [SECURITY.fr.md](SECURITY.fr.md) pour signaler une vulnérabilité en privé plutôt que via une issue publique.

---

## Roadmap

**Prochainement**
- [ ] Superposition visuelle des conflits directement sur la timeline
- [ ] Audit de sécurité approfondi des routes web POST et des endpoints API exposés
- [ ] Tests ciblés sur la paie, les échanges, les shifts ouverts et les notifications

**Plus tard**
- [ ] PWA installable avec mode hors-ligne partiel
- [ ] Export paie CSV/XML
- [ ] Webhooks sortants (Slack, LINE, Teams)
- [ ] Support multilingue étendu de l'interface web (FR/EN/JA seulement pour l'instant)
- [ ] Tableau de bord d'analytique et de reporting multi-magasins
- [ ] Planning multi-magasins avec échanges de shifts inter-magasins
- [ ] Optimisation de la paie et du coût de personnel multi-magasins
- [ ] Intégration inventaire/ventes multi-magasins (caisse, ERP, e-commerce)
- [ ] Suivi de performance et gamification des employés multi-magasins
- [ ] Conformité et application du droit du travail multi-magasins (heures sup, pauses, jours fériés)
- [ ] Application mobile multi-magasins pour employés et managers (iOS, Android)

Historique complet dans [CHANGELOG.fr.md](CHANGELOG.fr.md).

---

## Licence

[GNU Affero General Public License v3.0](LICENSE). Vous êtes libre d'utiliser, modifier et redistribuer Kintai, y compris commercialement — mais toute version modifiée exploitée comme service réseau doit rendre son code source disponible. Voir [LICENSE](LICENSE) pour le texte complet.
