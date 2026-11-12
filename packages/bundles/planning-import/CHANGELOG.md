# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir de l'import de planning Excel de l'ancien framework (`src/Core/Services/ExcelShiftImport`) : algorithme en trois phases repris à l'identique en comportement, pages Livewire + Flux, permission `shifts.import` du Core, tables `store_import_settings` et `import_aliases` du bundle.

Écarts :
- Les shifts sont créés par les actions du Core (`CreateShift`, `ReplaceShift`, `DeleteShift`) : droits et règles du Core, shifts remplacés conservés en corbeille avec leur remplaçant, événements `ShiftCreated` / `ShiftReplaced` / `ShiftDeleted`. Les suppressions de shifts absents du fichier exigent `shifts.delete`.
- L'ancien refus d'importer un shift identique à celui d'un autre employé (conçu pour les employés supprimés dont les shifts restaient) disparaît : plusieurs employés peuvent avoir les mêmes heures. Le drapeau « employé supprimé » de l'aperçu disparaît avec lui.
- Le fichier envoyé n'est plus conservé dans `storage/uploads` (il contient des noms d'employés) ; le modèle `template.xlsm` n'est plus proposé.
- Un nom qui finit par un caractère dont le dernier octet UTF-8 est 0x80 (par exemple « む ») n'est plus tronqué par le nettoyage des espaces de fin de cellule.
- Le motif des feuilles est une expression régulière validée à l'enregistrement ; un motif invalide revient à « toutes les feuilles ».
- Les réglages vivent sur leur propre page (`/planning-import/settings`) et non plus dans le formulaire du magasin ; la colonne d'alias s'appelle `staff_name`.
- Création d'un employé inconnu depuis l'aperçu par `CreateEmployee` (source `import`), avec les noms en furigana obligatoires.
- Nouveaux : menu « Import de planning », journal (`planning_import.imported`, nombres seulement), suppression des alias à l'anonymisation.
