# Import de planning Excel (`kintai/bundle-planning-import`)

Importe les shifts d'un planning Excel : envoi du fichier, aperçu, confirmation. Les noms lus dans le fichier sont rattachés aux employés du magasin et retenus (alias) pour les imports suivants.

- Écrans : `/planning-import` (choix du magasin et du fichier, aperçu éditable, confirmation), `/planning-import/settings` (réglages d'un magasin). Entrée « Import de planning » dans la section Planning du menu.
- Droit : la permission `shifts.import` du Core, avec sa portée magasin (accordée au rôle `manager` par défaut). Le bundle n'ajoute aucune permission. Le bundle désactivé, aucune route, aucun menu ; les tables restent.
- Création des shifts : uniquement par les actions du Core (`CreateShift`, `ReplaceShift`, `DeleteShift`) avec leurs droits (`shifts.create`, `shifts.update`, `shifts.delete`) et leurs règles ; rien n'est recopié dans le bundle. Les avertissements du garde-fou (indisponibilité, chevauchement, durée) sont forcés puisque l'aperçu est confirmé ; un congé approuvé refuse le shift, qui est compté « ignoré ».
- Fichier : `xlsx`, `xls`, `xlsm`, `csv` ou `ods`, 20 Mo au plus, lu par PhpSpreadsheet et jamais conservé. L'année et le mois viennent du nom du fichier quand il les contient (`2026年11月.xlsx`) ; la date de chaque bloc ne garde alors que son jour.
- Détection en trois phases (`src/Detection`) :
  1. visuelle : le fichier est une suite de blocs d'un jour (date en colonne A, lignes de shifts dessous) ; une cellule colorée ou bordée appartient à un segment dont les heures se déduisent de la grille ; une couleur identifie un employé (un segment coloré sans nom prend le nom le plus fréquent de sa couleur) ; le bruit de moins de 30 minutes est fusionné entre deux segments de même couleur, sinon ignoré ;
  2. qualification et score de confiance : fond coloré = shift si le nom est valide ; fond blanc = shift seulement avec nom valide, 4 heures au moins et bordures haut et bas ; sinon absence ;
  3. déduplication stricte sur « nom|date|début|fin » (première occurrence gardée, doublons comptés).
- Aperçu : les plages contiguës d'un même nom le même jour sont fusionnées ; chaque nom est rattaché à un employé actif du magasin (nom complet « nom prénom » ou nom affiché, sinon alias retenu) ; les lignes sans employé ou déjà planifiées sont décochées ; chevauchements signalés ; un employé inconnu peut être créé sur place (`CreateEmployee`, source `import`, rôle par défaut du magasin).
- Confirmation (une transaction) : le même shift déjà planifié est ignoré ; un shift qui en chevauche un autre de l'employé le remplace (l'ancien part à la corbeille, remplacé) ; les shifts de ces employés dans le magasin et la période du fichier, à une date que le fichier ne couvre pas pour eux, sont supprimés (si l'acteur a `shifts.delete`) ; les noms rattachés deviennent des alias. L'aperçu est revérifié à la confirmation (employé membre du magasin, date et heures valides).
- Réglages par magasin (`shifts.import`) : première et dernière colonne d'heures (défaut 4 et 52), heure de départ (6), minutes par colonne (30), lignes par jour (9), lignes de shifts (7), motif regex des feuilles (`^\d{4}$` ; sans correspondance, toutes les feuilles sont lues), pause automatique (à partir de N minutes, de M minutes ; 0 = désactivée).
- Journal : `planning_import.imported` (nombres seulement), `planning_import.settings_updated`. Aucun nom d'employé ni contenu du fichier dans les journaux.
- Données : tables `store_import_settings` et `import_aliases`. Les alias d'un employé anonymisé sont supprimés ; désactiver le bundle conserve tout.
- Traductions : `lang/fr.json`, `lang/ja.json`.

Tests : `php artisan test packages/bundles/planning-import` (fichiers Excel synthétiques générés avec PhpSpreadsheet, aucun fichier client).
