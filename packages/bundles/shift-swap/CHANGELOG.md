# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir du bundle `shift-swap` 3.0.0 de l'ancien framework : pages Livewire + Flux, droits avec portée magasin et droits par défaut du rôle `manager` (contrat 1.2), `PendingRequestProvider`, widgets, notifications (`Notifier`), journal, API `/api/v1/shift-swap/requests` (ancienne URL `/api/v1/shift-swap-requests`). Exige le contrat 1.3 (`ShiftExchanger`, `ShiftWarnings`).

Écarts :
- l'échange passe par le remplacement de shifts du Core (les anciens shifts partent à la corbeille, deux nouveaux shifts portent l'échange) au lieu de réaffecter les shifts ; les garde-fous du planning (congé bloquant, chevauchement, indisponibilité, durée) remplacent le seul contrôle « deux shifts le même jour », avec confirmation ;
- un gérant ne peut approuver qu'une demande que le collègue a acceptée (avant, l'approbation était possible dès la création) ; il prévient les approbateurs à l'acceptation (`swap_to_approve`) ;
- une demande mémorise la version des deux shifts : un shift modifié depuis ne s'échange plus ;
- seuls les shifts à venir s'échangent ; un shift ne peut être dans qu'une seule demande en attente ;
- le taux horaire et les minutes nettes imposés d'un shift ne suivent pas l'échange (propres à l'employé) ;
- l'API n'expose plus de `PUT status` : les décisions sont des actions (`accept`, `decline`, `cancel`, `approve`, `refuse`, `apply`) ; `PUT` ne corrige que le motif ;
- les statistiques d'échanges du Core (`SwapProvider`) et la carte « Échanges » du tableau de bord employé n'existent plus dans le nouveau Core : le widget « Échanges en attente de ma réponse » les remplace ;
- les notifications de planning du Core (shift ajouté, retiré) accompagnent l'échange.

## 1.1.0

Exige le contrat 1.7 (`requiredContract` `^1.7`) : `SwapStatistics` fournit les demandes d'échange de tous statuts (`Kintai\Contracts\Shifts\ShiftSwapStatistics`, filtrées sur `created_at`) aux statistiques d'un autre bundle (analytics) ; liée au Core tant que le bundle est activé.
