# Inventaire fonctionnel de Kintai (spécification de la reconstruction)

Source : dépôt de production `Kintai` (`AudricSan/Kintai`, branche `develop`, merge #327 du 04/11/2026), lu dans
`../Kintai`. Chemins cités relatifs à ce dépôt. Bundles hors périmètre, sauf les points d'accroche que le Cœur leur offre.

**Limites.** Inventaire du 04/11/2026, fait en lecture rapide : les règles citées sont vérifiées dans le code, mais
plusieurs zones n'ont pas été lues (liste en fin de document). Chaque tranche de la phase 4 commence par relire ces zones
pour son domaine.

---

## 1. Identité et accès

### Connexion
- Deux modes : e-mail + mot de passe, ou **code employé + code magasin** (code magasin mis en majuscules, l'employé doit
  être membre du magasin) — `src/Core/Auth/AuthService.php:55-123`.
- Compte inconnu, inactif ou supprimé : hachage factice pour un temps de réponse constant (`AuthService.php:419-422`).
- Régénération de session à la connexion. Empreinte du hash du mot de passe en session : changer de mot de passe
  déconnecte les autres sessions (`AuthService.php:256-305`).
- « Rester connecté » 30 jours, jeton sélecteur/validateur haché, rotation à chaque usage (`AuthService.php:132-172, 364-400`).
- Mot de passe par défaut (« 0000 ») : obligation de le changer (`AuthService.php:232-250`).
- Limitation des échecs de connexion (`LoginThrottleMiddleware`, 429 + `Retry-After`).
- Changement de langue (`POST /lang/{locale}`), changement d'appareil (`POST /switch-device`).

### Mot de passe oublié
- Réponse identique que le compte existe ou non ; aucun mail aux comptes inactifs ou supprimés ; mail dans la langue du
  destinataire ; jeton haché à durée limitée ; la réinitialisation révoque sessions et jetons API
  (`src/Core/Services/PasswordResetService.php:28-119`, `CredentialRevoker`).

### Profil personnel (`/profile`)
- Onglets : informations, avatar (compressé, ancien fichier supprimé), mot de passe, notifications, navigation,
  disponibilités, flux iCal, Google Agenda, export de ses données, export de son horaire (JSON/CSV/ICS), suppression de
  son compte (interdite à l'Owner).
- Avatar servi par `GET /avatar/{user}` pour l'annuaire.

### Utilisateurs (administration)
- Liste (comptes anonymisés exclus), création complète et création rapide, contrôle en direct de l'unicité du code
  employé et de l'e-mail, édition, réinitialisation du mot de passe, taux horaires personnels par type de shift, export
  PDF et JSON — `src/UI/Controller/Web/Staff/AdminUserController.php`.
- Un gérant n'agit que sur les employés de ses magasins ; un non-Owner ne touche jamais un compte Owner
  (`PermissionService.php:283-295`).
- **Suppression = anonymisation**, jamais d'effacement : e-mail `deleted-{id}@kintai.local`, mot de passe vidé, avatar
  supprimé, partage de contact coupé, `deleted_at` posé, références opérationnelles supprimées, historique rattaché à
  « Utilisateur supprimé » — `src/Core/Auth/UserAnonymizer.php:34-108`.
- Profil enrichi, furigana séparés, partage de contacts (`share_email`).
- Journal : `user.created`, `user.viewed`, `user.deleted`, `user_rate.saved/deleted`, `export.employees_*`, avatar.

### Rôles et droits (RBAC)
- Tables `roles` (`is_system`, `is_manager`, `color`), `role_permissions` (clé + portée), `role_assignments`
  (portée globale ou magasin).
- **Owner** = rôle système en portée globale. Rôle système = toutes les clés. Une permission marquée « toutes les
  boutiques » ignore la portée magasin. `is_manager` (affiche la navigation gérant) est indépendant des permissions.
  Plafond anti-escalade à l'attribution d'un rôle — `src/Core/Auth/PermissionService.php`.
- Gestion des rôles et de leurs titulaires réservée à l'Owner (`/admin/roles*`) ; rôle d'un membre dans un magasin
  (`/stores/{id}/members/*`).
- Clés du Cœur (`src/Core/Auth/PermissionCatalog.php:19-35`) :

| Clé | Sens |
|---|---|
| `employees.view/create/update/delete` | Employés (update inclut mot de passe, taux, appartenance aux magasins ; delete = anonymiser) |
| `shifts.view/create/update/delete` | Shifts et types de shift (types : `shifts.update`) |
| `shifts.import` | Import Excel de planning |
| `stores.view/create/update/delete` | Magasins ; `stores.view` donne aussi le journal d'activité |
| `payroll.view/generate/export` | Statistiques, coûts, déductions, export |

  Réservé à l'Owner sans clé : rôles, réglages, sauvegardes, mise à jour, langues, bundles, licence, assistant
  d'installation, réglages mail/notifications/agenda.

**Cible Laravel** : authentification du kit de démarrage Livewire (adaptée au code employé + code magasin), `Auth`,
throttle natif, Gate/policies alimentées par le RBAC à portée magasin. Évaluer `spatie/laravel-permission` (mode
*teams*, équipe = magasin) contre une implémentation propre, en phase 3.

---

## 2. Magasins et planning

### Magasins
- Liste, création, édition, suppression ; membres (ajout, rôle, retrait) ; déductions par membre
  (`subject_to_deductions`) ; statistiques, rentabilité, rapport et statistiques par employé, export des statistiques.
- Validation : code et nom obligatoires, nom ≤ 200, devise dans une liste fermée (défaut EUR), style du symbole
  (défaut `kanji`), fuseau normalisé — `src/Core/Validation/StoreValidator.php`.
- Normalisation du fuseau : IANA, abréviations (JST, CET…), décalages (`UTC+9`, `+09:00` → `Asia/Tokyo`) —
  `src/Core/Services/StoreTimezone.php:18-70`. **Les plannings utilisent le fuseau de l'instance** (`APP_TIMEZONE`) ; le
  fuseau du magasin ne sert qu'à la synchronisation Google (`InstanceTimezone.php`).
- Fonctionnalités activables par magasin (`store_features`), réglages de déduction et d'import par magasin.

### Types de shift
- Tranche horaire (défaut 08:00-16:00) et taux horaire ; rattachés à plusieurs magasins (`shift_type_stores`) ; un
  utilisateur ne modifie un type que s'il gère tous ses magasins (`AdminShiftTypeController.php:180-194`).
- Taux personnel par employé et par type (`user_shift_type_rates`), prioritaire sur le taux du type.

### Shifts
- Vues : liste (mois, magasin, employé), calendrier mensuel, frise (glisser-déposer, création rapide), impression de la
  frise, conflits ; vues employé lecture seule (liste, calendrier, jour, semaine).
- Shift ouvert (`is_open`, sans employé) avec note ; surcharges manuelles du taux et des minutes nettes.
- Passage minuit (`cross_midnight`), pause, minutes nettes = durée − pause (plancher 0).
- **Garde-fou congé** : un congé approuvé bloque création, édition et déplacement (409 en JSON) ; shifts ouverts exemptés.
- **Garde-fou disponibilité** : avertissement seulement, forçable (`force_availability`) ; contrôlé à la création, à
  l'édition si l'employé ou la date change, au déplacement si la date change (`src/Core/Services/AvailabilityGuard.php`).
- Type dominant recalculé à l'enregistrement (type qui couvre le plus de minutes nettes).
- Conflits : chevauchement par employé (`startA < endB && startB < endA`), résolution unitaire (« garder le plus
  récent ») et en masse.
- Suppression unitaire et en masse ; chaque suppression est copiée dans `shift_deletion_log` (annulation iCal).
- `ical_sequence` incrémenté à chaque modification.
- Effets : journal (`shift.created/updated/deleted/bulk_deleted`), notifications (`shift_assigned`, `shift_updated`,
  `shift_deleted`), événement « shift ouvert fermé », synchronisation des agendas. Un échec de notification n'annule pas
  l'opération.

### Calcul des coûts (service pur, à reprendre)
`src/Core/Services/ShiftWageCalculator.php` — source unique des statistiques, rapports et paie.
- Répartit les minutes du shift entre les tranches des types qui le recouvrent (passages minuit gérés), réduit chaque
  tranche au prorata de la pause, montant `round(taux × minutes / 60, 2)` par tranche.
- Ordre : taux forcé sur le shift → sinon taux personnel → sinon taux du type ; minutes nettes forcées si présentes.
- Aucun type recouvrant : coût 0.

### Disponibilités
- Par employé, par magasin et par jour de semaine (1 = lundi) ; information, jamais contrainte. Saisie dans le profil et
  par l'API.

### Import de planning Excel
- Envoi, aperçu, confirmation ; détection en trois phases (bordures/colonnes/visuel, qualification avec score de
  confiance, déduplication) ; alias de noms d'employés (`import_aliases`) ; réglages par magasin —
  `src/Core/Services/ExcelShiftImport/`. Algorithme métier à reprendre comme service.

### Exports
- Page `/exports` : jeu de données (shifts, disponibilités, types, magasins, employés, paie) × magasin × employés ×
  période × format (CSV, Excel, PDF, ICS) ; droits par jeu de données et par magasin, chacun exporte au moins ses propres
  données — `src/Core/Export/ExportAccess.php`.

### Flux iCal
- `GET /ical/{token}/{store}/shifts.ics`, sans session, jeton par employé et magasin, régénérable.
- Un VEVENT par shift (UID stable, `SEQUENCE` = `ical_sequence`), horaires en UTC, shifts supprimés en `CANCELLED`
  pendant 30 jours, congés en journée entière, lignes repliées à 75 octets — `src/Core/Services/IcalService.php`.

### Synchronisation Google Agenda
- Liaison OAuth par employé (connexion, options, resynchronisation, déconnexion avec ou sans suppression des
  événements) ; identifiants OAuth de l'instance réglés par l'Owner.
- File de synchronisation : mise en file à chaque changement de shift, réconciliation périodique, réessais à délai
  exponentiel (max 6 h), abandon après N essais ; jetons OAuth chiffrés — `src/Core/Services/CalendarSyncService.php`.

**Cible Laravel** : modèles et relations, `FormRequest`, policies, Livewire pour la frise, le calendrier, le formulaire de
shift (aperçu du coût, avertissements) et l'aperçu d'import ; file Laravel (`ShouldQueue`, `backoff`) pour la
synchronisation ; chiffrement par cast `encrypted` ; `ShiftWageCalculator`, `AvailabilityGuard`, `StoreTimezone`,
`IcalService` et l'import Excel repris comme services, avec tests.

---

## 3. Tableau de bord, navigation, communication

### Tableau de bord
- Deux tableaux : gérant (`/`) et employé (`/employee`). Grille personnalisable : ajout et retrait de widgets,
  dispositions nommées, personnelles ou par rôle (seul l'admin choisit celles des rôles).
- Widgets du Cœur — gérant : compteurs, alertes, résumé des statistiques, aperçu financier, absentéisme, shifts du jour ;
  employé : shifts du jour, à venir, statistiques du mois. Le gérant peut ajouter ses widgets personnels (`emp_*`).
- Chaque widget a une audience, un ordre, une permission, une fonctionnalité de magasin, une taille et un intervalle de
  rafraîchissement ; une erreur dans un widget le retire sans casser la page (`src/Core/Extension/DashboardWidgets.php`).
- Gérant restreint : seulement ses magasins. Statistiques sur 30 jours, tendance financière sur 180 jours, retard au-delà
  de 5 min, heures sup au-delà de 40 h/semaine (`HomeController.php:52-61`). Compteur de demandes en attente.

### Navigation
- Menu par sections (employé : planning, demandes, statistiques, compte ; gérant : + RH, Owner : + système), entrées
  masquables par l'utilisateur, `settings` jamais masquable et réservé à l'Owner ; barre mobile de 2 à 4 entrées —
  `src/Core/Extension/Navigation.php`.

### Notifications
- Cloche avec interrogation toutes les 15 s, page paginée, tout marquer lu, tout supprimer, ouvrir (marque lue puis
  redirige vers un chemin interne).
- Canaux : base, e-mail (lien de désabonnement signé, sans connexion), Web Push (VAPID), push mobile (FCM via l'API).
  Préférences par type et par canal.
- Types du Cœur : `shift_assigned`, `shift_updated`, `shift_deleted`, `calendar_sync_error` ; système (Owner) :
  `core_update_available`, `migration_pending`, `bundle_update_available`, `backup_failed`, `backup_stale`,
  `channel_broken`, `license_quota`.
- Purge des notifications lues (90 jours par défaut, 7 à 3650) et des abonnements push inactifs depuis 180 jours.

### Journal d'activité
- `/admin/activity` (`stores.view`) : consultation, export ; purge par l'Owner ; rétention 180 jours par défaut.

### Autres
- Page des demandes en attente (agrège congés, échanges, bourse aux shifts : bundles).
- Mails : réinitialisation, notifications, test d'envoi par l'Owner ; expéditeur réglable.
- PWA : manifeste et service worker.
- Documentation intégrée (`/docs`, contenu du wiki synchronisé) ; signalement de problème (`/support/report-issue`, crée
  une issue GitHub).

**Cible Laravel** : notifications Laravel (canaux `database`, `mail`, Web Push via `laravel-notification-channels/webpush`,
FCM), préférences filtrées dans `via()` ; tableau de bord et cloche en Livewire (`wire:poll`) ; journal par un modèle
`ActivityEntry` alimenté par des événements (ou `spatie/laravel-activitylog`).

---

## 4. Administration et système

- **Réglages de l'Owner** (`app_settings`) : sous-titre, message de connexion, e-mail de support, URL publique,
  couleurs du thème et mode sombre, mascotte, expéditeur des mails, mode maintenance et message, journal d'accès,
  rétentions (journal, notifications), limite de l'API, sauvegarde automatique et nombre conservé, canaux de mise à jour
  (Cœur, bundles), bundles activés, état de l'assistant d'installation.
- **Sauvegardes** : création, restauration, suppression, téléchargement, sauvegarde automatique planifiée, alerte en cas
  d'échec ou de sauvegarde trop ancienne.
- **Réinitialisation de l'application** (préparation + exécution).
- **Mise à jour** depuis GitHub (canaux release/beta/alpha, application, migrations, suivi en direct).
- **Langues** (édition, activation, suppression).
- **Bundles** : registres, marché, installation et désinstallation, canal de mise à jour.
- **Licence** : activation, rafraîchissement, désactivation, vérification quotidienne, alerte de quota.
- **Assistant d'installation** (licence, étapes, bundles, questionnaire).
- **Cron** : tâches `backup`, `log-purge`, `license-check`, `notification-purge`, `system-notifications`,
  `calendar-sync`, déclenchées par `GET /cron/run/{job}` avec jeton haché (en-tête ou paramètre) ou par
  `scripts/cron.php` ; fréquences non fixées dans le code (wiki : quotidien, horaire pour les alertes, chaque minute pour
  la synchronisation). Les alertes système sont aussi lancées « au passage » par un middleware.
- **API v1** (JSON, erreurs `{error, code}`) : `ping`, connexion (jeton 90 jours max, haché), déconnexion, `me`, liste et
  révocation de ses jetons ; utilisateurs, préférences (tableau de bord, navigation, notifications), jetons push, jetons
  iCal, taux ; magasins et membres ; types de shift ; shifts ; disponibilités ; notifications ; journal. Limite
  300 requêtes/min par jeton ou par IP.
- **Sécurité** : CSRF, CSP avec nonce, `X-Frame-Options`, HSTS, limitation de débit, fichiers du stockage servis après
  contrôle d'accès.

**Cible Laravel** : réglages par un modèle `AppSetting` typé ; `Schedule` + `schedule:run` (et une route signée pour
l'hébergement sans cron, si on la garde) ; Sanctum pour l'API (expiration, abilities) ; `RateLimiter` ; `php artisan
down` avec exception Owner ; middleware d'en-têtes ou `spatie/laravel-csp` ; `spatie/laravel-backup` pour les
sauvegardes.

---

## 5. Points d'extension offerts aux bundles

`src/Core/Extension/ExtensionRegistry.php` : menu (`navigation`), permissions, fonctionnalités par magasin, widgets du
tableau de bord, cartes de statistiques, superpositions de pages, types de notifications, tâches cron, sections du
profil, écouteurs d'événements, jeux de données d'export. Plus les fournisseurs `Absences` (congés, utilisé par le
planning) et `ShiftFormSections` (sections du formulaire de shift).

Règle de la reconstruction : chaque point d'extension est recréé **avec le domaine du Cœur qui l'utilise**, et le Cœur
s'en sert lui-même pour ses propres widgets, entrées de menu, etc.

---

## 6. Anomalies relevées dans l'existant (à corriger en reconstruisant)

1. `moveShift` ne recalcule pas le type dominant (`AdminShiftController.php:1457-1461`).
2. `quickShift` écrit `user_id = 0` pour un shift ouvert, `storeShift` écrit `null`.
3. Durées calculées par `strtotime("HH:MM")` sur la date du jour : écart possible un jour de changement d'heure.
4. Pas de validation du format d'heure dans `moveShift` et `quickShift`.
5. Tâches système déclenchées au passage d'une requête (`NotificationMiddleware`) en plus du cron.

À confirmer chacune par un test sur `Kintai` avant de la déclarer.

---

## 7. Fonctionnalités dont l'abandon ou le remplacement est à décider

**Tranché le 04/11/2026** (plan, décisions 5 à 8) : installation et mise à jour web conservées (hébergement mutualisé
sans terminal), licence conservée, édition des traductions abandonnée, signalement GitHub et documentation du wiki
conservés, push FCM conservé pour une future application mobile. Restent ouverts : marché de bundles, sauvegarde
(maison ou `spatie/laravel-backup`), cron par URL, slugs de routes.

| Fonctionnalité | Proposition |
|---|---|
| Assistant d'installation web | `php artisan kintai:install` ; page web minimale seulement si l'hébergement mutualisé l'exige |
| Mise à jour depuis GitHub dans l'application | Remplacer par déploiement (`composer`, `migrate`) ; garder seulement l'alerte « mise à jour disponible » |
| Marché et installation de bundles dans l'application | Remplacer par `composer require` ; garder l'activation par magasin |
| Sauvegarde/restauration maison | `spatie/laravel-backup` ; restauration hors application |
| Édition des langues dans l'application | Fichiers `lang/` versionnés ; à garder seulement si des clients traduisent eux-mêmes |
| Cron par URL à jeton | Garder seulement si des installations n'ont pas de cron système |
| Documentation synchronisée depuis le wiki | Lien vers la documentation externe |
| Signalement d'issue GitHub | Lien ou e-mail de support |
| Licence | À garder si le modèle commercial l'exige ; à isoler dans un package |
| Slugs de routes (`route_slugs`) | Abandon (identifiants ou `getRouteKeyName`) |
| Push mobile FCM | À garder seulement s'il existe une application mobile en service |

---

## 8. Zones non lues (à relire au début de chaque tranche)

- Identité : validation de création/édition d'utilisateur, `AdminRoleController`, `RoleAssignmentSyncService`, seed des
  rôles par défaut, durée des jetons de réinitialisation, seuils du throttle, colonnes exactes de l'anonymisation.
- Planning : JavaScript de la frise et du calendrier, tests de `ShiftWageCalculator`, résolution des conflits en masse,
  déductions (`StoreService`), `StoreStatsService` (1085 lignes), import Excel, `ScheduleExportService`, fuseau de la
  synchronisation Google, `MAX_ATTEMPTS`.
- Tableau de bord : `DashboardAlertService`, résolution des dispositions, règles par défaut des préférences de
  notification, correspondance déclencheurs/types dans `AdminShiftController`.
- Système : services de sauvegarde, de mise à jour, de réinitialisation, de licence ; écrans d'administration ; format des
  réponses de l'API v1 (pagination, enveloppe) ; `src/Core/Security/*`.

## 9. Ordre proposé des tranches (phase 4)

1. Identité et accès (connexion, profil, utilisateurs, rôles et droits) — tout le reste en dépend.
2. Magasins et types de shift.
3. Shifts, calcul des coûts, disponibilités.
4. Tableau de bord et navigation (premiers registres d'extension : widgets, menu).
5. Notifications et journal d'activité.
6. Exports, iCal, import Excel.
7. API v1.
8. Synchronisation Google Agenda.
9. Administration et système (réglages, sauvegardes, maintenance, cron).
