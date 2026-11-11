# Statistiques (`kintai/bundle-analytics`)

Statistiques de magasin et d'employé, rapport par employé, rentabilité face aux ventes, comparaison multi-magasins, trois widgets du tableau de bord gérant et export des statistiques.

- Droits : `payroll.view` (voir) et `payroll.export` (exporter) du Core, avec leur portée magasin, lus par `AnalyticsAccess` (`canView`, `canExport`, `viewableStoreIds`, `viewableStores`, `exportableStoreIds`, `storesOfEmployee`). Le bundle n'ajoute aucune permission. Un gérant restreint ne voit que ses magasins ; sans `payroll.view` les pages répondent 403. Le bundle désactivé, rien n'est enregistré (pages en 404, aucun widget, menu, export ni action) ; il n'a aucune table.

## Pages (routes sous `/analytics`, noms `analytics.*`)

Toutes sont des composants Livewire pleine page ; le magasin (`?store=`) est choisi parmi ceux que l'utilisateur voit, la période (`?period=`, 7 à 365 jours) par raccourcis. Le droit sur le magasin est relu à chaque rendu.

| Route | Page |
| --- | --- |
| `analytics.stats` (`/analytics`) | Statistiques du magasin : scores (équité, efficacité, stabilité, bien-être), planification, charge de travail, stabilité, RH, analyse financière, jours de la semaine, qualité du planning, comparaison à la période précédente. |
| `analytics.employees` | Rapport par employé : shifts, heures, coût estimé, jours travaillés, congés, série la plus longue. |
| `analytics.employee` (`?user=`) | Fiche d'un employé dans un magasin (le magasin est l'un de ceux que l'utilisateur voit et où l'employé est membre). L'ouverture est journalisée (`analytics.employee_viewed`, sans donnée de la fiche). |
| `analytics.profitability` | Rentabilité : ventes, clients et coût du personnel (saisi et calculé) par jour et par mois. Sans fournisseur de ventes (bundle daily-report), la page affiche un état vide explicite et l'entrée de menu n'est pas proposée. |

Montants mis en forme par le `Money` du Core (devise et style du symbole du magasin). Graphiques : histogrammes CSS (aucune dépendance JavaScript). Libellés fixes (« Not defined », « No type », jours de la semaine) passés par les traductions du bundle (`lang/fr.json`, `lang/ja.json`).

## Points d'extension utilisés

- Menu (section Statistiques) : « Store statistics », « Employee report », « Profitability » (seulement avec un fournisseur de ventes).
- Widgets du tableau de bord gérant (`payroll.view`, magasins visibles seulement) : `analytics.stats` (heures nettes, coût et stabilité sur 30 jours, tendance hebdomadaire pour un seul magasin), `analytics.finance` (masse salariale du mois contre le mois précédent, tendance sur six mois, un bloc par devise), `analytics.absence` (taux d'absentéisme et congés approuvés sur 30 jours). Les widgets du Core (chiffres clés, alertes, shifts du jour, « Mon mois ») ne sont pas touchés.
- Action de la liste et de la fiche des employés : « Statistics » (`EmployeeAction`, visible si l'utilisateur voit un magasin de l'employé).
- Exports du Core : jeu de données `store-stats` (« Store statistics », `payroll.export`, par magasin, avec période, filtre employés, CSV / Excel / PDF) : une ligne par magasin et par employé avec heures nettes, coût estimé et devise, depuis `StoreStatsService::storeStatsExportData`. La page des statistiques propose le lien d'export CSV (`exports.download`) avec `payroll.export`.

## Services (`src/`)

- `StoreStatsService` : `storeStats($storeId, $period, $min, $max)` (même tableau de résultats que l'ancien service : répartition des heures, coûts, équité, stabilité, absences, repos courts, risque d'épuisement, comparaison à la période précédente), `costByMonth`, `storeStatsExportData($storeId, $period, $until)`, `employeeReport`, `multiStoreComparison` et `comparisonFor($viewer, $period)` (limité aux magasins que l'utilisateur voit).
- `EmployeeStatsService` : `employeeStats($storeId, $userId, $period)` (fiche d'un employé dans un magasin) et `calculate($userId, $month)` (vue mensuelle et salaire estimé, tous magasins). Les montants sont bruts ; la mise en forme est celle de la page.
- `ProfitabilityService::storeProfitability($storeId, $from, $to)` : ventes du jour lues par `Kintai\Contracts\Sales\DailySalesProvider` (contrat 1.6, fourni par le bundle daily-report) face au coût du personnel calculé. Sans fournisseur de ventes actif, `available` vaut faux et il n'y a aucune ligne, sans erreur.
- `DashboardFigures` : chiffres des trois widgets. `Presenter` : mise en forme (montants, noms, pourcentages).
- Coûts : toujours par le `ShiftWageCalculator` du Core (tranches de types, taux personnels, taux plat, minutes nettes imposées) ; rien n'est recopié. Shifts supprimés et shifts ouverts (sans employé) exclus.
- Cache : `storeStats` et `costByMonth` sont gardés dans le cache `array` de Laravel (clé magasin, période, bornes de durée et jour ; pas d'invalidation, la durée d'une requête ; 60 secondes au plus dans un processus qui dure).
- Congés : par `Kintai\Contracts\Planning\TimeOffStatistics` (contrat 1.7, fourni par le bundle timeoff ; aucun sans bundle de congés). La répartition par statut et par type compte toutes les demandes qui commencent sur la période ; jours d'absence, taux d'absentéisme et demandes par employé ne comptent que les congés approuvés.
- Échanges de shifts : par `Kintai\Contracts\Shifts\ShiftSwapStatistics` (contrat 1.7, fourni par le bundle shift-swap) ; seuls les échanges acceptés comptent, pour le demandeur comme pour le collègue sollicité. Sans bundle d'échanges, la colonne et la carte « Échanges » sont masquées.
- Liste des magasins : actions « Statistics » et « Profitability » par `Kintai\Contracts\Stores\StoreAction` (contrat 1.7), visibles avec `payroll.view` sur le magasin ; la rentabilité n'est proposée qu'avec un fournisseur de ventes.

## Écarts connus avec l'ancienne version

Les pages montrent les chiffres disponibles et masquent les blocs vides, sans colonne ni zéro trompeur.

- Widget RH : retards et semaines en heures supplémentaires ne sont pas calculés (le bundle timeclock n'est pas lu) ; le widget montre le taux d'absentéisme et les congés approuvés.
- Export : l'ancien CSV à plusieurs sections (résumé, semaines, mois, types) devient le jeu de données tabulaire `store-stats` (employé par magasin) des exports du Core ; période libre (jusqu'à 366 jours) au lieu des seules périodes de 7 à 365 jours finissant aujourd'hui.
- Graphiques : histogrammes CSS à la place des graphiques Chart.js ; scores en cartes colorées plutôt qu'en anneaux.

Tests : `php artisan test packages/bundles/analytics`.
