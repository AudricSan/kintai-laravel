# Mascottes Kintai — inventaire des poses

Ce document liste les poses des mascottes de Kintai déjà intégrées à
l'application, celles disponibles mais pas encore utilisées, et les
éventuels manques restants.

Depuis le 18-19/09/2026, il y a **deux** mascottes : Kitsune (le renard,
anciennement appelé "Foxy") et Tanuki (le tanuki), toutes deux personnelles
à AudricSan en tant que développeur (utilisables sur tous ses projets, pas
seulement Kintai — voir [[user_foxy_personal_brand]]) et toutes deux
utilisées pour Kintai. **Seule Kitsune est pour l'instant câblée dans
l'app** (`public/assets/img/mascot/`, voir section 1) ; Tanuki est un jeu
de sources complet mais encore entièrement non intégré (section 3).

## Organisation du dossier `docs/brand/`

Depuis le 18-19/09/2026, les sources ne sont plus à plat dans
`docs/brand/` mais réparties en deux sous-dossiers, un par mascotte :

- `docs/brand/Kitsune/` — tous les fichiers historiquement à la racine
  (`foxy-style-guide.png`, `foxy_pose.png`, `foxy-pose-sheet-v2.png`,
  `foxy-expressions-sheet-hd.png`, `expr-*-hd.png`) y ont été déplacés, et
  l'ensemble des poses individuelles numérotées (`NN_nom.png`, ex.
  `27_reffu.png`, `17_recherche_vide.png`, `21_Maintenance.png`, `405.png`,
  etc. — tout ce que décrivait ce document jusqu'au 16/09/2026) a été
  **supprimé et remplacé** par un nouveau jeu régénéré, nommé
  sémantiquement `kitsune_<contexte>.png` plutôt que par numéro (voir
  Sources ci-dessous). Le style (renard orange, sac "K" vert) reste le
  même.
- `docs/brand/Tanuki/` — nouveau, jeu de sources complet pour la mascotte
  Tanuki (voir Sources ci-dessous). Rien n'y a d'équivalent avant le
  18-19/09/2026.

Comme avant, **ces deux dossiers restent des documents de travail, à ne
jamais utiliser directement dans l'app** — chaque pose utilisée doit être
détourée/recadrée et exportée en PNG transparent dans
`public/assets/img/mascot/` (process en section 6). Aucun fichier sous
`public/assets/img/mascot/` n'a été modifié par cette réorganisation :
l'app affiche toujours exactement les mêmes images qu'avant le 18/09/2026.

## Sources — Kitsune (renard)

