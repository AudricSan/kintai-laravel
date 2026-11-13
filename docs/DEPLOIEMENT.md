# Déploiement et exploitation de Kintai

Guide de la version Laravel native. Les variables se posent dans `.env` à la racine du projet ; celles qui manquent gardent
leur valeur par défaut (`config/kintai.php`). L'ancien guide, valable pour la version précédente, est dans
[archive/DEPLOIEMENT.md](archive/DEPLOIEMENT.md).

## Installation

1. Télécharger `kintai-X.Y.Z.zip` d'une release GitHub, vérifier sa somme `kintai-X.Y.Z.zip.sha256`, le décompresser.
2. Faire pointer la racine web sur `public/`. `.env`, `storage/` et `vendor/` ne doivent jamais être servis.
3. Donner l'écriture à l'utilisateur PHP sur `storage/` et `bootstrap/cache/`, ainsi que sur la racine du projet si `.env`
   doit être créé par l'installeur.
4. Installer, au choix :
   - en ligne de commande : `php artisan kintai:install` ;
   - dans le navigateur (hébergement sans terminal) : ouvrir `/install`. L'assistant exige le **jeton d'installation**,
     écrit dans `storage/app/install-token` : le lire sur le serveur (FTP, gestionnaire de fichiers) et le saisir. Le jeton
     est supprimé une fois l'installation terminée.

