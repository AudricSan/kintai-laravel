# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir de `StoreStatsService`, `EmployeeStatsService` et `Core/Extension/Sales` de l'ancien framework (première tranche : services ; seconde tranche : interface, voir plus bas). Le calcul de la paie (`buildPayslipData`) reste dans le bundle `salary-report`.

Écarts :
- Les ventes passent par `Kintai\Contracts\Sales\DailySalesProvider` (contrat 1.6) ; le bundle daily-report le fournit. Sans fournisseur, la rentabilité renvoie `available = false`.
- Le cache de `storeStats` / `costByMonth` passe par la façade `Cache` (store `array`) avec les clés et la durée de la requête d'origine ; 60 secondes au plus dans un processus long.
- Un shift est « modifié » quand `updated_at` est postérieur à `created_at` (les shifts Laravel ont toujours un `updated_at`).
- Absences : seuls les congés approuvés sont publiés par le contrat ; les statuts en attente, refusé et annulé valent 0 et `timeoffsByType` est vide. Les échanges de shifts (bundle shift-swap) ne sont pas lus : `swaps` et `swapCount` valent 0.
- Les shifts ouverts (sans employé) ne comptent pas. Les taux personnels de tous les employés des shifts du magasin s'appliquent (et non des seuls membres actuels). La liste d'utilisateurs ne contient que les membres, les employés des shifts et les créateurs de shifts, sans champ secret.
- Les semaines s'étiquettent par année ISO (`o-W`) au lieu de `Y-W`. `calculate()` renvoie des montants bruts et la devise : libellés de dates et mise en forme sont ceux de la page.
- `multiStoreComparison` ignore un magasin inconnu ; `comparisonFor` limite aux magasins du droit `payroll.view`.

Seconde tranche (interface) :
- Pages Livewire sous `/analytics` : statistiques du magasin, rapport par employé, fiche d'un employé (ouverture journalisée), rentabilité (état vide sans fournisseur de ventes). Droits `payroll.view` / `payroll.export` du Core, gérant restreint à ses magasins, droit relu à chaque rendu.
- Menu (section Statistiques), action « Statistics » sur la liste et la fiche des employés, trois widgets du tableau de bord gérant (aperçu des statistiques, vue d'ensemble financière par devise, RH et absentéisme).
- Jeu de données d export `store-stats` (exports du Core, `payroll.export`) branché sur `storeStatsExportData` (nouveau paramètre facultatif `$until`).
- Libellés fixes des services (« Not defined », « No type », jours de la semaine) passés par les traductions `fr` / `ja` du bundle : les clés de ces tableaux sont donc traduites.
- Écarts : pas de colonne ni de bloc pour les échanges de shifts, les congés par statut ou par type, les retards et les heures supplémentaires (voir le README) ; pas de lien depuis la liste des magasins ; histogrammes CSS à la place de Chart.js ; export tabulaire (un enregistrement par employé) au lieu du CSV à sections.