- `docs/brand/Kitsune/foxy-style-guide.png`, `foxy_pose.png`,
  `foxy-pose-sheet-v2.png`, `foxy-expressions-sheet-hd.png`,
  `expr-{normal,smile,wink,worried,angry}-hd.png` — planches historiques,
  conservées pour référence (voir l'ancien historique détaillé dans
  l'historique git de ce fichier si besoin) mais plus la source active
  pour aucune pose depuis la régénération ci-dessous.
- **`docs/brand/Kitsune/kitsune_<contexte>.png`** — nouvelle source à
  privilégier : poses régénérées individuellement, fond blanc uni, nommées
  par contexte plutôt que par numéro (`kitsune_http404.png`,
  `kitsune_boite_vide.png`, `kitsune_salut_accueil.png`, etc.). Couvre les
  codes HTTP 401/403/404/405/408/409/413/422/429/500/501/502/504 (401,
  429, 501, 502, 504 pas encore utilisés dans l'app — voir section 2), plus
  les scènes déjà câblées (accueil, boîte vide, cloche, pointeuse,
  enveloppe de paie, etc.) et quelques nouvelles (fête/jalon, fatigue,
  déçu, saute, s'étire, court, assis, lit + livre).
- Plusieurs fichiers `kitsune_<contexte>_Dub.png` existent en doublon quasi
  identique du fichier sans suffixe (vérifié visuellement sur
  `kitsune_http404.png`/`kitsune_http404_Dub.png` — pixel pour pixel la
  même image) : contrairement aux variantes `_v2`/`_v3` de Tanuki
  (ci-dessous), ce ne sont **pas** des variantes alternatives à choisir,
  juste des doublons — le fichier sans suffixe suffit.
- `ChatGPT Image 18 sept. 2026...png` (×3) et `image-gen-*.png` (×2) —
  sorties brutes de génération, non renommées. Au moins une
  (`image-gen-6(5).png`) est une variante écartée de `kitsune_http422.png`
  (même pose, tourbillon de confusion à la place du point d'interrogation
  vert) : à garder pour référence si la version retenue doit être
  retravaillée, sinon sans usage direct.

## Sources — Tanuki (tanuki)

Nouveau depuis le 18-19/09/2026, aucun équivalent avant.

- **`docs/brand/Tanuki/tanuki_planche_reference_character_design.png`** —
  planche de référence officielle "Kintai マスコットキャラクター Tanuki".
  Contient le concept (texte JP : présence bienveillante, rythme tranquille
  mais encourageant, silhouette ronde et attachante, lien avec la nature et
  les gens, partenaire de Kintai), la palette officielle (`#8B6B4F`
  brun clair, `#D9B896` beige, `#F7F3E7` crème, `#5A4636` brun foncé,
  `#4CAF50` vert — **identique au vert de Kitsune**, cohérence de marque
  Kintai —, `#E8F5E9` vert très clair), la structure/proportions (grosse
  tête ronde ~moitié de la hauteur totale, oreilles petites et rondes,
  corps trapu, queue à rayures ~moitié de la hauteur du corps), 4 variations
  de visage (通常/normal, にっこり/sourire, びっくり/surpris, ウインク/clin
  d'œil), la liste complète des poses et expressions prévues (voir
  ci-dessous), des exemples d'usage (icône d'app, écran de démarrage,
  illustration in-app, icône de notification) et des interdits (ne pas
  déformer, ne pas faire pivoter, ne pas utiliser en traits seuls, ne pas
  utiliser en basse résolution).
- `docs/brand/Tanuki/tanuki_planche_reference_poses.png` — variante centrée
  sur la grille de poses (non détaillée individuellement ici).
- **`docs/brand/Tanuki/tanuki_<contexte>.png`** (et variantes `_v2`/`_v3`)
  — poses individuelles fond blanc, même logique que les `kitsune_*.png`.
  Contrairement aux `_Dub` de Kitsune, les suffixes `_v2`/`_v3` sont ici de
  **vraies variantes alternatives**, pas des doublons (vérifié sur
  `tanuki_http404.png` vs `tanuki_http404_v2.png` : deux compositions
  différentes — loupe simple avec "?" jaune vs bulle de dialogue verte
  "recherche" + "?" vert) — à choisir/trancher au moment de l'export, pas à
  fusionner automatiquement.
- Couverture HTTP nettement plus large que Kitsune : 400, 401, 403, 404
  (+v2), 405, 408, 409, 410, 413, 422 (+v2), 429, 500, 501, 502, 503, 504 —
  inclut notamment **400, 410 et 503**, absents du jeu Kitsune actuel (voir
  section 4).
- Poses "scène" hors codes HTTP : assis, assis au repos, attente normale
  (+v2), boîte vide (+v2, +v3), cloche/notification (+v2), court (+v2,
  +v3), déçu, dort, encourage (+v2, court), enveloppe de paie (+v2),
  fatigue debout (+v2), fête/jalon (+v2), heureux, lit + livre, marche
  (+v2), outils/maintenance (+v2, +v3), pointeuse/horloge (+v2), recherche
  vide, réfléchit, salut/accueil (+v2, +v3), saute (+v2, +v3), se retourne,
  s'étire, smartphone, surpris.

## 1. Poses Kitsune intégrées dans l'app

Ce tableau documente ce que l'app affiche **aujourd'hui** — inchangé par
cette réorganisation, puisque `public/assets/img/mascot/` n'a pas bougé.
La colonne "Source d'origine" pointe vers des fichiers `NN_nom.png`
**supprimés** depuis le 18-19/09/2026 (voir Sources ci-dessus) ; elle est
gardée à titre d'historique. La colonne "Équivalent Kitsune actuel" liste
le fichier du nouveau jeu régénéré qui semble correspondre le mieux (vérifié
visuellement quand indiqué), pour le jour où ces exports seront
retravaillés à partir des nouvelles sources — **rien n'a été re-exporté ni
re-câblé**, c'est une piste, pas un changement effectif.

| Pose                                  | Source d'origine (supprimée)     | Équivalent Kitsune actuel (non câblé)                                                                 | Fichier exporté (inchangé) | Contexte d'usage                                                                                  |
| -------------------------------------- | --------------------------------- | ------------------------------------------------------------------------------------------------------ | --------------------------- | -------------------------------------------------------------------------------------------------- |
| 拒否・refuse (patte levée, "stop")     | `27_reffu.png`                    | `kitsune_http403.png` — vérifié, même idée (barrière + panneau sens interdit)                          | `http-error/403.png`        | Page 403                                                                                            |
| 検索（空） (loupe + bulle "?")         | `17_recherche_vide.png`           | `kitsune_http404.png` — vérifié, quasi identique (loupe + "K" + "?")                                   | `http-error/404.png`        | Page 404                                                                                            |
| Scène dédiée (texte JP en dur)         | `405.png`                         | `kitsune_http405.png` — vérifié, composition redessinée bien plus simple ; texte JP réduit à un seul mot ("送信"/envoyer sur le bouton) au lieu de plusieurs phrases | `http-error/405.png`        | Page 405                                                                                             |
| 振り返る (se retourne)                 | `03_se_retourne.png`              | `kitsune_http422.png` — vérifié, mais pose totalement différente (renard perplexe devant un formulaire avec champs en erreur, plus parlant pour une 422)         | `http-error/422.png`        | Page 422                                                                                            |
| ツール・メンテナンス (outils)          | `21_Maintenance.png`              | `kitsune_outils_maintenance.png` — vérifié, même idée (clé + tournevis) ; **`kitsune_http500.png` existe aussi séparément** (renard agitant une clochette, vérifié — sémantique différente, "alerte") | `http-error/500.png`        | Page 500                                                                                             |
| 寝る (dort)                            | `05_dort.png`                     | **aucun équivalent direct** — le plus proche est `kitsune_lit_livre.png` (assis, lit un livre, vérifié : ne dort pas, juste calme) ; voir section 4                | `http-error/503.png`        | Page 503 (maintenance)                                                                              |
| スマホを見る (regarde son téléphone)   | `06_telephone.png`                | **aucun équivalent** dans le nouveau jeu Kitsune — voir section 4 (Tanuki en a un : `tanuki_smartphone.png`) | `docs-hero.png`             | Sidebar wiki + page "wiki non cloné" (`/docs`)                                                       |
| Salut / accueil                        | foxy_pose #16 (grille, basse résolution) | `kitsune_salut_accueil.png` — vérifié, pose "salue de la main, tire la langue"                    | `wave-hello.png`            | Bandeau des pages invité (`layout/guest.php`)                                                       |
| ボックス（空）, penché sur le carton   | `18_boite_vide_penche.png`        | `kitsune_boite_vide.png` — probable (pas de distinction "penché"/"assis" dans le nouveau jeu)          | `box-empty.png`             | Icône par défaut de **tout** `.empty-state` (CSS `::before`)                                        |
| 通知・ベル                             | `22_cloche.png`                   | `kitsune_cloche_notification.png` — vérifié, même idée                                                  | `bell.png`                  | Variante `.empty-state--bell`, `/notifications`                                                     |
| タイムカード・時計, pointeuse "08:59"  | `19_pointeuse.png`                | `kitsune_pointeuse_horloge.png` — probable                                                              | `timeclock-empty.png`       | Variante `.empty-state--timeclock`, `employee-timeclock.php`                                        |
| 給与明細 (texte JP gravé dans l'image) | `20_fiche_paie.png`               | `kitsune_enveloppe_paie.png` — vérifié, **même problème persistant** : "給与明細書" toujours gravé dans le dessin | `salary-empty.png`          | Variante `.empty-state--salary`, `reports-salary.php` — voir section 5                              |

Le reste de la section 1 (emplacement final `public/assets/img/mascot/`,
sous-dossier `http-error/`, nommage par code HTTP, détourage/recadrage
480px, pages de secours statiques `public/errors/<code>.html`) est
inchangé par cette réorganisation — voir l'historique git de ce fichier
pour le détail si besoin.

## 2. Poses Kitsune disponibles, pas encore câblées

En plus des équivalents de la section 1, le nouveau jeu `kitsune_*.png`
couvre des contextes sans usage actuel dans l'app :

| Fichier                             | Pose                                          | Contexte suggéré                                                             |
| ------------------------------------ | ---------------------------------------------- | ------------------------------------------------------------------------------ |
| `kitsune_http401.png`                | Accès non authentifié                          | Page 401 (pas de vue dédiée actuellement dans `src/UI/View/errors/`)          |
| `kitsune_http429.png`                | Trop de requêtes                               | Page 429 (idem, pas de vue dédiée actuellement)                                |
| `kitsune_http501.png`, `502.png`, `504.png` | Erreurs serveur/passerelle               | Pas de vues dédiées actuellement                                               |
| `kitsune_fete_jalon.png`             | 祝う・節目, célébration                        | Jalon atteint (mise à jour réussie, sauvegarde terminée)                      |
| `kitsune_decu.png`                   | がっかり, déçu                                 | Refus/rejet d'une demande                                                     |
| `kitsune_heureux.png`                | Heureux                                        | Confirmation de succès (shift créé, congé approuvé)                          |
| `kitsune_saute.png`                  | ジャンプ, saut joyeux                          | Confirmation de succès (variante de `heureux`)                                |
| `kitsune_setire.png`                 | のび, étirement                                | Accueil dashboard ("Bonjour" du matin)                                       |
| `kitsune_court.png`                  | 走る, course                                   | Traitement en cours (import Excel, sauvegarde, sync du wiki)                  |
| `kitsune_encourage.png`              | 応援する, encourage                            | Fin d'assistant d'installation, jalon atteint                                 |
| `kitsune_assis.png`                  | 座る, assis neutre                             | État vide alternatif si on veut varier `box-empty`                            |
| `kitsune_recherche_vide.png`         | 検索（空）, variante de `http404`              | "Aucun résultat" sur une liste avec recherche/filtre                          |
| `kitsune_lit_livre.png`              | Assis, lit un livre                            | Voir section 4 (piste pour combler l'absence de pose "dort")                  |

## 3. Poses Tanuki intégrées dans l'app

**Mise à jour (20/09/2026)** : `public/assets/img/mascot/` est réparti en
`mascot/kitsune/` et `mascot/tanuki/` (même arborescence dans chaque, y
compris `http-error/`), résolu par `kintai\Core\Services\MascotResolver`
(réglage Owner `app_mascot_mode` sur `/admin/owner-settings` : `mix` par
défaut — tirage aléatoire par requête, aucune persistance —, ou figé sur
`kitsune`/`tanuki`). Les 13 emplacements de vues passent par le helper
`mascot_path(string $context): string` plutôt que de construire leur
chemin en dur ; les 4 icônes d'état vide en CSS (`base.css`) réagissent à
un attribut `data-mascot` posé sur `<html>` par les layouts.

Les 17 poses Tanuki sont désormais exportées (sources fournies déjà
détourées — fond transparent — plutôt que via le flood-fill documenté en
section 6, qui reste la méthode de repli si une future pose arrive sans
fond déjà retiré) :

| Contexte (`mascot_path()`) | Source Tanuki                       | Hauteur |
| --------------------------- | ------------------------------------ | ------- |
| `brand-icon`                 | `tanuki_salut_accueil.png`           | 240px   |
| `login`                      | `tanuki_setire.png`                  | 240px   |
| `footer-fox-1`                | `tanuki_assis.png`                   | 240px   |
| `footer-fox-2`                | `tanuki_se_retourne.png`             | 240px   |
| `footer-fox-3`                | `tanuki_heureux.png`                 | 240px   |
| `footer-fox-4`                | `tanuki_assis_repos.png`             | 240px   |
| `http-error/403`             | `tanuki_http403.png`                 | 480px   |
| `http-error/404`             | `tanuki_http404.png`                 | 480px   |
| `http-error/405`             | `tanuki_http405.png`                 | 480px   |
| `http-error/422`             | `tanuki_http422.png`                 | 480px   |
| `http-error/500`             | `tanuki_http500.png`                 | 480px   |
| `http-error/503`             | `tanuki_http503.png`                 | 480px   |
| `docs-hero`                   | `tanuki_smartphone.png`              | 240px   |
| `box-empty`                   | `tanuki_boite_vide.png`              | 240px   |
| `bell`                        | `tanuki_cloche_notification.png`     | 240px   |
| `timeclock-empty`             | `tanuki_pointeuse_horloge.png`       | 240px   |
| `salary-empty`                | `tanuki_enveloppe_paie.png`          | 240px   |

Variantes `_v2`/`_v3` : non retenues pour cette première passe (base
`tanuki_<contexte>.png` utilisée partout où une variante existait) — à
revisiter au cas par cas si une des bases s'avère moins lisible en usage
réel. Les contextes 400/410/408/409/413/429/501/502/504 (Tanuki en a des
poses dédiées, Kitsune non, et aucun des deux côtés n'a de vue HTTP
dédiée dans `src/UI/View/errors/`) restent non exportés — à réévaluer si
ces codes gagnent une vue dédiée un jour.

## 4. Écarts entre les deux jeux

- **503 (maintenance)** : Kitsune n'a pas de pose "dort" dans le nouveau
  jeu (la plus proche, `kitsune_lit_livre.png`, lit calmement plutôt que de
  dormir) ; Tanuki, lui, a `tanuki_dort.png`, une correspondance directe.
- **`docs-hero.png` (regarde son téléphone)** : aucun équivalent Kitsune
  dans le nouveau jeu ; Tanuki a `tanuki_smartphone.png`.
- **400 et 410** : présents uniquement chez Tanuki (`tanuki_http400.png`,
  `tanuki_http410.png`), absents des deux côtés en pratique puisqu'aucune
  vue dédiée n'existe pour ces codes dans `src/UI/View/errors/`.

## 5. Points d'attention

- **`error-405.png` : texte japonais gravé dans l'image, réduit mais pas
  éliminé.** L'ancienne composition (`405.png`, supprimée) contenait
  plusieurs phrases en japonais gravées ; le nouvel équivalent
  (`kitsune_http405.png`) ne garde plus qu'un seul mot japonais ("送信" sur
  le bouton d'un formulaire simulé) — nette amélioration, mais toujours pas
  neutre pour un visiteur FR/EN. Non re-exporté/re-câblé à ce jour ; à
  garder en tête si cette pose est retravaillée.
- **`salary-empty.png` (給与明細) : texte japonais gravé, toujours présent
  dans le nouveau jeu.** `kitsune_enveloppe_paie.png` reprend le même
  problème que l'ancien `20_fiche_paie.png` : "給与明細書" est gravé
  directement dans le dessin, pas détachable. Si cette pose est un jour
  re-exportée à partir de la nouvelle source, le problème signalé à
  l'utilisateur (voir historique git) reste entier.
- **Pose 15 (Kitsune), variante "salue de la main" perdue (12/09/2026,
  historique)** : toujours non récupérable — voir historique git de ce
  fichier pour le détail de l'incident. Sans objet pour le nouveau jeu
  `kitsune_*.png` qui ne reprend pas la numérotation.
- **Aucun fichier `public/assets/img/mascot/` n'a changé** avec cette
  réorganisation : c'est un renommage/complément des *sources*
  (`docs/brand/`) uniquement. Toute correspondance indiquée en section 1
  est une piste pour un futur re-export, pas un changement déjà effectif.

## 6. Process technique (rappel)

1. Repérer si la pose vient d'une planche à grille (`foxy-style-guide.png`
   = 8 colonnes × 2 lignes légende dessous ; `foxy_pose.png`/`foxy-pose-
   sheet-v2.png` = 6 colonnes × 4 lignes légende dessus, largeur de cellule
   256px) ou d'un fichier individuel fond blanc (`kitsune_*.png`,
   `tanuki_*.png` — pas de grille ni légende à exclure, comme les anciens
   `NN_nom.png`).
2. Détourer le fond par flood-fill **depuis les bords uniquement**, avec un
   critère "clair et peu saturé" (`min(r,g,b) > 205` et écart max-min
   `< 32`) plutôt qu'une simple distance à une couleur de fond fixe. Un
   recadrage par différence de couleur simple, ou un flood-fill qui ne part
   pas des bords, rend aussi transparentes les zones claires du
   ventre/pattes de l'animal.
3. Exporter en PNG transparent, hauteur standard 240px (480px pour les
   pages d'erreur HTTP, voir section 1), dans
   `public/assets/img/mascot/<kitsune|tanuki>/<contexte>.png` (même nom de
   contexte des deux côtés — `http-error/<code>.png` garde son
   sous-dossier — c'est ce nom que `mascot_path($context)` reçoit).
4. Utiliser la clé de traduction `mascot_alt` (déjà présente en fr/en/ja)
   pour l'attribut `alt`.
5. Pour une icône réutilisée par un CSS `background: url(...)` (cas de
   `.empty-state::before`), bien compter les niveaux de dossier depuis le
   fichier CSS où la règle est écrite (`public/assets/css/src/base.css` →
   `public/assets/img/...` = **deux** niveaux au-dessus, `../../img/...`),
   pas depuis `app.css` qui l'importe.
6. **Ne jamais `mv`/renommer directement vers un nom de fichier déjà
   utilisé** sans vérifier d'abord qu'il est libre (`mv -n`, ou lister le
   dossier juste avant) — un renommage écrase silencieusement la cible sur
   ce système de fichiers, sans corbeille ni confirmation.
