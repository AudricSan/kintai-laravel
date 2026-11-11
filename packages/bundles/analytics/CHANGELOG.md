# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir de `StoreStatsService`, `EmployeeStatsService` et `Core/Extension/Sales` de l'ancien framework (première tranche : services ; seconde tranche : interface, voir plus bas). Le calcul de la paie (`buildPayslipData`) reste dans le bundle `salary-report`.

Écarts :
- Les ventes passent par `Kintai\Contracts\Sales\DailySalesProvider` (contrat 1.6) ; le bundle daily-report le fournit. Sans fournisseur, la rentabilité renvoie `available = false`.
- Le cache de `storeStats` / `costByMonth` passe par la façade `Cache` (store `array`) avec les clés et la durée de la requête d'origine ; 60 secondes au plus dans un processus long.
- Un shift est « modifié » quand `updated_at` est postérieur à `created_at` (les shifts Laravel ont toujours un `updated_at`).
- Absences : les demandes de congé et les échanges de shifts passent par les contrats `TimeOffStatistics` et `ShiftSwapStatistics` (contrat 1.7, voir 1.1.0) ; sans bundle de congés ou d'échanges, il n'y en a aucun.
- Les shifts ouverts (sans employé) ne comptent pas. Les taux personnels de tous les employés des shifts du magasin s'appliquent (et non des seuls membres actuels). La liste d'utilisateurs ne contient que les membres, les employés des shifts et les créateurs de shifts, sans champ secret.
- Les semaines s'étiquettent par année ISO (`o-W`) au lieu de `Y-W`. `calculate()` renvoie des montants bruts et la devise : libellés de dates et mise en forme sont ceux de la page.
- `multiStoreComparison` ignore un magasin inconnu ; `comparisonFor` limite aux magasins du droit `payroll.view`.

Seconde tranche (interface) :
- Pages Livewire sous `/analytics` : statistiques du magasin, rapport par employé, fiche d'un employé (ouverture journalisée), rentabilité (état vide sans fournisseur de ventes). Droits `payroll.view` / `payroll.export` du Core, gérant restreint à ses magasins, droit relu à chaque rendu.
- Menu (section Statistiques), action « Statistics » sur la liste et la fiche des employés, trois widgets du tableau de bord gérant (aperçu des statistiques, vue d'ensemble financière par devise, RH et absentéisme).
- Jeu de données d export `store-stats` (exports du Core, `payroll.export`) branché sur `storeStatsExportData` (nouveau paramètre facultatif `$until`).
- Libellés fixes des services (« Not defined », « No type », jours de la semaine) passés par les traductions `fr` / `ja` du bundle : les clés de ces tableaux sont donc traduites.
- Écarts : pas de retards ni d'heures supplémentaires (voir le README) ; histogrammes CSS à la place de Chart.js ; export tabulaire (un enregistrement par employé) au lieu du CSV à sections.

## 1.1.0

Comble les écarts de la 1.0.0 grâce au contrat 1.7 (`requiredContract` `^1.7`) :
- Congés : `StoreStatsService::storeStats` lit toutes les demandes par `Kintai\Contracts\Planning\TimeOffStatistics` (fournie par le bundle timeoff) : `timeoffsByStatus` avec les quatre statuts réels et `timeoffsByType` rempli ; le taux d'absentéisme, les jours d'absence et les demandes par employé restent calculés sur les congés approuvés. La page du magasin montre les blocs « par statut » et « par type » (masqués sans demande).
- Échanges de shifts : `swaps` (rapport par employé) et `swapCount` (fiche) comptent les échanges acceptés créés sur la période où l'employé est demandeur ou collègue sollicité, par `Kintai\Contracts\Shifts\ShiftSwapStatistics` (fournie par le bundle shift-swap). La colonne et la carte « Échanges » n'apparaissent que s'il y en a.
- Liens « Statistiques » et « Rentabilité » sur chaque ligne de la liste des magasins, par `StoreAction` (contrat 1.7), avec les droits des pages cibles ; « Rentabilité » n'est proposée qu'avec un fournisseur de ventes.
- `ApprovedAbsences` lit `TimeOffStatistics` à la place de `TimeOffProvider` ; nouveau `AcceptedSwaps`.