L'installeur contrôle les prérequis, crée `.env` (réglages de production) et la clé d'application, vérifie la connexion à la
base **avant** d'écrire, migre, crée les rôles du Core et le premier Owner, puis pose le verrou `storage/app/installed`.
Deux installations simultanées sont sérialisées : une seule aboutit. Une fois installé, `/install` répond 404 (jusqu'à une
réinitialisation d'usine, qui exige à nouveau un jeton).

## Production : réglages obligatoires

| Variable | Valeur | Pourquoi |
| :--- | :--- | :--- |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | aucune trace d'exception côté navigateur |
| `APP_URL` | l'adresse publique **en HTTPS** | liens des e-mails et de réinitialisation, hôte de confiance, cookie `Secure` |
| `APP_KEY` | générée à l'installation ; **ne pas la changer sans procédure** (voir rotation) | chiffre les secrets stockés |

À partir d'`APP_URL` (hors `localhost`, `127.0.0.1` et `*.test`) :

- seul cet hôte est accepté (`trustHosts`) : une requête avec un autre en-tête `Host` est refusée ;
- le cookie de session est `Secure` si l'adresse commence par `https://` (surcharge : `SESSION_SECURE_COOKIE`) ;
- le lien de réinitialisation de mot de passe est construit depuis l'adresse publique réglée par l'Owner, sinon `APP_URL`,
  jamais depuis l'en-tête `Host`.

Derrière un proxy ou un répartiteur qui termine le TLS, il doit transmettre `X-Forwarded-Proto` pour que l'application voie
HTTPS.

## Variables propres à Kintai

| Variable | Défaut | Rôle |
| :--- | :--- | :--- |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | `fr`, `en` | langue de l'instance |
| `KINTAI_TIMEZONE` | `UTC` | fuseau des dates enregistrées |
| `KINTAI_INSTALL_ENFORCE` | `true` | `false` coupe la redirection vers `/install` (développement) |
| `KINTAI_UPDATE_REPOSITORY`, `KINTAI_UPDATE_CHANNEL` | `AudricSan/kintai-laravel`, `release` | source et canal (`release`, `beta`, `alpha`) des mises à jour |
| `KINTAI_API_RATE_LIMIT` | `300` | requêtes par minute sur l'API |
| `KINTAI_NOTIFICATIONS_RETENTION_DAYS` | `90` | rétention des notifications lues |
| `KINTAI_ACTIVITY_RETENTION_DAYS` | `180` | rétention du journal d'activité (`0` = tout garder) |
| `KINTAI_LICENSE_API_KEY` | vide | clé produit ; sans elle, le palier gratuit s'applique. `KINTAI_LICENSE_API_URL` (https obligatoire) a une valeur par défaut ; la clé publique de vérification est figée dans le code |
| `VAPID_*` | vide | notifications Web Push (`php artisan kintai:webpush:keys` génère la paire) |
| `FCM_ENABLED`, `FCM_PROJECT_ID`, `FCM_CREDENTIALS_PATH` | désactivé | notifications push mobiles |
| `MAIL_*` | `log` | envoi des e-mails (à régler : sinon rien n'est envoyé) |
| `DB_*` | SQLite | base de données (`DB_CONNECTION=mysql` pour MySQL) |
| `QUEUE_CONNECTION` | `database` | file des envois différés (e-mail, push) |

## Tâches planifiées

Une seule ligne de crontab suffit :

```
* * * * * cd /chemin/vers/kintai && php artisan schedule:run
```

Elle exécute : sauvegarde automatique (03:00, si activée), synchronisation de la documentation (04:00), validation de la
licence (05:30, si une clé est posée), purges des notifications et du journal d'activité (quotidiennes), actualisation de la
dernière release (toutes les heures, minute 50) et alertes système (toutes les heures).

Sans accès au cron du serveur, un service externe peut appeler `/cron/run` avec un jeton de tâche planifiée (haché en base) :
`curl -H "Authorization: Bearer VOTRE_JETON" https://votresite.example/cron/run`. Préférer l'en-tête à `?token=` : une URL
finit dans les journaux d'accès du serveur.

## Sauvegardes et restauration

- **Créer** : administration → Sauvegarde (Owner), ou la tâche planifiée. Les fichiers sont dans
  `storage/app/private/backups/` (base et fichiers téléversés).
- **Tester la restauration** sur une copie jetable avant d'en avoir besoin. L'extraction refuse les chemins absolus, `..` et
  les liens symboliques.
- Une réinitialisation (données ou usine) et chaque mise à jour créent d'abord une sauvegarde. `.env`, `APP_KEY` et les
  sauvegardes ne sont jamais effacés par une réinitialisation d'usine.

## Mise à jour

Page *Mises à jour* (Owner) ou `php artisan kintai:update`. L'archive n'est téléchargée que depuis les releases du dépôt, sa
somme SHA-256 est vérifiée avant toute écriture, chaque entrée est contrôlée (pas de `..`, de chemin absolu, de lien
symbolique, de `.env` ni de `storage/`). Séquence : sauvegarde complète, mode maintenance, remplacement des fichiers (anciens
conservés), puis migrations dans une requête servie par le nouveau code. Tout échec restaure les fichiers, et la base depuis
la sauvegarde si les migrations avaient commencé ; une mise à jour interrompue s'annule depuis la page.

Limites connues : la somme prouve l'intégrité du téléchargement, pas l'identité de l'éditeur ; un dépôt privé n'est pas pris
en charge.

## Migrer une instance de l'ancienne version

`php tools/legacy-migration/migrate.php --source=<dossier de l'ancienne instance> [--dry-run]` : voir le
[README de l'outil](../tools/legacy-migration/README.md). La cible doit être installée et ne contenir aucun utilisateur. Les
comptes qui avaient l'ancien mot de passe `0000` (ou un mot de passe faible connu) doivent le changer à la première
connexion ; les liens d'agenda iCal sont à régénérer.

## Rotation des secrets

- **Mot de passe d'un compte, jeton d'API, cookie « rester connecté »** : changer le mot de passe déconnecte les autres
  sessions et révoque les jetons d'API.
- **Lien d'agenda (iCal)** : se régénère depuis le profil ; l'ancien lien cesse de fonctionner.
- **`APP_KEY`** : elle chiffre les secrets enregistrés (clé produit, jeton de licence, mots de passe SMTP). La changer les rend
  illisibles : les ressaisir dans l'administration juste après.
- **`MAIL_PASSWORD`, clé de licence, identifiants FCM** : à changer chez le fournisseur, puis dans `.env`.

## Journaux

`storage/logs/` reçoit les erreurs. Ces fichiers contiennent des données d'exploitation : les garder hors du web, limiter
leur accès et fixer une rotation. Le journal d'activité (actions sensibles, mises à jour, réinitialisations) se purge selon
`KINTAI_ACTIVITY_RETENTION_DAYS`.
