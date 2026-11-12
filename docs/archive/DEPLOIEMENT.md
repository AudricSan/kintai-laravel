# Déploiement et exploitation de Kintai

Guide issu de l'audit du 31/10/2026 (critères « Déploiement » et « Documentation »). Les variables se posent dans `.env`
à la racine du projet ; celles qui manquent gardent leur valeur par défaut.

## Production : réglages obligatoires

| Variable | Valeur | Pourquoi |
| :--- | :--- | :--- |
| `APP_DEBUG` | `false` | aucune trace d'exception côté navigateur |
| `APP_URL` | l'adresse publique en HTTPS | liens des e-mails, de réinitialisation et d'agenda ; jamais déduits de l'en-tête `Host` |
| `SESSION_SECURE` | `true` (ou laisser vide en HTTPS : le cookie est alors `Secure` automatiquement) | cookie de session jamais envoyé en clair |
| `APP_KEY` | générée à l'installation, **ne jamais la changer sans procédure** (voir rotation) | chiffre les secrets stockés (`SecretBox`) |

Derrière un proxy ou un répartiteur qui termine le TLS, le serveur doit transmettre `X-Forwarded-Proto` pour que l'application
voie HTTPS. Les permissions du dossier `storage/` suffisent en écriture pour l'utilisateur PHP ; `.env` et `config/` ne
doivent pas être lisibles par le web (le `.htaccess` fourni les protège, sinon faire de `public/` la racine web).

## Variables propres à Kintai

| Variable | Défaut | Rôle |
| :--- | :--- | :--- |
| `APP_LOCALE` | `en` | langue de l'instance (choisie à l'installation) |
| `APP_TIMEZONE` | `Asia/Tokyo` | fuseau des dates enregistrées |
| `CRON_ALLOW_QUERY_TOKEN` | `false` | accepte `?token=` pour les tâches planifiées ; à laisser à `false` et préférer `Authorization: Bearer` |
| `KINTAI_ALLOW_UNPINNED_BUNDLES` | `false` | autorise l'installation d'un bundle sans commit épinglé (déconseillé) |
| `KINTAI_IMPORT_ROOTS` | vide | dossiers locaux autorisés comme source d'import d'une instance, séparés par `;` |
| `KINTAI_HTTP_ALLOW_PRIVATE` | `false` | autorise les requêtes sortantes vers des adresses privées (développement uniquement) |
| `GITHUB_UPDATE_REPO`, `GITHUB_UPDATE_TOKEN` | dépôt public | source des mises à jour |
| `MAIL_*` | sendmail | envoi des e-mails (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`) |
| `PUSH_FCM_*` | désactivé | notifications push mobiles |
| `DB_*` | SQLite | base de données (`DB_CONNECTION=mysql` pour MySQL) |

## Tâches planifiées

Chaque tâche a son propre jeton, haché en base (`cron_tokens`). Créer le jeton depuis l'administration ou avec
`php artisan kintai:cron-token --user-id=1 --job=backup`, puis appeler :

    curl -H "Authorization: Bearer VOTRE_JETON" https://votresite.example/cron/run/backup

Un jeton peut expirer ou être limité à certaines tâches ; il se révoque en le supprimant.

## Sauvegardes et restauration

- **Créer** une sauvegarde : administration → Sauvegarde (Owner), ou la tâche planifiée `backup`. Le fichier ZIP contient la
  base et les fichiers téléversés ; il est dans `storage/backups/`.
- **Tester** la restauration sur une copie jetable avant d'en avoir besoin : installer une instance vide, puis Sauvegarde →
  Restaurer. L'extraction refuse les chemins absolus, `..` et les liens symboliques (`SafeZip`).
- Une réinitialisation (données ou usine) crée toujours une sauvegarde d'abord et l'ajoute au journal `storage/logs/reset.log`.

## Mise à niveau

1. Sauvegarder (ci-dessus), puis passer en mode maintenance depuis l'administration.
2. Mettre à jour le code (page Mises à jour, ou `git pull` + `composer install --no-dev`).
3. `php artisan migrate --force` (la page Mises à jour le fait aussi).
4. Vérifier `composer audit`, puis rouvrir l'instance.

Les bundles installés restent compatibles tant que le **contrat** (`contract.snapshot.json`) ne change pas de version
majeure ; une version mineure ajoute seulement. Un bundle tiers s'installe uniquement avec un commit épinglé et une
confirmation explicite.

## Rotation des secrets

- **Mot de passe d'un compte, jeton d'API, cookie « rester connecté »** : changer le mot de passe révoque les autres sessions,
  les jetons d'API et les cookies de ce compte.
- **Jeton d'agenda (iCal)** : se régénère depuis le profil ; l'ancien lien cesse de fonctionner. Le segment secret n'apparaît
  jamais dans le journal d'accès.
- **Jeton de tâche planifiée** : supprimer et recréer.
- **`APP_KEY`** : elle chiffre les secrets enregistrés (mots de passe SMTP, jetons de connexion d'agenda). La changer rend ces
  valeurs illisibles : ressaisir les secrets concernés dans l'administration juste après le changement.
- **`GITHUB_UPDATE_TOKEN`, `MAIL_PASSWORD`, identifiants FCM** : à changer chez le fournisseur, puis dans `.env`.

## Journaux

`storage/logs/error.log` reçoit les erreurs inattendues ; `LogScrubber` y masque mots de passe, jetons et liens secrets, et la
pile d'appels n'y contient pas les arguments. Ces fichiers contiennent tout de même des données d'exploitation : les garder
hors du web, limiter leur accès et fixer une rétention (rotation du serveur, purge du journal d'activité depuis l'administration).
